<?php
/**
 * Test Fase 4A — Construcción del hash del panel de Envia (avs_build_envia_hash).
 *
 * No requiere WordPress. Correr:
 *   C:\xampp\php\php.exe tests\test04-hash.php
 *
 * @package Amazonia_Vendor_Shipping
 */

define( 'AVS_TEST', true );
require __DIR__ . '/../includes/avs-functions.php';

$fails = array();

$hash = avs_build_envia_hash( 'SITE', 'C1', 'S1' );

// 1. Coincide con base64( site:company:store ).
if ( $hash !== base64_encode( 'SITE:C1:S1' ) ) {
	$fails[] = 'El hash no coincide con base64("SITE:C1:S1").';
}

// 2. Al decodificar recupera los tres valores en orden.
$parts = explode( ':', base64_decode( $hash ) );
if ( count( $parts ) !== 3 || 'SITE' !== $parts[0] || 'C1' !== $parts[1] || 'S1' !== $parts[2] ) {
	$fails[] = 'El hash decodificado no recupera site:company:store en orden.';
}

if ( empty( $fails ) ) {
	echo "FASE 4A: PASS\n";
	exit( 0 );
}
echo "FASE 4A: FAIL\n";
foreach ( $fails as $f ) {
	echo "  - {$f}\n";
}
exit( 1 );
