<?php
/**
 * Test Fase 2A — Lógica pura del reparto de costo (avs_calc_split).
 *
 * No requiere WordPress. Correr:
 *   C:\xampp\php\php.exe tests\test02-split.php
 *
 * @package Amazonia_Vendor_Shipping
 */

define( 'AVS_TEST', true );
require __DIR__ . '/../includes/avs-functions.php';

$fails = array();

$approx = function ( $a, $b ) {
	return abs( (float) $a - (float) $b ) < 0.0001;
};

// customer_pays: el cliente paga todo, el vendor no absorbe nada.
$r = avs_calc_split( 'customer_pays', 100, 30 );
if ( ! $approx( $r['paid'], 100 ) || ! $approx( $r['absorbed'], 0 ) ) {
	$fails[] = "customer_pays → paid={$r['paid']} absorbed={$r['absorbed']} (esperado 100/0)";
}

// vendor_absorbs: envío gratis al cliente, el vendor absorbe todo.
$r = avs_calc_split( 'vendor_absorbs', 100, 30 );
if ( ! $approx( $r['paid'], 0 ) || ! $approx( $r['absorbed'], 100 ) ) {
	$fails[] = "vendor_absorbs → paid={$r['paid']} absorbed={$r['absorbed']} (esperado 0/100)";
}

// shared_fixed normal: cliente paga la fija, vendor absorbe el resto.
$r = avs_calc_split( 'shared_fixed', 100, 30 );
if ( ! $approx( $r['paid'], 30 ) || ! $approx( $r['absorbed'], 70 ) ) {
	$fails[] = "shared_fixed(100,30) → paid={$r['paid']} absorbed={$r['absorbed']} (esperado 30/70)";
}

// shared_fixed cuando la tarifa fija supera el costo real: el cliente no paga de más.
$r = avs_calc_split( 'shared_fixed', 20, 30 );
if ( ! $approx( $r['paid'], 20 ) || ! $approx( $r['absorbed'], 0 ) ) {
	$fails[] = "shared_fixed(20,30) → paid={$r['paid']} absorbed={$r['absorbed']} (esperado 20/0)";
}

// modo desconocido → se comporta como customer_pays (seguro por defecto).
$r = avs_calc_split( 'xxx', 100, 30 );
if ( ! $approx( $r['paid'], 100 ) || ! $approx( $r['absorbed'], 0 ) ) {
	$fails[] = "modo inválido → paid={$r['paid']} absorbed={$r['absorbed']} (esperado 100/0)";
}

if ( empty( $fails ) ) {
	echo "FASE 2A: PASS\n";
	exit( 0 );
}
echo "FASE 2A: FAIL\n";
foreach ( $fails as $f ) {
	echo "  - {$f}\n";
}
exit( 1 );
