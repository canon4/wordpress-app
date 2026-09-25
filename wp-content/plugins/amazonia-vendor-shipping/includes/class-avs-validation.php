<?php
/**
 * Endurece la validación de datos de envío para reducir guías con dirección inválida.
 *
 * @package Amazonia_Vendor_Shipping
 */

defined( 'ABSPATH' ) || exit;

class AVS_Validation {

	/**
	 * Caché en memoria por ciclo de petición.
	 *
	 * @var array<int,bool>
	 */
	private static $vendor_status_cache = array();

	public static function init() {
		add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'validate_postcode' ), 20, 2 );
		add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'validate_vendor_origin' ), 25, 2 );

		// Blindaje defensivo contra bug de TypeError en WCFM shipping by country (PHP 8+)
		add_filter( 'get_user_metadata', array( __CLASS__, 'fix_wcfm_shipping_by_country_type_error' ), 10, 4 );

		// Condición: ocultar productos de vendedores con dirección incompleta
		add_filter( 'woocommerce_product_is_visible', array( __CLASS__, 'filter_product_visibility' ), 10, 2 );
		add_action( 'woocommerce_product_query', array( __CLASS__, 'exclude_incomplete_vendors_from_query' ) );

		// Bloquear compra si acceden a la ficha directamente
		add_filter( 'woocommerce_is_purchasable', array( __CLASS__, 'filter_product_purchasable' ), 10, 2 );
		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'validate_add_to_cart_vendor' ), 10, 2 );
		add_action( 'woocommerce_single_product_summary', array( __CLASS__, 'render_incomplete_vendor_notice' ), 30 );

		// Aviso al vendedor en su panel de WCFM
		add_action( 'wcfm_dashboard_before_widgets', array( __CLASS__, 'render_vendor_dashboard_address_notice' ) );

		// Invalidación de caché de vendedores incompletos
		add_action( 'wcfm_vendor_settings_before_update', array( __CLASS__, 'clear_incomplete_vendors_cache' ) );
		add_action( 'profile_update', array( __CLASS__, 'clear_incomplete_vendors_cache' ) );
		add_action( 'user_register', array( __CLASS__, 'clear_incomplete_vendors_cache' ) );
	}

	/**
	 * Determina si la dirección del vendedor está completa para operar y calcular envíos.
	 *
	 * @param int $vendor_id
	 * @return bool
	 */
	public static function is_vendor_address_complete( $vendor_id ) {
		$vendor_id = absint( $vendor_id );
		if ( ! $vendor_id ) {
			return true;
		}

		if ( isset( self::$vendor_status_cache[ $vendor_id ] ) ) {
			return self::$vendor_status_cache[ $vendor_id ];
		}

		// Si el usuario no es un vendedor de WCFM (ej. administrador), no aplicamos la restricción
		if ( function_exists( 'wcfm_is_vendor' ) && ! wcfm_is_vendor( $vendor_id ) ) {
			self::$vendor_status_cache[ $vendor_id ] = true;
			return true;
		}

		$is_complete = false;
		if ( class_exists( 'AVS_Origin' ) && method_exists( 'AVS_Origin', 'is_complete' ) ) {
			$is_complete = AVS_Origin::is_complete( $vendor_id );
		} else {
			$street  = get_user_meta( $vendor_id, '_wcfm_street_1', true );
			$city    = get_user_meta( $vendor_id, '_wcfm_city', true );
			$state   = get_user_meta( $vendor_id, '_wcfm_state', true );
			$country = get_user_meta( $vendor_id, '_wcfm_country', true );

			if ( empty( $street ) || empty( $city ) || empty( $state ) || empty( $country ) ) {
				$profile = get_user_meta( $vendor_id, 'wcfmmp_profile_settings', true );
				$addr    = ( isset( $profile['address'] ) && is_array( $profile['address'] ) ) ? $profile['address'] : array();
				$street  = $street ?: ( $addr['street_1'] ?? '' );
				$city    = $city ?: ( $addr['city'] ?? '' );
				$state   = $state ?: ( $addr['state'] ?? '' );
				$country = $country ?: ( $addr['country'] ?? '' );
			}

			$is_complete = ! empty( trim( (string) $street ) )
				&& ! empty( trim( (string) $city ) )
				&& ! empty( trim( (string) $state ) )
				&& ! empty( trim( (string) $country ) );
		}

		self::$vendor_status_cache[ $vendor_id ] = (bool) $is_complete;
		return self::$vendor_status_cache[ $vendor_id ];
	}

	/**
	 * Oculta el producto de listados públicos si el vendedor no tiene dirección completa.
	 *
	 * @param bool $visible
	 * @param int  $product_id
	 * @return bool
	 */
	public static function filter_product_visibility( $visible, $product_id ) {
		if ( ! $visible ) {
			return false;
		}

		// No ocultar dentro de paneles administrativos ni en el gestor de productos de WCFM
		if ( is_admin() || ( function_exists( 'wcfm_is_endpoint' ) && wcfm_is_endpoint() ) ) {
			return $visible;
		}

		$vendor_id = get_post_field( 'post_author', $product_id );
		if ( ! self::is_vendor_address_complete( $vendor_id ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Excluye a nivel de consulta SQL los productos de vendedores con dirección incompleta.
	 *
	 * @param WP_Query $q
	 */
	public static function exclude_incomplete_vendors_from_query( $q ) {
		if ( is_admin() || ! $q->is_main_query() ) {
			return;
		}

		if ( function_exists( 'wcfm_is_endpoint' ) && wcfm_is_endpoint() ) {
			return;
		}

		$incomplete = self::get_incomplete_vendor_ids();
		if ( empty( $incomplete ) ) {
			return;
		}

		$existing = (array) $q->get( 'author__not_in' );
		$q->set( 'author__not_in', array_unique( array_merge( $existing, $incomplete ) ) );
	}

	/**
	 * Retorna los IDs de vendedores cuya dirección está incompleta (con caché transitorio).
	 *
	 * @return int[]
	 */
	public static function get_incomplete_vendor_ids() {
		$cached = get_transient( 'avs_incomplete_vendor_ids' );
		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		$vendors = get_users( array(
			'role'   => 'wcfm_vendor',
			'fields' => array( 'ID' ),
		) );

		$incomplete = array();
		foreach ( $vendors as $v ) {
			if ( ! self::is_vendor_address_complete( $v->ID ) ) {
				$incomplete[] = (int) $v->ID;
			}
		}

		set_transient( 'avs_incomplete_vendor_ids', $incomplete, 15 * MINUTE_IN_SECONDS );
		return $incomplete;
	}

	/**
	 * Limpia la caché transitoria y en memoria de vendedores incompletos.
	 */
	public static function clear_incomplete_vendors_cache() {
		self::$vendor_status_cache = array();
		delete_transient( 'avs_incomplete_vendor_ids' );
	}

	/**
	 * Deshabilita la compra del producto si el vendedor no tiene dirección lista.
	 *
	 * @param bool       $purchasable
	 * @param WC_Product $product
	 * @return bool
	 */
	public static function filter_product_purchasable( $purchasable, $product ) {
		if ( ! $purchasable || ! is_a( $product, 'WC_Product' ) ) {
			return false;
		}

		$vendor_id = get_post_field( 'post_author', $product->get_id() );
		if ( ! self::is_vendor_address_complete( $vendor_id ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Validación previa a añadir al carrito para peticiones directas o AJAX.
	 *
	 * @param bool $passed
	 * @param int  $product_id
	 * @return bool
	 */
	public static function validate_add_to_cart_vendor( $passed, $product_id ) {
		$vendor_id = get_post_field( 'post_author', $product_id );
		if ( ! self::is_vendor_address_complete( $vendor_id ) ) {
			wc_add_notice(
				__( 'No es posible comprar este producto porque la tienda aún no ha configurado su dirección de envío.', 'amazonia-vendor-shipping' ),
				'error'
			);
			return false;
		}
		return $passed;
	}

	/**
	 * Muestra aviso informativo en la página individual de producto si el vendedor está incompleto.
	 */
	public static function render_incomplete_vendor_notice() {
		global $product;
		if ( ! $product || ! is_a( $product, 'WC_Product' ) ) {
			return;
		}

		$vendor_id = get_post_field( 'post_author', $product->get_id() );
		if ( ! self::is_vendor_address_complete( $vendor_id ) ) {
			echo '<div class="avs-vendor-incomplete-notice" style="margin:1em 0;padding:12px 16px;background:#fff8e6;border:1px solid #ffeeba;border-radius:6px;color:#856404;font-size:0.95em;">';
			echo esc_html__( 'Este producto no está disponible temporalmente mientras la tienda finaliza la configuración de su dirección de envío.', 'amazonia-vendor-shipping' );
			echo '</div>';
		}
	}

	/**
	 * Notificación destacada en el dashboard de WCFM para el vendedor.
	 */
	public static function render_vendor_dashboard_address_notice() {
		if ( ! function_exists( 'wcfm_is_vendor' ) || ! wcfm_is_vendor() ) {
			return;
		}

		$vendor_id = apply_filters( 'wcfm_current_vendor_id', get_current_user_id() );
		if ( ! self::is_vendor_address_complete( $vendor_id ) ) {
			$settings_url = function_exists( 'get_wcfm_settings_url' ) ? get_wcfm_settings_url() : admin_url( 'admin.php?page=wcfm-settings' );
			echo '<div class="wcfm-message wcfm-error" style="display:block;margin:15px 0;background:#fff3cd;border-left:5px solid #ffc107;color:#856404;padding:14px 18px;border-radius:4px;font-size:14px;">';
			echo '<strong>' . esc_html__( '⚠️ Atención:', 'amazonia-vendor-shipping' ) . '</strong> ';
			echo esc_html__( 'Tus productos están ocultos al público y no pueden ser comprados porque aún no has completado la dirección de tu tienda (calle, ciudad, departamento y país).', 'amazonia-vendor-shipping' ) . ' ';
			echo '<a href="' . esc_url( $settings_url ) . '" style="text-decoration:underline;font-weight:bold;color:#533f03;">' . esc_html__( 'Haz clic aquí para completarla en Ajustes', 'amazonia-vendor-shipping' ) . ' &rarr;</a>';
			echo '</div>';
		}
	}

	/**
	 * Blindaje defensivo contra bug de WCFM (TypeError en class-wcfmmp-shipping-by-country.php:322 en PHP 8+).
	 * Si un vendedor no tiene la meta configurada o está vacía, devuelve un array con valores por defecto.
	 *
	 * @param mixed  $value
	 * @param int    $object_id
	 * @param string $meta_key
	 * @param bool   $single
	 * @return mixed
	 */
	public static function fix_wcfm_shipping_by_country_type_error( $value, $object_id, $meta_key, $single = false ) {
		if ( '_wcfmmp_shipping_by_country' === $meta_key ) {
			if ( function_exists( 'remove_filter' ) ) {
				remove_filter( 'get_user_metadata', array( __CLASS__, __FUNCTION__ ), 10 );
			}
			$raw = function_exists( 'get_user_meta' ) ? get_user_meta( $object_id, '_wcfmmp_shipping_by_country', true ) : null;
			if ( function_exists( 'add_filter' ) ) {
				add_filter( 'get_user_metadata', array( __CLASS__, __FUNCTION__ ), 10, 4 );
			}

			if ( ! is_array( $raw ) || empty( $raw ) ) {
				$default = array(
					'_free_shipping_amount'       => '',
					'_wcfmmp_shipping_type_price' => 0,
					'_wcfmmp_additional_product'  => 0,
					'_wcfmmp_additional_qty'      => 0,
				);
				return array( $default );
			}
		}
		return $value;
	}

	/**
	 * Bloquea el checkout cuando un vendedor con productos en el carrito no tiene un origen
	 * de envío válido y su paquete se queda sin ninguna opción de envío disponible.
	 *
	 * @param array    $data
	 * @param WP_Error $errors
	 */
	public static function validate_vendor_origin( $data, $errors ) {
		if ( ! function_exists( 'WC' ) || ! WC()->cart || ! WC()->cart->needs_shipping() || ! class_exists( 'AVS_Origin' ) ) {
			return;
		}
		if ( ! WC()->shipping() ) {
			return;
		}

		$packages = WC()->shipping()->get_packages();
		foreach ( $packages as $package ) {
			$vendor_id = isset( $package['vendor_id'] ) ? absint( $package['vendor_id'] ) : 0;
			if ( ! $vendor_id ) {
				continue;
			}

			$has_origin = AVS_Origin::get_origin_id( $vendor_id ) && AVS_Origin::is_complete( $vendor_id );
			if ( $has_origin ) {
				continue;
			}

			// Sin origen válido: si además el paquete no tiene ninguna tarifa disponible, se bloquea.
			$rates = isset( $package['rates'] ) && is_array( $package['rates'] ) ? $package['rates'] : array();
			if ( ! empty( $rates ) ) {
				continue; // Hay alguna alternativa (p. ej. recogida local): no bloquear.
			}

			$store = AVS_Origin::get_vendor_address( $vendor_id );
			$name  = ! empty( $store['name'] ) ? $store['name'] : ( '#' . $vendor_id );
			$errors->add(
				'avs_no_origin',
				sprintf(
					/* translators: %s: nombre de la tienda */
					__( 'La tienda "%s" aún no ha configurado su dirección de origen de envío, por lo que no es posible calcular el envío de sus productos. Contáctala o quítalos del carrito para continuar.', 'amazonia-vendor-shipping' ),
					$name
				)
			);
		}
	}

	/**
	 * Exige código postal cuando el pedido necesita envío y el país lo requiere para Envia.
	 *
	 * @param array    $data   Datos publicados del checkout.
	 * @param WP_Error $errors Contenedor de errores del checkout.
	 */
	public static function validate_postcode( $data, $errors ) {
		if ( ! function_exists( 'WC' ) || ! WC()->cart || ! WC()->cart->needs_shipping() ) {
			return;
		}

		$ship_to_diff = ! empty( $data['ship_to_different_address'] );
		$country      = $ship_to_diff ? ( $data['shipping_country'] ?? '' ) : ( $data['billing_country'] ?? '' );
		$postcode     = $ship_to_diff ? ( $data['shipping_postcode'] ?? '' ) : ( $data['billing_postcode'] ?? '' );

		if ( '' === (string) $country ) {
			return;
		}

		if ( avs_postcode_required( $country ) && '' === trim( (string) $postcode ) ) {
			$errors->add(
				'shipping',
				__( 'Ingresa el código postal para poder calcular el envío.', 'amazonia-vendor-shipping' )
			);
		}
	}
}
