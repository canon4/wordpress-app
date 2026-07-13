<?php
/**
 * Test Fase 1 — Resolución del modo de cobro (vendor → global).
 *
 * Correr:  C:\xampp\php\php.exe tests\test01-config.php
 *
 * @package Amazonia_Vendor_Shipping
 */

$wp_load = __DIR__ . '/../../../../wp-load.php';
require_once $wp_load;

if ( ! class_exists( 'AVS_Config' ) ) {
	echo "FASE 1: FAIL\n  - AVS_Config no está cargada.\n";
	exit( 1 );
}

$fails    = array();
$vendor   = 999999; // ID de vendedor ficticio para probar la resolución.

// Guardar estado previo para restaurar al final.
$prev_mode  = get_option( AVS_Config::OPT_MODE, null );
$prev_fixed = get_option( AVS_Config::OPT_FIXED, null );

/* --- Modo: global sin override --- */
update_option( AVS_Config::OPT_MODE, 'vendor_absorbs' );
delete_user_meta( $vendor, AVS_Config::META_MODE );
if ( 'vendor_absorbs' !== AVS_Config::get_mode( $vendor ) ) {
	$fails[] = 'Sin override, get_mode debería devolver el global (vendor_absorbs).';
}

/* --- Modo: override del vendedor --- */
update_user_meta( $vendor, AVS_Config::META_MODE, 'customer_pays' );
if ( 'customer_pays' !== AVS_Config::get_mode( $vendor ) ) {
	$fails[] = 'Con override, get_mode debería devolver customer_pays.';
}

/* --- Modo inválido en override → cae al global --- */
update_user_meta( $vendor, AVS_Config::META_MODE, 'basura' );
if ( 'vendor_absorbs' !== AVS_Config::get_mode( $vendor ) ) {
	$fails[] = 'Override inválido debería caer al global.';
}

/* --- Tarifa fija: global vs override --- */
update_option( AVS_Config::OPT_FIXED, 30 );
delete_user_meta( $vendor, AVS_Config::META_FIXED );
if ( 30.0 !== AVS_Config::get_fixed_rate( $vendor ) ) {
	$fails[] = 'Sin override, get_fixed_rate debería ser 30 (global).';
}
update_user_meta( $vendor, AVS_Config::META_FIXED, 50 );
if ( 50.0 !== AVS_Config::get_fixed_rate( $vendor ) ) {
	$fails[] = 'Con override, get_fixed_rate debería ser 50.';
}

/* --- Limpieza --- */
delete_user_meta( $vendor, AVS_Config::META_MODE );
delete_user_meta( $vendor, AVS_Config::META_FIXED );
if ( null === $prev_mode ) {
	delete_option( AVS_Config::OPT_MODE );
} else {
	update_option( AVS_Config::OPT_MODE, $prev_mode );
}
if ( null === $prev_fixed ) {
	delete_option( AVS_Config::OPT_FIXED );
} else {
	update_option( AVS_Config::OPT_FIXED, $prev_fixed );
}

if ( empty( $fails ) ) {
	echo "FASE 1: PASS\n";
	exit( 0 );
}
echo "FASE 1: FAIL\n";
foreach ( $fails as $f ) {
	echo "  - {$f}\n";
}
exit( 1 );
