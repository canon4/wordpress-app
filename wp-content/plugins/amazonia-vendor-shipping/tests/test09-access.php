<?php
/**
 * Test Fase 9 — Control de acceso a la guía (regresión de vulnerabilidad).
 *
 *   C:\xampp\php\php.exe tests\test09-access.php
 *
 * Cubre el fallo de control de acceso encontrado en la auditoría: `rest_can()` y `can_view()`
 * consultaban `wcfm_is_order_for_vendor()`, una función que NO existe en WCFM. La expresión
 * `! function_exists(...) || ...` evaluaba a true, de modo que CUALQUIER vendedor autenticado
 * podía generar la guía de CUALQUIER pedido (gasta saldo real de Envia y expone la dirección
 * del cliente en el PDF).
 *
 * Aquí se verifica que la propiedad del pedido se resuelve contra la tabla real de WCFM
 * ({prefix}wcfm_marketplace_orders), que es la que asocia pedido ↔ vendedor.
 *
 * @package Amazonia_Vendor_Shipping
 */

require __DIR__ . '/../../../../wp-load.php';

global $wpdb;

$fails = array();

if ( ! class_exists( 'AVS_Label' ) ) {
	echo "FASE 9: FAIL\n  - AVS_Label no está cargada.\n";
	exit( 1 );
}

/* --- La función ausente que causó el fallo no debe volver a usarse como guardia --- */
if ( function_exists( 'wcfm_is_order_for_vendor' ) ) {
	// Si algún día WCFM la define, este test deja de ser representativo: avisar.
	$fails[] = 'wcfm_is_order_for_vendor() ahora existe: revisar si conviene delegar en ella.';
}

/* --- Busca un pedido real con su vendedor, y un vendedor que NO participe en él --- */
$row = $wpdb->get_row( "SELECT order_id, vendor_id FROM {$wpdb->prefix}wcfm_marketplace_orders ORDER BY order_id DESC LIMIT 1", ARRAY_A );

if ( ! $row ) {
	echo "FASE 9: SKIP\n  - No hay pedidos en wcfm_marketplace_orders para probar.\n";
	exit( 0 );
}

$order_id  = (int) $row['order_id'];
$owner_id  = (int) $row['vendor_id'];

// Un vendor_id que NO está en ese pedido.
$intruder_id = (int) $wpdb->get_var(
	$wpdb->prepare(
		"SELECT DISTINCT vendor_id FROM {$wpdb->prefix}wcfm_marketplace_orders
		 WHERE vendor_id NOT IN ( SELECT vendor_id FROM {$wpdb->prefix}wcfm_marketplace_orders WHERE order_id = %d )
		 LIMIT 1",
		$order_id
	)
);

/* --- 1. El vendedor participante SÍ es dueño --- */
if ( true !== AVS_Label::vendor_owns_order( $order_id, $owner_id ) ) {
	$fails[] = "El vendedor {$owner_id} participa en el pedido {$order_id}: vendor_owns_order debería ser true.";
}

/* --- 2. Un vendedor ajeno NO es dueño (el corazón de la vulnerabilidad) --- */
if ( $intruder_id ) {
	if ( false !== AVS_Label::vendor_owns_order( $order_id, $intruder_id ) ) {
		$fails[] = "El vendedor {$intruder_id} NO participa en el pedido {$order_id}: vendor_owns_order debe ser false.";
	}
} else {
	// Sin otro vendedor real, se prueba con un ID inexistente.
	if ( false !== AVS_Label::vendor_owns_order( $order_id, 999999 ) ) {
		$fails[] = 'Un vendedor inexistente nunca debe ser dueño de un pedido.';
	}
}

/* --- 3. Entradas degeneradas: nunca conceden acceso --- */
if ( false !== AVS_Label::vendor_owns_order( 0, $owner_id ) ) {
	$fails[] = 'order_id = 0 no debe conceder acceso.';
}
if ( false !== AVS_Label::vendor_owns_order( $order_id, 0 ) ) {
	$fails[] = 'vendor_id = 0 no debe conceder acceso.';
}

/* --- 4. Sin sesión iniciada, can_manage_order() siempre deniega --- */
wp_set_current_user( 0 );
if ( false !== AVS_Label::can_manage_order( $order_id ) ) {
	$fails[] = 'Un usuario anónimo no debe poder gestionar la guía de un pedido.';
}

/* --- 5. El admin sí puede --- */
$admin = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
if ( ! empty( $admin ) ) {
	wp_set_current_user( (int) $admin[0] );
	if ( true !== AVS_Label::can_manage_order( $order_id ) ) {
		$fails[] = 'El administrador debería poder gestionar la guía de cualquier pedido.';
	}
}
wp_set_current_user( 0 );

if ( empty( $fails ) ) {
	echo "FASE 9: PASS\n";
	exit( 0 );
}
echo "FASE 9: FAIL\n";
foreach ( $fails as $f ) {
	echo "  - {$f}\n";
}
exit( 1 );
