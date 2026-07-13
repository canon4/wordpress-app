<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class AMPS_Vendor {

    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'wcfm_vendor_end_settings_payment', [ $this, 'render_mp_connect_section' ] );
        add_action( 'before_wcfm_marketplace_settings', [ $this, 'render_oauth_notice' ] );
    }

    /**
     * Sección "Mercado Pago Split" dentro del bloque de pago del vendedor.
     */
    public function render_mp_connect_section( $vendor_id ) {
        $is_connected = AMPS_OAuth::vendor_is_connected( $vendor_id );
        $token        = AMPS_OAuth::get_vendor_token( $vendor_id );

        // Generar la URL de OAuth con state basado en transient — no depende de sesión de usuario
        $state = wp_generate_password( 32, false );
        set_transient( 'amps_oauth_' . $state, $vendor_id, 10 * MINUTE_IN_SECONDS );

        $connect_url = add_query_arg( [
            'client_id'     => AMPS_Settings::get_app_id(),
            'response_type' => 'code',
            'platform_id'   => 'mp',
            'state'         => $state,
            'redirect_uri'  => AMPS_Settings::get_callback_url(),
        ], AMPS_Settings::get_mp_auth_url() );
        ?>
        <style><?php echo $this->get_inline_css(); ?></style>
        <div class="wcfm_clearfix"></div>
        <div class="amps-connect-section">
            <h3 class="amps-section-title">
                <img src="https://http2.mlstatic.com/frontend-assets/mp-web-navigation/ui-navigation/6.7.81/mercadopago/logo__large@2x.png"
                     alt="Mercado Pago" style="height:22px; vertical-align:middle; margin-right:8px;">
                Mercado Pago Split
            </h3>

            <?php if ( $is_connected ) : ?>
                <div class="amps-status amps-status--connected">
                    <span class="amps-dot"></span>
                    Cuenta conectada — ID: <strong><?php echo esc_html( $token['mp_user_id'] ); ?></strong>
                    <?php
                    $expires = $token['token_expires'] ?? 0;
                    if ( $expires ) {
                        $days = ceil( ( $expires - time() ) / DAY_IN_SECONDS );
                        echo '<small style="margin-left:10px;color:#777;">Token expira en ' . intval( $days ) . ' días</small>';
                    }
                    ?>
                </div>
                <p style="margin-top:10px;">
                    Los pagos de tus ventas llegarán directamente a tu cuenta de Mercado Pago.
                    La comisión de la plataforma se descuenta automáticamente.
                </p>
                <div style="margin-top:12px; display:flex; gap:10px; flex-wrap:wrap;">
                    <a href="<?php echo esc_url( $connect_url ); ?>" class="amps-btn amps-btn--secondary">
                        Reconectar cuenta
                    </a>
                    <button type="button" class="amps-btn amps-btn--danger" id="amps-disconnect-btn"
                            data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>"
                            data-url="<?php echo esc_url( rest_url( 'amazonia-mp/v1/oauth/disconnect' ) ); ?>">
                        Desconectar
                    </button>
                </div>
            <?php else : ?>
                <div class="amps-status amps-status--disconnected">
                    <span class="amps-dot"></span>
                    Sin cuenta conectada — tus ventas no tendrán pago split activado.
                </div>
                <p style="margin-top:10px;">
                    Conecta tu cuenta de Mercado Pago para recibir automáticamente el dinero de tus ventas,
                    descontando solo la comisión de la plataforma.
                </p>
                <div style="margin-top:12px;">
                    <a href="<?php echo esc_url( $connect_url ); ?>" class="amps-btn amps-btn--primary">
                        Conectar con Mercado Pago
                    </a>
                </div>
                <p style="margin-top:8px; font-size:12px; color:#777;">
                    Al conectar, Mercado Pago te pedirá autorizar a la plataforma Amazonia a procesar pagos en tu nombre.
                </p>
            <?php endif; ?>
        </div>

        <script>
        (function() {
            var btn = document.getElementById('amps-disconnect-btn');
            if (!btn) return;
            btn.addEventListener('click', function() {
                if (!confirm('¿Seguro que quieres desconectar tu cuenta de Mercado Pago?')) return;
                fetch(btn.dataset.url, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': btn.dataset.nonce
                    }
                }).then(function(r) {
                    if (r.ok) window.location.reload();
                });
            });
        })();
        </script>
        <?php
    }

    /**
     * Muestra el aviso de éxito o error tras el redirect OAuth.
     */
    public function render_oauth_notice( $vendor_id ) {
        $status = sanitize_text_field( $_GET['amps_status'] ?? '' );
        $msg    = sanitize_text_field( urldecode( $_GET['amps_msg'] ?? '' ) );

        if ( ! $status || ! $msg ) return;

        $type = $status === 'success' ? 'success' : 'error';
        echo '<div class="wcfm-message wcfm-' . esc_attr( $type ) . '">' . esc_html( $msg ) . '</div>';
    }

    private function get_inline_css() {
        return '
        .amps-connect-section {
            margin: 20px 0;
            padding: 20px;
            border: 1.5px solid #e0e0e0;
            border-radius: 8px;
            background: #fafafa;
        }
        .amps-section-title {
            margin: 0 0 14px;
            font-size: 15px;
            font-weight: 700;
            color: #333;
            display: flex;
            align-items: center;
        }
        .amps-status {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            padding: 8px 12px;
            border-radius: 6px;
        }
        .amps-status--connected {
            background: #d4edda;
            color: #155724;
        }
        .amps-status--disconnected {
            background: #fff3cd;
            color: #856404;
        }
        .amps-dot {
            width: 8px; height: 8px;
            border-radius: 50%;
            flex-shrink: 0;
        }
        .amps-status--connected .amps-dot { background: #28a745; }
        .amps-status--disconnected .amps-dot { background: #ffc107; }
        .amps-btn {
            display: inline-block;
            padding: 9px 18px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            border: none;
        }
        .amps-btn--primary { background: #009ee3; color: #fff; }
        .amps-btn--primary:hover { background: #007dc3; color: #fff; }
        .amps-btn--secondary { background: #e0e0e0; color: #333; }
        .amps-btn--danger { background: #dc3545; color: #fff; }
        .amps-btn--danger:hover { background: #c82333; color: #fff; }
        ';
    }
}
