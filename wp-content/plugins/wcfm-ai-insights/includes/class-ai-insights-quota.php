<?php
/**
 * Cuota mensual de análisis de métricas por vendedor, con reserva atómica.
 *
 * Fork de WCFM_AI_Quota (plugin wcfm-ai-assistant) con prefijo de opción
 * propio: la cuota de "generar descripción de producto" y la de "analizar
 * métricas de venta" son presupuestos de IA distintos y no deben compartir
 * el mismo contador.
 *
 * Mismo algoritmo: el contador se incrementa PRIMERO con una sentencia SQL
 * atómica (INSERT ... ON DUPLICATE KEY UPDATE) y se reembolsa si la llamada
 * a la IA falla o si la reserva se pasó del límite.
 *
 * @package WCFM_AI_Insights
 */

defined( 'ABSPATH' ) || exit;

class WCFM_AI_Insights_Quota {

	const PREFIX = 'wcfm_ai_insights_count_';

	public static function key( $vendor_id, $month = '' ) {
		$month = $month ?: gmdate( 'Y_m' );
		return self::PREFIX . absint( $vendor_id ) . '_' . $month;
	}

	public static function usage( $vendor_id ) {
		global $wpdb;
		$key = self::key( $vendor_id );
		$val = $wpdb->get_var(
			$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $key )
		);
		return (int) $val;
	}

	/**
	 * Reserva un análisis de forma atómica.
	 *
	 * @param int $vendor_id
	 * @param int $limit Máximo mensual. 0 = sin cuota (no reserva nada).
	 * @return bool
	 */
	public static function reserve( $vendor_id, $limit ) {
		global $wpdb;

		$limit = (int) $limit;
		if ( $limit <= 0 ) {
			return true;
		}

		$key = self::key( $vendor_id );

		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->options} (option_name, option_value, autoload)
				 VALUES (%s, '1', 'no')
				 ON DUPLICATE KEY UPDATE option_value = option_value + 1",
				$key
			)
		);
		wp_cache_delete( $key, 'options' );

		$count = self::usage( $vendor_id );

		if ( $count > $limit ) {
			self::refund( $vendor_id );
			return false;
		}
		return true;
	}

	public static function refund( $vendor_id ) {
		global $wpdb;
		$key = self::key( $vendor_id );

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options}
				 SET option_value = GREATEST(CAST(option_value AS SIGNED) - 1, 0)
				 WHERE option_name = %s",
				$key
			)
		);
		wp_cache_delete( $key, 'options' );
	}

	public static function reset( $vendor_id ) {
		delete_option( self::key( $vendor_id ) );
		wp_cache_delete( self::key( $vendor_id ), 'options' );
	}
}
