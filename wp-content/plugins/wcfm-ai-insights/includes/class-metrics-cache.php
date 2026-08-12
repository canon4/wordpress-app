<?php
/**
 * Cache de agregados de métricas y de recomendaciones de IA.
 *
 * Dos buckets separados:
 *  - Agregado crudo (sin IA): TTL corto, evita repetir la consulta SQL en
 *    cada carga de la pantalla admin dentro de la misma hora.
 *  - Recomendación de IA: TTL largo (configurable), es lo caro — nunca se
 *    debe volver a llamar al proveedor de IA solo porque el admin recargó
 *    la página.
 *
 * Se guardan como wp_options con autoload=false: no deben cargarse en cada
 * request de WordPress, solo cuando se piden explícitamente (mismo criterio
 * que wcfm_ai_usage_log en wcfm-ai-assistant).
 *
 * @package WCFM_AI_Insights
 */

defined( 'ABSPATH' ) || exit;

class WCFM_Metrics_Cache {

	/** Mínimo tiempo entre refrescos forzados de recomendación, en segundos. */
	const FORCE_REFRESH_COOLDOWN = 300; // 5 minutos.

	private static function agg_key( $vendor_id, $period_days ) {
		return sprintf( 'wcfm_ai_insights_agg_%d_%d_%s', absint( $vendor_id ), absint( $period_days ), gmdate( 'Y-m-d' ) );
	}

	private static function rec_key( $vendor_id, $period_days ) {
		return sprintf( 'wcfm_ai_insights_rec_%d_%d_%s', absint( $vendor_id ), absint( $period_days ), gmdate( 'Y-m-d' ) );
	}

	/* ------------------------------------------------------------------ */
	/*  Agregado crudo                                                      */
	/* ------------------------------------------------------------------ */

	public static function get_summary( $vendor_id, $period_days ) {
		$key   = self::agg_key( $vendor_id, $period_days );
		$entry = get_option( $key, false );
		if ( ! is_array( $entry ) || ! isset( $entry['cached_at'] ) ) {
			return false;
		}
		if ( ( time() - $entry['cached_at'] ) > HOUR_IN_SECONDS ) {
			return false;
		}
		return $entry['data'];
	}

	public static function set_summary( $vendor_id, $period_days, array $summary ) {
		$key = self::agg_key( $vendor_id, $period_days );
		update_option( $key, array(
			'cached_at' => time(),
			'data'      => $summary,
		), false );
	}

	/* ------------------------------------------------------------------ */
	/*  Recomendación de IA                                                 */
	/* ------------------------------------------------------------------ */

	/**
	 * @param int $vendor_id
	 * @param int $period_days
	 * @param int $ttl_seconds TTL configurable (default: 1 día).
	 * @return array|false
	 */
	public static function get_recommendation( $vendor_id, $period_days, $ttl_seconds = DAY_IN_SECONDS ) {
		$key   = self::rec_key( $vendor_id, $period_days );
		$entry = get_option( $key, false );
		if ( ! is_array( $entry ) || ! isset( $entry['generated_at'] ) ) {
			return false;
		}
		if ( ( time() - $entry['generated_at'] ) > $ttl_seconds ) {
			return false;
		}
		return $entry;
	}

	public static function set_recommendation( $vendor_id, $period_days, array $data ) {
		$key   = self::rec_key( $vendor_id, $period_days );
		$entry = array(
			'generated_at' => time(),
			'data'         => $data,
		);
		update_option( $key, $entry, false );
		return $entry;
	}

	/**
	 * Si el último refresco (forzado o no) fue hace menos de FORCE_REFRESH_COOLDOWN,
	 * un force_refresh se rechaza — evita vaciar la cuota mensual a fuerza de clics.
	 *
	 * @param int $vendor_id
	 * @param int $period_days
	 * @return bool True si se puede forzar un refresco ahora.
	 */
	public static function can_force_refresh( $vendor_id, $period_days ) {
		$key   = self::rec_key( $vendor_id, $period_days );
		$entry = get_option( $key, false );
		if ( ! is_array( $entry ) || ! isset( $entry['generated_at'] ) ) {
			return true;
		}
		return ( time() - $entry['generated_at'] ) >= self::FORCE_REFRESH_COOLDOWN;
	}
}
