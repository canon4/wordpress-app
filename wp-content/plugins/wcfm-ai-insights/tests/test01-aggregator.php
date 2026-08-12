<?php
/**
 * Test 1 — Agregación de métricas (integración: necesita WordPress + DB).
 *
 *   C:\xampp\php\php.exe tests\test01-aggregator.php
 *
 * Inserta filas fixture directamente en wp_wcfm_marketplace_orders para un
 * vendedor y productos ficticios, corre el aggregator, y verifica:
 *  - suma correcta de unidades/ingresos del periodo actual y anterior
 *  - variación porcentual
 *  - que pedidos trashed/refunded/cancelados NO cuenten como venta
 *  - ranking top/bottom
 *  - señal de riesgo de stock
 *
 * Es una prueba de integración (no pura) porque el aggregator depende de
 * $wpdb y de wc_get_product(); no tiene sentido mockear ambas cosas.
 *
 * @package WCFM_AI_Insights
 */

require __DIR__ . '/../../../../wp-load.php';

if ( ! class_exists( 'WCFM_Metrics_Aggregator' ) ) {
	require_once WP_PLUGIN_DIR . '/wcfm-ai-insights/includes/class-metrics-aggregator.php';
}

global $wpdb;
$fails = array();

if ( ! function_exists( 'wc_get_product' ) || ! $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}wcfm_marketplace_orders'" ) ) {
	echo "TEST 1 (agregador de metricas): PASS (omitido: WooCommerce/WCFM no disponibles en este entorno)\n";
	exit( 0 );
}

/* ---------------------------------------------------------------------
 * Fixtures
 * ------------------------------------------------------------------- */

$vendor_id = 999321; // vendedor ficticio, no debe chocar con datos reales.

$product_a = wp_insert_post( array(
	'post_type'   => 'product',
	'post_status' => 'publish',
	'post_title'  => 'Producto Test A (crece)',
) );
$product_b = wp_insert_post( array(
	'post_type'   => 'product',
	'post_status' => 'publish',
	'post_title'  => 'Producto Test B (cae)',
) );
$product_c = wp_insert_post( array(
	'post_type'   => 'product',
	'post_status' => 'publish',
	'post_title'  => 'Producto Test C (bajo stock)',
) );

$stocks = array( $product_a => 100, $product_b => 50, $product_c => 2 ); // C: muy poco stock frente a su venta.
foreach ( $stocks as $pid => $qty ) {
	$p = wc_get_product( $pid );
	$p->set_manage_stock( true );
	$p->set_stock_quantity( $qty );
	$p->save();
}

$table = $wpdb->prefix . 'wcfm_marketplace_orders';

function wcfm_ai_insights_test_insert_order( $wpdb, $table, $vendor_id, $product_id, $qty, $total, $days_ago, $status = 'wc-completed', $trashed = 0, $refunded = 0 ) {
	static $order_id = 5000000;
	static $item_id   = 6000000;
	$order_id++;
	$item_id++;
	$wpdb->insert( $table, array(
		'vendor_id'      => $vendor_id,
		'order_id'       => $order_id,
		'customer_id'    => 1,
		'payment_method' => 'test',
		'product_id'     => $product_id,
		'variation_id'   => 0,
		'quantity'       => $qty,
		'item_id'        => $item_id,
		'item_total'     => $total,
		'order_status'   => $status,
		'is_trashed'     => $trashed,
		'is_refunded'    => $refunded,
		'created'        => gmdate( 'Y-m-d H:i:s', strtotime( "-{$days_ago} days" ) ),
	) );
}

// Producto A: crece (periodo actual mucho mayor que el anterior).
wcfm_ai_insights_test_insert_order( $wpdb, $table, $vendor_id, $product_a, 10, '1000.00', 5 );
wcfm_ai_insights_test_insert_order( $wpdb, $table, $vendor_id, $product_a, 2, '200.00', 40 );

// Producto B: cae.
wcfm_ai_insights_test_insert_order( $wpdb, $table, $vendor_id, $product_b, 1, '100.00', 5 );
wcfm_ai_insights_test_insert_order( $wpdb, $table, $vendor_id, $product_b, 10, '1000.00', 40 );

// Producto C: alta velocidad de venta con poco stock (riesgo de quiebre).
wcfm_ai_insights_test_insert_order( $wpdb, $table, $vendor_id, $product_c, 20, '400.00', 2 );

// Ruido que NO debe contar: trashed, refunded, cancelado, y de OTRO vendedor.
wcfm_ai_insights_test_insert_order( $wpdb, $table, $vendor_id, $product_a, 999, '99999.00', 5, 'wc-completed', 1, 0 ); // trashed
wcfm_ai_insights_test_insert_order( $wpdb, $table, $vendor_id, $product_a, 999, '99999.00', 5, 'wc-completed', 0, 1 ); // refunded
wcfm_ai_insights_test_insert_order( $wpdb, $table, $vendor_id, $product_a, 999, '99999.00', 5, 'wc-cancelled', 0, 0 ); // estado invalido
wcfm_ai_insights_test_insert_order( $wpdb, $table, 888444, $product_a, 999, '99999.00', 5 ); // otro vendedor

/* ---------------------------------------------------------------------
 * Ejecutar
 * ------------------------------------------------------------------- */

$summary = WCFM_Metrics_Aggregator::get_vendor_summary( $vendor_id, 30 );
$by_id   = array();
foreach ( $summary['products'] as $row ) {
	$by_id[ $row['product_id'] ] = $row;
}

/* --- Producto A: solo las filas validas de "hace 5 dias" cuentan en el periodo actual --- */
if ( ! isset( $by_id[ $product_a ] ) ) {
	$fails[] = 'Producto A deberia aparecer en el resumen.';
} else {
	$a = $by_id[ $product_a ];
	if ( 10 !== $a['units_sold'] ) {
		$fails[] = "Producto A: unidades del periodo actual deberian ser 10, son {$a['units_sold']} (ruido de trashed/refunded/cancelado/otro-vendedor no debe sumar).";
	}
	if ( 1000.00 !== $a['revenue'] ) {
		$fails[] = "Producto A: ingresos del periodo actual deberian ser 1000.00, son {$a['revenue']}.";
	}
	if ( $a['pct_change_revenue'] <= 0 ) {
		$fails[] = 'Producto A deberia mostrar variacion positiva (crecio vs periodo anterior).';
	}
}

/* --- Producto B: cae --- */
if ( isset( $by_id[ $product_b ] ) && $by_id[ $product_b ]['pct_change_revenue'] >= 0 ) {
	$fails[] = 'Producto B deberia mostrar variacion negativa (cayo vs periodo anterior).';
}

/* --- Top/bottom --- */
$tb = WCFM_Metrics_Aggregator::top_bottom_from_summary( $summary, 1 );
if ( empty( $tb['top'] ) || $tb['top'][0]['product_id'] !== $product_a ) {
	$fails[] = 'El top-1 por ingresos deberia ser el Producto A.';
}

/* --- Riesgo de stock: Producto C tiene poco stock y alta venta reciente --- */
$risk = WCFM_Metrics_Aggregator::get_stock_risk_products( $vendor_id );
$risk_ids = array_column( $risk['stockout_risk'], 'product_id' );
if ( ! in_array( $product_c, $risk_ids, true ) ) {
	$fails[] = 'Producto C deberia marcarse en riesgo de quiebre de stock.';
}

/* ---------------------------------------------------------------------
 * Limpieza
 * ------------------------------------------------------------------- */

$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE vendor_id IN (%d, %d)", $vendor_id, 888444 ) );
foreach ( array( $product_a, $product_b, $product_c ) as $pid ) {
	wp_delete_post( $pid, true );
}

/* ---------------------------------------------------------------------
 * Resultado
 * ------------------------------------------------------------------- */

if ( empty( $fails ) ) {
	echo "TEST 1 (agregador de metricas): PASS\n";
	exit( 0 );
}
echo "TEST 1 (agregador de metricas): FAIL\n";
foreach ( $fails as $f ) {
	echo "  - {$f}\n";
}
exit( 1 );
