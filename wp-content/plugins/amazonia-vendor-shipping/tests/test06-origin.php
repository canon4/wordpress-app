<?php
/**
 * Test Fase 7 — Origen de envío por vendedor.
 *
 *   C:\xampp\php\php.exe tests\test06-origin.php
 *
 * Cubre la parte determinista: funciones puras de dirección/firma/payload, lectura de la
 * dirección del vendedor desde WCFM, y el round-trip del address_id. La parte viva
 * (registro real en Envia + cotización + bloqueo en checkout) se verifica en test06b (manual).
 *
 * @package Amazonia_Vendor_Shipping
 */

require __DIR__ . '/../../../../wp-load.php';

$fails = array();

/* ----------------------------------------------------------------------
 * 1. Funciones puras
 * -------------------------------------------------------------------- */
foreach ( array( 'avs_normalize_state', 'avs_vendor_address_complete', 'avs_vendor_origin_signature', 'avs_map_origin_form_payload' ) as $fn ) {
	if ( ! function_exists( $fn ) ) {
		$fails[] = "Falta la función pura {$fn}().";
	}
}

if ( empty( $fails ) ) {
	// Normalización de estado.
	if ( 'CAQ' !== avs_normalize_state( 'CO-CAQ' ) ) {
		$fails[] = "avs_normalize_state('CO-CAQ') debería ser 'CAQ'.";
	}
	if ( 'CAQ' !== avs_normalize_state( 'CAQ' ) ) {
		$fails[] = "avs_normalize_state('CAQ') debería ser 'CAQ'.";
	}
	if ( '' !== avs_normalize_state( '' ) ) {
		$fails[] = "avs_normalize_state('') debería ser ''.";
	}

	// Completitud: CO está exento de CP.
	$co_ok = array(
		'street'   => 'call 1',
		'city'     => 'Florencia',
		'state'    => 'CO-CAQ',
		'country'  => 'CO',
		'postcode' => '',
	);
	if ( true !== avs_vendor_address_complete( $co_ok ) ) {
		$fails[] = 'Dirección CO completa (sin CP, país exento) debería ser válida.';
	}
	$co_bad = $co_ok;
	$co_bad['city'] = '';
	if ( false !== avs_vendor_address_complete( $co_bad ) ) {
		$fails[] = 'Dirección sin ciudad debería ser inválida.';
	}
	$mx_no_cp = array(
		'street'   => 'calle',
		'city'     => 'CDMX',
		'state'    => 'MX-CMX',
		'country'  => 'MX',
		'postcode' => '',
	);
	if ( false !== avs_vendor_address_complete( $mx_no_cp ) ) {
		$fails[] = 'Dirección MX sin CP debería ser inválida (MX exige CP).';
	}
	$mx_cp = $mx_no_cp;
	$mx_cp['postcode'] = '01000';
	if ( true !== avs_vendor_address_complete( $mx_cp ) ) {
		$fails[] = 'Dirección MX con CP debería ser válida.';
	}

	// Firma: estable e insensible a cambios triviales, pero cambia con la dirección.
	$sig1 = avs_vendor_origin_signature( $co_ok );
	$sig2 = avs_vendor_origin_signature( $co_ok );
	if ( $sig1 !== $sig2 ) {
		$fails[] = 'La firma debería ser estable para la misma dirección.';
	}
	$changed = $co_ok;
	$changed['street'] = 'otra calle';
	if ( $sig1 === avs_vendor_origin_signature( $changed ) ) {
		$fails[] = 'La firma debería cambiar al cambiar la calle.';
	}

	// Payload de registro mapeado contra un esquema de país estilo Envia (CO).
	$schema = array(
		array( 'fieldName' => 'street', 'rules' => array( 'required' => true ) ),
		array( 'fieldName' => 'state', 'rules' => array( 'required' => true ) ),
		array( 'fieldName' => 'city_select', 'rules' => array( 'required' => true ) ),
		array( 'fieldName' => 'postal_code', 'rules' => array( 'required' => false ) ),
		array( 'fieldName' => 'reference', 'rules' => array( 'required' => false ) ),
		array( 'fieldName' => 'number', 'rules' => array( 'required' => false ) ), // no debería incluirse (opcional, sin dato)
	);
	$payload = avs_map_origin_form_payload(
		$schema,
		array(
			'name'     => 'Tienda X',
			'email'    => 'x@x.co',
			'phone'    => '123',
			'street'   => 'call 1',
			'number'   => 'carra 12',
			'city'     => 'Florencia',
			'state'    => 'CO-CAQ',
			'country'  => 'co',
			'postcode' => '180001',
		)
	);
	if ( 'CAQ' !== ( $payload['state'] ?? '' ) ) {
		$fails[] = 'El payload debería normalizar el estado a CAQ.';
	}
	if ( 'CO' !== ( $payload['country'] ?? '' ) ) {
		$fails[] = 'El payload debería poner el país en mayúsculas.';
	}
	if ( 'call 1' !== ( $payload['street'] ?? '' ) ) {
		$fails[] = 'El payload debería mapear la calle al campo street del esquema.';
	}
	if ( 'Florencia' !== ( $payload['city'] ?? '' ) ) {
		$fails[] = 'El payload debería mapear la ciudad al campo city.';
	}
	if ( array_key_exists( 'city_select', $payload ) ) {
		$fails[] = 'El payload NO debe incluir city_select (Envia lo rechaza: solo-UI).';
	}
	if ( '180001' !== ( $payload['postal_code'] ?? '' ) ) {
		$fails[] = 'El payload debería usar postal_code (no postalCode).';
	}
	if ( array_key_exists( 'postalCode', $payload ) ) {
		$fails[] = 'El payload NO debe incluir postalCode (Envia lo rechaza con 422).';
	}
}

/* ----------------------------------------------------------------------
 * 2. Clases y hooks
 * -------------------------------------------------------------------- */
if ( ! class_exists( 'AVS_Origin' ) ) {
	$fails[] = 'No existe la clase AVS_Origin.';
} else {
	foreach ( array( 'get_vendor_address', 'is_complete', 'get_origin_id', 'register_with_envia', 'maybe_register' ) as $m ) {
		if ( ! method_exists( 'AVS_Origin', $m ) ) {
			$fails[] = "Falta AVS_Origin::{$m}().";
		}
	}
	if ( ! has_action( 'wcfm_vendor_settings_before_update' ) ) {
		$fails[] = 'AVS_Origin no está enganchado a wcfm_vendor_settings_before_update.';
	}
}

if ( ! class_exists( 'AVS_Quote' ) ) {
	$fails[] = 'No existe la clase AVS_Quote.';
} else {
	// El filtro de origen no debe alterar la opción cuando no se está forzando ninguna cotización.
	$opt = array( 'enviaOrigin' => 'default', 'shop' => '1' );
	if ( AVS_Quote::filter_origin( $opt ) !== $opt ) {
		$fails[] = 'AVS_Quote::filter_origin no debería alterar la opción fuera de una cotización.';
	}
	if ( ! has_filter( 'option_woocommerce_envia_shipping_settings' ) ) {
		$fails[] = 'AVS_Quote no está enganchado a option_woocommerce_envia_shipping_settings.';
	}
}

if ( ! class_exists( 'AVS_Validation' ) || ! method_exists( 'AVS_Validation', 'validate_vendor_origin' ) ) {
	$fails[] = 'Falta AVS_Validation::validate_vendor_origin() (bloqueo por origen faltante).';
}

/* ----------------------------------------------------------------------
 * 3. Lectura real de un vendedor + round-trip del address_id
 * -------------------------------------------------------------------- */
if ( class_exists( 'AVS_Origin' ) ) {
	$vendors = get_users( array( 'role' => 'wcfm_vendor', 'number' => 1, 'fields' => 'ID' ) );
	if ( ! empty( $vendors ) ) {
		$vid  = (int) $vendors[0];
		$addr = AVS_Origin::get_vendor_address( $vid );
		foreach ( array( 'name', 'street', 'city', 'state', 'country', 'postcode' ) as $k ) {
			if ( ! array_key_exists( $k, $addr ) ) {
				$fails[] = "get_vendor_address({$vid}) no devolvió la clave '{$k}'.";
			}
		}

		// Round-trip del address_id (con backup/restore para no ensuciar datos reales).
		$prev_id  = get_user_meta( $vid, AVS_Origin::META_ORIGIN_ID, true );
		update_user_meta( $vid, AVS_Origin::META_ORIGIN_ID, 'TEST-999' );
		if ( 'TEST-999' !== AVS_Origin::get_origin_id( $vid ) ) {
			$fails[] = 'get_origin_id no lee el address_id guardado.';
		}
		if ( '' !== $prev_id ) {
			update_user_meta( $vid, AVS_Origin::META_ORIGIN_ID, $prev_id );
		} else {
			delete_user_meta( $vid, AVS_Origin::META_ORIGIN_ID );
		}
	} else {
		echo "  (aviso: no hay vendedores wcfm_vendor para probar lectura real)\n";
	}
}

/* ----------------------------------------------------------------------
 * Resultado
 * -------------------------------------------------------------------- */
if ( empty( $fails ) ) {
	echo "FASE 7: PASS\n";
	exit( 0 );
}
echo "FASE 7: FAIL\n";
foreach ( $fails as $f ) {
	echo "  - {$f}\n";
}
exit( 1 );
