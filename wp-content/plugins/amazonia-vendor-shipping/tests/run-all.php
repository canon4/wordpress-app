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
	'test09-access.php',
	'test10-multivendor-label.php',
);

$all_pass = true;

foreach ( $tests as $t ) {
	$path = $dir . DIRECTORY_SEPARATOR . $t;
	$out  = array();
	$code = 0;
	exec( escapeshellarg( $php ) . ' ' . escapeshellarg( $path ) . ' 2>&1', $out, $code );
	$summary = trim( implode( ' | ', $out ) );

	// Un test pasa solo si sale con código 0 Y emite su marca "FASE n: PASS".
	// Exigir la marca es imprescindible: si WordPress no puede conectar a la base de
	// datos, aborta con wp_die() ANTES de llegar al echo del test y aun así termina
	// con código 0. Mirando solo el código de salida, esos fallos se contaban como PASS.
	$pass = ( 0 === $code && preg_match( '/:\s*PASS\b/', $summary ) );

	if ( ! $pass && '' !== $summary && false !== stripos( $summary, '<!DOCTYPE html>' ) ) {
		// WordPress murió y volcó una página de error en vez de correr el test.
		$summary = 'WordPress abortó antes de ejecutar el test (revisar conexión a la base de datos / wp-load.php).';
	}
	if ( strlen( $summary ) > 200 ) {
		$summary = substr( $summary, 0, 200 ) . '…';
	}

	echo ( $pass ? '[PASS] ' : '[FAIL] ' ) . str_pad( $t, 24 ) . ' → ' . $summary . "\n";
	if ( ! $pass ) {
		$all_pass = false;
	}
}

echo "\n" . ( $all_pass ? 'TODAS LAS FASES AUTOMATIZADAS: PASS' : 'HAY FALLOS — revisar arriba' ) . "\n";
exit( $all_pass ? 0 : 1 );
