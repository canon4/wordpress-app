<?php
/**
 * Test Fase 8 — Transportadora por vendedor.
 *
 *   C:\xampp\php\php.exe tests\test07-carrier.php
 *
 * Determinista: normalización/permitido de transportadoras (funciones puras) y la resolución
 * vendedor→por defecto en AVS_Config. El filtrado en el checkout se verifica en test07b (manual).
 *
 * @package Amazonia_Vendor_Shipping
 */

require __DIR__ . '/../../../../wp-load.php';

$fails = array();

/* ---- Funciones puras ---- */
foreach ( array( 'avs_marketplace_carriers', 'avs_carrier_key', 'avs_carrier_allowed' ) as $fn ) {
	if ( ! function_exists( $fn ) ) {
		$fails[] = "Falta la función pura {$fn}().";
	}
}

if ( empty( $fails ) ) {
	// Normalización (Envia manda "serviEntrega").
	if ( 'servientrega' !== avs_carrier_key( 'serviEntrega' ) ) {
		$fails[] = "avs_carrier_key('serviEntrega') debería ser 'servientrega'.";
	}
	if ( 'coordinadora' !== avs_carrier_key( 'Coordinadora' ) ) {
		$fails[] = "avs_carrier_key('Coordinadora') debería ser 'coordinadora'.";
	}

	// Permitido / no permitido.
	if ( true !== avs_carrier_allowed( 'coordinadora', array( 'coordinadora' ) ) ) {
		$fails[] = 'coordinadora debería estar permitida cuando está en la lista.';
	}
	if ( false !== avs_carrier_allowed( 'serviEntrega', array( 'coordinadora' ) ) ) {
		$fails[] = 'serviEntrega NO debería estar permitida si solo se permite coordinadora.';
	}
	if ( true !== avs_carrier_allowed( 'serviEntrega', array( 'servientrega' ) ) ) {
		$fails[] = 'El permitido debería ser insensible a mayúsculas (serviEntrega ~ servientrega).';
	}
	if ( true !== avs_carrier_allowed( 'loquesea', array() ) ) {
		$fails[] = 'Lista vacía = sin restricción: debería permitir cualquiera.';
	}
}

/* ---- AVS_Config: resolución vendedor → por defecto ---- */
if ( ! class_exists( 'AVS_Config' ) ) {
	$fails[] = 'No existe AVS_Config.';
} else {
	foreach ( array( 'get_vendor_carrier', 'get_default_carriers', 'allowed_carriers' ) as $m ) {
		if ( ! method_exists( 'AVS_Config', $m ) ) {
			$fails[] = "Falta AVS_Config::{$m}().";
		}
	}

	if ( empty( $fails ) ) {
		// Backup del estado real.
		$prev_opt = get_option( AVS_Config::OPT_DEFAULT_CARRIERS, array() );

		// 1) Lista por defecto del marketplace.
		update_option( AVS_Config::OPT_DEFAULT_CARRIERS, array( 'Coordinadora', 'servientrega', 'inexistente' ) );
		$def = AVS_Config::get_default_carriers();
		if ( array( 'coordinadora', 'servientrega' ) !== $def ) {
			$fails[] = 'get_default_carriers debería normalizar y descartar códigos inválidos. Dio: ' . wp_json_encode( $def );
		}

		// 2) Vendedor con transportadora fija.
		$vendors = get_users( array( 'role' => 'wcfm_vendor', 'number' => 1, 'fields' => 'ID' ) );
		if ( ! empty( $vendors ) ) {
			$vid      = (int) $vendors[0];
			$prev_car = get_user_meta( $vid, AVS_Config::META_CARRIER, true );

			update_user_meta( $vid, AVS_Config::META_CARRIER, 'coordinadora' );
			if ( array( 'coordinadora' ) !== AVS_Config::allowed_carriers( $vid ) ) {
				$fails[] = 'Vendedor con transportadora fija debería permitir solo esa.';
			}

			delete_user_meta( $vid, AVS_Config::META_CARRIER );
			if ( AVS_Config::allowed_carriers( $vid ) !== $def ) {
				$fails[] = 'Vendedor sin transportadora debería caer a la lista por defecto.';
			}

			// Restaurar.
			if ( '' !== $prev_car ) {
				update_user_meta( $vid, AVS_Config::META_CARRIER, $prev_car );
			}
		} else {
			echo "  (aviso: no hay vendedores para probar la resolución por vendedor)\n";
		}

		// Restaurar opción.
		update_option( AVS_Config::OPT_DEFAULT_CARRIERS, $prev_opt );
	}
}

if ( empty( $fails ) ) {
	echo "FASE 8: PASS\n";
	exit( 0 );
}
echo "FASE 8: FAIL\n";
foreach ( $fails as $f ) {
	echo "  - {$f}\n";
}
exit( 1 );
