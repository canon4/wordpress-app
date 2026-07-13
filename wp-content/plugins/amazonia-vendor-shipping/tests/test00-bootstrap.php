<?php
/**
 * Test Fase 0 — Andamiaje del plugin.
 *
 * Carga WordPress y verifica que el plugin esté activo y expuesto correctamente.
 * Correr:  C:\xampp\php\php.exe tests\test00-bootstrap.php
 *
 * @package Amazonia_Vendor_Shipping
 */

$wp_load = __DIR__ . '/../../../../wp-load.php';
if ( ! file_exists( $wp_load ) ) {
	fwrite( STDERR, "No se encontró wp-load.php en: {$wp_load}\n" );
	exit( 1 );
}
require_once $wp_load;

$fails = array();

// 1. La clase principal del plugin existe.
if ( ! class_exists( 'Amazonia_Vendor_Shipping' ) ) {
	$fails[] = 'La clase Amazonia_Vendor_Shipping no existe (¿plugin cargado?).';
}

// 2. La función pura avs_calc_split está disponible.
if ( ! function_exists( 'avs_calc_split' ) ) {
	$fails[] = 'La función avs_calc_split no existe.';
}

// 3. El plugin figura como activo.
if ( ! function_exists( 'is_plugin_active' ) ) {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
}
if ( ! is_plugin_active( 'amazonia-vendor-shipping/amazonia-vendor-shipping.php' ) ) {
	$fails[] = 'El plugin no está activo en WordPress.';
}

if ( empty( $fails ) ) {
	echo "FASE 0: PASS\n";
	exit( 0 );
}

echo "FASE 0: FAIL\n";
foreach ( $fails as $f ) {
	echo "  - {$f}\n";
}
exit( 1 );
