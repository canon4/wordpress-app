<?php
namespace Envia\Classes\Module;

defined( 'ABSPATH' ) || exit;
trait Envia_Orders {
	public static function orders_action() {
		add_filter( 'bulk_actions-woocommerce_page_wc-orders', array( static::class, 'print_envia_bulk_action' ) );
	
		add_filter( 'bulk_actions-edit-shop_order', array( static::class, 'print_envia_bulk_action' ) );

		add_filter( 'handle_bulk_actions-woocommerce_page_wc-orders', array( static::class, 'send_to_envia_order_manager' ), 10, 3 );

		add_filter( 'handle_bulk_actions-edit-shop_order', array( static::class, 'send_to_envia_order_manager' ), 10, 3 );

		add_action( 'add_meta_boxes', array( static::class, 'print_envia_meta_box' ), 4, 2 );
	}

	public static function print_envia_bulk_action( $actions ) {
		$actions['send_to_envia'] = __( 'Shipping actions - Envia.com', 'envia-shipping' );
		return $actions;
	}

	public static function send_to_envia_order_manager( $redirect_to, $action, $post_ids ) { 
		if ( 'send_to_envia' !== $action ) {
			return $redirect_to; // Exit
		}
		$ids = '';
		foreach ( $post_ids as $key => $orderId ) {
			$ids .= count( $post_ids ) == $key + 1 ? $orderId  : $orderId . ',';
		}
		$redirect_to = '/wp-admin/admin.php?page=envia-order-manager&ids=' . $ids;
		return $redirect_to;
	}

	public static function print_envia_meta_box( $screen_id, $post ) {
		$orderStatus = method_exists( $post, 'get_status' ) ? $post->get_status() : $post->post_status;
		if ( self::validate_order_status( $orderStatus ) ) {
			$orderId = method_exists( $post, 'get_id' ) ? $post->get_id() : $post->ID ;
			$allowScreens = array( 'shop_order', 'woocommerce_page_wc-orders' );
			foreach ( $allowScreens as $screenId ) {
				if ( $screen_id == $screenId ) {
					add_meta_box(
						'envia-order-manager',
						__( 'Generate or quote a new shipping label', 'envia-shipping' ),
						array( __CLASS__, 'render_envia_meta_box' ),
						$screen_id,
						'normal',
						'high',
						array( 'orderId' => $orderId )
					);
				}
			}
		}
	}

	public static function validate_order_status( $status ) {
		$notStatus = array( 'completed', 'cancelled' );
		foreach ( $notStatus as $value ) {
			if ( stripos( $status, $value ) !== false ) {
				return false;
			}
		}
		return true;
	}

	public static function render_envia_meta_box( $post, $args ) {
		echo "<div class = 'envia-meta-box'>
			<div class = 'meta-envia-container'>
				<img height ='30px' src='" . esc_url( \Enviacom::ENVIA_S3 ) . "/uploads/images/logo-enviapaqueteria.png' alt='envia.com'>
				<p>
				<b>The order is ready to generate a quote and be shipping</b>
				</p>
			</div>
			<div class = 'meta-envia-button'>
				<a id = 'envia-quote-shipping' target='_blank' rel='noopener' href='/wp-admin/admin.php?page=envia-order-manager&id=" . esc_html( $args['args']['orderId'] ) . "'> Quote and shipping </a>
			</div>
		</div>" . PHP_EOL;
		wp_enqueue_script( 'orderBox', plugins_url( '../../admin/js/enviaOrders.js', __FILE__ ), array(), '1.2', false );
	}
}
