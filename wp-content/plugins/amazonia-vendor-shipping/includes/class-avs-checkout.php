<?php
/**
 * Intercepta las tarifas de Envia en el checkout y aplica el modo de cobro por vendedor.
 *
 * @package Amazonia_Vendor_Shipping
 */

defined( 'ABSPATH' ) || exit;

class AVS_Checkout {

	public static function init() {
		// Después de WCFM (que arma los paquetes por vendedor).
		add_filter( 'woocommerce_package_rates', array( __CLASS__, 'apply_mode_to_rates' ), 200, 2 );
	}

	/**
	 * Ajusta el costo que ve/paga el cliente según el modo del vendedor del paquete y
	 * guarda en el rate cuánto absorbe el vendedor (se propaga al shipping item del pedido).
	 *
	 * @param WC_Shipping_Rate[] $rates   Rates del paquete, por id.
	 * @param array              $package Paquete de WooCommerce (WCFM añade 'vendor_id').
	 * @return WC_Shipping_Rate[]
	 */
	public static function apply_mode_to_rates( $rates, $package ) {
		$vendor_id = isset( $package['vendor_id'] ) ? absint( $package['vendor_id'] ) : 0;
		$mode      = AVS_Config::get_mode( $vendor_id );
		$fixed     = AVS_Config::get_fixed_rate( $vendor_id );
		$allowed   = AVS_Config::allowed_carriers( $vendor_id );

		// Si Envia nativo no cotizó (usa un origen estático inválido para multivendedor),
		// inyectamos las tarifas cotizadas desde el origen del propio vendedor.
		if ( $vendor_id && class_exists( 'AVS_Quote' ) && ! self::has_envia_rate( $rates ) ) {
			$injected = AVS_Quote::get_vendor_rates( $package, $vendor_id );
			if ( ! empty( $injected ) ) {
				$rates = $rates + $injected;
				self::suppress_envia_notice();
			}
		}

		foreach ( $rates as $key => $rate ) {
			if ( 'envia_shipping' !== $rate->get_method_id() ) {
				continue;
			}

			// Transportadora por vendedor: el cliente solo ve la(s) transportadora(s) permitida(s).
			$meta    = $rate->get_meta_data();
			$carrier = isset( $meta['carrier'] ) ? $meta['carrier'] : '';
			if ( ! avs_carrier_allowed( $carrier, $allowed ) ) {
				unset( $rates[ $key ] );
				continue;
			}

			$quoted = (float) $rate->get_cost();
			$split  = avs_calc_split( $mode, $quoted, $fixed );

			// Escalar los impuestos de envío en proporción a lo que efectivamente paga el cliente.
			$taxes = $rate->get_taxes();
			if ( $quoted > 0 && ! empty( $taxes ) ) {
				$factor = $split['paid'] / $quoted;
				foreach ( $taxes as $k => $t ) {
					$taxes[ $k ] = (float) $t * $factor;
				}
			} else {
				$taxes = array();
			}

			$rate->set_cost( $split['paid'] );
			$rate->set_taxes( $taxes );

			// Metas internas (prefijo "_" → WooCommerce no las muestra al cliente).
			// WC_Order_Item_Shipping::set_rate() las copia al shipping item del pedido.
			$rate->add_meta_data( '_avs_quoted', $quoted );
			$rate->add_meta_data( '_avs_absorbed', $split['absorbed'] );
			$rate->add_meta_data( '_avs_mode', $mode );
		}

		return $rates;
	}

	/**
	 * ¿El paquete ya tiene alguna tarifa de Envia?
	 *
	 * @param WC_Shipping_Rate[] $rates
	 * @return bool
	 */
	private static function has_envia_rate( $rates ) {
		foreach ( $rates as $rate ) {
			if ( 'envia_shipping' === $rate->get_method_id() ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Quita el aviso "Enter a valid address to view shipping options" que el método nativo
	 * de Envia agrega al fallar con su origen estático, cuando nosotros sí cotizamos.
	 */
	private static function suppress_envia_notice() {
		if ( ! function_exists( 'wc_get_notices' ) || ! WC()->session ) {
			return;
		}
		$notice_type = wc_get_notices( 'notice' );
		if ( empty( $notice_type ) ) {
			return;
		}
		$target = __( 'Enter a valid address to view shipping options', 'envia-shipping' );

		// Capturar TODO antes de limpiar (wc_clear_notices sin argumento borra todos los tipos).
		$errors    = wc_get_notices( 'error' );
		$successes = wc_get_notices( 'success' );
		$kept      = array();
		foreach ( $notice_type as $n ) {
			$text = isset( $n['notice'] ) ? $n['notice'] : '';
			if ( trim( wp_strip_all_tags( $text ) ) === $target ) {
				continue; // Descartar el aviso de Envia.
			}
			$kept[] = $n;
		}

		wc_clear_notices();
		foreach ( $errors as $n ) {
			wc_add_notice( $n['notice'], 'error', isset( $n['data'] ) ? $n['data'] : array() );
		}
		foreach ( $successes as $n ) {
			wc_add_notice( $n['notice'], 'success', isset( $n['data'] ) ? $n['data'] : array() );
		}
		foreach ( $kept as $n ) {
			wc_add_notice( $n['notice'], 'notice', isset( $n['data'] ) ? $n['data'] : array() );
		}
	}
}
