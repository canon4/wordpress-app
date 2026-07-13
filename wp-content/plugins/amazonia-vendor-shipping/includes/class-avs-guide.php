<?php
/**
 * Botón de guía en el detalle de pedido del dashboard del vendedor.
 *
 * Fase 6: el vendedor genera la guía por API (sin iniciar sesión en Envia) y la descarga como PDF.
 * Si el pedido ya tiene guía → botón "Descargar guía (PDF)" + tracking.
 * Si no → botón "Generar guía" (acción deliberada; llama a AVS_Label vía REST).
 *
 * Sin modificar el core de WCFM: engancha por hooks. Solo en pedidos del vendedor que usaron Envia.
 *
 * @package Amazonia_Vendor_Shipping
 */

defined( 'ABSPATH' ) || exit;

class AVS_Guide {

	/** @var bool Si se renderizó algún botón, encolar CSS/JS en el footer. */
	private static $needs_assets = false;

	public static function init() {
		add_action( 'wcfm_after_order_quick_actions', array( __CLASS__, 'render_button' ), 20, 1 );
		add_action( 'wp_footer', array( __CLASS__, 'render_assets' ), 50 );
	}

	/**
	 * El pedido tiene al menos un método de envío de Envia (no aplica a recogida local, etc.).
	 */
	private static function order_uses_envia( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return false;
		}
		foreach ( $order->get_items( 'shipping' ) as $item ) {
			if ( 'envia_shipping' === $item->get_method_id() ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * El usuario actual puede ver/gestionar la guía de este pedido.
	 * Vendedores: solo sus pedidos (aislamiento de WCFM). Admin: cualquiera.
	 */
	public static function can_view( $order_id ) {
		$order_id = absint( $order_id );
		if ( ! $order_id ) {
			return false;
		}
		if ( current_user_can( 'manage_woocommerce' ) ) {
			return true;
		}
		if ( function_exists( 'wcfm_is_vendor' ) && wcfm_is_vendor() ) {
			return ! function_exists( 'wcfm_is_order_for_vendor' ) || wcfm_is_order_for_vendor( $order_id );
		}
		return false;
	}

	/**
	 * Renderiza el botón (descargar o generar) en las acciones rápidas del pedido.
	 *
	 * @param int $order_id
	 */
	public static function render_button( $order_id ) {
		$order_id = absint( $order_id );
		if ( ! self::can_view( $order_id ) || ! self::order_uses_envia( $order_id ) ) {
			return;
		}
		self::$needs_assets = true;

		if ( class_exists( 'AVS_Label' ) && AVS_Label::has_label( $order_id ) ) {
			$url      = AVS_Label::get_label_url( $order_id );
			$tracking = AVS_Label::get_tracking( $order_id );
			printf(
				'<a href="%s" target="_blank" rel="noopener" class="button avs-label-download">%s</a>',
				esc_url( $url ),
				esc_html__( 'Descargar guía (PDF)', 'amazonia-vendor-shipping' )
			);
			if ( $tracking ) {
				printf( ' <span class="avs-label-tracking">%s %s</span>', esc_html__( 'Tracking:', 'amazonia-vendor-shipping' ), esc_html( $tracking ) );
			}
			return;
		}

		printf(
			'<a href="#" class="button avs-label-generate" data-order="%1$d">%2$s</a> <span class="avs-label-status" data-order="%1$d"></span>',
			$order_id,
			esc_html__( 'Generar guía', 'amazonia-vendor-shipping' )
		);
	}

	/**
	 * CSS + JS del botón (una sola vez, si se renderizó algo).
	 */
	public static function render_assets() {
		if ( ! self::$needs_assets ) {
			return;
		}
		$cfg = array(
			'url'           => esc_url_raw( rest_url( 'avs/v1/generate-label' ) ),
			'nonce'         => wp_create_nonce( 'wp_rest' ),
			'confirm'       => __( '¿Generar la guía de envío? Esto crea un envío real con la transportadora y consume saldo de la cuenta de Envia.', 'amazonia-vendor-shipping' ),
			'generating'    => __( 'Generando…', 'amazonia-vendor-shipping' ),
			'retry'         => __( 'Generar guía', 'amazonia-vendor-shipping' ),
			'download'      => __( 'Descargar guía (PDF)', 'amazonia-vendor-shipping' ),
			'trackingLabel' => __( 'Tracking:', 'amazonia-vendor-shipping' ),
			'neterr'        => __( 'Error de red al generar la guía.', 'amazonia-vendor-shipping' ),
		);
		?>
		<style>
			.avs-label-generate,.avs-label-download{margin-left:6px}
			.avs-label-status{margin-left:8px;color:#b32d2e;font-size:12px}
			.avs-label-tracking{margin-left:8px;color:#227122;font-size:12px}
		</style>
		<script>
		( function () {
			var CFG = <?php echo wp_json_encode( $cfg ); ?>;
			document.addEventListener( 'click', function ( e ) {
				var btn = e.target.closest( '.avs-label-generate' );
				if ( ! btn ) { return; }
				e.preventDefault();
				if ( ! window.confirm( CFG.confirm ) ) { return; }
				var order  = btn.getAttribute( 'data-order' );
				var status = document.querySelector( '.avs-label-status[data-order="' + order + '"]' );
				if ( status ) { status.textContent = ''; }
				btn.style.pointerEvents = 'none';
				btn.textContent = CFG.generating;
				fetch( CFG.url, {
					method: 'POST',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': CFG.nonce },
					body: JSON.stringify( { order_id: parseInt( order, 10 ) } )
				} )
				.then( function ( r ) { return r.json(); } )
				.then( function ( d ) {
					if ( d && d.ok ) {
						var html = '<a href="' + d.label + '" target="_blank" rel="noopener" class="button avs-label-download">' + CFG.download + '</a>';
						if ( d.tracking ) { html += ' <span class="avs-label-tracking">' + CFG.trackingLabel + ' ' + d.tracking + '</span>'; }
						btn.outerHTML = html;
					} else {
						btn.style.pointerEvents = '';
						btn.textContent = CFG.retry;
						if ( status ) { status.textContent = ( d && d.message ) ? d.message : 'Error'; }
					}
				} )
				.catch( function () {
					btn.style.pointerEvents = '';
					btn.textContent = CFG.retry;
					if ( status ) { status.textContent = CFG.neterr; }
				} );
			} );
		} )();
		</script>
		<?php
	}
}
