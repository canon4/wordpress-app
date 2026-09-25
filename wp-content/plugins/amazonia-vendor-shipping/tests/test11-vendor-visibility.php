<?php
/**
 * Test Fase 11 — Validación de dirección de tienda de vendedores, visibilidad de productos
 * y blindaje contra TypeError en envíos por país de WCFM.
 *
 *   C:\xampp\php\php.exe tests\test11-vendor-visibility.php
 *
 * @package Amazonia_Vendor_Shipping
 */

define( 'ABSPATH', 1 );
define( 'AVS_TEST', 1 );

require_once __DIR__ . '/../includes/avs-functions.php';
require_once __DIR__ . '/../includes/class-avs-validation.php';

$fails = array();

// 1. Verificar métodos en AVS_Validation
$expected_methods = array(
	'is_vendor_address_complete',
	'filter_product_visibility',
	'exclude_incomplete_vendors_from_query',
	'filter_product_purchasable',
	'validate_add_to_cart_vendor',
	'render_incomplete_vendor_notice',
	'render_vendor_dashboard_address_notice',
	'fix_wcfm_shipping_by_country_type_error',
);

foreach ( $expected_methods as $m ) {
	if ( ! method_exists( 'AVS_Validation', $m ) ) {
		$fails[] = "Método AVS_Validation::{$m} no existe.";
	}
}

// 2. Verificar blindaje de TypeError para shipping by country
// Simular cuando la metadata es un string vacío "" (PHP 8 TypeError)
$fixed = AVS_Validation::fix_wcfm_shipping_by_country_type_error( null, 123, '_wcfmmp_shipping_by_country', true );

if ( ! is_array( $fixed ) || ! isset( $fixed[0] ) || ! is_array( $fixed[0] ) ) {
	$fails[] = "fix_wcfm_shipping_by_country_type_error no devolvió el contenedor esperado para get_user_metadata.";
} else {
	$inner = $fixed[0];
	if ( ! isset( $inner['_wcfmmp_additional_qty'] ) || $inner['_wcfmmp_additional_qty'] !== 0 ) {
		$fails[] = "_wcfmmp_additional_qty debe estar definido como 0 en el fallback.";
	}
	if ( ! isset( $inner['_wcfmmp_shipping_type_price'] ) || $inner['_wcfmmp_shipping_type_price'] !== 0 ) {
		$fails[] = "_wcfmmp_shipping_type_price debe estar definido como 0 en el fallback.";
	}
}

// Para otra meta no debe alterar el valor
$other_meta = AVS_Validation::fix_wcfm_shipping_by_country_type_error( null, 123, '_some_other_key', true );
if ( null !== $other_meta ) {
	$fails[] = "fix_wcfm_shipping_by_country_type_error no debe interceptar otras claves de metadatos.";
}

// 3. Verificar validación de dirección con función pura
$complete_addr = array(
	'street'   => 'Cra 5 # 10-20',
	'city'     => 'Florencia',
	'state'    => 'CO-CAQ',
	'country'  => 'CO',
	'postcode' => '',
);

$incomplete_addr = array(
	'street'   => '',
	'city'     => 'Florencia',
	'state'    => 'CO-CAQ',
	'country'  => 'CO',
	'postcode' => '',
);

if ( ! avs_vendor_address_complete( $complete_addr ) ) {
	$fails[] = "Dirección completa para CO debería ser válida.";
}

if ( avs_vendor_address_complete( $incomplete_addr ) ) {
	$fails[] = "Dirección sin calle no debe ser considerada válida.";
}

if ( empty( $fails ) ) {
	echo "FASE 11: PASS\n";
	exit( 0 );
}

echo "FASE 11: FAIL\n";
foreach ( $fails as $f ) {
	echo "  - {$f}\n";
}
exit( 1 );
