<?php 
namespace Envia\Classes\Module;

defined( 'ABSPATH' ) || exit;
trait Envia_Templates {
	public static function templates_action() {
		add_action( 'admin_menu', array( static::class, 'add_submenu_option' ) );
		add_action( 'admin_head', array( static::class, 'open_manager_in_new_tab' ) );
	}

	/**
	 * Hace que el enlace del gestor de ordenes de Envia se abra en una pestana
	 * nueva (mismo comportamiento que WCFM), sin sacar al usuario del wp-admin actual.
	 */
	public static function open_manager_in_new_tab() {
		?>
		<script>
		document.addEventListener( 'DOMContentLoaded', function() {
			var links = document.querySelectorAll( '#adminmenu a[href*="page=envia-order-manager"]' );
			links.forEach( function( link ) {
				link.setAttribute( 'target', '_blank' );
				link.setAttribute( 'rel', 'noopener' );
			} );
		} );
		</script>
		<?php
	}

	public static function print_envia_page() {
		if ( is_admin() ) {
			if ( current_user_can( 'manage_options' ) && current_user_can( 'manage_woocommerce' ) ) {
				$companyId = isset( get_option( 'woocommerce_envia_shipping_settings' )['company'] ) ? get_option( 'woocommerce_envia_shipping_settings' )['company'] : null;
				$storeId = isset( get_option( 'woocommerce_envia_shipping_settings' )['shop'] ) ? get_option( 'woocommerce_envia_shipping_settings' )['shop'] : null;
				$storeUrl = site_url();
				if ( ! is_null( $storeId ) && ! is_null( $storeUrl ) ) {
					$args = array(
						'status' => 'true',
						'hash' => base64_encode( $storeUrl . ':' . $companyId . ':' . $storeId ),
						'enviaUrl' => 'https://shipping.envia.com/ecommerce',
					);
				} else {
					$args = array( 
						'status' => 'false',
						'message' => 'Start to use Envia.com in your store admin.',
					);
				}
			} else {
				$args = array( 
					'status' => 'unauthorized',
					'message' => 'Your user account does not have permission to display this page.',
				);
			}
			self::envia_template_part('envia', 'display', $args);
		}
	}

	public static function envia_template_part( $slug, $name = null, $args = array() ) {
		do_action( "get_template_part_{$slug}", $slug, $name, $args );

		$templates = array();
		$name      = (string) $name;
		if ( '' !== $name ) {
			$templates[] = "{$slug}-{$name}.php";
		}

		$templates[] = "{$slug}.php";
		self::envia_template_loader( $templates, $args );
	}

	public static function envia_template_loader( $templates, $args ) {
		$toLoad = null;
		foreach ( $templates as $template ) {
			if ( file_exists( \Enviacom::MDABSPATH . '/templates/' . $template ) ) {
				$toLoad = \Enviacom::MDABSPATH . '/templates/' . $template;
			}
		}
		if ( ! is_null( $toLoad ) ) {
			load_template( $toLoad, false, $args );
		}

		$css = array(
			'ordersAdmin' => array(
				'file' => 'orders-admin',
				'version' => '1.7',
			),
		'configOauthAdmin' => array( 
			'file' => 'config-oauth-admin',
			'version' => '1.6',
		),
		);
		self::orders_css_loader( $css );
	}
	
	public static function orders_css_loader( $files = array() ) {
		if ( 0 == count( $files ) ) {
						return 0;
		}
		foreach ( $files as $handle => $css ) {
			if ( file_exists( \Enviacom::MDABSPATH . '/admin/css/envia-' . $css['file'] . '.css' ) ) {
				$toLoad = plugins_url( '../../admin/css/envia-' . $css['file'] . '.css', __FILE__ );
				wp_enqueue_style( $handle, $toLoad, array(), $css['version'], false );
			}
		}
	}

	public static function add_submenu_option() {
		add_submenu_page(
			'woocommerce',
			__( 'Envia.com', 'envia-shipping' ),
			__( 'Envia.com', 'envia-shipping' ),
			'manage_woocommerce',
			'envia-order-manager',
			array( static::class, 'print_envia_page' )
		);
	}

	public function load_admin_settings($system) {
		?>
			<div class = 'modal-envia hidden' id= 'modal-envia-oauth'>
				<div class ='saving-block' id = 'saving-oauth'>
					<div class="lds-dual-ring">
						<svg id = 'check-oauth' class="animated-check" viewBox="0 0 24 24"><path d="M4.1 12.7L9 17.6 20.3 6.3" fill="none"></path> </svg>
					</div>
					<p>Waiting to Envia.com</p>
				</div>
			</div>
			<div class='envia-top-box'>
				<div class='title-top-box'>
					<h2><?php echo esc_html( $this->method_title ); ?></h2>
					<div class='img-title'>
						<img height="30" src=<?php echo '../wp-content/plugins/' . esc_html( basename( \Enviacom::MDABSPATH )  ) . '/admin/images/envia-logo-dark.svg'; ?> >
					</div>
				</div>
				<div class='info-top-box'>
					<div class = 'description-text'>
						<p><?php echo esc_html( $this->method_description ); ?></p>
					</div>
					<div class='info-connection-store'>
						<div id = 'about'> About your store </div>
						<div class = 'info-connection info-data'>
							<div class = 'data-value'>
								<span>Version:</span>
								<?php echo esc_html(\Enviacom::get_current_version()); ?>
							</div>	
							<div class = 'data-value'>
								<span>Origin:</span>
								<?php echo esc_html($this->origin); ?>
							</div>
							<div class = 'data-value'>
								<span>EnviaID:</span>
								<?php echo esc_html($this->get_option( 'company' )); ?>
							</div>
							<div class = 'data-value'>
								<span>StoreID:</span>
								<?php echo esc_html($this->get_option( 'shop' )); ?>
							</div>
							<div class = 'data-value'>
								<span>PHP:</span>
								<?php echo esc_html($system["php"]); ?>
							</div>
							<div class = 'data-value'>
								<span>WC:</span>
								<?php echo esc_html($system["woocommerce"]); ?>
							</div>
							<div class = 'data-value'>
								<span>WP:</span>
								<?php echo esc_html($system["wordpress"]); ?>
							</div>
						</div>
					</div>
				</div>
			</div>
			<div class='envia-sub'>
				<p> Connection </p>
				<hr>
			</div>

			<div class='envia-connection'  >
				<div id='div-envia-oauth' class = 'connection-block'>
					<p>Connect your store</p>
					<input type='button' id ='envia_oauth' class='oauth-btn'>
				</div>
				<div class='connection-block'>
					<p>Connection status</p>
					<div id='status-label'>
						<div id='status-icon'></div>
						<label id='status-msj'></label>
						<!-- <a href=''>Something wrong?</a> -->
					</div>
				</div>
			</div>

		<table class="form-table">
			<?php $this->generate_settings_html(); ?>
		</table>
			<div class = 'modal-envia hidden' id= 'modal-envia'>
				<?php
				$nonce = wp_create_nonce( 'my-nonce' );
				include_once \Enviacom::MDABSPATH . '/includes/forms/create-address.php'; 
				?>
				<div class ='saving-block' id='saving-origin'>
					<div class="lds-dual-ring">
						<svg id = 'check-origin' class="animated-check" viewBox="0 0 24 24"><path d="M4.1 12.7L9 17.6 20.3 6.3" fill="none"></path> </svg>
					</div>
					<p>Sending</p>
				</div>
			</div>
			<div>
				<div class='custom-option'>
					<p>Create a new origin address</p>
					<button type= 'button' class='custom-option-btn open-option envia-open-origin'>Add origin address</button>
				</div>
			</div>
			<p class="envia-settings-note">
				<span class="not-msj">* Not available: </span>
				<?php esc_html_e( 'This option is not compatible with your current cart/checkout template configuration.', 'envia-shipping' ); ?>
			</p>
			<?php
	}
}
