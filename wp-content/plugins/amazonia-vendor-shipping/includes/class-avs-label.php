<?php
/**
 * Fase 6 — Generación de guía por API (estilo MercadoLibre).
 *
 * El vendedor da clic en "Generar guía" en su dashboard; nuestro plugin llama a la API de Envia
 * (api.envia.com/ship/generate) con la API key del marketplace y el ORIGEN del vendedor, guarda el
 * PDF + tracking en el pedido y los expone para descarga. El vendedor nunca inicia sesión en Envia.
 *
 * Requiere una API key de Envia (WooCommerce → Envíos Amazonia). Generar guía crea un envío real
 * y consume saldo de la cuenta Envia; por eso es una acción manual y deliberada del vendedor.
 *
 * @package Amazonia_Vendor_Shipping
 */

defined( 'ABSPATH' ) || exit;

class AVS_Label {

	const META_URL      = '_avs_label_url';
	const META_TRACKING = '_avs_tracking';
	const META_TRACK_URL = '_avs_tracking_url';
	const META_CARRIER  = '_avs_label_carrier';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest' ) );
	}

	/* ---------------------------------------------------------------------
	 * Lectura de la guía
	 *
	 * La guía se guarda en el ÍTEM DE ENVÍO, no en el pedido. Un pedido multivendedor
	 * lleva un ítem de envío por vendedor, así que cada vendedor tiene su propia guía:
	 * guardarla a nivel de pedido hacía que la primera guía generada tapara a las demás
	 * y que un vendedor viera (y descargara) el PDF del otro, con la dirección del cliente.
	 * ------------------------------------------------------------------- */

	/**
	 * @param int $order_id
	 * @param int $vendor_id Vendedor cuyo paquete se consulta. 0 = el único paquete del pedido.
	 * @return bool
	 */
	public static function has_label( $order_id, $vendor_id = 0 ) {
		return '' !== self::get_label_url( $order_id, $vendor_id );
	}

	/**
	 * URL del PDF de la guía del paquete de ese vendedor ('' si no tiene).
	 *
	 * @param int $order_id
	 * @param int $vendor_id
	 * @return string
	 */
	public static function get_label_url( $order_id, $vendor_id = 0 ) {
		$item = self::find_item( $order_id, $vendor_id );
		return $item ? (string) $item->get_meta( self::META_URL ) : '';
	}

	/**
	 * Número de seguimiento del paquete de ese vendedor ('' si no tiene).
	 *
	 * @param int $order_id
	 * @param int $vendor_id
	 * @return string
	 */
	public static function get_tracking( $order_id, $vendor_id = 0 ) {
		$item = self::find_item( $order_id, $vendor_id );
		return $item ? (string) $item->get_meta( self::META_TRACKING ) : '';
	}

	/**
	 * ¿El vendedor tiene un paquete de Envia en este pedido? (decide si mostrarle el botón)
	 *
	 * @param int $order_id
	 * @param int $vendor_id
	 * @return bool
	 */
	public static function has_envia_package( $order_id, $vendor_id = 0 ) {
		return null !== self::find_item( $order_id, $vendor_id );
	}

	/* ---------------------------------------------------------------------
	 * Generación
	 * ------------------------------------------------------------------- */

	/**
	 * Genera por API la guía del paquete de UN vendedor y la guarda en su ítem de envío.
	 *
	 * @param int $order_id
	 * @param int $vendor_id Vendedor cuyo paquete se etiqueta. 0 = el único paquete del pedido
	 *                       (uso de admin; con varios paquetes es ambiguo y se rechaza).
	 * @return array|WP_Error ['label'=>url, 'tracking'=>string] en éxito.
	 */
	public static function generate( $order_id, $vendor_id = 0 ) {
		$order_id = absint( $order_id );
		$order    = wc_get_order( $order_id );
		if ( ! $order ) {
			return new WP_Error( 'avs_no_order', __( 'Pedido no encontrado.', 'amazonia-vendor-shipping' ) );
		}

		$ship = self::envia_shipping_item( $order, $vendor_id );
		if ( ! $ship ) {
			if ( ! self::envia_shipping_items( $order ) ) {
				return new WP_Error( 'avs_no_envia', __( 'El pedido no usó un envío de Envia.', 'amazonia-vendor-shipping' ) );
			}
			return new WP_Error( 'avs_ambiguous', __( 'Este pedido tiene envíos de varios vendedores: hay que indicar de qué vendedor es la guía.', 'amazonia-vendor-shipping' ) );
		}

		// Idempotencia por paquete: no se re-cobra una guía ya generada.
		if ( '' !== (string) $ship->get_meta( self::META_URL ) ) {
			return array(
				'label'    => (string) $ship->get_meta( self::META_URL ),
				'tracking' => (string) $ship->get_meta( self::META_TRACKING ),
				'already'  => true,
			);
		}

		$api = AVS_Config::get_api_key();
		if ( ! $api ) {
			return new WP_Error( 'avs_no_api', __( 'Falta configurar la API key de Envia en "Envíos Amazonia".', 'amazonia-vendor-shipping' ) );
		}

		// El vendedor del paquete lo manda el ítem, nunca el cliente de la petición.
		$ship_vendor = absint( $ship->get_meta( 'vendor_id' ) );
		$carrier     = (string) $ship->get_meta( 'carrier' );
		$service     = (string) $ship->get_meta( 'service' );
		if ( '' === $carrier || '' === $service ) {
			return new WP_Error( 'avs_no_service', __( 'El pedido no tiene transportadora/servicio de Envia para generar la guía.', 'amazonia-vendor-shipping' ) );
		}
		if ( ! $ship_vendor || ! AVS_Origin::get_origin_id( $ship_vendor ) ) {
			return new WP_Error( 'avs_no_origin', __( 'El vendedor de este pedido no tiene un origen de envío configurado en Envia.', 'amazonia-vendor-shipping' ) );
		}

		$origin      = AVS_Origin::get_vendor_address( $ship_vendor );
		$destination = self::order_destination( $order );
		$packages    = array( self::order_package( $order, $ship_vendor ) );
		$shipment    = array( 'carrier' => $carrier, 'service' => $service );

		$payload = avs_build_label_payload( $origin, $destination, $packages, $shipment );

		$res = self::api_post( '/ship/generate/', $api, $payload );
		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$data     = isset( $res['data'][0] ) && is_array( $res['data'][0] ) ? $res['data'][0] : ( isset( $res['data'] ) ? $res['data'] : $res );
		$label    = isset( $data['label'] ) ? (string) $data['label'] : '';
		$tracking = isset( $data['trackingNumber'] ) ? (string) $data['trackingNumber'] : '';
		if ( '' === $label ) {
			return new WP_Error( 'avs_no_label', __( 'Envia no devolvió el PDF de la guía.', 'amazonia-vendor-shipping' ), $res );
		}

		$ship->update_meta_data( self::META_URL, esc_url_raw( $label ) );
		$ship->update_meta_data( self::META_TRACKING, $tracking );
		$ship->update_meta_data( self::META_TRACK_URL, isset( $data['trackingUrl'] ) ? esc_url_raw( $data['trackingUrl'] ) : '' );
		$ship->update_meta_data( self::META_CARRIER, isset( $data['carrier'] ) ? (string) $data['carrier'] : $carrier );
		$ship->save();

		$order->add_order_note(
			sprintf(
				/* translators: 1: vendor id, 2: tracking */
				__( 'Guía de Envia generada para el vendedor #%1$d. Tracking: %2$s', 'amazonia-vendor-shipping' ),
				$ship_vendor,
				$tracking ?: 'N/D'
			)
		);
		$order->save();

		return array(
			'label'    => $label,
			'tracking' => $tracking,
		);
	}

	/* ---------------------------------------------------------------------
	 * Resolución del paquete (ítem de envío) de un vendedor
	 * ------------------------------------------------------------------- */

	/**
	 * Todos los ítems de envío del pedido que usaron Envia.
	 *
	 * @param WC_Order $order
	 * @return array Lista de WC_Order_Item_Shipping.
	 */
	private static function envia_shipping_items( $order ) {
		$items = array();
		foreach ( $order->get_items( 'shipping' ) as $it ) {
			if ( 'envia_shipping' === $it->get_method_id() ) {
				$items[] = $it;
			}
		}
		return $items;
	}

	/**
	 * Ítem de envío que corresponde a un vendedor.
	 *
	 * Con $vendor_id = 0 (admin) solo es inequívoco si el pedido tiene UN único paquete de
	 * Envia; si hay varios se devuelve null en vez de adivinar, que era justo el fallo:
	 * tomar "el primero" podía etiquetar el paquete de otro vendedor.
	 *
	 * @param WC_Order $order
	 * @param int      $vendor_id
	 * @return WC_Order_Item_Shipping|null
	 */
	private static function envia_shipping_item( $order, $vendor_id = 0 ) {
		$items     = self::envia_shipping_items( $order );
		$vendor_id = absint( $vendor_id );

		if ( ! $items ) {
			return null;
		}
		if ( ! $vendor_id ) {
			return ( 1 === count( $items ) ) ? $items[0] : null;
		}
		foreach ( $items as $it ) {
			if ( absint( $it->get_meta( 'vendor_id' ) ) === $vendor_id ) {
				return $it;
			}
		}
		return null;
	}

	/**
	 * Igual que envia_shipping_item() pero desde un ID de pedido (para los getters públicos).
	 *
	 * @param int $order_id
	 * @param int $vendor_id
	 * @return WC_Order_Item_Shipping|null
	 */
	private static function find_item( $order_id, $vendor_id = 0 ) {
		$order = wc_get_order( absint( $order_id ) );
		return $order ? self::envia_shipping_item( $order, $vendor_id ) : null;
	}

	private static function order_destination( $order ) {
		return array(
			'name'     => $order->get_formatted_shipping_full_name() ?: $order->get_formatted_billing_full_name(),
			'company'  => $order->get_shipping_company(),
			'email'    => $order->get_billing_email(),
			'phone'    => $order->get_billing_phone(),
			'street'   => $order->get_shipping_address_1() ?: $order->get_billing_address_1(),
			'number'   => $order->get_shipping_address_2() ?: $order->get_billing_address_2(),
			'city'     => $order->get_shipping_city() ?: $order->get_billing_city(),
			'state'    => $order->get_shipping_state() ?: $order->get_billing_state(),
			'country'  => $order->get_shipping_country() ?: $order->get_billing_country(),
			'postcode' => $order->get_shipping_postcode() ?: $order->get_billing_postcode(),
		);
	}

	/**
	 * Construye el paquete a etiquetar sumando SOLO los productos del vendedor indicado.
	 *
	 * Antes se sumaba el pedido entero: en un pedido multivendedor, la guía de un vendedor
	 * salía con el peso y el valor de los productos de todos, es decir, una guía incorrecta
	 * (y más cara). Con $vendor_id = 0 se mantiene el comportamiento antiguo (pedido completo).
	 *
	 * @param WC_Order $order
	 * @param int      $vendor_id
	 * @return array Paquete para el payload de Envia.
	 */
	private static function order_package( $order, $vendor_id = 0 ) {
		$vendor_id = absint( $vendor_id );
		$weight    = 0.0;
		$value     = 0.0;
		$l = 0.0; $w = 0.0; $h = 0.0;
		$counted   = 0;

		foreach ( $order->get_items() as $it ) {
			$p = $it->get_product();
			if ( ! $p ) {
				continue;
			}
			if ( $vendor_id && $vendor_id !== self::item_vendor_id( $it, $p ) ) {
				continue; // Producto de otro vendedor: no va en este paquete.
			}
			$weight += ( (float) $p->get_weight() ) * $it->get_quantity();
			$value  += (float) $it->get_total();
			$l       = max( $l, (float) $p->get_length() );
			$w       = max( $w, (float) $p->get_width() );
			$h       = max( $h, (float) $p->get_height() );
			$counted++;
		}

		// Si no se pudo atribuir ningún producto al vendedor, no inventamos: se usa el total
		// del pedido como valor declarado (mismo comportamiento que antes).
		if ( ! $counted ) {
			$value = (float) $order->get_total();
		}

		return avs_build_label_package( $weight, $l, $w, $h, $value, 'Pedido #' . $order->get_id() );
	}

	/**
	 * Vendedor dueño de una línea del pedido.
	 *
	 * @param WC_Order_Item_Product $item
	 * @param WC_Product            $product
	 * @return int 0 si no se puede determinar.
	 */
	private static function item_vendor_id( $item, $product ) {
		// WCFM guarda el vendedor en la meta de la línea cuando el pedido se divide.
		$vendor = absint( $item->get_meta( '_vendor_id' ) );
		if ( $vendor ) {
			return $vendor;
		}
		// Si no, se deduce del producto (el vendedor es su autor en WCFM).
		if ( function_exists( 'wcfm_get_vendor_id_by_post' ) ) {
			return absint( wcfm_get_vendor_id_by_post( $product->get_id() ) );
		}
		return 0;
	}

	/**
	 * POST a la API de Envia con la API key (Bearer). Devuelve el body decodificado o WP_Error.
	 */
	private static function api_post( $path, $api_key, $payload ) {
		$res = wp_remote_post(
			AVS_Config::get_api_base() . $path,
			array(
				'timeout' => 45,
				'headers' => array(
					'Content-Type'  => 'application/json; charset=utf-8',
					'Authorization' => 'Bearer ' . $api_key,
				),
				'body'    => wp_json_encode( $payload ),
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$code = wp_remote_retrieve_response_code( $res );
		$body = json_decode( wp_remote_retrieve_body( $res ), true );

		if ( $code >= 400 || ( isset( $body['meta'] ) && 'error' === $body['meta'] ) ) {
			$msg = '';
			if ( isset( $body['error']['message'] ) ) {
				$msg = is_array( $body['error']['message'] ) ? wp_json_encode( $body['error']['message'] ) : $body['error']['message'];
			} elseif ( isset( $body['message'] ) ) {
				$msg = is_array( $body['message'] ) ? wp_json_encode( $body['message'] ) : $body['message'];
			} else {
				$msg = wp_remote_retrieve_body( $res );
			}
			return new WP_Error( 'avs_envia_generate', $msg ?: __( 'Error al generar la guía en Envia.', 'amazonia-vendor-shipping' ), array( 'http' => $code ) );
		}
		return is_array( $body ) ? $body : array();
	}

	/* ---------------------------------------------------------------------
	 * REST: botón "Generar guía" del vendedor
	 * ------------------------------------------------------------------- */

	public static function register_rest() {
		register_rest_route(
			'avs/v1',
			'/generate-label',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rest_generate' ),
				'permission_callback' => array( __CLASS__, 'rest_can' ),
				'args'                => array(
					'order_id'  => array( 'required' => true, 'sanitize_callback' => 'absint' ),
					// Solo lo usa un admin para desambiguar un pedido con varios vendedores.
					// A un vendedor se le IGNORA: su ID se toma de la sesión, no de la petición.
					'vendor_id' => array( 'required' => false, 'sanitize_callback' => 'absint' ),
				),
			)
		);
	}

	/**
	 * Vendedor en cuyo nombre actúa la petición actual.
	 *
	 * Un vendedor solo puede actuar sobre su propio paquete: su ID sale de la sesión.
	 * Un admin no está atado a un vendedor, así que devuelve 0 (y podrá indicarlo aparte).
	 *
	 * @return int
	 */
	public static function context_vendor_id() {
		if ( current_user_can( 'manage_woocommerce' ) ) {
			return 0;
		}
		if ( function_exists( 'wcfm_is_vendor' ) && wcfm_is_vendor() ) {
			return self::current_vendor_id();
		}
		return 0;
	}

	/**
	 * Permiso: vendedor participante del pedido (o admin de la tienda).
	 */
	public static function rest_can( $request ) {
		return self::can_manage_order( $request->get_param( 'order_id' ) );
	}

	/**
	 * ¿El usuario actual puede gestionar la guía de este pedido?
	 *
	 * Admin de la tienda: cualquier pedido. Vendedor: solo aquellos en los que participa.
	 *
	 * Antes se consultaba `wcfm_is_order_for_vendor()`, una función que NO existe en WCFM:
	 * la expresión `! function_exists(...) || ...` devolvía true y dejaba a cualquier vendedor
	 * autenticado generar la guía de CUALQUIER pedido (consume saldo real de Envia y expone
	 * la dirección del cliente). Ahora la propiedad se verifica contra la tabla de WCFM.
	 *
	 * @param int $order_id
	 * @return bool
	 */
	public static function can_manage_order( $order_id ) {
		$order_id = absint( $order_id );
		if ( ! $order_id || ! is_user_logged_in() ) {
			return false;
		}
		if ( current_user_can( 'manage_woocommerce' ) ) {
			return true;
		}
		if ( ! function_exists( 'wcfm_is_vendor' ) || ! wcfm_is_vendor() ) {
			return false;
		}
		return self::vendor_owns_order( $order_id, self::current_vendor_id() );
	}

	/**
	 * ID de vendedor del usuario actual (0 si no aplica).
	 *
	 * @return int
	 */
	public static function current_vendor_id() {
		return absint( apply_filters( 'wcfm_current_vendor_id', get_current_user_id() ) );
	}

	/**
	 * ¿El vendedor participa en el pedido?
	 *
	 * Fuente de verdad: `{prefix}wcfm_marketplace_orders`, donde WCFM registra una fila por
	 * cada par (pedido, vendedor). Un pedido multivendedor tiene varias filas con el mismo
	 * order_id, así que esta consulta responde exactamente "¿este vendedor está en el pedido?".
	 *
	 * @param int $order_id
	 * @param int $vendor_id
	 * @return bool
	 */
	public static function vendor_owns_order( $order_id, $vendor_id ) {
		global $wpdb;

		$order_id  = absint( $order_id );
		$vendor_id = absint( $vendor_id );
		if ( ! $order_id || ! $vendor_id ) {
			return false;
		}

		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}wcfm_marketplace_orders WHERE order_id = %d AND vendor_id = %d",
				$order_id,
				$vendor_id
			)
		);

		return (int) $found > 0;
	}

	public static function rest_generate( $request ) {
		$order_id = absint( $request->get_param( 'order_id' ) );

		// Vendedor: de la sesión. Solo un admin puede indicarlo en la petición.
		$vendor_id = self::context_vendor_id();
		if ( ! $vendor_id && current_user_can( 'manage_woocommerce' ) ) {
			$vendor_id = absint( $request->get_param( 'vendor_id' ) );
		}

		$res = self::generate( $order_id, $vendor_id );
		if ( is_wp_error( $res ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'message' => $res->get_error_message() ), 200 );
		}
		return new WP_REST_Response(
			array(
				'ok'       => true,
				'label'    => $res['label'],
				'tracking' => $res['tracking'],
				'message'  => __( 'Guía generada correctamente.', 'amazonia-vendor-shipping' ),
			),
			200
		);
	}
}
