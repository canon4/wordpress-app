<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class AMPS_Webhook {

    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );
    }

    public function register_routes() {
        register_rest_route( 'amazonia-mp/v1', '/webhook', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'handle' ],
            'permission_callback' => '__return_true',
        ] );
    }

    /**
     * Recibe la notificación IPN/webhook de Mercado Pago y actualiza el pedido.
     */
    public function handle( WP_REST_Request $request ) {
        $topic = $request->get_param( 'topic' ) ?? $request->get_param( 'type' );
        $id    = $request->get_param( 'id' ) ?? $request->get_param( 'data_id' );

        // MP envía dos formatos: IPN (?topic=payment&id=123) y webhooks (?type=payment, body.data.id)
        if ( 'payment' !== $topic ) {
            return new WP_REST_Response( [ 'status' => 'ignored' ], 200 );
        }

        if ( empty( $id ) ) {
            $body = $request->get_json_params();
            $id   = $body['data']['id'] ?? null;
        }

        if ( empty( $id ) ) {
            return new WP_REST_Response( [ 'status' => 'no_id' ], 400 );
        }

        $payment = $this->fetch_payment( intval( $id ) );

        if ( is_wp_error( $payment ) ) {
            return new WP_REST_Response( [ 'status' => 'error', 'msg' => $payment->get_error_message() ], 200 );
        }

        $order_id = intval( $payment['external_reference'] ?? 0 );
        $order    = $order_id ? wc_get_order( $order_id ) : null;

        if ( ! $order ) {
            return new WP_REST_Response( [ 'status' => 'order_not_found' ], 200 );
        }

        $this->update_order_status( $order, $payment['status'], $payment['status_detail'] ?? '' );

        return new WP_REST_Response( [ 'status' => 'ok' ], 200 );
    }

    /**
     * Consulta el pago en la API de MP usando el token del marketplace.
     *
     * @return array|WP_Error
     */
    private function fetch_payment( int $payment_id ) {
        $response = wp_remote_get(
            AMPS_Settings::get_mp_api_url() . '/v1/payments/' . $payment_id,
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . AMPS_Settings::get_marketplace_token(),
                ],
                'timeout' => 20,
            ]
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( empty( $body['id'] ) ) {
            return new WP_Error( 'mp_payment_error', $body['message'] ?? 'No se pudo obtener el pago.' );
        }

        return $body;
    }

    /**
     * Mapea los estados de MP a estados de WooCommerce y actualiza el pedido.
     */
    private function update_order_status( WC_Order $order, string $mp_status, string $detail ) {
        // Evitar procesar si ya está completado o cancelado
        if ( in_array( $order->get_status(), [ 'completed', 'cancelled', 'refunded' ], true ) ) {
            return;
        }

        switch ( $mp_status ) {
            case 'approved':
                $order->payment_complete();
                $order->add_order_note( 'Pago aprobado por Mercado Pago. Detalle: ' . $detail );
                break;

            case 'pending':
            case 'in_process':
                $order->update_status( 'on-hold', 'Pago pendiente en Mercado Pago. Detalle: ' . $detail );
                break;

            case 'rejected':
                $order->update_status( 'failed', 'Pago rechazado por Mercado Pago. Detalle: ' . $detail );
                break;

            case 'cancelled':
            case 'refunded':
            case 'charged_back':
                $order->update_status( 'cancelled', 'Pago cancelado/devuelto en Mercado Pago.' );
                break;
        }
    }
}
