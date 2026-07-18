<?php
/**
 * Test Fase 10 — Aislamiento de la guía en pedidos multivendedor.
 *
 *   C:\xampp\php\php.exe tests\test10-multivendor-label.php
 *
 * Regresión del segundo fallo de la auditoría: la guía se guardaba a nivel de PEDIDO y
 * `envia_shipping_item()` devolvía "el primer" ítem de Envia, sin mirar el vendedor. En un
 * pedido con dos vendedores (existe: el #144) eso hacía que:
 *   - un vendedor generara la guía usando el paquete/origen del OTRO,
 *   - el segundo vendedor viera y descargara el PDF del primero (con la dirección del cliente),
 *   - el segundo vendedor no pudiera generar nunca la suya (el pedido "ya tenía guía").
 *
 * Ahora la guía vive en el ÍTEM DE ENVÍO, uno por vendedor. Este test construye un pedido
 * con dos paquetes de Envia y comprueba el aislamiento. No llama a la API de Envia.
 *
 * @package Amazonia_Vendor_Shipping
 */

require __DIR__ . '/../../../../wp-load.php';

$fails = array();

if ( ! class_exists( 'AVS_Label' ) || ! function_exists( 'wc_create_order' ) ) {
	echo "FASE 10: FAIL\n  - AVS_Label o WooCommerce no están disponibles.\n";
	exit( 1 );
}

$VENDOR_A = 2;
$VENDOR_B = 5;

/* --- Pedido de prueba con DOS paquetes de Envia, uno por vendedor --- */
$order = wc_create_order();

$add_pkg = function ( $order, $vendor_id, $label ) {
	$item = new WC_Order_Item_Shipping();
	$item->set_method_title( $label );
	$item->set_method_id( 'envia_shipping' );
	$item->set_total( 0 );
	$item->add_meta_data( 'vendor_id', $vendor_id, true );
	$order->add_item( $item );
	return $item;
};

$item_a = $add_pkg( $order, $VENDOR_A, 'Envia - Vendedor A' );
$item_b = $add_pkg( $order, $VENDOR_B, 'Envia - Vendedor B' );
$order->save();

$order_id = $order->get_id();

/* --- 1. Cada vendedor "ve" su propio paquete --- */
if ( true !== AVS_Label::has_envia_package( $order_id, $VENDOR_A ) ) {
	$fails[] = "El vendedor {$VENDOR_A} debería tener un paquete de Envia en el pedido.";
}
if ( true !== AVS_Label::has_envia_package( $order_id, $VENDOR_B ) ) {
	$fails[] = "El vendedor {$VENDOR_B} debería tener un paquete de Envia en el pedido.";
}
// Un vendedor ajeno no tiene paquete aquí.
if ( false !== AVS_Label::has_envia_package( $order_id, 999999 ) ) {
	$fails[] = 'Un vendedor sin paquete en el pedido no debería tener uno.';
}

/* --- 2. La guía del vendedor A NO se filtra al vendedor B (el corazón del fallo) --- */
$item_a->update_meta_data( AVS_Label::META_URL, 'https://example.test/guia-vendedor-a.pdf' );
$item_a->update_meta_data( AVS_Label::META_TRACKING, 'TRACK-A-001' );
$item_a->save();

if ( 'https://example.test/guia-vendedor-a.pdf' !== AVS_Label::get_label_url( $order_id, $VENDOR_A ) ) {
	$fails[] = 'El vendedor A debería ver su propia guía.';
}
if ( 'TRACK-A-001' !== AVS_Label::get_tracking( $order_id, $VENDOR_A ) ) {
	$fails[] = 'El vendedor A debería ver su propio tracking.';
}
if ( '' !== AVS_Label::get_label_url( $order_id, $VENDOR_B ) ) {
	$fails[] = 'FUGA: el vendedor B está viendo la guía del vendedor A.';
}
if ( '' !== AVS_Label::get_tracking( $order_id, $VENDOR_B ) ) {
	$fails[] = 'FUGA: el vendedor B está viendo el tracking del vendedor A.';
}
if ( true !== AVS_Label::has_label( $order_id, $VENDOR_A ) ) {
	$fails[] = 'has_label debería ser true para el vendedor A.';
}
// B sigue SIN guía → puede generar la suya (antes quedaba bloqueado).
if ( false !== AVS_Label::has_label( $order_id, $VENDOR_B ) ) {
	$fails[] = 'El vendedor B no tiene guía todavía: has_label debe ser false para poder generarla.';
}

/* --- 3. Un admin sin indicar vendedor no puede adivinar: debe rechazar, no elegir "el primero" --- */
$res = AVS_Label::generate( $order_id, 0 );
if ( ! is_wp_error( $res ) || 'avs_ambiguous' !== $res->get_error_code() ) {
	$got = is_wp_error( $res ) ? $res->get_error_code() : 'sin error';
	$fails[] = "Con varios vendedores y sin indicar cuál, generate() debe devolver 'avs_ambiguous' (devolvió: {$got}).";
}

/* --- Limpieza: borrar el pedido de prueba --- */
$order->delete( true );

if ( empty( $fails ) ) {
	echo "FASE 10: PASS\n";
	exit( 0 );
}
echo "FASE 10: FAIL\n";
foreach ( $fails as $f ) {
	echo "  - {$f}\n";
}
exit( 1 );
