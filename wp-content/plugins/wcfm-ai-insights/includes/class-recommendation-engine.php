<?php
/**
 * Orquesta: cache -> agregador de métricas -> cuota -> API de IA -> cache.
 *
 * Depende de que el plugin wcfm-ai-assistant esté activo: reutiliza su clase
 * WCFM_AI_API (configuración de proveedor/API key compartida, por decisión
 * explícita del usuario — un solo lugar para configurar la IA del
 * marketplace) y su WCFM_AI_Security para sanear texto antes del prompt.
 *
 * @package WCFM_AI_Insights
 */

defined( 'ABSPATH' ) || exit;

class WCFM_AI_Recommendation_Engine {

	/**
	 * @param int  $vendor_id
	 * @param int  $period_days
	 * @param bool $force_refresh
	 * @return array|WP_Error
	 */
	public static function get_or_generate( $vendor_id, $period_days, $force_refresh = false ) {
		if ( ! class_exists( 'WCFM_AI_API' ) ) {
			return new WP_Error(
				'missing_dependency',
				'El plugin "WCFM AI Assistant" debe estar activo y configurado (usa el mismo proveedor de IA).',
				array( 'status' => 424 )
			);
		}

		$vendor_id   = absint( $vendor_id );
		$period_days = WCFM_Metrics_Aggregator::sanitize_period_days( $period_days );

		if ( $force_refresh && ! WCFM_Metrics_Cache::can_force_refresh( $vendor_id, $period_days ) ) {
			return new WP_Error(
				'cooldown',
				'Ya se generó una recomendación recientemente. Esperá unos minutos antes de forzar otra.',
				array( 'status' => 429 )
			);
		}

		if ( ! $force_refresh ) {
			$cached = WCFM_Metrics_Cache::get_recommendation( $vendor_id, $period_days );
			if ( false !== $cached ) {
				$cached['from_cache'] = true;
				return $cached;
			}
		}

		$summary = WCFM_Metrics_Cache::get_summary( $vendor_id, $period_days );
		if ( false === $summary ) {
			$summary = WCFM_Metrics_Aggregator::get_vendor_summary( $vendor_id, $period_days );
			WCFM_Metrics_Cache::set_summary( $vendor_id, $period_days, $summary );
		}

		if ( empty( $summary['products'] ) ) {
			return new WP_Error(
				'no_data',
				'No hay ventas registradas para este vendedor en el periodo elegido.',
				array( 'status' => 200 )
			);
		}

		$is_admin = current_user_can( 'manage_options' );
		$reserved = false;

		if ( ! $is_admin ) {
			$limit = WCFM_AI_Security::sanitize_limit( get_option( 'wcfm_ai_insights_vendor_monthly_limit', 30 ) );
			if ( ! WCFM_AI_Insights_Quota::reserve( $vendor_id, $limit ) ) {
				return new WP_Error( 'rate_limit', 'Has alcanzado el límite mensual de análisis de métricas.', array( 'status' => 429 ) );
			}
			$reserved = true;
		}

		$api    = new WCFM_AI_API();
		$result = $api->generate_recommendations( $summary );

		if ( is_wp_error( $result ) ) {
			if ( $reserved ) {
				WCFM_AI_Insights_Quota::refund( $vendor_id );
			}
			return $result;
		}

		$entry = WCFM_Metrics_Cache::set_recommendation( $vendor_id, $period_days, $result );
		$entry['from_cache'] = false;
		return $entry;
	}
}
