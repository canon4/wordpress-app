<?php
/**
 * Plugin Name: Amazonia — Mercado Pago Split
 * Description: Integra Mercado Pago Split Payments con el marketplace WCFM. Cada comunidad/vendedor conecta su propia cuenta MP y recibe pagos directamente.
 * Version: 1.0.0
 * Author: Diego Canon
 * Requires Plugins: woocommerce, woocommerce-mercadopago, wc-multivendor-marketplace
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'AMPS_VERSION', '1.0.0' );
define( 'AMPS_PATH', plugin_dir_path( __FILE__ ) );
define( 'AMPS_URL', plugin_dir_url( __FILE__ ) );

final class Amazonia_MP_Split {

    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'plugins_loaded', [ $this, 'init' ], 20 );
    }

    public function init() {
        if ( ! class_exists( 'WooCommerce' ) || ! class_exists( 'WCFMmp' ) ) {
            add_action( 'admin_notices', [ $this, 'missing_dependencies_notice' ] );
            return;
        }

        require_once AMPS_PATH . 'includes/class-amps-settings.php';
        require_once AMPS_PATH . 'includes/class-amps-oauth.php';
        require_once AMPS_PATH . 'includes/class-amps-vendor.php';
        require_once AMPS_PATH . 'includes/class-amps-gateway.php';
        require_once AMPS_PATH . 'includes/class-amps-webhook.php';

        AMPS_Settings::instance();
        AMPS_OAuth::instance();
        AMPS_Vendor::instance();
        AMPS_Webhook::instance();

        add_filter( 'woocommerce_payment_gateways', [ $this, 'register_gateway' ] );
    }

    public function register_gateway( array $gateways ): array {
        $gateways[] = 'AMPS_Gateway';
        return $gateways;
    }

    public function missing_dependencies_notice() {
        echo '<div class="notice notice-error"><p><strong>Amazonia MP Split:</strong> Requiere WooCommerce, el plugin oficial de Mercado Pago y WCFM Multivendor activos.</p></div>';
    }
}

Amazonia_MP_Split::instance();
