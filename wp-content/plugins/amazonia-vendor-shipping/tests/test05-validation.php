<?php
/**
 * Test Fase 5 — Regla de código postal obligatorio para Envia.
 *
 *   C:\xampp\php\php.exe tests\test05-validation.php
 *
 * @package Amazonia_Vendor_Shipping
 */

require __DIR__ . '/../../../../wp-load.php';

$fails = array();

if ( ! function_exists( 'avs_postcode_required' ) ) {
	echo "FASE 5: FAIL\n  - avs_postcode_required no existe.\n";
	exit( 1 );
}
if ( ! class_exists( 'AVS_Validation' ) || ! method_exists( 'AVS_Validation', 'validate_postcode' ) ) {
	echo "FASE 5: FAIL\n  - AVS_Validation::validate_postcode no existe.\n";
	exit( 1 );
}

// Regla combinada país + CP (lo que evalúa el handler).
$needs_error = function ( $country, $postcode ) {
	return avs_postcode_required( $country ) && '' === trim( (string) $postcode );
};

// MX exige CP: vacío → error.
if ( true !== $needs_error( 'MX', '' ) ) {
	$fails[] = 'MX con CP vacío debería marcar error.';
}
// MX con CP → sin error.
if ( false !== $needs_error( 'MX', '12345' ) ) {
	$fails[] = 'MX con CP presente no debería marcar error.';
}
// CO está exento → sin error aunque falte CP.
if ( false !== $needs_error( 'CO', '' ) ) {
	$fails[] = 'CO (exento) no debería exigir CP.';
}
// Insensible a mayúsculas.
if ( false !== $needs_error( 'co', '' ) ) {
	$fails[] = 'La exención debería ser insensible a mayúsculas.';
}

// El handler está enganchado al checkout.
if ( ! has_action( 'woocommerce_after_checkout_validation' ) ) {
	$fails[] = 'El handler no está enganchado a woocommerce_after_checkout_validation.';
}

if ( empty( $fails ) ) {
	echo "FASE 5: PASS\n";
	exit( 0 );
}
echo "FASE 5: FAIL\n";
foreach ( $fails as $f ) {
	echo "  - {$f}\n";
}
exit( 1 );
