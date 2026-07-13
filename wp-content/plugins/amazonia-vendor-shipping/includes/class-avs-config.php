<?php
/**
 * Configuración del modo de cobro de envío: global (admin) y por vendedor (dashboard WCFM).
 *
 * @package Amazonia_Vendor_Shipping
 */

defined( 'ABSPATH' ) || exit;

class AVS_Config {

	const OPT_MODE             = 'avs_shipping_mode';
	const OPT_FIXED            = 'avs_shipping_fixed_rate';
	const OPT_DEFAULT_CARRIERS = 'avs_default_carriers';
	const OPT_API_KEY          = 'avs_envia_api_key';
	const OPT_API_SANDBOX      = 'avs_envia_api_sandbox';
	const META_MODE            = '_avs_shipping_mode';
	const META_FIXED           = '_avs_shipping_fixed_rate';
	const META_CARRIER         = '_avs_carrier';
	const DEFAULT_MODE         = 'customer_pays';

	public static function init() {
		// Ajustes globales (admin).
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_menu', array( __CLASS__, 'add_settings_page' ) );

		// Campos por vendedor en el dashboard WCFM (sección de envío).
		add_filter( 'wcfmmp_settings_fields_shipping', array( __CLASS__, 'add_vendor_fields' ), 20 );
		add_action( 'wcfm_vendor_settings_before_update', array( __CLASS__, 'save_vendor_fields' ), 20, 2 );
	}

	/* ---------------------------------------------------------------------
	 * Resolución de valores (vendor → global)
	 * ------------------------------------------------------------------- */

	public static function get_global_mode() {
		$mode = get_option( self::OPT_MODE, self::DEFAULT_MODE );
		return in_array( $mode, avs_valid_modes(), true ) ? $mode : self::DEFAULT_MODE;
	}

	public static function get_global_fixed_rate() {
		return max( 0.0, (float) get_option( self::OPT_FIXED, 0 ) );
	}

	/**
	 * Modo de cobro efectivo para un vendedor: su override, o el global por defecto.
	 */
	public static function get_mode( $vendor_id = 0 ) {
		$vendor_id = absint( $vendor_id );
		if ( $vendor_id ) {
			$mode = get_user_meta( $vendor_id, self::META_MODE, true );
			if ( $mode && in_array( $mode, avs_valid_modes(), true ) ) {
				return $mode;
			}
		}
		return self::get_global_mode();
	}

	/**
	 * Tarifa fija efectiva para un vendedor: su override, o la global.
	 */
	public static function get_fixed_rate( $vendor_id = 0 ) {
		$vendor_id = absint( $vendor_id );
		if ( $vendor_id ) {
			$val = get_user_meta( $vendor_id, self::META_FIXED, true );
			if ( '' !== $val ) {
				return max( 0.0, (float) $val );
			}
		}
		return self::get_global_fixed_rate();
	}

	/**
	 * Transportadora fija del vendedor (código normalizado) o '' si usa la lista por defecto.
	 */
	public static function get_vendor_carrier( $vendor_id = 0 ) {
		$vendor_id = absint( $vendor_id );
		if ( ! $vendor_id ) {
			return '';
		}
		$c = avs_carrier_key( get_user_meta( $vendor_id, self::META_CARRIER, true ) );
		return array_key_exists( $c, avs_marketplace_carriers() ) ? $c : '';
	}

	/**
	 * Lista por defecto de transportadoras del marketplace (códigos normalizados).
	 *
	 * @return string[]
	 */
	public static function get_default_carriers() {
		$list = get_option( self::OPT_DEFAULT_CARRIERS, array() );
		$list = array_filter( array_map( 'avs_carrier_key', (array) $list ) );
		$valid = array_keys( avs_marketplace_carriers() );
		return array_values( array_intersect( $list, $valid ) );
	}

	/**
	 * Transportadoras permitidas para el paquete de un vendedor:
	 * su transportadora fija si la eligió; si no, la lista por defecto del marketplace.
	 * Lista vacía = sin restricción (se muestran todas).
	 *
	 * @param int $vendor_id
	 * @return string[]
	 */
	public static function allowed_carriers( $vendor_id = 0 ) {
		$carrier = self::get_vendor_carrier( $vendor_id );
		if ( '' !== $carrier ) {
			return array( $carrier );
		}
		return self::get_default_carriers();
	}

	/* ---------------------------------------------------------------------
	 * Ajustes globales (admin)
	 * ------------------------------------------------------------------- */

	public static function register_settings() {
		register_setting( 'avs_settings', self::OPT_MODE, array( 'sanitize_callback' => array( __CLASS__, 'sanitize_mode' ) ) );
		register_setting( 'avs_settings', self::OPT_FIXED, array( 'sanitize_callback' => array( __CLASS__, 'sanitize_rate' ) ) );
		register_setting( 'avs_settings', self::OPT_DEFAULT_CARRIERS, array( 'sanitize_callback' => array( __CLASS__, 'sanitize_carriers' ) ) );
		register_setting( 'avs_settings', self::OPT_API_KEY, array( 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( 'avs_settings', self::OPT_API_SANDBOX, array( 'sanitize_callback' => array( __CLASS__, 'sanitize_bool' ) ) );
	}

	public static function sanitize_bool( $value ) {
		return $value ? '1' : '';
	}

	/**
	 * API key de Envia para generar guías por API. Distinta del token OAuth del plugin.
	 */
	public static function get_api_key() {
		return trim( (string) get_option( self::OPT_API_KEY, '' ) );
	}

	/**
	 * ¿Usar el entorno de pruebas (sandbox) de Envia?
	 */
	public static function is_sandbox() {
		return (bool) get_option( self::OPT_API_SANDBOX, '' );
	}

	/**
	 * Host base de la API de Envia según el entorno (producción o sandbox).
	 */
	public static function get_api_base() {
		return self::is_sandbox() ? 'https://api-test.envia.com' : 'https://api.envia.com';
	}

	public static function sanitize_carriers( $value ) {
		$valid = array_keys( avs_marketplace_carriers() );
		$out   = array_filter( array_map( 'avs_carrier_key', (array) $value ) );
		return array_values( array_intersect( $out, $valid ) );
	}

	public static function sanitize_mode( $value ) {
		return in_array( $value, avs_valid_modes(), true ) ? $value : self::DEFAULT_MODE;
	}

	public static function sanitize_rate( $value ) {
		return max( 0.0, (float) $value );
	}

	public static function add_settings_page() {
		add_submenu_page(
			'woocommerce',
			__( 'Envíos multivendedor (Amazonia)', 'amazonia-vendor-shipping' ),
			__( 'Envíos Amazonia', 'amazonia-vendor-shipping' ),
			'manage_woocommerce',
			'avs-settings',
			array( __CLASS__, 'render_settings_page' )
		);
	}

	public static function render_settings_page() {
		$mode  = self::get_global_mode();
		$fixed = self::get_global_fixed_rate();
		$labels = self::mode_labels();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Envíos multivendedor — configuración por defecto', 'amazonia-vendor-shipping' ); ?></h1>
			<p><?php esc_html_e( 'Modo de cobro de envío aplicado a todos los vendedores que no tengan una configuración propia.', 'amazonia-vendor-shipping' ); ?></p>
			<form method="post" action="options.php">
				<?php settings_fields( 'avs_settings' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="<?php echo esc_attr( self::OPT_MODE ); ?>"><?php esc_html_e( 'Modo de cobro', 'amazonia-vendor-shipping' ); ?></label></th>
						<td>
							<select name="<?php echo esc_attr( self::OPT_MODE ); ?>" id="<?php echo esc_attr( self::OPT_MODE ); ?>">
								<?php foreach ( $labels as $key => $label ) : ?>
									<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $mode, $key ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="<?php echo esc_attr( self::OPT_FIXED ); ?>"><?php esc_html_e( 'Tarifa fija al cliente', 'amazonia-vendor-shipping' ); ?></label></th>
						<td>
							<input type="number" step="0.01" min="0" name="<?php echo esc_attr( self::OPT_FIXED ); ?>" id="<?php echo esc_attr( self::OPT_FIXED ); ?>" value="<?php echo esc_attr( $fixed ); ?>" />
							<p class="description"><?php esc_html_e( 'Solo se usa con el modo "Tarifa fija compartida": el cliente paga esta cantidad y el vendedor absorbe el resto.', 'amazonia-vendor-shipping' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Transportadoras por defecto', 'amazonia-vendor-shipping' ); ?></th>
						<td>
							<?php
							$defaults = self::get_default_carriers();
							foreach ( avs_marketplace_carriers() as $code => $label ) :
								?>
								<label style="display:block;margin:2px 0">
									<input type="checkbox" name="<?php echo esc_attr( self::OPT_DEFAULT_CARRIERS ); ?>[]" value="<?php echo esc_attr( $code ); ?>" <?php checked( in_array( $code, $defaults, true ) ); ?> />
									<?php echo esc_html( $label ); ?>
								</label>
							<?php endforeach; ?>
							<p class="description"><?php esc_html_e( 'Se aplican a los vendedores que aún no eligieron su transportadora. Si no marcas ninguna, se muestran todas.', 'amazonia-vendor-shipping' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="<?php echo esc_attr( self::OPT_API_KEY ); ?>"><?php esc_html_e( 'API Key de Envia (guías)', 'amazonia-vendor-shipping' ); ?></label></th>
						<td>
							<?php $api = self::get_api_key(); ?>
							<input type="password" autocomplete="off" name="<?php echo esc_attr( self::OPT_API_KEY ); ?>" id="<?php echo esc_attr( self::OPT_API_KEY ); ?>" value="<?php echo esc_attr( $api ); ?>" style="width:420px;max-width:100%" />
							<p class="description">
								<?php esc_html_e( 'Token de API de tu cuenta Envia (panel Envia → Integraciones/API). Permite generar las guías por API con el origen de cada vendedor, sin que el vendedor inicie sesión en Envia. Es distinto del token de conexión OAuth del plugin.', 'amazonia-vendor-shipping' ); ?>
								<?php echo $api ? '<strong style="color:#227122">' . esc_html__( '✓ Configurada', 'amazonia-vendor-shipping' ) . '</strong>' : '<strong style="color:#b32d2e">' . esc_html__( '✗ Falta configurarla (el botón de guía no podrá generar guías).', 'amazonia-vendor-shipping' ) . '</strong>'; ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Entorno de Envia', 'amazonia-vendor-shipping' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( self::OPT_API_SANDBOX ); ?>" value="1" <?php checked( self::is_sandbox() ); ?> />
								<?php esc_html_e( 'Usar entorno de pruebas (sandbox) para generar guías', 'amazonia-vendor-shipping' ); ?>
							</label>
							<p class="description"><?php printf( /* translators: %s: host base */ esc_html__( 'Actívalo si tu API key es de una cuenta de pruebas de Envia. Host actual: %s', 'amazonia-vendor-shipping' ), '<code>' . esc_html( self::get_api_base() ) . '</code>' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Campos por vendedor (dashboard WCFM)
	 * ------------------------------------------------------------------- */

	/**
	 * Añade el selector de modo y la tarifa fija a la sección de envío del vendedor.
	 *
	 * @param array $fields Campos existentes de WCFM.
	 * @return array
	 */
	public static function add_vendor_fields( $fields ) {
		$vendor_id = absint( apply_filters( 'wcfm_current_vendor_id', get_current_user_id() ) );
		$mode      = get_user_meta( $vendor_id, self::META_MODE, true );
		$fixed     = get_user_meta( $vendor_id, self::META_FIXED, true );

		$fields['avs_shipping_mode'] = array(
			'label'       => __( 'Costo de envío Envia', 'amazonia-vendor-shipping' ),
			'name'        => 'avs_shipping[mode]',
			'type'        => 'select',
			'class'       => 'wcfm-select wcfm_ele',
			'label_class' => 'wcfm_title wcfm_ele',
			'options'     => array( '' => __( 'Usar configuración del marketplace', 'amazonia-vendor-shipping' ) ) + self::mode_labels(),
			'value'       => $mode,
			'hints'       => __( 'Quién paga el envío cotizado por Envia para tus productos.', 'amazonia-vendor-shipping' ),
		);
		$fields['avs_shipping_fixed_rate'] = array(
			'label'       => __( 'Tarifa fija al cliente', 'amazonia-vendor-shipping' ),
			'name'        => 'avs_shipping[fixed_rate]',
			'type'        => 'number',
			'class'       => 'wcfm-text wcfm_non_negative_input wcfm_ele',
			'label_class' => 'wcfm_title wcfm_ele',
			'placeholder' => '0.00',
			'value'       => ( '' !== $fixed ) ? $fixed : '',
			'hints'       => __( 'Solo con "Tarifa fija compartida": el cliente paga esto y tú absorbes el resto.', 'amazonia-vendor-shipping' ),
		);

		$carrier = avs_carrier_key( get_user_meta( $vendor_id, self::META_CARRIER, true ) );
		$fields['avs_shipping_carrier'] = array(
			'label'       => __( 'Transportadora de tu tienda', 'amazonia-vendor-shipping' ),
			'name'        => 'avs_shipping[carrier]',
			'type'        => 'select',
			'class'       => 'wcfm-select wcfm_ele',
			'label_class' => 'wcfm_title wcfm_ele',
			'options'     => array( '' => __( 'Usar las del marketplace (por defecto)', 'amazonia-vendor-shipping' ) ) + avs_marketplace_carriers(),
			'value'       => $carrier,
			'hints'       => __( 'Elige la transportadora cuya oficina de recogida te quede más cerca. El cliente solo verá esa. Si no eliges, se usan las del marketplace.', 'amazonia-vendor-shipping' ),
		);
		return $fields;
	}

	/**
	 * Guarda los campos del vendedor al actualizar sus settings.
	 *
	 * @param int   $user_id
	 * @param array $form Datos del formulario completo del dashboard.
	 */
	public static function save_vendor_fields( $user_id, $form ) {
		if ( ! isset( $form['avs_shipping'] ) || ! is_array( $form['avs_shipping'] ) ) {
			return;
		}
		$mode = isset( $form['avs_shipping']['mode'] ) ? sanitize_text_field( $form['avs_shipping']['mode'] ) : '';
		if ( '' === $mode || ! in_array( $mode, avs_valid_modes(), true ) ) {
			delete_user_meta( $user_id, self::META_MODE ); // Vacío = heredar del global.
		} else {
			update_user_meta( $user_id, self::META_MODE, $mode );
		}

		if ( isset( $form['avs_shipping']['fixed_rate'] ) && '' !== $form['avs_shipping']['fixed_rate'] ) {
			update_user_meta( $user_id, self::META_FIXED, max( 0.0, (float) $form['avs_shipping']['fixed_rate'] ) );
		} else {
			delete_user_meta( $user_id, self::META_FIXED );
		}

		$carrier = isset( $form['avs_shipping']['carrier'] ) ? avs_carrier_key( $form['avs_shipping']['carrier'] ) : '';
		if ( '' !== $carrier && array_key_exists( $carrier, avs_marketplace_carriers() ) ) {
			update_user_meta( $user_id, self::META_CARRIER, $carrier );
		} else {
			delete_user_meta( $user_id, self::META_CARRIER ); // Vacío = usar las del marketplace.
		}
	}

	/* ---------------------------------------------------------------------
	 * Utilidades
	 * ------------------------------------------------------------------- */

	/**
	 * Etiquetas legibles de cada modo.
	 *
	 * @return array<string,string>
	 */
	public static function mode_labels() {
		return array(
			'customer_pays'  => __( 'El cliente paga el envío', 'amazonia-vendor-shipping' ),
			'vendor_absorbs' => __( 'El vendedor absorbe el envío (gratis al cliente)', 'amazonia-vendor-shipping' ),
			'shared_fixed'   => __( 'Tarifa fija compartida', 'amazonia-vendor-shipping' ),
		);
	}
}
