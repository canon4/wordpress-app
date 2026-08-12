<?php
/**
 * Test 2 — Cuota mensual atómica de análisis de métricas.
 *
 *   C:\xampp\php\php.exe tests\test02-quota.php
 *
 * Mismo algoritmo que WCFM_AI_Quota (wcfm-ai-assistant/tests/test02-quota.php);
 * se repite aquí porque WCFM_AI_Insights_Quota es un fork con su propio
 * prefijo de opción y debe verificarse independiente (no debe compartir
 * balde con la cuota del generador de descripciones).
 *
 * @package WCFM_AI_Insights
 */

require __DIR__ . '/../../../../wp-load.php';

if ( ! class_exists( 'WCFM_AI_Insights_Quota' ) ) {
	require_once WP_PLUGIN_DIR . '/wcfm-ai-insights/includes/class-ai-insights-quota.php';
}

$fails  = array();
$vendor = 999124; // vendedor ficticio, distinto del usado por wcfm-ai-assistant.

WCFM_AI_Insights_Quota::reset( $vendor );

if ( 0 !== WCFM_AI_Insights_Quota::usage( $vendor ) ) {
	$fails[] = 'El consumo inicial deberia ser 0.';
}

$limit = 5;
for ( $i = 1; $i <= $limit; $i++ ) {
	if ( true !== WCFM_AI_Insights_Quota::reserve( $vendor, $limit ) ) {
		$fails[] = "La reserva #{$i} deberia autorizarse (limite {$limit}).";
	}
}
if ( $limit !== WCFM_AI_Insights_Quota::usage( $vendor ) ) {
	$fails[] = 'Tras ' . $limit . ' reservas el consumo deberia ser ' . $limit . '.';
}
if ( false !== WCFM_AI_Insights_Quota::reserve( $vendor, $limit ) ) {
	$fails[] = 'Pasado el limite, reserve() debe denegar.';
}
if ( $limit !== WCFM_AI_Insights_Quota::usage( $vendor ) ) {
	$fails[] = 'Una reserva denegada no debe dejar el contador inflado.';
}

/* --- Namespacing: la cuota de insights NO debe verse afectada por la de wcfm-ai-assistant --- */
if ( class_exists( 'WCFM_AI_Quota' ) || is_file( WP_PLUGIN_DIR . '/wcfm-ai-assistant/includes/class-ai-quota.php' ) ) {
	if ( ! class_exists( 'WCFM_AI_Quota' ) ) {
		require_once WP_PLUGIN_DIR . '/wcfm-ai-assistant/includes/class-ai-quota.php';
	}
	WCFM_AI_Quota::reset( $vendor );
	WCFM_AI_Quota::reserve( $vendor, 100 );
	WCFM_AI_Quota::reserve( $vendor, 100 );
	WCFM_AI_Quota::reserve( $vendor, 100 ); // 3 reservas en el balde del OTRO plugin.

	if ( WCFM_AI_Insights_Quota::usage( $vendor ) !== $limit ) {
		$fails[] = 'La cuota de insights no debe compartir contador con wcfm_ai_count_ (colision de balde).';
	}
	WCFM_AI_Quota::reset( $vendor );
}

WCFM_AI_Insights_Quota::reset( $vendor );

if ( empty( $fails ) ) {
	echo "TEST 2 (cuota insights atomica): PASS\n";
	exit( 0 );
}
echo "TEST 2 (cuota insights atomica): FAIL\n";
foreach ( $fails as $f ) {
	echo "  - {$f}\n";
}
exit( 1 );
