<?php
/**
 * Test Fase 6 — Generación de guía por API.
 *
 *   C:\xampp\php\php.exe tests\test08-label.php
 *
 * Determinista: constructores puros del payload de guía, API key en config, y la clase AVS_Label.
 * La generación real contra Envia se prueba en test08b (manual, requiere API key y saldo).
 *
 * @package Amazonia_Vendor_Shipping
 */

require __DIR__ . '/../../../../wp-load.php';

$fails = array();

/* ---- Funciones puras del payload ---- */
foreach ( array( 'avs_label_address', 'avs_build_label_package', 'avs_build_label_payload' ) as $fn ) {
	if ( ! function_exists( $fn ) ) {
		$fails[] = "Falta la función pura {$fn}().";
	}
}

if ( empty( $fails ) ) {
	// Dirección normalizada.
	$addr = avs_label_address( array(
		'name' => 'Tienda', 'email' => 'a@a.co', 'phone' => '1',
		'street' => 'call 1', 'number' => 'n', 'city' => 'Florencia',
		'state' => 'CO-CAQ', 'country' => 'co', 'postcode' => '180001',
	) );
	if ( 'CAQ' !== $addr['state'] ) {
		$fails[] = 'avs_label_address debería normalizar el estado a CAQ.';
	}
	if ( 'CO' !== $addr['country'] ) {
		$fails[] = 'avs_label_address debería poner el país en mayúsculas.';
	}
	if ( '180001' !== $addr['postalCode'] ) {
		$fails[] = 'avs_label_address debería mapear postcode → postalCode.';
	}

	// Paquete con defaults seguros.
	$pkg = avs_build_label_package( 0, 0, 0, 0, -5, 'X' );
	if ( 1.0 !== $pkg['weight'] ) {
		$fails[] = 'Peso 0 debería caer al default 1kg.';
	}
	if ( 10.0 !== $pkg['dimensions']['length'] ) {
		$fails[] = 'Dimensión 0 debería caer al default 10cm.';
	}
	if ( 0.0 !== $pkg['declaredValue'] ) {
		$fails[] = 'Valor declarado negativo debería normalizarse a 0.';
	}

	// Payload completo.
	$payload = avs_build_label_payload(
		array( 'name' => 'O', 'city' => 'Florencia', 'state' => 'CO-CAQ', 'country' => 'CO' ),
		array( 'name' => 'D', 'city' => 'Bogota', 'state' => 'CO-DC', 'country' => 'CO' ),
		array( $pkg ),
		array( 'carrier' => 'interRapidisimo', 'service' => 'ground_small' )
	);
	if ( 1 !== ( $payload['shipment']['type'] ?? null ) ) {
		$fails[] = 'El payload debería forzar shipment.type=1 (etiqueta).';
	}
	if ( 'interRapidisimo' !== ( $payload['shipment']['carrier'] ?? '' ) ) {
		$fails[] = 'El payload debería conservar el carrier del envío.';
	}
	if ( 'PDF' !== ( $payload['settings']['printFormat'] ?? '' ) ) {
		$fails[] = 'El payload debería pedir printFormat PDF.';
	}
	if ( ! isset( $payload['origin']['state'] ) || 'CAQ' !== $payload['origin']['state'] ) {
		$fails[] = 'El origen del payload debería estar normalizado.';
	}
}

/* ---- Config: API key ---- */
if ( ! class_exists( 'AVS_Config' ) || ! method_exists( 'AVS_Config', 'get_api_key' ) ) {
	$fails[] = 'Falta AVS_Config::get_api_key().';
} else {
	$prev = get_option( AVS_Config::OPT_API_KEY, '' );
	update_option( AVS_Config::OPT_API_KEY, '  test-key-123  ' );
	if ( 'test-key-123' !== AVS_Config::get_api_key() ) {
		$fails[] = 'get_api_key debería devolver la key sin espacios.';
	}
	update_option( AVS_Config::OPT_API_KEY, $prev );
}

/* ---- Clase AVS_Label ---- */
if ( ! class_exists( 'AVS_Label' ) ) {
	$fails[] = 'No existe la clase AVS_Label.';
} else {
	foreach ( array( 'generate', 'has_label', 'get_label_url', 'get_tracking', 'rest_can', 'rest_generate' ) as $m ) {
		if ( ! method_exists( 'AVS_Label', $m ) ) {
			$fails[] = "Falta AVS_Label::{$m}().";
		}
	}
	// Un pedido sin meta de guía no debe reportar guía.
	$orders = wc_get_orders( array( 'limit' => 1, 'orderby' => 'date', 'order' => 'DESC', 'return' => 'ids' ) );
	if ( ! empty( $orders ) ) {
		$oid = (int) $orders[0];
		if ( '' === AVS_Label::get_label_url( $oid ) && false !== AVS_Label::has_label( $oid ) ) {
			$fails[] = 'has_label debería ser false cuando no hay URL de guía.';
		}
	}
}

/* ---- Guía: botón enganchado ---- */
if ( ! has_action( 'wcfm_after_order_quick_actions' ) ) {
	$fails[] = 'AVS_Guide no está enganchado a wcfm_after_order_quick_actions.';
}

if ( empty( $fails ) ) {
	echo "FASE 6: PASS\n";
	exit( 0 );
}
echo "FASE 6: FAIL\n";
foreach ( $fails as $f ) {
	echo "  - {$f}\n";
}
exit( 1 );
