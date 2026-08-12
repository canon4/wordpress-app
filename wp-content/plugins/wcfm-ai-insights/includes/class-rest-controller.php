<?php
/**
 * Rutas REST del análisis de métricas.
 *
 * Todo bajo namespace propio (wcfm-ai-insights/v1), separado del namespace
 * de wcfm-ai-assistant (wcfm-ai/v1). En esta entrega el alcance es el panel
 * de administración del manager: TODAS las rutas exigen manage_options,
 * incluida /summary — a diferencia de wcfm-ai-assistant, aquí SÍ se acepta
 * un vendor_id externo (porque es el admin eligiendo qué vendedor mirar),
 * pero solo bajo ese gate de administrador. Nunca abrir estas rutas a
 * vendedores sin antes revisar ese supuesto.
 *
 * @package WCFM_AI_Insights
 */

defined( 'ABSPATH' ) || exit;

class WCFM_AI_Insights_REST_Controller {

	public function register_routes() {
		register_rest_route( 'wcfm-ai-insights/v1', '/summary', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'handle_summary' ),
			'permission_callback' => array( $this, 'check_admin_permission' ),
		) );

		register_rest_route( 'wcfm-ai-insights/v1', '/recommendations', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_recommendations' ),
			'permission_callback' => array( $this, 'check_admin_permission' ),
		) );

		register_rest_route( 'wcfm-ai-insights/v1', '/test', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'handle_test' ),
			'permission_callback' => array( $this, 'check_admin_permission' ),
		) );
	}

	public function check_admin_permission() {
		return current_user_can( 'manage_options' );
	}

	public function handle_summary( WP_REST_Request $request ) {
		$vendor_id   = absint( $request->get_param( 'vendor_id' ) );
		$period_days = WCFM_Metrics_Aggregator::sanitize_period_days( $request->get_param( 'period' ) );

		if ( ! $vendor_id ) {
			return new WP_Error( 'missing_vendor', 'vendor_id es requerido.', array( 'status' => 400 ) );
		}

		$summary = WCFM_Metrics_Cache::get_summary( $vendor_id, $period_days );
		if ( false === $summary ) {
			$summary = WCFM_Metrics_Aggregator::get_vendor_summary( $vendor_id, $period_days );
			WCFM_Metrics_Cache::set_summary( $vendor_id, $period_days, $summary );
		}

		$risk = WCFM_Metrics_Aggregator::get_stock_risk_products( $vendor_id );

		return rest_ensure_response( array(
			'summary' => $summary,
			'risk'    => $risk,
		) );
	}

	public function handle_recommendations( WP_REST_Request $request ) {
		$body        = $request->get_json_params();
		$vendor_id   = absint( isset( $body['vendor_id'] ) ? $body['vendor_id'] : 0 );
		$period_days = WCFM_Metrics_Aggregator::sanitize_period_days( isset( $body['period_days'] ) ? $body['period_days'] : 30 );
		$force       = ! empty( $body['force_refresh'] );

		if ( ! $vendor_id ) {
			return new WP_Error( 'missing_vendor', 'vendor_id es requerido.', array( 'status' => 400 ) );
		}

		$result = WCFM_AI_Recommendation_Engine::get_or_generate( $vendor_id, $period_days, $force );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	public function handle_test( WP_REST_Request $request ) {
		if ( ! class_exists( 'WCFM_AI_API' ) ) {
			return rest_ensure_response( array(
				'success' => false,
				'message' => 'El plugin "WCFM AI Assistant" debe estar activo.',
			) );
		}
		$api    = new WCFM_AI_API();
		$result = $api->test_connection();
		return rest_ensure_response( $result );
	}
}
