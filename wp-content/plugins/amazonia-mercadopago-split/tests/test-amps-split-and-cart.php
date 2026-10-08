<?php
/**
 * Test unitario para lógica pura de Carrito Mono-tienda, Markup de flete y Split de Mercado Pago.
 *
 * Ejecutar con:
 *   C:\xampp\php\php.exe wp-content/plugins/amazonia-mercadopago-split/tests/test-amps-split-and-cart.php
 */

define( 'AMPS_TEST', true );

$passed = 0;
$failed = 0;

function assert_test( $condition, $title ) {
    global $passed, $failed;
    if ( $condition ) {
        echo "[PASS] {$title}\n";
        $passed++;
    } else {
        echo "[FAIL] {$title}\n";
        $failed++;
    }
}

echo "=== INICIO TESTS: Amazonia MP Split & Cart ===\n\n";

// 1. Test de Markup de Flete
echo "-- Test 1: Cálculo de Markup de Flete para Pasarela --\n";
function calc_shipping_with_markup( $base_cost, $pct, $fixed ) {
    if ( $base_cost <= 0 ) return 0.0;
    $final = ( $base_cost * ( 1 + ( $pct / 100 ) ) ) + $fixed;
    return ceil( $final / 100 ) * 100;
}

$base = 15000;
$result = calc_shipping_with_markup( $base, 5.0, 1000 );
// 15000 * 1.05 = 15750 + 1000 = 16750 -> ceil(16750 / 100) * 100 = 16800
assert_test( $result === 16800.0, "Flete base 15.000 con 5% + 1.000 COP y ceil a centena debe dar 16.800 COP (obtenido: {$result})" );

$base_odd = 15325;
$result_odd = calc_shipping_with_markup( $base_odd, 5.0, 1000 );
// 15325 * 1.05 = 16091.25 + 1000 = 17091.25 -> redondeado a centenas: 17100
assert_test( $result_odd === 17100.0, "Flete base 15.325 con redondeo a centena superior debe dar 17.100 COP (obtenido: {$result_odd})" );

// 2. Test de Cálculo de marketplace_fee (Split)
echo "\n-- Test 2: Cálculo de marketplace_fee (Split de Pagos) --\n";
function calc_marketplace_fee( $shipping_total, $items_total, $commission_pct ) {
    $platform_product_fee = ( $commission_pct > 0 )
        ? round( $items_total * ( $commission_pct / 100 ), 2 )
        : 0.0;
    return round( $shipping_total + $platform_product_fee, 2 );
}

// Caso 1: 0% comisión sobre producto (el cliente paga $100k de producto y $16.750 de flete)
$fee_zero_comm = calc_marketplace_fee( 16750.0, 100000.0, 0.0 );
assert_test( $fee_zero_comm === 16750.0, "Con 0% comisión, marketplace_fee debe ser exactamente el flete ($16.750) (obtenido: {$fee_zero_comm})" );

// Caso 2: 10% comisión sobre producto (el cliente paga $100k de producto y $16.750 de flete)
$fee_ten_comm = calc_marketplace_fee( 16750.0, 100000.0, 10.0 );
// 16.750 + 10.000 = 26.750
assert_test( $fee_ten_comm === 26750.0, "Con 10% comisión, marketplace_fee debe ser flete + $10.000 = $26.750 (obtenido: {$fee_ten_comm})" );

// Caso 3: Envío gratis ($0 flete) y 0% comisión
$fee_free_shipping = calc_marketplace_fee( 0.0, 50000.0, 0.0 );
assert_test( $fee_free_shipping === 0.0, "Con flete $0 y 0% comisión, marketplace_fee debe ser $0 (obtenido: {$fee_free_shipping})" );

// 3. Test de Validación de Carrito Mono-tienda
echo "\n-- Test 3: Validación lógica de Carrito Mono-tienda --\n";
function validate_cart_vendor_logic( $cart_vendors, $new_product_vendor ) {
    if ( empty( $cart_vendors ) ) {
        return true; // Carrito vacío, siempre permitido
    }
    foreach ( $cart_vendors as $v_id ) {
        if ( $v_id !== $new_product_vendor ) {
            return false; // Vendedores distintos -> bloqueo
        }
    }
    return true; // Mismo vendedor
}

$cart_with_vendor_5 = [ 5, 5 ];
assert_test( validate_cart_vendor_logic( $cart_with_vendor_5, 5 ) === true, "Mismo vendedor (ID 5): adición permitida" );
assert_test( validate_cart_vendor_logic( $cart_with_vendor_5, 8 ) === false, "Vendedor diferente (ID 8 vs ID 5): adición bloqueada" );
assert_test( validate_cart_vendor_logic( [], 8 ) === true, "Carrito vacío: adición permitida" );

echo "\n============================================\n";
echo "RESULTADO FINAL: {$passed} PASARON, {$failed} FALLARON\n";
echo "============================================\n";

exit( $failed > 0 ? 1 : 0 );
