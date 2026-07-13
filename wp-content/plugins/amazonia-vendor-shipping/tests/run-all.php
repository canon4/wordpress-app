<?php
/**
 * Runner: ejecuta todos los tests automatizados y resume PASS/FAIL.
 *
 *   C:\xampp\php\php.exe tests\run-all.php
 *
 * Cada test corre como proceso hijo (unos cargan WordPress, otros son PHP puro).
 *
 * @package Amazonia_Vendor_Shipping
 */

$php = PHP_BINARY;
$dir = __DIR__;

$tests = array(
	'test00-bootstrap.php',
	'test01-config.php',
	'test02-split.php',
	'test03-ledger.php',
	'test04-hash.php',
	'test05-validation.php',
	'test06-origin.php',
	'test07-carrier.php',
	'test08-label.php',
);

$all_pass = true;

foreach ( $tests as $t ) {
	$path = $dir . DIRECTORY_SEPARATOR . $t;
	$out  = array();
	$code = 0;
	exec( escapeshellarg( $php ) . ' ' . escapeshellarg( $path ) . ' 2>&1', $out, $code );
	$summary = trim( implode( ' | ', $out ) );
	echo ( 0 === $code ? '[PASS] ' : '[FAIL] ' ) . str_pad( $t, 24 ) . ' → ' . $summary . "\n";
	if ( 0 !== $code ) {
		$all_pass = false;
	}
}

echo "\n" . ( $all_pass ? 'TODAS LAS FASES AUTOMATIZADAS: PASS' : 'HAY FALLOS — revisar arriba' ) . "\n";
exit( $all_pass ? 0 : 1 );
