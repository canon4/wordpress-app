<?php
/**
 * Plugin Name: WCFM AI Insights — Análisis de Métricas
 * Plugin URI:  https://example.com/wcfm-ai-insights
 * Description: Analiza métricas de venta por vendedor/producto y genera recomendaciones de negocio (precio, stock, promoción) con IA. Requiere el plugin "WCFM AI Assistant" activo y configurado (comparte su proveedor/API key).
 * Version:     1.0.0
 * Author:      Diego Canon
 * Requires PHP: 7.4
 * Text Domain: wcfm-ai-insights
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WCFM_AI_INSIGHTS_VERSION', '1.0.0' );
define( 'WCFM_AI_INSIGHTS_PATH', plugin_dir_path( __FILE__ ) );
define( 'WCFM_AI_INSIGHTS_URL', plugin_dir_url( __FILE__ ) );

class WCFM_AI_Insights {

	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', array( $this, 'load_includes' ) );
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
		add_action( 'admin_notices', array( $this, 'maybe_render_dependency_notice' ) );
	}

	public function load_includes() {
		require_once WCFM_AI_INSIGHTS_PATH . 'includes/class-metrics-aggregator.php';
		require_once WCFM_AI_INSIGHTS_PATH . 'includes/class-metrics-cache.php';
		require_once WCFM_AI_INSIGHTS_PATH . 'includes/class-ai-insights-quota.php';
		require_once WCFM_AI_INSIGHTS_PATH . 'includes/class-recommendation-engine.php';
		require_once WCFM_AI_INSIGHTS_PATH . 'includes/class-rest-controller.php';
	}

	/* ------------------------------------------------------------------ */
	/*  Dependencia: wcfm-ai-assistant                                      */
	/* ------------------------------------------------------------------ */

	private function has_dependency() {
		// class_exists() alcanza porque wcfm-ai-assistant registra sus clases
		// en el hook 'init' igual que este plugin, y ambos ya corrieron para
		// cuando se renderiza admin_notices/admin_menu.
		return class_exists( 'WCFM_AI_API' ) && class_exists( 'WCFM_AI_Security' );
	}

	public function maybe_render_dependency_notice() {
		if ( $this->has_dependency() ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p><strong>WCFM AI Insights</strong>: requiere que el plugin <strong>WCFM AI Assistant</strong> esté activo (comparte su configuración de proveedor de IA). Actívalo para poder generar recomendaciones.</p></div>';
	}

	/* ------------------------------------------------------------------ */
	/*  Admin menu + settings                                              */
	/* ------------------------------------------------------------------ */

	public function add_menu() {
		add_submenu_page(
			'woocommerce',
			'IA Insights',
			'IA Insights',
			'manage_options',
			'wcfm-ai-insights',
			array( $this, 'render_admin_page' )
		);
	}

	public function register_settings() {
		register_setting( 'wcfm_ai_insights_settings', 'wcfm_ai_insights_vendor_monthly_limit', array(
			'sanitize_callback' => class_exists( 'WCFM_AI_Security' )
				? array( 'WCFM_AI_Security', 'sanitize_limit' )
				: 'absint',
		) );
		register_setting( 'wcfm_ai_insights_settings', 'wcfm_ai_insights_default_period_days', array(
			'sanitize_callback' => array( 'WCFM_Metrics_Aggregator', 'sanitize_period_days' ),
		) );
	}

	public function enqueue_admin_assets( $hook ) {
		if ( strpos( $hook, 'wcfm-ai-insights' ) === false ) {
			return;
		}

		wp_enqueue_style(
			'wcfm-ai-insights',
			WCFM_AI_INSIGHTS_URL . 'assets/css/wcfm-ai-insights.css',
			array(),
			WCFM_AI_INSIGHTS_VERSION
		);

		wp_enqueue_script(
			'wcfm-ai-insights-admin',
			WCFM_AI_INSIGHTS_URL . 'assets/js/admin-insights.js',
			array( 'jquery' ),
			WCFM_AI_INSIGHTS_VERSION,
			true
		);

		wp_localize_script( 'wcfm-ai-insights-admin', 'wcfmAIInsights', array(
			'restUrl' => rest_url( 'wcfm-ai-insights/v1/' ),
			'nonce'   => wp_create_nonce( 'wp_rest' ),
		) );
	}

	public function render_admin_page() {
		$vendors = get_users( array(
			'role'    => 'wcfm_vendor',
			'orderby' => 'display_name',
			'fields'  => array( 'ID', 'display_name' ),
		) );
		$default_period = (int) get_option( 'wcfm_ai_insights_default_period_days', 30 );
		$monthly_limit  = (int) get_option( 'wcfm_ai_insights_vendor_monthly_limit', 30 );
		include WCFM_AI_INSIGHTS_PATH . 'templates/admin-insights-view.php';
	}

	/* ------------------------------------------------------------------ */
	/*  REST                                                                */
	/* ------------------------------------------------------------------ */

	public function register_rest_routes() {
		$controller = new WCFM_AI_Insights_REST_Controller();
		$controller->register_routes();
	}
}

WCFM_AI_Insights::get_instance();
