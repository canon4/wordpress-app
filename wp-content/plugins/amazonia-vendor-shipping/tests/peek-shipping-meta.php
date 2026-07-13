<?php
/**
 * Utilidad: inspecciona los metadatos de envío de un pedido.
 *
 *   C:\xampp\php\php.exe tests\peek-shipping-meta.php <ORDER_ID>
 *
 * @package Amazonia_Vendor_Shipping
 */

require __DIR__ . '/../../../../wp-load.php';

$order_id = isset( $argv[1] ) ? absint( $argv[1] ) : 0;
if ( ! $order_id ) {
	echo "Uso: php tests\\peek-shipping-meta.php <ORDER_ID>\n";
	exit( 1 );
}

$order = wc_get_order( $order_id );
if ( ! $order ) {
	echo "Pedido #{$order_id} no encontrado.\n";
	exit( 1 );
}

echo "Pedido #{$order_id} — ítems de envío:\n";
foreach ( $order->get_items( 'shipping' ) as $item_id => $item ) {
	printf(
		"  item %d | método=%s | vendor_id=%s | _avs_mode=%s | _avs_quoted=%s | _avs_absorbed=%s\n",
		$item_id,
		$item->get_method_id(),
		(string) $item->get_meta( 'vendor_id' ),
		(string) $item->get_meta( '_avs_mode' ),
		(string) $item->get_meta( '_avs_quoted' ),
		(string) $item->get_meta( '_avs_absorbed' )
	);
}
