<?php
/**
 * Restricción de Carrito Mono-tienda (Single Vendor Cart)
 *
 * Garantiza que cada orden pertenezca a una única familia/tienda,
 * permitiendo pagos directos y despachos geográficos independientes.
 *
 * @package Amazonia_MP_Split
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class AMPS_Cart {

    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Validar adición de productos al carrito
        add_filter( 'woocommerce_add_to_cart_validation', [ $this, 'validate_single_vendor' ], 10, 5 );

        // Procesar acción de vaciar carrito y cambiar a nueva tienda
        add_action( 'template_redirect', [ $this, 'handle_switch_vendor' ] );

        // Validación en el checkout (red de seguridad)
        add_action( 'woocommerce_check_cart_items', [ $this, 'validate_cart_items_on_checkout' ] );
    }

    /**
     * Obtiene el vendor_id asociado a un producto.
     */
    public static function get_product_vendor_id( $product_id ) {
        if ( function_exists( 'wcfm_get_vendor_id_by_post' ) ) {
            $vendor_id = wcfm_get_vendor_id_by_post( $product_id );
            if ( $vendor_id ) return (int) $vendor_id;
        }
        return (int) get_post_field( 'post_author', $product_id );
    }

    /**
     * Obtiene el nombre público de la tienda o familia.
     */
    public static function get_store_name( $vendor_id ) {
        if ( function_exists( 'wcfmmp_get_store_info' ) ) {
            $info = wcfmmp_get_store_info( $vendor_id );
            if ( ! empty( $info['store_name'] ) ) {
                return $info['store_name'];
            }
        }
        $user = get_userdata( $vendor_id );
        return $user ? $user->display_name : 'otra tienda';
    }

    /**
     * Obtiene el vendor_id de los ítems actualmente en el carrito.
     * Retorna 0 si el carrito está vacío.
     */
    public static function get_cart_vendor_id() {
        if ( ! WC()->cart || WC()->cart->is_empty() ) {
            return 0;
        }

        foreach ( WC()->cart->get_cart() as $cart_item ) {
            $product_id = $cart_item['product_id'];
            $vendor_id  = self::get_product_vendor_id( $product_id );
            if ( $vendor_id > 0 ) {
                return $vendor_id;
            }
        }

        return 0;
    }

    /**
     * Valida que no se mezclen productos de distintos vendedores.
     */
    public function validate_single_vendor( $passed, $product_id, $quantity, $variation_id = 0, $variations = null ) {
        if ( ! WC()->cart || WC()->cart->is_empty() ) {
            return $passed;
        }

        $cart_vendor_id = self::get_cart_vendor_id();
        $new_vendor_id  = self::get_product_vendor_id( $product_id );

        // Si alguno no tiene vendedor o son el mismo, permitir
        if ( ! $cart_vendor_id || ! $new_vendor_id || $cart_vendor_id === $new_vendor_id ) {
            return $passed;
        }

        // Vendedores diferentes: bloquear y mostrar mensaje explicativo
        $current_store_name = esc_html( self::get_store_name( $cart_vendor_id ) );
        $new_store_name     = esc_html( self::get_store_name( $new_vendor_id ) );

        $switch_url = add_query_arg( [
            'amps_switch_vendor' => $product_id,
            'amps_qty'           => max( 1, intval( $quantity ) ),
            'amps_variation_id'  => intval( $variation_id ),
            '_amps_nonce'        => wp_create_nonce( 'amps_switch_' . $product_id ),
        ], wc_get_cart_url() );

        $message = sprintf(
            '<div class="amps-cart-notice">' .
            '<p><strong>Comercio directo y de origen:</strong> Cada producto proviene directamente del territorio de una familia o comunidad productora. Por razones geográficas y de despacho independiente, cada compra se gestiona por separado.</p>' .
            '<p>Actualmente tienes en tu carrito productos de <strong>%s</strong>.</p>' .
            '<p style="margin-top: 10px;">' .
            '<a href="%s" class="button" style="margin-right: 10px; background: #009ee3; color: #fff; border: none; padding: 8px 14px; border-radius: 4px; text-decoration: none; font-weight: bold; display: inline-block;">Vaciar carrito y comprar a %s</a> ' .
            '<a href="%s" class="button" style="background: #e0e0e0; color: #333; border: none; padding: 8px 14px; border-radius: 4px; text-decoration: none; display: inline-block;">Conservar productos actuales</a>' .
            '</p>' .
            '</div>',
            $current_store_name,
            esc_url( $switch_url ),
            $new_store_name,
            esc_url( wc_get_cart_url() )
        );

        wc_add_notice( $message, 'error' );
        return false;
    }

    /**
     * Procesa la solicitud del usuario para vaciar el carrito y agregar el producto de la nueva tienda.
     */
    public function handle_switch_vendor() {
        if ( ! isset( $_GET['amps_switch_vendor'] ) || ! isset( $_GET['_amps_nonce'] ) ) {
            return;
        }

        $product_id   = absint( $_GET['amps_switch_vendor'] );
        $quantity     = isset( $_GET['amps_qty'] ) ? max( 1, absint( $_GET['amps_qty'] ) ) : 1;
        $variation_id = isset( $_GET['amps_variation_id'] ) ? absint( $_GET['amps_variation_id'] ) : 0;
        $nonce        = sanitize_text_field( $_GET['_amps_nonce'] );

        if ( ! wp_verify_nonce( $nonce, 'amps_switch_' . $product_id ) ) {
            wc_add_notice( 'Enlace de confirmación expirado. Por favor intenta de nuevo.', 'error' );
            wp_safe_redirect( wc_get_cart_url() );
            exit;
        }

        if ( WC()->cart ) {
            WC()->cart->empty_cart();
            WC()->cart->add_to_cart( $product_id, $quantity, $variation_id );

            $vendor_id  = self::get_product_vendor_id( $product_id );
            $store_name = esc_html( self::get_store_name( $vendor_id ) );

            wc_add_notice(
                sprintf( 'Se ha vaciado el carrito anterior. Ahora estás comprando a <strong>%s</strong>.', $store_name ),
                'success'
            );
        }

        wp_safe_redirect( wc_get_cart_url() );
        exit;
    }

    /**
     * Valida en el checkout que:
     * 1. No haya ítems de múltiples vendedores en el carrito.
     * 2. La tienda tenga cuenta de Mercado Pago conectada.
     */
    public function validate_cart_items_on_checkout() {
        if ( ! WC()->cart || WC()->cart->is_empty() ) {
            return;
        }

        $vendors = [];
        foreach ( WC()->cart->get_cart() as $cart_item ) {
            $v_id = self::get_product_vendor_id( $cart_item['product_id'] );
            if ( $v_id > 0 ) {
                $vendors[ $v_id ] = true;
            }
        }

        if ( count( $vendors ) > 1 ) {
            wc_add_notice(
                'Tu carrito contiene productos de más de una tienda o familia. Por razones de logística de origen directo, debes comprar a una tienda a la vez.',
                'error'
            );
        }

        // Validar que el vendedor tenga MP conectado si la pasarela activa es MP Split
        if ( count( $vendors ) === 1 ) {
            $vendor_id = array_key_first( $vendors );
            if ( $vendor_id && ! AMPS_Settings::is_sandbox() && ! AMPS_OAuth::vendor_is_connected( $vendor_id ) ) {
                $store_name = self::get_store_name( $vendor_id );
                wc_add_notice(
                    sprintf(
                        'La tienda <strong>%s</strong> aún no ha completado la vinculación de su cuenta de cobro en Mercado Pago. Por favor contacta a la tienda o al soporte de Amazonia.',
                        esc_html( $store_name )
                    ),
                    'error'
                );
            }
        }
    }
}
