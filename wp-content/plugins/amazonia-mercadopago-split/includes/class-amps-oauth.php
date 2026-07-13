<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class AMPS_OAuth {

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
        // MP redirige aquí con el código de autorización
        register_rest_route( 'amazonia-mp/v1', '/oauth/callback', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'handle_callback' ],
            'permission_callback' => '__return_true',
        ] );

        // Desconectar cuenta MP del vendedor
        register_rest_route( 'amazonia-mp/v1', '/oauth/disconnect', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'handle_disconnect' ],
            'permission_callback' => [ $this, 'vendor_permission' ],
        ] );
    }

    public function vendor_permission() {
        return is_user_logged_in() && current_user_can( 'wcfm_vendor' );
    }

    /**
     * Genera la URL de OAuth de MP y redirige al vendedor.
     */
    public function handle_connect( WP_REST_Request $request ) {
        $vendor_id = get_current_user_id();
        $nonce     = wp_create_nonce( 'amps_oauth_' . $vendor_id );
        $state     = $vendor_id . '_' . $nonce;

        $auth_url = add_query_arg( [
            'client_id'     => AMPS_Settings::get_app_id(),
            'response_type' => 'code',
            'platform_id'   => 'mp',
            'state'         => $state,
            'redirect_uri'  => AMPS_Settings::get_callback_url(),
        ], AMPS_Settings::get_mp_auth_url() );

        wp_redirect( $auth_url );
        exit;
    }

    /**
     * Recibe el código de MP, lo intercambia por access_token y lo guarda.
     */
    public function handle_callback( WP_REST_Request $request ) {
        $code  = sanitize_text_field( $request->get_param( 'code' ) );
        $state = sanitize_text_field( $request->get_param( 'state' ) );
        $error = $request->get_param( 'error' );

        // El vendedor rechazó la autorización
        if ( $error ) {
            $this->redirect_to_settings( 'error', 'Autorización cancelada. Intenta de nuevo.' );
            return;
        }

        if ( empty( $code ) || empty( $state ) ) {
            $this->redirect_to_settings( 'error', 'Parámetros inválidos en el callback.' );
            return;
        }

        // Validar state contra transient — no requiere sesión de usuario en el callback
        $vendor_id = intval( get_transient( 'amps_oauth_' . $state ) );
        if ( ! $vendor_id ) {
            $this->redirect_to_settings( 'error', 'Token de seguridad inválido o expirado. Intenta conectar de nuevo.' );
            return;
        }
        delete_transient( 'amps_oauth_' . $state );

        // Intercambiar código por access_token
        $token_data = $this->exchange_code( $code );

        if ( is_wp_error( $token_data ) ) {
            $this->redirect_to_settings( 'error', 'Error al obtener el token: ' . $token_data->get_error_message() );
            return;
        }

        // Guardar token en el perfil del vendedor
        $this->save_vendor_token( $vendor_id, $token_data );

        $this->redirect_to_settings( 'success', '¡Cuenta de Mercado Pago conectada exitosamente!' );
    }

    /**
     * Elimina el token MP del vendedor.
     */
    public function handle_disconnect( WP_REST_Request $request ) {
        $vendor_id = get_current_user_id();
        $this->delete_vendor_token( $vendor_id );
        return new WP_REST_Response( [ 'success' => true ], 200 );
    }

    /**
     * Intercambia el código de autorización por access_token con la API de MP.
     */
    private function exchange_code( string $code ) {
        $response = wp_remote_post(
            AMPS_Settings::get_mp_api_url() . '/oauth/token',
            [
                'headers' => [ 'Content-Type' => 'application/json' ],
                'body'    => wp_json_encode( [
                    'client_id'     => AMPS_Settings::get_app_id(),
                    'client_secret' => AMPS_Settings::get_client_secret(),
                    'code'          => $code,
                    'grant_type'    => 'authorization_code',
                    'redirect_uri'  => AMPS_Settings::get_callback_url(),
                    'test_token'    => AMPS_Settings::is_sandbox(),
                ] ),
                'timeout' => 30,
            ]
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( empty( $body['access_token'] ) ) {
            $msg = $body['message'] ?? 'Respuesta inesperada de Mercado Pago.';
            return new WP_Error( 'mp_token_error', $msg );
        }

        return $body;
    }

    /**
     * Guarda el access_token en wcfmmp_profile_settings del vendedor.
     * Reutiliza la estructura existente de WCFM — no crea campos nuevos.
     */
    public static function save_vendor_token( int $vendor_id, array $token_data ) {
        $profile = get_user_meta( $vendor_id, 'wcfmmp_profile_settings', true );
        if ( ! is_array( $profile ) ) $profile = [];

        $profile['payment']['mercado_pago'] = [
            'access_token'  => sanitize_text_field( $token_data['access_token'] ),
            'mp_user_id'    => intval( $token_data['user_id'] ?? 0 ),
            'refresh_token' => sanitize_text_field( $token_data['refresh_token'] ?? '' ),
            'token_expires' => time() + intval( $token_data['expires_in'] ?? 15552000 ),
            'is_sandbox'    => AMPS_Settings::is_sandbox(),
            'scope'         => sanitize_text_field( $token_data['scope'] ?? '' ),
        ];

        update_user_meta( $vendor_id, 'wcfmmp_profile_settings', $profile );
    }

    public static function get_vendor_token( int $vendor_id ): ?array {
        $profile = get_user_meta( $vendor_id, 'wcfmmp_profile_settings', true );
        return $profile['payment']['mercado_pago'] ?? null;
    }

    public static function vendor_is_connected( int $vendor_id ): bool {
        $token = self::get_vendor_token( $vendor_id );
        if ( empty( $token['access_token'] ) ) return false;

        // Verificar que el token no haya expirado
        if ( ! empty( $token['token_expires'] ) && $token['token_expires'] < time() ) {
            return false;
        }

        return true;
    }

    private function delete_vendor_token( int $vendor_id ) {
        $profile = get_user_meta( $vendor_id, 'wcfmmp_profile_settings', true );
        if ( is_array( $profile ) && isset( $profile['payment']['mercado_pago'] ) ) {
            unset( $profile['payment']['mercado_pago'] );
            update_user_meta( $vendor_id, 'wcfmmp_profile_settings', $profile );
        }
    }

    private function redirect_to_settings( string $status, string $message ) {
        $settings_url = add_query_arg(
            [ 'amps_status' => $status, 'amps_msg' => urlencode( $message ) ],
            function_exists( 'get_wcfm_settings_url' ) ? get_wcfm_settings_url() : admin_url()
        );
        wp_redirect( $settings_url . '#wcfm_settings_form_payment_head' );
        exit;
    }
}
