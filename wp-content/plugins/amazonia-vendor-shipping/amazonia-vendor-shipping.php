<?php
/**
 * Plugin Name: Amazonia Vendor Shipping
 * Description: Gestión de envíos multivendedor estilo MercadoLibre sobre Envia + WCFM: cobro configurable (vendor absorbe / cliente paga / tarifa fija), deducción al ledger del vendor y guía de envío por vendedor.
 * Version: 0.1.0
 * Author: Amazonia
 * Requires PHP: 7.4
 * Text Domain: amazonia-vendor-shipping
 *
 * @package Amazonia_Vendor_Shipping
 */

defined( 'ABSPATH' ) || exit;

define( 'AVS_VERSION', '0.1.0' );
define( 'AVS_FILE', __FILE__ );
define( 'AVS_PATH', plugin_dir_path( __FILE__ ) );
define( 'AVS_URL', plugin_dir_url( __FILE__ ) );

// Lógica pura, disponible siempre que el plugin esté cargado (sin depender de hooks).
require_once AVS_PATH . 'includes/avs-functions.php';

/**
 * Contenedor principal del plugin (singleton).
 */
final class Amazonia_Vendor_Shipping {

	/** @var Amazonia_Vendor_Shipping|null */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'plugins_loaded', array( $this, 'load' ), 20 );
	}

	/**
	 * Carga los módulos de cada fase. Se van habilitando conforme se implementan.
	 */
	public function load() {
		require_once AVS_PATH . 'includes/class-avs-config.php';
		AVS_Config::init();

		require_once AVS_PATH . 'includes/class-avs-origin.php';
		AVS_Origin::init();

		require_once AVS_PATH . 'includes/class-avs-quote.php';
		AVS_Quote::init();

		require_once AVS_PATH . 'includes/class-avs-checkout.php';
		AVS_Checkout::init();

		require_once AVS_PATH . 'includes/class-avs-ledger.php';
		AVS_Ledger::init();

		require_once AVS_PATH . 'includes/class-avs-label.php';
		AVS_Label::init();

		require_once AVS_PATH . 'includes/class-avs-guide.php';
		AVS_Guide::init();

		require_once AVS_PATH . 'includes/class-avs-validation.php';
		AVS_Validation::init();

		do_action( 'avs_loaded' );
	}
}

Amazonia_Vendor_Shipping::instance();
