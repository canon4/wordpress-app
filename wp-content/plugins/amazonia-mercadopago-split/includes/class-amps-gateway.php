<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class AMPS_Gateway extends WC_Payment_Gateway {

    public function __construct() {
        $this->id                 = 'amazonia_mp_split';
        $this->method_title       = 'Mercado Pago Split (Amazonia)';
        $this->method_description = 'Pagos con split automático: el dinero llega directamente a la cuenta MP del vendedor.';
        $this->has_fields         = false;
        $this->supports           = [ 'products' ];

        $this->init_form_fields();
        $this->init_settings();

        $this->title       = $this->get_option( 'title', 'Mercado Pago' );
        $this->description = $this->get_option( 'description', 'Paga de forma segura con Mercado Pago.' );
        $this->enabled     = $this->get_option( 'enabled' );

        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [ $this, 'process_admin_options' ] );
    }

    public function init_form_fields() {
        $this->form_fields = [
            'enabled' => [
                'title'   => 'Activar/desactivar',
                'type'    => 'checkbox',
                'label'   => 'Activar Mercado Pago Split',
                'default' => 'yes',
            ],
            'title' => [
                'title'   => 'Título',
                'type'    => 'text',
                'default' => 'Mercado Pago',
                'desc_tip' => true,
                'description' => 'Título que el cliente ve en el checkout.',
            ],
            'description' => [
                'title'   => 'Descripción',
                'type'    => 'textarea',
                'default' => 'Paga de forma segura con Mercado Pago. Serás redirigido al sitio de Mercado Pago para completar el pago.',
            ],
        ];
    }

    /**
     * Punto de entrada de WooCommerce al confirmar el pedido.
     */
    public function process_payment( $order_id ) {
        $order = wc_get_order( $order_id );

        // Obtener el token a usar para crear la preferencia
        $access_token = $this->resolve_access_token( $order );

        if ( empty( $access_token ) ) {
            wc_add_notice( 'No hay cuenta de pago configurada. Contacta al administrador.', 'error' );
            return [ 'result' => 'failure' ];
        }

        // Construir y crear la preferencia en MP
        $vendor_id  = $this->get_order_primary_vendor( $order );
        $preference = $this->build_preference( $order, $vendor_id );
        $result     = $this->create_preference( $preference, $access_token );

        if ( is_wp_error( $result ) ) {
            wc_add_notice( 'Error al procesar el pago: ' . $result->get_error_message(), 'error' );
            return [ 'result' => 'failure' ];
        }

        // Guardar preference_id en el pedido para el webhook
        $order->update_meta_data( '_amps_preference_id', $result['id'] );
        $order->update_status( 'pending', 'Esperando confirmación de Mercado Pago.' );
        $order->save();

        $init_point = AMPS_Settings::is_sandbox()
            ? ( $result['sandbox_init_point'] ?? $result['init_point'] )
            : $result['init_point'];

        return [
            'result'   => 'success',
            'redirect' => $init_point,
        ];
    }

    /**
     * Devuelve el access_token a usar: primero el del vendedor del pedido,
     * si no tiene cuenta conectada usa el token del marketplace como fallback.
     */
    private function resolve_access_token( WC_Order $order ): string {
        // En sandbox usamos el token del marketplace para que el checkout funcione.
        // En producción se usa el token del vendedor para el split real.
        if ( AMPS_Settings::is_sandbox() ) {
            return AMPS_Settings::get_marketplace_token();
        }

        $vendor_id = $this->get_order_primary_vendor( $order );

        if ( $vendor_id && AMPS_OAuth::vendor_is_connected( $vendor_id ) ) {
            $token_data = AMPS_OAuth::get_vendor_token( $vendor_id );
            return $token_data['access_token'] ?? '';
        }

        return AMPS_Settings::get_marketplace_token();
    }

    /**
     * Devuelve el vendor_id del primer ítem del pedido.
     * En WCFM el autor del producto es el vendedor.
     */
    private function get_order_primary_vendor( WC_Order $order ): int {
        foreach ( $order->get_items() as $item ) {
            $product_id = $item->get_product_id();
            if ( $product_id ) {
                $vendor_id = intval( get_post_field( 'post_author', $product_id ) );
                if ( $vendor_id > 0 ) return $vendor_id;
            }
        }
        return 0;
    }

    /**
     * Construye el cuerpo de la preferencia de MP.
     */
    private function build_preference( WC_Order $order, int $vendor_id = 0 ): array {
        $items    = [];
        $currency = get_woocommerce_currency();

        foreach ( $order->get_items() as $item ) {
            $unit_price = floatval( $order->get_item_total( $item, false ) );
            if ( $unit_price <= 0 ) $unit_price = 0.01; // MP requiere > 0

            $items[] = [
                'id'          => (string) $item->get_product_id(),
                'title'       => mb_substr( $item->get_name(), 0, 256 ),
                'quantity'    => max( 1, $item->get_quantity() ),
                'unit_price'  => $unit_price,
                'currency_id' => $currency,
            ];
        }

        // Agregar envío como ítem separado si aplica
        if ( $order->get_shipping_total() > 0 ) {
            $items[] = [
                'id'          => 'shipping',
                'title'       => 'Envío',
                'quantity'    => 1,
                'unit_price'  => floatval( $order->get_shipping_total() ),
                'currency_id' => $currency,
            ];
        }

        $preference = [
            'items'              => $items,
            'payer'              => [
                'email'   => $order->get_billing_email(),
                'name'    => $order->get_billing_first_name(),
                'surname' => $order->get_billing_last_name(),
                'phone'   => [ 'number' => $order->get_billing_phone() ],
                'address' => [
                    'zip_code'    => $order->get_billing_postcode(),
                    'street_name' => trim( $order->get_billing_address_1() . ' ' . $order->get_billing_address_2() ),
                ],
            ],
            'back_urls'          => [
                'success' => $this->get_return_url( $order ),
                'failure' => $order->get_cancel_order_url_raw(),
                'pending' => $this->get_return_url( $order ),
            ],
            'auto_return'        => 'approved',
            'external_reference' => (string) $order->get_id(),
        ];

        // En producción aplicar split: marketplace_fee + campo marketplace.
        // En sandbox MP bloquea pagos split entre cuentas de prueba, se omite.
        if ( ! AMPS_Settings::is_sandbox() ) {
            $total      = floatval( $order->get_total() );
            $commission = AMPS_Settings::get_commission();
            $preference['marketplace_fee'] = round( $total * $commission / 100, 2 );

            if ( $vendor_id && AMPS_OAuth::vendor_is_connected( $vendor_id ) ) {
                $preference['marketplace'] = AMPS_Settings::get_app_id();
            }
        }

        return $preference;
    }

    /**
     * Llama a la API de MP para crear la preferencia.
     *
     * @return array|WP_Error
     */
    private function create_preference( array $preference, string $access_token ) {
        $response = wp_remote_post(
            AMPS_Settings::get_mp_api_url() . '/checkout/preferences',
            [
                'headers' => [
                    'Content-Type'  => 'application/json',
                    'Authorization' => 'Bearer ' . $access_token,
                ],
                'body'    => wp_json_encode( $preference ),
                'timeout' => 30,
            ]
        );

        if ( is_wp_error( $response ) ) {
            error_log( '[AMPS-GW] wp_remote_post error: ' . $response->get_error_message() );
            return $response;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( empty( $body['id'] ) ) {
            $msg = $body['message'] ?? ( $body['cause'][0]['description'] ?? 'Error desconocido de Mercado Pago.' );
            return new WP_Error( 'mp_preference_error', $msg );
        }

        return $body;
    }
}
