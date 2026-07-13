<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class AMPS_Settings {

    private static $instance = null;
    const OPTION_KEY = 'amps_settings';

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_menu', [ $this, 'register_menu' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
    }

    public function register_menu() {
        add_submenu_page(
            'woocommerce',
            'MP Split — Amazonia',
            'MP Split',
            'manage_woocommerce',
            'amps-settings',
            [ $this, 'render_page' ]
        );
    }

    public function register_settings() {
        register_setting( 'amps_settings_group', self::OPTION_KEY, [ $this, 'sanitize' ] );
    }

    public function sanitize( $input ) {
        return [
            'client_secret'     => sanitize_text_field( $input['client_secret'] ?? '' ),
            'commission_percent'=> min( 99, max( 0, floatval( $input['commission_percent'] ?? 10 ) ) ),
            'sandbox_mode'      => ! empty( $input['sandbox_mode'] ) ? 'yes' : 'no',
        ];
    }

    public function render_page() {
        $settings = self::get();
        ?>
        <div class="wrap">
            <h1>Mercado Pago Split — Amazonia</h1>
            <form method="post" action="options.php">
                <?php settings_fields( 'amps_settings_group' ); ?>
                <table class="form-table">
                    <tr>
                        <th>APP ID (Client ID)</th>
                        <td><code><?php echo esc_html( get_option( '_mp_client_id', '—' ) ); ?></code><br>
                            <small>Obtenido del plugin oficial de MP. Solo lectura.</small></td>
                    </tr>
                    <tr>
                        <th><label for="amps_client_secret">Client Secret</label></th>
                        <td>
                            <input type="password" id="amps_client_secret"
                                   name="<?php echo self::OPTION_KEY; ?>[client_secret]"
                                   value="<?php echo esc_attr( $settings['client_secret'] ); ?>"
                                   class="regular-text">
                            <p class="description">Encuéntralo en developers.mercadopago.com → tu app → Credenciales.</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="amps_commission">Comisión del marketplace (%)</label></th>
                        <td>
                            <input type="number" id="amps_commission"
                                   name="<?php echo self::OPTION_KEY; ?>[commission_percent]"
                                   value="<?php echo esc_attr( $settings['commission_percent'] ); ?>"
                                   min="0" max="99" step="0.5" class="small-text"> %
                            <p class="description">Porcentaje que retiene el marketplace de cada venta.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Modo sandbox</th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo self::OPTION_KEY; ?>[sandbox_mode]"
                                       value="yes" <?php checked( $settings['sandbox_mode'], 'yes' ); ?>>
                                Activar modo de pruebas
                            </label>
                            <p class="description">Usa las credenciales TEST del plugin oficial de MP.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>URL de callback OAuth</th>
                        <td>
                            <code><?php echo esc_html( self::get_callback_url() ); ?></code><br>
                            <small>Configura esta URL en tu app de Mercado Pago → Redirect URI.</small>
                        </td>
                    </tr>
                </table>
                <?php submit_button( 'Guardar configuración' ); ?>
            </form>
        </div>
        <?php
    }

    public static function get() {
        $defaults = [
            'client_secret'      => '',
            'commission_percent' => 10,
            'sandbox_mode'       => 'yes',
        ];
        return wp_parse_args( get_option( self::OPTION_KEY, [] ), $defaults );
    }

    public static function get_app_id() {
        return get_option( '_mp_client_id', '' );
    }

    public static function get_client_secret() {
        $settings = self::get();
        return $settings['client_secret'];
    }

    public static function get_commission() {
        $settings = self::get();
        return floatval( $settings['commission_percent'] );
    }

    public static function is_sandbox() {
        $settings = self::get();
        return $settings['sandbox_mode'] === 'yes';
    }

    public static function get_marketplace_token() {
        if ( self::is_sandbox() ) {
            return get_option( '_mp_access_token_test', '' );
        }
        return get_option( '_mp_access_token_prod', '' );
    }

    public static function get_callback_url() {
        return rest_url( 'amazonia-mp/v1/oauth/callback' );
    }

    public static function get_mp_api_url() {
        return 'https://api.mercadopago.com';
    }

    public static function get_mp_auth_url() {
        // Colombia usa auth.mercadopago.com.co
        return 'https://auth.mercadopago.com.co/authorization';
    }
}
