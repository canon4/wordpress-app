# Test Fase 2B — Verificación manual del checkout

Requiere un checkout real, por eso es manual. Los pasos asumen el túnel ngrok activo.

## Preparación
1. WooCommerce → Envíos → **Envíos Amazonia**: modo global = **El vendedor absorbe el envío**.
2. Un producto de un vendedor con **peso y dimensiones** definidos.
3. Zona de envío con el método **Envia** activo, y dirección de cliente con **código postal** válido.

## Casos

### A. vendor_absorbs (global)
- [ ] En el checkout, el método de Envia aparece en **$0** (gratis para el cliente).
- [ ] Se completa el pedido.
- [ ] En el pedido, el ítem de envío guarda `_avs_absorbed` = costo cotizado y `_avs_mode` = `vendor_absorbs`.
      Verificar con:
      `C:\xampp\php\php.exe tests\peek-shipping-meta.php <ORDER_ID>`

### B. customer_pays (override del vendedor)
- [ ] En el dashboard del vendedor → Ajustes → Envío: "Costo de envío Envia" = **El cliente paga**.
- [ ] En el checkout, Envia muestra el **costo cotizado completo**.
- [ ] El ítem de envío guarda `_avs_absorbed` = 0.

### C. shared_fixed
- [ ] Modo = **Tarifa fija compartida**, tarifa fija = 30.
- [ ] Envia muestra **30** al cliente (si el cotizado > 30).
- [ ] El ítem de envío guarda `_avs_absorbed` = cotizado − 30.

## Nota sobre impuestos
Al modificar el costo del rate, los impuestos de envío se escalan en proporción a lo que paga el
cliente (0 si el envío es gratis). Verificar que el total del pedido sea coherente.
