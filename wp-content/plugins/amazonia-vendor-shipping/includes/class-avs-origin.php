<?php
/**
 * Origen de envío por vendedor (modelo MercadoLibre/MercadoPago).
 *
 * El plugin de Envia solo admite UN origen estático por zona/método. Aquí registramos
 * la dirección de tienda de CADA vendedor en la cuenta de Envia (POST /user-address),
 * guardamos su address_id y una firma para re-sincronizar cuando cambie.
 *
 * Registro automático al guardar la tienda + botón de respaldo en el dashboard.
 *
 * @package Amazonia_Vendor_Shipping
 */

defined( 'ABSPATH' ) || exit;

class AVS_Origin {

	const META_ORIGIN_ID  = '_avs_envia_origin_id';
	const META_ORIGIN_SIG = '_avs_envia_origin_sig';

	public static function init() {
		// Auto-registro tras guardar los settings del vendedor (después de AVS_Config, prioridad 20).
		add_action( 'wcfm_vendor_settings_before_update', array( __CLASS__, 'on_vendor_settings_update' ), 30, 2 );

		// Botón de respaldo "Sincronizar con Envia" en el dashboard del vendedor.
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest' ) );
		add_action( 'wp_footer', array( __CLASS__, 'render_sync_button' ), 40 );
	}

	/* ---------------------------------------------------------------------
	 * Lectura de la dirección del vendedor (WCFM)
	 * ------------------------------------------------------------------- */

	/**
	 * Dirección de tienda del vendedor, normalizada para usarse como origen de Envia.
	 *
	 * @param int $vendor_id
	 * @return array{name:string,email:string,phone:string,street:string,number:string,city:string,state:string,country:string,postcode:string}
	 */
	public static function get_vendor_address( $vendor_id ) {
		$vendor_id = absint( $vendor_id );

		$profile = get_user_meta( $vendor_id, 'wcfmmp_profile_settings', true );
		$profile = is_array( $profile ) ? $profile : array();
		$store   = isset( $profile['address'] ) && is_array( $profile['address'] ) ? $profile['address'] : array();

		$street_1 = get_user_meta( $vendor_id, '_wcfm_street_1', true );
		$street_2 = get_user_meta( $vendor_id, '_wcfm_street_2', true );
		$city     = get_user_meta( $vendor_id, '_wcfm_city', true );
		$zip      = get_user_meta( $vendor_id, '_wcfm_zip', true );
		$state    = get_user_meta( $vendor_id, '_wcfm_state', true );
		$country  = get_user_meta( $vendor_id, '_wcfm_country', true );

		// Fallbacks al perfil serializado si las claves planas están vacías.
		$street_1 = $street_1 ?: ( $store['street_1'] ?? '' );
		$street_2 = $street_2 ?: ( $store['street_2'] ?? '' );
		$city     = $city ?: ( $store['city'] ?? '' );
		$zip      = $zip ?: ( $store['zip'] ?? '' );
		$state    = $state ?: ( $store['state'] ?? '' );
		$country  = $country ?: ( $store['country'] ?? '' );

		$store_name = isset( $profile['store_name'] ) ? $profile['store_name'] : get_the_author_meta( 'display_name', $vendor_id );
		$email      = isset( $profile['store_email'] ) && $profile['store_email'] ? $profile['store_email'] : get_the_author_meta( 'user_email', $vendor_id );
		$phone      = isset( $profile['phone'] ) ? $profile['phone'] : '';

		return array(
			'name'     => (string) $store_name,
			'email'    => (string) $email,
			'phone'    => (string) $phone,
			'street'   => (string) $street_1,
			'number'   => (string) $street_2,
			'city'     => (string) $city,
			'state'    => (string) $state,
			'country'  => (string) $country,
			'postcode' => (string) $zip,
		);
	}

	/**
	 * ¿La dirección del vendedor está completa para cotizar?
	 *
	 * @param int $vendor_id
	 * @return bool
	 */
	public static function is_complete( $vendor_id ) {
		return avs_vendor_address_complete( self::get_vendor_address( $vendor_id ) );
	}

	/**
	 * address_id del origen del vendedor en Envia (vacío si no se ha registrado).
	 *
	 * @param int $vendor_id
	 * @return string
	 */
	public static function get_origin_id( $vendor_id ) {
		return (string) get_user_meta( absint( $vendor_id ), self::META_ORIGIN_ID, true );
	}

	/* ---------------------------------------------------------------------
	 * Registro / sincronización con Envia
	 * ------------------------------------------------------------------- */

	private static function envia_settings() {
		$s = get_option( 'woocommerce_envia_shipping_settings', array() );
		return is_array( $s ) ? $s : array();
	}

	private static function envia_token() {
		$s = self::envia_settings();
		return isset( $s['token'] ) ? $s['token'] : '';
	}

	/**
	 * Registra (o vuelve a registrar) la dirección del vendedor como origen en Envia.
	 *
	 * @param int  $vendor_id
	 * @param bool $force     Registrar aunque la firma no haya cambiado.
	 * @return string|WP_Error address_id en éxito.
	 */
	public static function register_with_envia( $vendor_id, $force = false ) {
		$vendor_id = absint( $vendor_id );
		$addr      = self::get_vendor_address( $vendor_id );

		if ( ! avs_vendor_address_complete( $addr ) ) {
			return new WP_Error( 'avs_incomplete', __( 'La dirección de la tienda está incompleta (calle, ciudad, estado, país y código postal si aplica).', 'amazonia-vendor-shipping' ) );
		}

		$token = self::envia_token();
		if ( ! $token ) {
			return new WP_Error( 'avs_no_token', __( 'La cuenta de Envia del marketplace no está conectada.', 'amazonia-vendor-shipping' ) );
		}

		$sig = avs_vendor_origin_signature( $addr );
		if ( ! $force && self::get_origin_id( $vendor_id ) && get_user_meta( $vendor_id, self::META_ORIGIN_SIG, true ) === $sig ) {
			return self::get_origin_id( $vendor_id ); // Sin cambios: ya está sincronizado.
		}

		if ( ! class_exists( '\Enviacom' ) ) {
			return new WP_Error( 'avs_no_envia', __( 'El plugin de Envia no está activo.', 'amazonia-vendor-shipping' ) );
		}

		// Esquema de dirección que Envia exige para este país (valida estrictamente los campos).
		$country = strtoupper( (string) $addr['country'] );
		$schema  = self::fetch_country_schema( $country );
		if ( is_wp_error( $schema ) ) {
			return $schema;
		}

		$payload = avs_map_origin_form_payload( $schema, $addr );
		$url     = \Enviacom::ENVIA_QUERIES_HOSTNAME . '/user-address';

		try {
			$res = \Enviacom::requests_process( 'POST', $url, $token, $payload );
		} catch ( \Exception $e ) {
			return new WP_Error( 'avs_envia_error', $e->getMessage(), array( 'code' => $e->getCode() ) );
		}

		$address_id = '';
		if ( isset( $res['id'] ) ) {
			$address_id = (string) $res['id'];
		} elseif ( isset( $res['data']['id'] ) ) {
			$address_id = (string) $res['data']['id'];
		} elseif ( isset( $res['address_id'] ) ) {
			$address_id = (string) $res['address_id'];
		}

		if ( '' === $address_id ) {
			return new WP_Error( 'avs_no_id', __( 'Envia no devolvió un identificador de dirección.', 'amazonia-vendor-shipping' ), $res );
		}

		update_user_meta( $vendor_id, self::META_ORIGIN_ID, $address_id );
		update_user_meta( $vendor_id, self::META_ORIGIN_SIG, $sig );
		return $address_id;
	}

	/**
	 * Esquema de campos de dirección que Envia exige para un país (cacheado 12h).
	 *
	 * @param string $country ISO-2.
	 * @return array|WP_Error
	 */
	private static function fetch_country_schema( $country ) {
		$country = strtoupper( (string) $country );
		$cache   = 'avs_envia_form_' . $country;
		$cached  = get_transient( $cache );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$url = \Enviacom::ENVIA_QUERIES_HOSTNAME . '/generic-form?country_code=' . rawurlencode( $country ) . '&form=address_info';
		try {
			$schema = \Enviacom::requests_process( 'GET', $url, null, null );
		} catch ( \Exception $e ) {
			return new WP_Error( 'avs_schema_error', $e->getMessage(), array( 'code' => $e->getCode() ) );
		}
		if ( ! is_array( $schema ) ) {
			return new WP_Error( 'avs_schema_bad', __( 'Envia no devolvió el esquema de dirección del país.', 'amazonia-vendor-shipping' ) );
		}
		set_transient( $cache, $schema, 12 * HOUR_IN_SECONDS );
		return $schema;
	}

	/**
	 * Registra el origen si hace falta (dirección completa y firma cambiada o sin id).
	 * No lanza errores: pensado para ejecutarse en segundo plano al guardar la tienda.
	 *
	 * @param int $vendor_id
	 * @return void
	 */
	public static function maybe_register( $vendor_id ) {
		$vendor_id = absint( $vendor_id );
		if ( ! $vendor_id || ! self::is_complete( $vendor_id ) ) {
			return;
		}
		$res = self::register_with_envia( $vendor_id );
		if ( is_wp_error( $res ) ) {
			// Silencioso: el botón de respaldo permite reintentar y ver el error.
			self::log( 'maybe_register vendor ' . $vendor_id . ': ' . $res->get_error_message() );
		}
	}

	/* ---------------------------------------------------------------------
	 * Hooks
	 * ------------------------------------------------------------------- */

	/**
	 * Tras guardar los settings del vendedor, re-sincroniza su origen si cambió la dirección.
	 *
	 * @param int   $user_id
	 * @param array $form
	 */
	public static function on_vendor_settings_update( $user_id, $form ) {
		self::maybe_register( $user_id );
	}

	public static function register_rest() {
		register_rest_route(
			'avs/v1',
			'/sync-origin',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rest_sync_origin' ),
				'permission_callback' => function () {
					return is_user_logged_in() && function_exists( 'wcfm_is_vendor' ) && wcfm_is_vendor();
				},
			)
		);
	}

	/**
	 * Endpoint del botón de respaldo: registra el origen del vendedor actual.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public static function rest_sync_origin( $request ) {
		$vendor_id = absint( apply_filters( 'wcfm_current_vendor_id', get_current_user_id() ) );
		$res       = self::register_with_envia( $vendor_id, true );

		if ( is_wp_error( $res ) ) {
			return new WP_REST_Response(
				array(
					'ok'      => false,
					'message' => $res->get_error_message(),
				),
				200
			);
		}

		return new WP_REST_Response(
			array(
				'ok'         => true,
				'address_id' => $res,
				'message'    => __( 'Origen sincronizado con Envia correctamente.', 'amazonia-vendor-shipping' ),
			),
			200
		);
	}

	/**
	 * Botón "Sincronizar mi origen con Envia" para el vendedor, con estado actual.
	 * Se inyecta en el footer solo en el dashboard del vendedor (evita depender del slug).
	 */
	public static function render_sync_button() {
		if ( ! function_exists( 'wcfm_is_vendor' ) || ! wcfm_is_vendor() || ! is_user_logged_in() ) {
			return;
		}
		// Solo en páginas del dashboard de WCFM.
		if ( ! function_exists( 'wcfmmp_is_store_page' ) && ! ( function_exists( 'is_wcfm_page' ) && is_wcfm_page() ) ) {
			return;
		}
		if ( function_exists( 'is_wcfm_page' ) && ! is_wcfm_page() ) {
			return;
		}

		$vendor_id  = absint( apply_filters( 'wcfm_current_vendor_id', get_current_user_id() ) );
		$origin_id  = self::get_origin_id( $vendor_id );
		$complete   = self::is_complete( $vendor_id );
		$nonce      = wp_create_nonce( 'wp_rest' );
		$rest_url   = esc_url_raw( rest_url( 'avs/v1/sync-origin' ) );

		$status = $origin_id
			? sprintf( /* translators: %s: envia address id */ __( 'Origen sincronizado (ID Envia: %s).', 'amazonia-vendor-shipping' ), esc_html( $origin_id ) )
			: __( 'Origen aún no sincronizado con Envia.', 'amazonia-vendor-shipping' );
		if ( ! $complete ) {
			$status = __( 'Completa la dirección de tu tienda para poder cobrar envíos con Envia.', 'amazonia-vendor-shipping' );
		}
		?>
		<div id="avs-origin-box" style="position:fixed;left:16px;bottom:16px;z-index:99998;max-width:320px;background:#fff;border:1px solid #dcdcde;border-radius:8px;box-shadow:0 6px 24px rgba(0,0,0,.12);padding:12px 14px;font-size:13px;line-height:1.4">
			<strong><?php esc_html_e( 'Origen de envío (Envia)', 'amazonia-vendor-shipping' ); ?></strong>
			<p id="avs-origin-status" style="margin:6px 0 8px"><?php echo esc_html( $status ); ?></p>
			<button type="button" id="avs-origin-sync" class="button" <?php disabled( ! $complete ); ?>><?php esc_html_e( 'Sincronizar con Envia', 'amazonia-vendor-shipping' ); ?></button>
		</div>
		<script>
		( function () {
			var btn = document.getElementById( 'avs-origin-sync' );
			var out = document.getElementById( 'avs-origin-status' );
			if ( ! btn ) { return; }
			btn.addEventListener( 'click', function () {
				btn.disabled = true;
				out.textContent = <?php echo wp_json_encode( __( 'Sincronizando…', 'amazonia-vendor-shipping' ) ); ?>;
				fetch( <?php echo wp_json_encode( $rest_url ); ?>, {
					method: 'POST',
					headers: { 'X-WP-Nonce': <?php echo wp_json_encode( $nonce ); ?> }
				} )
				.then( function ( r ) { return r.json(); } )
				.then( function ( d ) { out.textContent = d.message || 'OK'; btn.disabled = false; } )
				.catch( function () { out.textContent = <?php echo wp_json_encode( __( 'Error de red al sincronizar.', 'amazonia-vendor-shipping' ) ); ?>; btn.disabled = false; } );
			} );
		} )();
		</script>
		<?php
	}

	private static function log( $msg ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[AVS_Origin] ' . $msg );
		}
	}
}
