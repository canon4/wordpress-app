<?php
/**
 * Test Fase 3 — Deducción del costo de envío en el ledger del vendedor.
 *
 * Crea un pedido de prueba con un ítem de envío que el vendedor absorbe, dispara el
 * procesamiento, y verifica el asiento de débito y la idempotencia.
 *
 *   C:\xampp\php\php.exe tests\test03-ledger.php
 *
 * @package Amazonia_Vendor_Shipping
 */

require __DIR__ . '/../../../../wp-load.php';

$fails = array();

if ( ! class_exists( 'AVS_Ledger' ) ) {
	echo "FASE 3: FAIL\n  - AVS_Ledger no está cargada.\n";
	exit( 1 );
}

global $wpdb, $WCFMmp;
$table = $wpdb->prefix . 'wcfm_marketplace_vendor_ledger';

if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) {
	echo "FASE 3: FAIL\n  - No existe la tabla {$table} (¿WCFM activo?).\n";
	exit( 1 );
}

$vendor = 999999; // vendedor ficticio

// --- Crear pedido de prueba con ítem de envío absorbido = 55 ---
$order = wc_create_order();
$item  = new WC_Order_Item_Shipping();
$item->set_method_title( 'Envia rates and shipping' );
$item->set_method_id( 'envia_shipping' );
$item->set_total( 0 );
$item->add_meta_data( 'vendor_id', $vendor, true );
$item->add_meta_data( '_avs_absorbed', 55, true );
$item->add_meta_data( '_avs_mode', 'vendor_absorbs', true );
$order->add_item( $item );
$order->save();
$order_id = $order->get_id();
$item_id  = $item->get_id();

// --- Procesar (primera vez) ---
AVS_Ledger::process_order( $order_id );

$rows = $wpdb->get_results(
	$wpdb->prepare( "SELECT debit FROM {$table} WHERE reference = %s AND reference_id = %d", AVS_Ledger::REFERENCE, $item_id )
);
if ( count( $rows ) !== 1 ) {
	$fails[] = 'Debería existir exactamente 1 asiento tras procesar (hay ' . count( $rows ) . ').';
} elseif ( abs( (float) $rows[0]->debit - 55.0 ) > 0.001 ) {
	$fails[] = "El débito debería ser 55 (es {$rows[0]->debit}).";
}

// --- Procesar (segunda vez): idempotencia ---
AVS_Ledger::process_order( $order_id );
$count2 = (int) $wpdb->get_var(
	$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE reference = %s AND reference_id = %d", AVS_Ledger::REFERENCE, $item_id )
);
if ( 1 !== $count2 ) {
	$fails[] = "La segunda pasada no debe duplicar el asiento (hay {$count2}).";
}

// --- Limpieza ---
$wpdb->delete( $table, array( 'reference' => AVS_Ledger::REFERENCE, 'reference_id' => $item_id ), array( '%s', '%d' ) );
$order->delete( true );

if ( empty( $fails ) ) {
	echo "FASE 3: PASS\n";
	exit( 0 );
}
echo "FASE 3: FAIL\n";
foreach ( $fails as $f ) {
	echo "  - {$f}\n";
}
exit( 1 );
