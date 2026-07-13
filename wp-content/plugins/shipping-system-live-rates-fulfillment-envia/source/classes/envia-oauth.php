<?php
namespace Envia\Classes\Module;

defined( 'ABSPATH' ) || exit;
trait Envia_Oauth { 
	public static function oauth_action() {
		add_action( 'admin_init', array( static::class, 'load' ) );
	}
	public static function load() {
		if ( is_plugin_active( plugin_basename( \Enviacom::MAINFILE ) ) ) {
			if ( ! get_option( 'envia_oauth_connection' ) ) {
				add_option( 'envia_oauth_connection', 'false' );
			}
		};
		$pages_allow_oauth = array(
			'wp-admin/plugins',
			'wp-admin/update.php?action=upload-plugin',
			'wp-admin/admin',
		);
		$self              = isset( $_SERVER['PHP_SELF'] ) ? sanitize_text_field( $_SERVER['PHP_SELF'] ) : null;
		self::scriptOauth(get_option( 'envia_oauth_connection' ));
	}

	public static function scriptOauth( $status ) {
		if ( 'false' == $status ) {
			update_option( 'envia_oauth_connection', 'wait' );
		}
		wp_enqueue_script( 'oauth', plugins_url( '../../admin/js/oauth.js', __FILE__ ), array(), '2.1', false );
		wp_localize_script(
			'oauth',
			'oauthData',
			array(
				'status'          => $status,
				'dirname'         => \Enviacom::MDABSPATH,
				'i18n'            => array(
					'notConnected'     => __( 'Your store is not connected yet.', 'envia-shipping' ),
					'connected'        => __( 'Your store is connected', 'envia-shipping' ),
					'connectBtn'       => __( 'Connect your store', 'envia-shipping' ),
					'refreshBtn'       => __( 'Refresh your connection', 'envia-shipping' ),
				),
			)
		);
	}

}
