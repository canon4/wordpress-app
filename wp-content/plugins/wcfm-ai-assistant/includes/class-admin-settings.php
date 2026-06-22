<?php
/**
 * WCFM AI Assistant — Admin settings page.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WCFM_AI_Admin_Settings {

	public function __construct() {
		add_action( 'admin_menu',            array( $this, 'add_menu' ) );
		add_action( 'admin_init',            array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
	}

	public function add_menu() {
		add_submenu_page(
			'woocommerce',
			'WCFM AI Assistant',
			'IA Assistant',
			'manage_options',
			'wcfm-ai-settings',
			array( $this, 'render_page' )
		);
	}

	public function register_settings() {
		$settings = array(
			'wcfm_ai_provider',
			'wcfm_ai_api_key',
			'wcfm_ai_model',
			'wcfm_ai_vendor_monthly_limit',
		);
		foreach ( $settings as $key ) {
			register_setting( 'wcfm_ai_options', $key, array( 'sanitize_callback' => 'sanitize_text_field' ) );
		}
	}

	public function enqueue_admin_assets( $hook ) {
		if ( $hook !== 'woocommerce_page_wcfm-ai-settings' ) {
			return;
		}
		wp_enqueue_script(
			'wcfm-ai-admin',
			WCFM_AI_PLUGIN_URL . 'assets/js/admin.js',
			array( 'jquery' ),
			WCFM_AI_VERSION,
			true
		);
		wp_localize_script( 'wcfm-ai-admin', 'wcfmAIAdmin', array(
			'restUrl' => rest_url( 'wcfm-ai/v1/test' ),
			'nonce'   => wp_create_nonce( 'wp_rest' ),
		) );
	}

	public function render_page() {
		$provider  = get_option( 'wcfm_ai_provider', 'deepseek' );
		$api_key   = get_option( 'wcfm_ai_api_key', '' );
		$model     = get_option( 'wcfm_ai_model', 'deepseek-chat' );
		$limit     = get_option( 'wcfm_ai_vendor_monthly_limit', 50 );
		$usage_log = get_option( 'wcfm_ai_usage_log', array() );

		$month_count = 0;
		$cur_month   = gmdate( 'Y-m' );
		foreach ( $usage_log as $entry ) {
			if ( strpos( $entry['timestamp'], $cur_month ) === 0 ) {
				$month_count++;
			}
		}
		$total_tokens = array_sum( array_column( $usage_log, 'tokens' ) );
		?>
		<div class="wrap wcfm-ai-admin">
			<h1>🤖 WCFM AI Assistant <span style="font-size:14px;color:#777;font-weight:400">v<?php echo esc_html( WCFM_AI_VERSION ); ?></span></h1>

			<div class="wcfm-ai-admin-layout">

				<!-- Main settings -->
				<div class="wcfm-ai-main-col">
					<div class="wcfm-ai-card">
						<h2>Configuración de IA</h2>
						<form method="post" action="options.php">
							<?php settings_fields( 'wcfm_ai_options' ); ?>
							<table class="form-table">
								<tr>
									<th scope="row">Proveedor de IA</th>
									<td>
										<select name="wcfm_ai_provider" id="wcfm_ai_provider_select" class="regular-text">
											<option value="deepseek" <?php selected( $provider, 'deepseek' ); ?>>
												DeepSeek V3 — Recomendado MVP (~$0.30/100 productos)
											</option>
											<option value="claude" <?php selected( $provider, 'claude' ); ?>>
												Claude Sonnet 4.6 — Mayor calidad cultural (~$8/100 productos)
											</option>
											<option value="openai" <?php selected( $provider, 'openai' ); ?>>
												OpenAI GPT-4o (~$4/100 productos)
											</option>
											<option value="gemini" <?php selected( $provider, 'gemini' ); ?>>
												Google Gemini Flash — Tier gratuito disponible
											</option>
											<option value="groq" <?php selected( $provider, 'groq' ); ?>>
												Groq — Ultra rápido (~$0.59/100 productos con Llama 3.3 70B)
											</option>
										</select>
									</td>
								</tr>
								<tr>
									<th scope="row">API Key</th>
									<td>
										<input type="password"
											name="wcfm_ai_api_key"
											id="wcfm_ai_api_key_input"
											value="<?php echo esc_attr( $api_key ); ?>"
											class="regular-text"
											placeholder="sk-..."
											autocomplete="new-password"
										/>
										<p class="description" id="wcfm_ai_key_hint">
											<?php echo esc_html( $this->get_key_hint( $provider ) ); ?>
										</p>
									</td>
								</tr>
								<tr>
									<th scope="row">Modelo</th>
									<td>
										<input type="text"
											name="wcfm_ai_model"
											id="wcfm_ai_model_input"
											value="<?php echo esc_attr( $model ); ?>"
											class="regular-text"
											placeholder="deepseek-chat"
										/>
										<p class="description">
											DeepSeek: <code>deepseek-chat</code> &nbsp;|&nbsp;
											Claude: <code>claude-sonnet-4-6</code> &nbsp;|&nbsp;
											OpenAI: <code>gpt-4o</code> &nbsp;|&nbsp;
											Gemini: <code>gemini-2.0-flash</code>
										</p>
									</td>
								</tr>
								<tr>
									<th scope="row">Límite por vendedor</th>
									<td>
										<input type="number"
											name="wcfm_ai_vendor_monthly_limit"
											value="<?php echo esc_attr( $limit ); ?>"
											min="1" max="500"
											style="width:80px"
										/> generaciones / mes
										<p class="description">Los administradores no tienen límite.</p>
									</td>
								</tr>
							</table>
							<?php submit_button( 'Guardar configuración' ); ?>
						</form>

						<hr/>

						<h3>Probar conexión</h3>
						<p class="description">Prueba que la API key funciona antes de lanzar con tus vendedores.</p>
						<button id="wcfm_ai_test_btn" class="button button-secondary">
							🔌 Probar conexión
						</button>
						<div id="wcfm_ai_test_result" class="wcfm-ai-test-result" style="display:none;"></div>
					</div>
				</div>

				<!-- Stats sidebar -->
				<div class="wcfm-ai-side-col">
					<div class="wcfm-ai-card wcfm-ai-stats">
						<h3>📊 Estadísticas</h3>
						<div class="wcfm-ai-stat-item">
							<span class="wcfm-ai-stat-number"><?php echo esc_html( $month_count ); ?></span>
							<span class="wcfm-ai-stat-label">generaciones este mes</span>
						</div>
						<div class="wcfm-ai-stat-item">
							<span class="wcfm-ai-stat-number"><?php echo esc_html( number_format( $total_tokens ) ); ?></span>
							<span class="wcfm-ai-stat-label">tokens totales</span>
						</div>
						<hr/>
						<h4>Últimas generaciones</h4>
						<ul class="wcfm-ai-log-list">
							<?php
							$recent = array_slice( array_reverse( $usage_log ), 0, 10 );
							foreach ( $recent as $entry ) :
								$ts = isset( $entry['timestamp'] ) ? $entry['timestamp'] : '';
							?>
								<li>
									<strong><?php echo esc_html( $entry['product'] ); ?></strong><br/>
									<small>
										Vendor #<?php echo esc_html( $entry['vendor_id'] ); ?>
										· <?php echo esc_html( $entry['tokens'] ); ?> tokens
										· <?php echo esc_html( $ts ); ?>
									</small>
								</li>
							<?php endforeach; ?>
							<?php if ( empty( $recent ) ) : ?>
								<li style="color:#999">Aún no hay generaciones.</li>
							<?php endif; ?>
						</ul>

						<?php if ( ! empty( $usage_log ) ) : ?>
							<hr/>
							<form method="post" style="margin-top:10px"
								onsubmit="return confirm('¿Limpiar todo el historial de uso?')">
								<?php wp_nonce_field( 'wcfm_ai_clear_log' ); ?>
								<input type="hidden" name="wcfm_ai_action" value="clear_log"/>
								<button type="submit" class="button button-small" style="color:#cc0000">
									Limpiar historial
								</button>
							</form>
						<?php endif; ?>
					</div>

					<div class="wcfm-ai-card" style="margin-top:15px">
						<h4>💡 Costos estimados (100 productos)</h4>
						<table style="width:100%;font-size:12px">
							<tr><td>DeepSeek V3</td><td style="text-align:right;color:#16a34a"><strong>~$0.30</strong></td></tr>
							<tr><td>Gemini Flash</td><td style="text-align:right;color:#16a34a"><strong>~$0.50</strong></td></tr>
							<tr><td>Groq Llama 3.1 8B</td><td style="text-align:right;color:#16a34a"><strong>~$0.30</strong></td></tr>
							<tr><td>Groq Llama 3.3 70B</td><td style="text-align:right"><strong>~$2.50</strong></td></tr>
							<tr><td>GPT-4o</td><td style="text-align:right"><strong>~$4.00</strong></td></tr>
							<tr><td>Claude Sonnet</td><td style="text-align:right"><strong>~$8.00</strong></td></tr>
						</table>
					</div>
				</div>

			</div><!-- .wcfm-ai-admin-layout -->
		</div>

		<style>
		.wcfm-ai-admin-layout { display:flex; gap:20px; margin-top:20px; align-items:flex-start; }
		.wcfm-ai-main-col  { flex:2; }
		.wcfm-ai-side-col  { flex:1; min-width:260px; }
		.wcfm-ai-card      { background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:20px; }
		.wcfm-ai-card h2, .wcfm-ai-card h3 { margin-top:0; }
		.wcfm-ai-stat-item { margin-bottom:12px; }
		.wcfm-ai-stat-number { display:block; font-size:32px; font-weight:700; color:#7c3aed; line-height:1; }
		.wcfm-ai-stat-label  { font-size:12px; color:#6b7280; }
		.wcfm-ai-log-list    { font-size:12px; max-height:220px; overflow-y:auto; padding-left:15px; }
		.wcfm-ai-log-list li { margin-bottom:8px; }
		.wcfm-ai-test-result { margin-top:12px; padding:12px; border-radius:6px; font-size:13px; }
		.wcfm-ai-test-result.success { background:#f0fdf4; border:1px solid #bbf7d0; }
		.wcfm-ai-test-result.error   { background:#fef2f2; border:1px solid #fecaca; }
		</style>
		<?php
		// Handle log clear action
		if ( isset( $_POST['wcfm_ai_action'] ) && $_POST['wcfm_ai_action'] === 'clear_log' ) {
			check_admin_referer( 'wcfm_ai_clear_log' );
			delete_option( 'wcfm_ai_usage_log' );
			echo '<script>location.reload();</script>';
		}
	}

	/**
	 * Get API key hint for the selected provider.
	 */
	private function get_key_hint( $provider ) {
		$hints = array(
			'deepseek' => 'Obtén tu key gratuita en platform.deepseek.com → API Keys',
			'claude'   => 'Obtén tu key en console.anthropic.com → API Keys',
			'openai'   => 'Obtén tu key en platform.openai.com → API Keys',
			'gemini'   => 'Obtén tu key en aistudio.google.com → Obtener clave API (tiene tier gratuito)',
			'groq'     => 'Obtén tu key gratuita en console.groq.com → API Keys — Ultra rápido (~280-1000 tokens/seg)',
		);
		return $hints[ $provider ] ?? '';
	}
}
