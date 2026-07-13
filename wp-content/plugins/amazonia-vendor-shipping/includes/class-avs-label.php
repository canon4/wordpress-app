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

	/* --------------------------------------------------------------------- */

	public static function has_label( $order_id ) {
		return '' !== self::get_label_url( $order_id );
	}

	public static function get_label_url( $order_id ) {
		$order = wc_get_order( $order_id );
		return $order ? (string) $order->get_meta( self::META_URL ) : '';
	}

	public static function get_tracking( $order_id ) {
		$order = wc_get_order( $order_id );
		return $order ? (string) $order->get_meta( self::META_TRACKING ) : '';
	}

	/* --------------------------------------------------------------------- */

	/**
	 * Genera la guía del pedido por API y guarda PDF + tracking en el pedido.
	 *
	 * @param int $order_id
	 * @return array|WP_Error  ['label'=>url, 'tracking'=>string] en éxito.
	 */
	public static function generate( $order_id ) {
		$order_id = absint( $order_id );
		$order    = wc_get_order( $order_id );
		if ( ! $order ) {
			return new WP_Error( 'avs_no_order', __( 'Pedido no encontrado.', 'amazonia-vendor-shipping' ) );
		}
		if ( self::has_label( $order_id ) ) {
			return array(
				'label'    => self::get_label_url( $order_id ),
				'tracking' => self::get_tracking( $order_id ),
				'already'  => true,
			);
		}

		$api = AVS_Config::get_api_key();
		if ( ! $api ) {
			return new WP_Error( 'avs_no_api', __( 'Falta configurar la API key de Envia en "Envíos Amazonia".', 'amazonia-vendor-shipping' ) );
		}

		$ship = self::envia_shipping_item( $order );
		if ( ! $ship ) {
			return new WP_Error( 'avs_no_envia', __( 'El pedido no usó un envío de Envia.', 'amazonia-vendor-shipping' ) );
		}

		$vendor_id = absint( $ship->get_meta( 'vendor_id' ) );
		$carrier   = (string) $ship->get_meta( 'carrier' );
		$service   = (string) $ship->get_meta( 'service' );
		if ( '' === $carrier || '' === $service ) {
			return new WP_Error( 'avs_no_service', __( 'El pedido no tiene transportadora/servicio de Envia para generar la guía.', 'amazonia-vendor-shipping' ) );
		}
		if ( ! $vendor_id || ! AVS_Origin::get_origin_id( $vendor_id ) ) {
			return new WP_Error( 'avs_no_origin', __( 'El vendedor de este pedido no tiene un origen de envío configurado en Envia.', 'amazonia-vendor-shipping' ) );
		}

		$origin      = AVS_Origin::get_vendor_address( $vendor_id );
		$destination = self::order_destination( $order );
		$packages    = array( self::order_package( $order ) );
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

		$order->update_meta_data( self::META_URL, esc_url_raw( $label ) );
		$order->update_meta_data( self::META_TRACKING, $tracking );
		$order->update_meta_data( self::META_TRACK_URL, isset( $data['trackingUrl'] ) ? esc_url_raw( $data['trackingUrl'] ) : '' );
		$order->update_meta_data( self::META_CARRIER, isset( $data['carrier'] ) ? (string) $data['carrier'] : $carrier );
		$order->add_order_note( sprintf( /* translators: %s tracking */ __( 'Guía de Envia generada. Tracking: %s', 'amazonia-vendor-shipping' ), $tracking ?: 'N/D' ) );
		$order->save();

		return array(
			'label'    => $label,
			'tracking' => $tracking,
		);
	}

	/* --------------------------------------------------------------------- */

	private static function envia_shipping_item( $order ) {
		foreach ( $order->get_items( 'shipping' ) as $it ) {
			if ( 'envia_shipping' === $it->get_method_id() ) {
				return $it;
			}
		}
		return null;
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

	private static function order_package( $order ) {
		$weight = 0.0;
		$l = 0.0; $w = 0.0; $h = 0.0;
		foreach ( $order->get_items() as $it ) {
			$p = $it->get_product();
			if ( ! $p ) {
				continue;
			}
			$weight += ( (float) $p->get_weight() ) * $it->get_quantity();
			$l = max( $l, (float) $p->get_length() );
			$w = max( $w, (float) $p->get_width() );
			$h = max( $h, (float) $p->get_height() );
		}
		return avs_build_label_package( $weight, $l, $w, $h, $order->get_total(), 'Pedido #' . $order->get_id() );
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
					'order_id' => array( 'required' => true, 'sanitize_callback' => 'absint' ),
				),
			)
		);
	}

	/**
	 * Permiso: vendedor dueño del pedido (o admin de la tienda).
	 */
	public static function rest_can( $request ) {
		if ( ! is_user_logged_in() ) {
			return false;
		}
		if ( current_user_can( 'manage_woocommerce' ) ) {
			return true;
		}
		$order_id = absint( $request->get_param( 'order_id' ) );
		if ( function_exists( 'wcfm_is_vendor' ) && wcfm_is_vendor() ) {
			return ! function_exists( 'wcfm_is_order_for_vendor' ) || wcfm_is_order_for_vendor( $order_id );
		}
		return false;
	}

	public static function rest_generate( $request ) {
		$order_id = absint( $request->get_param( 'order_id' ) );
		$res      = self::generate( $order_id );
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
