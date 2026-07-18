<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WCFM_AI_Admin_Settings {

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_menu' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
    }

    public function add_menu() {
        add_submenu_page(
            'woocommerce',
            'IA Assistant',
            'IA Assistant',
            'manage_options',
            'wcfm-ai-settings',
            array( $this, 'render_page' )
        );
    }

    /**
     * Los cuatro ajustes se guardaban SIN sanitize_callback (A-6). Ahora cada uno
     * valida su valor: proveedor y modelo contra patron/lista blanca, y el limite
     * acotado a un entero razonable.
     */
    public function register_settings() {
        register_setting( 'wcfm_ai_settings', 'wcfm_ai_provider', array(
            'sanitize_callback' => array( 'WCFM_AI_Security', 'sanitize_provider' ),
        ) );
        register_setting( 'wcfm_ai_settings', 'wcfm_ai_api_key', array(
            'sanitize_callback' => array( $this, 'sanitize_api_key' ),
        ) );
        register_setting( 'wcfm_ai_settings', 'wcfm_ai_model', array(
            'sanitize_callback' => array( $this, 'sanitize_model_setting' ),
        ) );
        register_setting( 'wcfm_ai_settings', 'wcfm_ai_vendor_monthly_limit', array(
            'sanitize_callback' => array( 'WCFM_AI_Security', 'sanitize_limit' ),
        ) );
    }

    /**
     * Guarda la API key SIN borrarla cuando el campo llega vacio.
     *
     * El formulario ya no devuelve la clave al navegador (A-5), asi que un envio
     * con el campo vacio significa "no la cambies", no "borrala".
     *
     * @param mixed $value
     * @return string
     */
    public function sanitize_api_key( $value ) {
        $new = trim( (string) $value );
        if ( '' === $new ) {
            return (string) get_option( 'wcfm_ai_api_key', '' ); // Conserva la existente.
        }
        return sanitize_text_field( $new );
    }

    /**
     * Valida el modelo; si no cumple el patron, conserva el anterior.
     *
     * @param mixed $value
     * @return string
     */
    public function sanitize_model_setting( $value ) {
        $clean = WCFM_AI_Security::sanitize_model( $value );
        return '' !== $clean ? $clean : (string) get_option( 'wcfm_ai_model', 'deepseek-chat' );
    }

    public function enqueue_admin_assets( $hook ) {
        if ( strpos( $hook, 'wcfm-ai-settings' ) === false ) {
            return;
        }
        wp_enqueue_script(
            'wcfm-ai-admin',
            WCFM_AI_URL . 'assets/js/admin.js',
            array( 'jquery' ),
            WCFM_AI_VERSION,
            true
        );
        wp_localize_script( 'wcfm-ai-admin', 'wcfmAIAdmin', array(
            'restUrl' => rest_url( 'wcfm-ai/v1/' ),
            'nonce'   => wp_create_nonce( 'wp_rest' ),
        ) );
    }

    public function render_page() {
        $provider = get_option( 'wcfm_ai_provider', 'deepseek' );
        $api_key  = get_option( 'wcfm_ai_api_key', '' );
        $model    = get_option( 'wcfm_ai_model', 'deepseek-chat' );
        $limit    = get_option( 'wcfm_ai_vendor_monthly_limit', 50 );
        $log      = get_option( 'wcfm_ai_usage_log', array() );

        $current_month = date( 'Y_m' );
        $month_entries = array_filter( $log, function( $e ) use ( $current_month ) {
            return isset( $e['month'] ) && $e['month'] === $current_month;
        } );
        $month_count  = count( $month_entries );
        $total_tokens = array_sum( array_column( $log, 'tokens' ) );
        $last10       = array_slice( array_reverse( $log ), 0, 10 );
        ?>
        <div class="wrap">
            <h1>&#9733; IA Assistant — Generador Cultural</h1>

            <?php if ( isset( $_GET['settings-updated'] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p>Configuración guardada correctamente.</p></div>
            <?php endif; ?>

            <div style="display:flex;gap:24px;align-items:flex-start;flex-wrap:wrap;">

                <!-- Main settings form -->
                <div style="flex:1;min-width:400px;">
                    <form method="post" action="options.php">
                        <?php settings_fields( 'wcfm_ai_settings' ); ?>
                        <table class="form-table">
                            <tr>
                                <th><label for="wcfm_ai_provider">Proveedor de IA</label></th>
                                <td>
                                    <select name="wcfm_ai_provider" id="wcfm_ai_provider_select">
                                        <option value="deepseek" <?php selected( $provider, 'deepseek' ); ?>>DeepSeek V3 — ~$0.30 / 100 productos</option>
                                        <option value="groq"     <?php selected( $provider, 'groq' ); ?>>Groq — ultra rápido ~1000 tok/seg</option>
                                        <option value="gemini"   <?php selected( $provider, 'gemini' ); ?>>Google Gemini Flash — tier gratuito</option>
                                        <option value="openai"   <?php selected( $provider, 'openai' ); ?>>OpenAI GPT-4o — ~$4 / 100 productos</option>
                                        <option value="claude"   <?php selected( $provider, 'claude' ); ?>>Claude Sonnet 4.6 — ~$8 / 100 productos</option>
                                        <option value="mistral"  <?php selected( $provider, 'mistral' ); ?>>Mistral Large</option>
                                    </select>
                                    <p class="description" id="wcfm_ai_key_hint"></p>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="wcfm_ai_api_key">API Key</label></th>
                                <td>
                                    <?php
                                    // A-5: la clave NO se vuelve a enviar al navegador. Antes se
                                    // renderizaba en el atributo value de un input type=password,
                                    // que solo la oculta visualmente: seguia legible en el codigo
                                    // fuente de la pagina. Se deja vacio y se muestra solo el estado.
                                    $has_key = '' !== trim( (string) $api_key );
                                    ?>
                                    <input type="password" name="wcfm_ai_api_key" id="wcfm_ai_api_key_input"
                                        value=""
                                        class="regular-text"
                                        autocomplete="new-password"
                                        placeholder="<?php echo esc_attr( $has_key ? '•••••••• clave guardada — escribe una nueva para reemplazarla' : 'Pega aquí tu API key' ); ?>" />
                                    <p class="description">
                                        <?php if ( $has_key ) : ?>
                                            <strong style="color:#227122">&#10003; Configurada</strong> —
                                            déjalo en blanco para conservarla.
                                        <?php else : ?>
                                            <strong style="color:#b32d2e">&#10007; Sin configurar</strong> —
                                            el generador no funcionará hasta que la cargues.
                                        <?php endif; ?>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="wcfm_ai_model">Modelo</label></th>
                                <td>
                                    <input type="text" name="wcfm_ai_model" id="wcfm_ai_model_input"
                                        value="<?php echo esc_attr( $model ); ?>"
                                        class="regular-text" />
                                    <p class="description">Nombre exacto del modelo según el proveedor seleccionado.</p>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="wcfm_ai_vendor_monthly_limit">Límite mensual por vendedor</label></th>
                                <td>
                                    <input type="number" name="wcfm_ai_vendor_monthly_limit"
                                        value="<?php echo esc_attr( $limit ); ?>"
                                        min="1" max="9999" class="small-text" />
                                    <span> generaciones / mes</span>
                                    <p class="description">Los administradores no tienen límite.</p>
                                </td>
                            </tr>
                        </table>
                        <?php submit_button( 'Guardar configuración' ); ?>
                    </form>

                    <!-- Test connection -->
                    <hr>
                    <h2>Probar conexión</h2>
                    <p>Genera una descripción de prueba con la configuración actual.</p>
                    <button id="wcfm_ai_test_btn" class="button button-secondary">Probar conexión</button>
                    <div id="wcfm_ai_test_result" style="margin-top:12px;padding:12px;display:none;border-radius:4px;"></div>

                    <!-- Cost table -->
                    <hr>
                    <h2>Estimación de costos por 100 productos</h2>
                    <table class="widefat striped" style="max-width:500px;">
                        <thead>
                            <tr><th>Proveedor</th><th>Modelo</th><th>Costo est.</th></tr>
                        </thead>
                        <tbody>
                            <tr><td>DeepSeek</td><td>deepseek-chat</td><td>~$0.30</td></tr>
                            <tr><td>Groq</td><td>llama-3.1-8b-instant</td><td>~$0.30</td></tr>
                            <tr><td>Groq</td><td>llama-3.3-70b-versatile</td><td>~$2.50</td></tr>
                            <tr><td>Gemini</td><td>gemini-2.0-flash</td><td>~$0.50 (o gratis)</td></tr>
                            <tr><td>OpenAI</td><td>gpt-4o</td><td>~$4.00</td></tr>
                            <tr><td>Claude</td><td>claude-sonnet-4-6</td><td>~$8.00</td></tr>
                        </tbody>
                    </table>
                </div>

                <!-- Stats sidebar -->
                <div style="width:280px;background:#f9f9f9;border:1px solid #ddd;border-radius:6px;padding:16px;">
                    <h2 style="margin-top:0;">Estadísticas</h2>
                    <p><strong>Este mes:</strong> <?php echo esc_html( $month_count ); ?> generaciones</p>
                    <p><strong>Total tokens:</strong> <?php echo esc_html( number_format( $total_tokens ) ); ?></p>
                    <p><strong>Log total:</strong> <?php echo esc_html( count( $log ) ); ?> entradas</p>

                    <?php if ( ! empty( $last10 ) ) : ?>
                        <h3>Últimas 10 generaciones</h3>
                        <table class="widefat" style="font-size:12px;">
                            <thead><tr><th>Producto</th><th>Tokens</th><th>Fecha</th></tr></thead>
                            <tbody>
                                <?php foreach ( $last10 as $entry ) : ?>
                                    <tr>
                                        <td><?php echo esc_html( isset( $entry['product_name'] ) ? mb_substr( $entry['product_name'], 0, 20 ) : '—' ); ?></td>
                                        <td><?php echo esc_html( $entry['tokens'] ?? '—' ); ?></td>
                                        <td><?php echo esc_html( isset( $entry['time'] ) ? date( 'd/m H:i', strtotime( $entry['time'] ) ) : '—' ); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>

                    <hr>
                    <form method="post" onsubmit="return confirm('¿Limpiar todo el log de uso?');">
                        <?php wp_nonce_field( 'wcfm_ai_clear_log' ); ?>
                        <input type="hidden" name="wcfm_ai_action" value="clear_log">
                        <button type="submit" class="button button-link-delete">Limpiar log</button>
                    </form>
                </div>

            </div><!-- /flex -->
        </div>
        <?php
        // Handle clear log action
        if (
            isset( $_POST['wcfm_ai_action'] ) &&
            $_POST['wcfm_ai_action'] === 'clear_log' &&
            check_admin_referer( 'wcfm_ai_clear_log' )
        ) {
            update_option( 'wcfm_ai_usage_log', array() );
            echo '<script>location.reload();</script>';
        }
    }
}
