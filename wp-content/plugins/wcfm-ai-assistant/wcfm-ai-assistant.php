<?php
/**
 * Plugin Name: WCFM AI Assistant — Generador Cultural
 * Plugin URI:  https://example.com/wcfm-ai-assistant
 * Description: Genera descripciones culturales de productos artesanales con IA para vendedores WCFM.
 * Version:     1.1.0
 * Author:      Diego Canon
 * Requires PHP: 7.4
 * Text Domain: wcfm-ai-assistant
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'WCFM_AI_VERSION', '1.1.0' );
define( 'WCFM_AI_PATH', plugin_dir_path( __FILE__ ) );
define( 'WCFM_AI_URL', plugin_dir_url( __FILE__ ) );

class WCFM_AI_Assistant {

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'init', array( $this, 'load_includes' ) );
        add_action( 'wcfm_page_heading', array( $this, 'render_header_button' ) );
        add_action( 'wp_footer', array( $this, 'render_modal' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
    }

    public function load_includes() {
        require_once WCFM_AI_PATH . 'includes/class-ai-api.php';
        require_once WCFM_AI_PATH . 'includes/class-admin-settings.php';
        new WCFM_AI_Admin_Settings();
    }

    /* ------------------------------------------------------------------ */
    /*  Helpers                                                             */
    /* ------------------------------------------------------------------ */

    private function user_can_use() {
        $is_vendor = function_exists( 'wcfm_is_vendor' ) && wcfm_is_vendor();
        return $is_vendor || current_user_can( 'manage_options' );
    }

    private function is_product_manage_page() {
        if ( function_exists( 'wcfm_is_endpoint_url' ) ) {
            return wcfm_is_endpoint_url( 'wcfm-products-manage' );
        }
        $uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
        return strpos( $uri, 'products-manage' ) !== false;
    }

    private function get_vendor_context( $vendor_id ) {
        $store_info = array();

        if ( function_exists( 'wcfm_get_vendor_store_name' ) ) {
            $store_info['store_name'] = wcfm_get_vendor_store_name( $vendor_id );
        } else {
            $store_info['store_name'] = get_user_meta( $vendor_id, 'store_name', true );
        }

        $wcfm_profile = get_user_meta( $vendor_id, 'wcfm_profile', true );
        $store_info['store_description'] = '';
        $store_info['location'] = '';

        if ( is_array( $wcfm_profile ) ) {
            $store_info['store_description'] = isset( $wcfm_profile['store_description'] ) ? $wcfm_profile['store_description'] : '';
            $store_info['location'] = isset( $wcfm_profile['address']['city'] ) ? $wcfm_profile['address']['city'] : '';
            if ( empty( $store_info['location'] ) && isset( $wcfm_profile['address']['state'] ) ) {
                $store_info['location'] = $wcfm_profile['address']['state'];
            }
        }

        $store_info['community_history'] = get_user_meta( $vendor_id, '_wcfm_ai_community_history', true );
        $store_info['traditions']        = get_user_meta( $vendor_id, '_wcfm_ai_traditions', true );

        return $store_info;
    }

    private function get_current_vendor_id() {
        $vendor_id = get_current_user_id();
        $vendor_id = apply_filters( 'wcfm_current_vendor_id', $vendor_id );
        return $vendor_id;
    }

    /* ------------------------------------------------------------------ */
    /*  Render: header button                                               */
    /* ------------------------------------------------------------------ */

    public function render_header_button() {
        if ( ! $this->user_can_use() || ! $this->is_product_manage_page() ) {
            return;
        }
        echo '<button id="wcfm-ai-open-btn" type="button">&#9733; Generar con IA</button>';
    }

    /* ------------------------------------------------------------------ */
    /*  Render: modal                                                       */
    /* ------------------------------------------------------------------ */

    public function render_modal() {
        if ( ! $this->user_can_use() || ! $this->is_product_manage_page() ) {
            return;
        }
        $vendor_id  = $this->get_current_vendor_id();
        $vendor_ctx = $this->get_vendor_context( $vendor_id );
        include WCFM_AI_PATH . 'templates/ai-modal.php';
    }

    /* ------------------------------------------------------------------ */
    /*  Assets                                                              */
    /* ------------------------------------------------------------------ */

    public function enqueue_assets() {
        if ( ! $this->user_can_use() || ! $this->is_product_manage_page() ) {
            return;
        }

        wp_enqueue_style(
            'wcfm-ai-assistant',
            WCFM_AI_URL . 'assets/css/wcfm-ai-assistant.css',
            array(),
            WCFM_AI_VERSION
        );

        wp_enqueue_script(
            'wcfm-ai-assistant',
            WCFM_AI_URL . 'assets/js/wcfm-ai-assistant.js',
            array( 'jquery' ),
            WCFM_AI_VERSION,
            true
        );

        wp_localize_script( 'wcfm-ai-assistant', 'wcfmAI', array(
            'restUrl' => rest_url( 'wcfm-ai/v1/' ),
            'nonce'   => wp_create_nonce( 'wp_rest' ),
        ) );
    }

    /* ------------------------------------------------------------------ */
    /*  REST API                                                            */
    /* ------------------------------------------------------------------ */

    public function register_rest_routes() {
        register_rest_route( 'wcfm-ai/v1', '/generate', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'handle_generate' ),
            'permission_callback' => array( $this, 'check_vendor_permission' ),
        ) );

        register_rest_route( 'wcfm-ai/v1', '/test', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'handle_test' ),
            'permission_callback' => function() {
                return current_user_can( 'manage_options' );
            },
        ) );
    }

    public function check_vendor_permission() {
        if ( ! is_user_logged_in() ) {
            return false;
        }
        $is_vendor = function_exists( 'wcfm_is_vendor' ) && wcfm_is_vendor();
        return $is_vendor || current_user_can( 'manage_options' );
    }

    public function handle_generate( WP_REST_Request $request ) {
        $params = $request->get_json_params();

        $product_name = isset( $params['product_name'] ) ? sanitize_text_field( $params['product_name'] ) : '';
        if ( empty( $product_name ) ) {
            return new WP_Error( 'missing_name', 'El nombre del producto es requerido.', array( 'status' => 400 ) );
        }

        // Rate limiting — admins skip
        $vendor_id = get_current_user_id();
        if ( ! current_user_can( 'manage_options' ) ) {
            $limit     = (int) get_option( 'wcfm_ai_vendor_monthly_limit', 50 );
            $key       = 'wcfm_ai_count_' . $vendor_id . '_' . date( 'Y_m' );
            $count     = (int) get_transient( $key );
            if ( $count >= $limit ) {
                return new WP_Error( 'rate_limit', 'Has alcanzado el límite mensual de generaciones.', array( 'status' => 429 ) );
            }
        }

        $api    = new WCFM_AI_API();
        $result = $api->generate( $params );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        // Increment counter
        if ( ! current_user_can( 'manage_options' ) ) {
            $key   = 'wcfm_ai_count_' . $vendor_id . '_' . date( 'Y_m' );
            $count = (int) get_transient( $key );
            set_transient( $key, $count + 1, MONTH_IN_SECONDS );
        }

        $this->log_usage( $vendor_id, $product_name, isset( $result['tokens_used'] ) ? $result['tokens_used'] : 0 );

        return rest_ensure_response( $result );
    }

    public function handle_test( WP_REST_Request $request ) {
        $api    = new WCFM_AI_API();
        $result = $api->test_connection();
        return rest_ensure_response( $result );
    }

    private function log_usage( $vendor_id, $product_name, $tokens ) {
        $log   = get_option( 'wcfm_ai_usage_log', array() );
        $log[] = array(
            'time'         => current_time( 'mysql' ),
            'vendor_id'    => $vendor_id,
            'product_name' => $product_name,
            'tokens'       => $tokens,
            'month'        => date( 'Y_m' ),
        );
        // Keep last 500
        if ( count( $log ) > 500 ) {
            $log = array_slice( $log, -500 );
        }
        update_option( 'wcfm_ai_usage_log', $log );
    }
}

WCFM_AI_Assistant::get_instance();
