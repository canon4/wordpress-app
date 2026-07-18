<?php
/**
 * Test 2 — Cuota mensual atómica (regresión de A-2).
 *
 *   C:\xampp\php\php.exe tests\test02-quota.php
 *
 * El fallo original: la comprobación era leer -> llamar a la IA -> incrementar.
 * Con el contador en 49 y límite 50, cinco peticiones concurrentes leían 49 y
 * las cinco pasaban. Ahora la reserva incrementa PRIMERO de forma atómica, así
 * que sólo una puede cruzar el límite.
 *
 * No hace ninguna llamada a la API.
 *
 * @package WCFM_AI_Assistant
 */

require __DIR__ . '/../../../../wp-load.php';

if ( ! class_exists( 'WCFM_AI_Quota' ) ) {
	require_once WP_PLUGIN_DIR . '/wcfm-ai-assistant/includes/class-ai-quota.php';
}

$fails  = array();
$vendor = 999123; // vendedor ficticio

WCFM_AI_Quota::reset( $vendor );

/* --- Consumo inicial --- */
if ( 0 !== WCFM_AI_Quota::usage( $vendor ) ) {
	$fails[] = 'El consumo inicial deberia ser 0.';
}

/* --- Reservas dentro del limite --- */
$limit = 5;
for ( $i = 1; $i <= $limit; $i++ ) {
	if ( true !== WCFM_AI_Quota::reserve( $vendor, $limit ) ) {
		$fails[] = "La reserva #{$i} deberia autorizarse (limite {$limit}).";
	}
}
if ( $limit !== WCFM_AI_Quota::usage( $vendor ) ) {
	$fails[] = 'Tras ' . $limit . ' reservas el consumo deberia ser ' . $limit . ', es ' . WCFM_AI_Quota::usage( $vendor ) . '.';
}

/* --- La siguiente se deniega y NO incrementa (se reembolsa) --- */
if ( false !== WCFM_AI_Quota::reserve( $vendor, $limit ) ) {
	$fails[] = 'Pasado el limite, reserve() debe denegar.';
}
if ( $limit !== WCFM_AI_Quota::usage( $vendor ) ) {
	$fails[] = 'Una reserva denegada no debe dejar el contador inflado (quedo en ' . WCFM_AI_Quota::usage( $vendor ) . ').';
}

/* --- EL NUCLEO DEL FALLO: N reservas seguidas no pueden pasar del limite --- */
WCFM_AI_Quota::reset( $vendor );
$limit    = 50;
$granted  = 0;
// Se sitúa el contador justo debajo del límite, como en la prueba de concepto.
for ( $i = 0; $i < 49; $i++ ) {
	WCFM_AI_Quota::reserve( $vendor, $limit );
}
// Cinco intentos "simultaneos": antes las cinco pasaban.
for ( $i = 0; $i < 5; $i++ ) {
	if ( WCFM_AI_Quota::reserve( $vendor, $limit ) ) {
		$granted++;
	}
}
if ( 1 !== $granted ) {
	$fails[] = "Con el contador en 49 y limite 50, solo 1 de 5 deberia pasar; pasaron {$granted}.";
}
if ( WCFM_AI_Quota::usage( $vendor ) > $limit ) {
	$fails[] = 'El consumo nunca debe superar el limite (es ' . WCFM_AI_Quota::usage( $vendor ) . ').';
}

/* --- Reembolso --- */
$before = WCFM_AI_Quota::usage( $vendor );
WCFM_AI_Quota::refund( $vendor );
if ( WCFM_AI_Quota::usage( $vendor ) !== $before - 1 ) {
	$fails[] = 'refund() deberia restar exactamente 1.';
}

/* --- El reembolso nunca baja de cero --- */
WCFM_AI_Quota::reset( $vendor );
WCFM_AI_Quota::refund( $vendor );
if ( WCFM_AI_Quota::usage( $vendor ) < 0 ) {
	$fails[] = 'El contador nunca debe quedar negativo.';
}

/* --- Limite 0 = sin cuota --- */
if ( true !== WCFM_AI_Quota::reserve( $vendor, 0 ) ) {
	$fails[] = 'Con limite 0 (sin cuota) siempre deberia autorizar.';
}

/* --- El contador NO debe autocargarse en cada peticion --- */
WCFM_AI_Quota::reset( $vendor );
WCFM_AI_Quota::reserve( $vendor, 10 );
global $wpdb;
$autoload = $wpdb->get_var(
	$wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", WCFM_AI_Quota::key( $vendor ) )
);
if ( ! in_array( $autoload, array( 'no', 'off' ), true ) ) {
	$fails[] = "El contador no deberia autocargarse (autoload='{$autoload}').";
}

/* --- Limpieza --- */
WCFM_AI_Quota::reset( $vendor );

if ( empty( $fails ) ) {
	echo "TEST 2 (cuota atomica): PASS\n";
	exit( 0 );
}
echo "TEST 2 (cuota atomica): FAIL\n";
foreach ( $fails as $f ) {
	echo "  - {$f}\n";
}
exit( 1 );
