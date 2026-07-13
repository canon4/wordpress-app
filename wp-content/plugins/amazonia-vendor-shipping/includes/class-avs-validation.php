<?php
/**
 * Endurece la validación de datos de envío para reducir guías con dirección inválida.
 *
 * @package Amazonia_Vendor_Shipping
 */

defined( 'ABSPATH' ) || exit;

class AVS_Validation {

	public static function init() {
		add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'validate_postcode' ), 20, 2 );
		add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'validate_vendor_origin' ), 25, 2 );
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
