<?php
/**
 * Descuenta al vendedor el costo de envío que absorbe, registrándolo en el ledger de WCFM.
 *
 * @package Amazonia_Vendor_Shipping
 */

defined( 'ABSPATH' ) || exit;

class AVS_Ledger {

	const ORDER_FLAG = '_avs_ledger_done';
	const REFERENCE  = 'shipping-cost';

	public static function init() {
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'process_order' ), 20, 1 );
		add_filter( 'wcfmmp_ledger_references', array( __CLASS__, 'register_reference' ) );
	}

	/**
	 * Registra el tipo de asiento propio para que se muestre legible en el Ledger Book.
	 *
	 * @param array $refs
	 * @return array
	 */
	public static function register_reference( $refs ) {
		$refs[ self::REFERENCE ] = __( 'Costo de envío', 'amazonia-vendor-shipping' );
		return $refs;
	}

	/**
	 * Por cada ítem de envío del pedido, descuenta al vendedor lo que absorbió.
	 * Idempotente: no reprocesa un pedido ya marcado.
	 *
	 * @param int $order_id
	 */
	public static function process_order( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		if ( 'yes' === $order->get_meta( self::ORDER_FLAG ) ) {
			return; // Ya procesado.
		}

		global $WCFMmp;
		if ( ! isset( $WCFMmp ) || ! isset( $WCFMmp->wcfmmp_ledger ) ) {
			return; // WCFM no disponible: no marcar como hecho, se reintenta luego.
		}

		foreach ( $order->get_items( 'shipping' ) as $item_id => $item ) {
			$vendor_id = absint( $item->get_meta( 'vendor_id' ) );
			$absorbed  = round( (float) $item->get_meta( '_avs_absorbed' ), 2 );

			if ( ! $vendor_id || $absorbed <= 0 ) {
				continue;
			}

			$details = sprintf(
				/* translators: %s: número de pedido */
				__( 'Costo de envío absorbido — pedido #%s', 'amazonia-vendor-shipping' ),
				$order->get_order_number()
			);

			// Débito en el balance del vendedor. reference_id = item_id (único por vendor/pedido).
			$WCFMmp->wcfmmp_ledger->wcfmmp_ledger_update(
				$vendor_id,
				$item_id,
				0,
				$absorbed,
				self::REFERENCE,
				$details,
				'completed'
			);
		}

		$order->update_meta_data( self::ORDER_FLAG, 'yes' );
		$order->save();
	}
}
