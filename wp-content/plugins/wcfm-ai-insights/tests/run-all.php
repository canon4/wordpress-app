<?php
/**
 * Runner: ejecuta todos los tests del plugin de insights y resume PASS/FAIL.
 *
 *   C:\xampp\php\php.exe tests\run-all.php
 *
 * @package WCFM_AI_Insights
 */

$php = PHP_BINARY;
$dir = __DIR__;

$tests = array(
	'test01-aggregator.php',
	'test02-quota.php',
	'test03-prompt.php',
);

$all_pass = true;

foreach ( $tests as $t ) {
	$path = $dir . DIRECTORY_SEPARATOR . $t;
	$out  = array();
	$code = 0;
	exec( escapeshellarg( $php ) . ' ' . escapeshellarg( $path ) . ' 2>&1', $out, $code );
	$summary = trim( implode( ' | ', $out ) );

	$pass = ( 0 === $code && preg_match( '/:\s*PASS\b/', $summary ) );

	if ( ! $pass && false !== stripos( $summary, '<!DOCTYPE html>' ) ) {
		$summary = 'WordPress aborto antes de ejecutar el test (revisar conexion a la base de datos).';
	}
	if ( strlen( $summary ) > 200 ) {
		$summary = substr( $summary, 0, 200 ) . '...';
	}

	echo ( $pass ? '[PASS] ' : '[FAIL] ' ) . str_pad( $t, 20 ) . ' -> ' . $summary . "\n";
	if ( ! $pass ) {
		$all_pass = false;
	}
}

echo "\n" . ( $all_pass ? 'TODOS LOS TESTS DE INSIGHTS: PASS' : 'HAY FALLOS - revisar arriba' ) . "\n";
exit( $all_pass ? 0 : 1 );
