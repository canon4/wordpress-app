<?php
/**
 * Cotización de Envia con origen por vendedor.
 *
 * El método nativo de Envia usa un origen estático (una sola dirección por zona), así que
 * no puede cotizar con la dirección de cada vendedor. Aquí reutilizamos su motor real
 * (`Envia_Shipping::get_shipping_rates`) construyendo una instancia fresca con el origen
 * del vendedor forzado vía filtro de opción, y mapeamos las tarifas resultantes.
 *
 * @package Amazonia_Vendor_Shipping
 */

defined( 'ABSPATH' ) || exit;

class AVS_Quote {

	/** @var string|null address_id a forzar como origen durante una cotización. */
	private static $force_origin_id = null;

	public static function init() {
		// Filtro que inyecta el origen del vendedor en la opción global que lee Envia.
		add_filter( 'option_woocommerce_envia_shipping_settings', array( __CLASS__, 'filter_origin' ), 50 );
	}

	/**
	 * Fuerza el enviaOrigin de la opción cuando estamos cotizando para un vendedor.
	 *
	 * @param mixed $value Valor de la opción.
	 * @return mixed
	 */
	public static function filter_origin( $value ) {
		if ( null !== self::$force_origin_id && is_array( $value ) ) {
			$value['enviaOrigin'] = self::$force_origin_id;
		}
		return $value;
	}

	/**
	 * Tarifas de Envia para el paquete de un vendedor, cotizadas desde SU origen.
	 *
	 * @param array $package   Paquete de WooCommerce (destino + contenidos del vendedor).
	 * @param int   $vendor_id
	 * @return WC_Shipping_Rate[] Lista (posiblemente vacía) de rates de envia_shipping.
	 */
	public static function get_vendor_rates( $package, $vendor_id ) {
		$vendor_id = absint( $vendor_id );
		$origin_id = AVS_Origin::get_origin_id( $vendor_id );

		if ( ! $origin_id || ! class_exists( '\Envia\Classes\Module\Envia_Shipping' ) ) {
			return array();
		}

		$raw = self::request_raw_rates( $package, $origin_id );
		if ( empty( $raw ) ) {
			return array();
		}

		$rates = array();
		foreach ( $raw as $value ) {
			// Solo entregas a domicilio (dropOff 0 y 1). Pickup en sucursal se maneja aparte.
			$dropoff = isset( $value['dropOff'] ) ? (int) $value['dropOff'] : 0;
			if ( 2 === $dropoff || 3 === $dropoff ) {
				continue;
			}
			$rate = self::map_rate( $value, $vendor_id );
			if ( $rate ) {
				$rates[ $rate->get_id() ] = $rate;
			}
		}
		return $rates;
	}

	/**
	 * Llama al motor de Envia con el origen forzado. Devuelve el array crudo de tarifas.
	 *
	 * @param array  $package
	 * @param string $origin_id
	 * @return array
	 */
	private static function request_raw_rates( $package, $origin_id ) {
		self::$force_origin_id = (string) $origin_id;
		$raw                   = array();
		try {
			$method = new \Envia\Classes\Module\Envia_Shipping( 0 );
			$result = $method->get_shipping_rates( $package );
			if ( is_array( $result ) ) {
				$raw = $result;
			}
		} catch ( \Exception $e ) {
			$raw = array();
		} finally {
			self::$force_origin_id = null;
		}
		return $raw;
	}

	/**
	 * Convierte una tarifa cruda de Envia en un WC_Shipping_Rate (método envia_shipping),
	 * para que ledger y guía por vendedor sigan funcionando igual.
	 *
	 * @param array $value
	 * @param int   $vendor_id
	 * @return WC_Shipping_Rate|null
	 */
	private static function map_rate( $value, $vendor_id ) {
		if ( ! isset( $value['serviceId'], $value['totalPrice'] ) ) {
			return null;
		}

		$service_title = ! empty( $value['aliasServiceDescription'] )
			? $value['aliasServiceDescription']
			: ( isset( $value['serviceDescription'] ) ? $value['serviceDescription'] : 'Envia' );

		$label = $service_title;
		if ( ! empty( $value['deliveryEstimate'] ) ) {
			$label .= ' ( ' . $value['deliveryEstimate'] . ' ) ';
		}

		$rate = new WC_Shipping_Rate(
			'envia-' . $value['serviceId'] . '-0',
			$label,
			(float) $value['totalPrice'],
			array(),
			'envia_shipping'
		);
		$rate->add_meta_data( 'delivery', isset( $value['deliveryEstimate'] ) ? $value['deliveryEstimate'] : '' );
		$rate->add_meta_data( 'carrier', isset( $value['carrier'] ) ? $value['carrier'] : '' );
		$rate->add_meta_data( 'serviceId', $value['serviceId'] );
		$rate->add_meta_data( 'service', isset( $value['service'] ) ? $value['service'] : '' );
		$rate->add_meta_data( '_avs_vendor_origin', AVS_Origin::get_origin_id( $vendor_id ) );

		return $rate;
	}
}
