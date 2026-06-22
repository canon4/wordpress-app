<?php
/**
 * Plugin Name: WCFM AI Assistant — Generador Cultural
 * Plugin URI:  https://marketplace-cultural.com
 * Description: Genera descripciones culturales enriquecidas para productos artesanales. Botón flotante en el header de WCFM.
 * Version:     1.1.0
 * Author:      Marketplace Cultural
 * Text Domain: wcfm-ai
 * Requires PHP: 7.4
 * Requires at least: 6.0
 * WC requires at least: 7.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'WCFM_AI_VERSION',    '1.1.0' );
define( 'WCFM_AI_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WCFM_AI_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

final class WCFM_AI_Assistant {

	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'plugins_loaded', array( $this, 'init' ), 10 );
	}

	public function init() {
		if ( ! class_exists( 'WCFM' ) ) {
			add_action( 'admin_notices', array( $this, 'notice_wcfm_required' ) );
			return;
		}

		require_once WCFM_AI_PLUGIN_DIR . 'includes/class-ai-api.php';
		require_once WCFM_AI_PLUGIN_DIR . 'includes/class-admin-settings.php';

		new WCFM_AI_Admin_Settings();

		// Button inside WCFM page heading
		add_action( 'wcfm_page_heading', array( $this, 'render_header_button' ) );

		// Modal HTML at the end of the page body
		add_action( 'wp_footer', array( $this, 'render_modal' ) );

		// Assets
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		// REST API
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
	}

	public function notice_wcfm_required() {
		echo '<div class="notice notice-error"><p><strong>WCFM AI Assistant:</strong> requiere que el plugin WCFM esté instalado y activo.</p></div>';
	}

	/** Returns true if current user can use the AI panel. */
	private function user_can_use() {
		$is_vendor = function_exists( 'wcfm_is_vendor' ) && wcfm_is_vendor();
		return $is_vendor || current_user_can( 'manage_options' );
	}

	/** Returns true if we are on the WCFM product manage page. */
	private function is_product_manage_page() {
		if ( function_exists( 'wcfm_is_endpoint_url' ) ) {
			return wcfm_is_endpoint_url( 'wcfm-products-manage' );
		}
		// Fallback: match the URL slug (default 'products-manage')
		$uri = sanitize_text_field( $_SERVER['REQUEST_URI'] ?? '' );
		return strpos( $uri, 'products-manage' ) !== false;
	}

	/** Render the "✨ IA" button inside the WCFM page heading. */
	public function render_header_button() {
		if ( ! $this->user_can_use() || ! $this->is_product_manage_page() ) {
			return;
		}
		?>
		<button type="button" id="wcfm-ai-open-btn" class="wcfm-ai-header-btn" aria-label="Abrir asistente de IA">
			<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
				<path d="M12 2L15.09 8.26L22 9.27L17 14.14L18.18 21.02L12 17.77L5.82 21.02L7 14.14L2 9.27L8.91 8.26L12 2Z"/>
			</svg>
			Generar con IA
		</button>
		<?php
	}

	/** Render modal HTML at wp_footer. */
	public function render_modal() {
		if ( ! $this->user_can_use() || ! $this->is_product_manage_page() ) {
			return;
		}

		$vendor_id   = apply_filters( 'wcfm_current_vendor_id', get_current_user_id() );
		$vendor_data = $this->get_vendor_context( $vendor_id );

		include WCFM_AI_PLUGIN_DIR . 'templates/ai-modal.php';
	}

	/** Enqueue CSS and JS on the product manage page. */
	public function enqueue_assets() {
		if ( ! $this->user_can_use() || ! $this->is_product_manage_page() ) {
			return;
		}

		wp_enqueue_style(
			'wcfm-ai-assistant',
			WCFM_AI_PLUGIN_URL . 'assets/css/wcfm-ai-assistant.css',
			array(),
			WCFM_AI_VERSION
		);

		wp_enqueue_script(
			'wcfm-ai-assistant',
			WCFM_AI_PLUGIN_URL . 'assets/js/wcfm-ai-assistant.js',
			array( 'jquery' ),
			WCFM_AI_VERSION,
			true
		);

		wp_localize_script( 'wcfm-ai-assistant', 'wcfmAI', array(
			'restUrl' => rest_url( 'wcfm-ai/v1/generate' ),
			'nonce'   => wp_create_nonce( 'wp_rest' ),
		) );
	}

	/** Build vendor context array from user meta. */
	public function get_vendor_context( $vendor_id ) {
		$store_name = get_user_meta( $vendor_id, 'store_name', true );
		$store_desc = get_user_meta( $vendor_id, 'store_description', true );

		$wcfm_profile = get_user_meta( $vendor_id, 'wcfm_vendor_store_profile', true );
		if ( is_array( $wcfm_profile ) && empty( $store_desc ) ) {
			$store_desc = $wcfm_profile['store_description'] ?? '';
		}

		$address  = get_user_meta( $vendor_id, 'address', true );
		$location = '';
		if ( is_array( $address ) ) {
			$parts    = array_filter( array(
				$address['street_1'] ?? '',
				$address['city']     ?? '',
				$address['state']    ?? '',
				$address['country']  ?? '',
			) );
			$location = implode( ', ', $parts );
		} elseif ( is_string( $address ) && $address ) {
			$location = $address;
		}
		if ( ! $location ) {
			$location = (string) get_user_meta( $vendor_id, 'store_location', true );
		}

		return array(
			'store_name'  => $store_name  ?: '',
			'store_desc'  => $store_desc  ?: '',
			'location'    => $location    ?: '',
			'community'   => (string) get_user_meta( $vendor_id, '_wcfm_ai_community_history', true ),
			'traditions'  => (string) get_user_meta( $vendor_id, '_wcfm_ai_traditions', true ),
		);
	}

	/** Register REST API routes. */
	public function register_rest_routes() {
		register_rest_route( 'wcfm-ai/v1', '/generate', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_generate' ),
			'permission_callback' => array( $this, 'check_vendor_permission' ),
		) );

		register_rest_route( 'wcfm-ai/v1', '/test', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'handle_test' ),
			'permission_callback' => function() { return current_user_can( 'manage_options' ); },
		) );
	}

	public function check_vendor_permission() {
		if ( ! is_user_logged_in() ) return false;
		if ( current_user_can( 'manage_options' ) ) return true;
		return function_exists( 'wcfm_is_vendor' ) && wcfm_is_vendor();
	}

	public function handle_generate( $request ) {
		$data = $request->get_json_params();

		if ( empty( $data['product_name'] ) ) {
			return new WP_Error( 'missing_name', 'Se requiere el nombre del producto.', array( 'status' => 400 ) );
		}

		$vendor_id = get_current_user_id();
		$limit_key = 'wcfm_ai_count_' . $vendor_id . '_' . gmdate( 'Y_m' );
		$count     = (int) get_transient( $limit_key );
		$max_limit = (int) get_option( 'wcfm_ai_vendor_monthly_limit', 50 );

		if ( $count >= $max_limit && ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'limit_reached',
				sprintf( 'Has alcanzado el límite de %d generaciones este mes.', $max_limit ),
				array( 'status' => 429 )
			);
		}

		$api    = new WCFM_AI_API();
		$result = $api->generate( $data );

		if ( is_wp_error( $result ) ) return $result;

		set_transient( $limit_key, $count + 1, MONTH_IN_SECONDS );
		$this->log_usage( $vendor_id, $data['product_name'], $result['tokens_used'] ?? 0 );

		return rest_ensure_response( array( 'success' => true, 'data' => $result ) );
	}

	public function handle_test( $request ) {
		$api = new WCFM_AI_API();
		return rest_ensure_response( $api->test_connection() );
	}

	private function log_usage( $vendor_id, $product_name, $tokens ) {
		$log   = get_option( 'wcfm_ai_usage_log', array() );
		$log[] = array(
			'vendor_id' => $vendor_id,
			'product'   => sanitize_text_field( $product_name ),
			'tokens'    => (int) $tokens,
			'timestamp' => current_time( 'mysql' ),
		);
		if ( count( $log ) > 500 ) $log = array_slice( $log, -500 );
		update_option( 'wcfm_ai_usage_log', $log );
	}
}

WCFM_AI_Assistant::get_instance();
