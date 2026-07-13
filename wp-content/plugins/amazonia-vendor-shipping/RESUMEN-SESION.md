# Resumen de sesión — Envíos Amazonia (Envia + WCFM)

Documento de lo trabajado en esta sesión: entorno de pruebas, plugin nuevo
`amazonia-vendor-shipping`, parches al plugin de Envia, y diagnóstico del checkout.

---

## 1. Entorno de pruebas (túnel ngrok)

Se expone el WordPress local de XAMPP a internet para probar la integración con envia.com.

- **Servidor local:** XAMPP / Apache en el puerto **80**.
- **PHP CLI:** `C:\xampp\php\php.exe` (no hay WP-CLI ni PHPUnit instalados).
- **DB:** MySQL/MariaDB de XAMPP, base `wooecomerce`, prefijo `wp_`.
- **Túnel:** `ngrok http 80` (ngrok 3.39.8).
- **URL pública actual:** `https://sensation-foam-frostily.ngrok-free.dev`
- **Panel de inspección de ngrok:** http://localhost:4040 (y API en `http://localhost:4040/api/tunnels`).

### Cómo reactivar el túnel
```bash
ngrok http 80
```
Luego se obtiene la URL con:
```bash
curl -s http://localhost:4040/api/tunnels
```
- Si sale la **misma URL** (`sensation-foam-frostily...`), no hay que tocar nada.
- Si sale una **URL distinta** (posible en el plan gratuito), actualizar `WP_HOME` y
  `WP_SITEURL` en `.env`.

### Ajustes que hacen funcionar el túnel (ya aplicados)
En `wp-config.php`:
- Carga manual del `.env` (XAMPP no lo carga solo) con `putenv()` / `$_ENV`.
- Detección de `X-Forwarded-Proto: https` → fija `$_SERVER['HTTPS'] = 'on'`
  (arregla los errores de *Mixed Content* detrás de la terminación HTTPS de ngrok).
- `COOKIE_DOMAIN` dinámico según el host de `WP_HOME`.

En `.env`:
- `WP_HOME` / `WP_SITEURL` apuntando a la URL de ngrok.
- Credenciales de DB de Docker (`WORDPRESS_DB_HOST=mysql_global`, etc.) **comentadas**
  para no romper la conexión local (aplican los fallbacks `localhost`/`root`).

---

## 2. Parches al plugin de Envia (third-party)

> ⚠️ Estos parches se **pierden si se actualiza** el plugin de Envia. El plugin nuevo
> `amazonia-vendor-shipping` **no depende** de ellos.

Arreglo de *warnings* de PHP 8 (claves de array indefinidas) con el operador `??`:

- `source/classes/actions/envia-actions.php` — `$response['message']` /
  `['statusCode']` y `$data['message']` / `['statusCode']`.
- `source/classes/actions/envia-legacy/envia-legacy-actions.php` — 4 casos de
  `displayPickUp` (`?? 'list'`) y `useLabels` (`?? 'no'`).

---

## 3. Cómo funciona la integración con envia.com

- **Cuenta única / centralizada:** el plugin de Envia usa **una sola cuenta** (token OAuth)
  para todo el marketplace. Se guarda en la opción `woocommerce_envia_shipping_settings`
  (campos: `token`, `company`, `shop`, `user`, `enviaOrigin`, etc.).
- **Cotización en vivo:** en checkout, Envia arma un payload (origen + destino + items +
  packages) y llama a su API para traer tarifas.
- **Generación de guía:** vía un **iframe** a
  `https://shipping.envia.com/ecommerce?hash=BASE64&id=ORDER_ID`,
  donde `hash = base64("site_url:companyId:storeId")`.
- **Limitación clave:** el plugin de Envia **no guarda** en WordPress el PDF de la guía,
  el tracking ni el costo; el iframe es unidireccional (sin `postMessage`).

### Modelo elegido (estilo Mercado Libre)
Cuenta de envío centralizada, pero **costo configurable por vendedor**: el vendedor absorbe,
el cliente paga, o costo fijo compartido. Lo absorbido por el vendedor se descuenta de su
**ledger** de WCFM (`wp_wcfm_marketplace_vendor_ledger`).

---

## 4. Plugin nuevo: `amazonia-vendor-shipping`

Ruta: `wp-content/plugins/amazonia-vendor-shipping/`

Implementado en **6 fases**, cada una con su test. No modifica el panel admin de WCFM:
se engancha por hooks (mismo patrón que `wcfm-ai-assistant`).

### Estructura
```
amazonia-vendor-shipping.php        Bootstrap + singleton (carga en plugins_loaded pri 20)
includes/
  avs-functions.php                 Funciones puras (testeables sin WordPress)
  class-avs-config.php              Config global + override por vendedor (Fase 1)
  class-avs-checkout.php            Ajuste de tarifas de Envia en checkout (Fase 2)
  class-avs-ledger.php              Descuento al ledger del vendedor (Fase 3)
  class-avs-guide.php               Botón "Ver guía" en dashboard del vendedor (Fase 4)
  class-avs-validation.php          Validación de código postal por país (Fase 5)
tests/
  test00-bootstrap.php ... test05-validation.php   1 test por fase
  test02b-checkout-manual.md, test04b-guide-manual.md   checklists manuales (UI)
  run-all.php                       Corre todos los tests
  peek-shipping-meta.php            Utilidad: inspecciona meta de envío de un pedido
```

### Fases

| Fase | Qué hace | Test |
|------|----------|------|
| 0 | Bootstrap del plugin y carga de includes | `test00-bootstrap.php` |
| 1 | Config: modo de envío global + override por vendedor (user_meta) | `test01-config.php` |
| 2 | Ajusta las tarifas de Envia en checkout según el modo | `test02-split.php` + manual |
| 3 | Descuenta al ledger del vendedor lo que absorbe | `test03-ledger.php` |
| 4 | Botón "Generar / Ver guía" en el pedido del vendedor (iframe a Envia) | `test04-hash.php` + manual |
| 5 | Valida código postal requerido según país | `test05-validation.php` |
| 6 | *(Futuro / fuera del MVP)* API directa de Envia: guardar PDF/tracking en el pedido | — |

Estado: **Fases 0–5 completas, todos los tests automáticos en PASS.** Fase 6 pendiente.

### Funciones puras clave (`avs-functions.php`)
- `avs_calc_split($mode, $quoted, $fixed)` → `['paid'=>float, 'absorbed'=>float]`.
  Modos: `customer_pays`, `vendor_absorbs`, `shared_fixed`.
- `avs_build_envia_hash($site_url, $company_id, $store_id)` → hash base64 del iframe.
- `avs_valid_modes()` → `['customer_pays','vendor_absorbs','shared_fixed']`.
- `avs_postcode_required($country)` → `false` para
  `['CL','GT','CO','NG','PA','UY','HN']` (espeja la lista de exención de Envia).

### Hooks principales
- `woocommerce_package_rates` (pri 200) → ajusta el costo de tarifas `envia_shipping` y
  guarda meta `_avs_quoted` / `_avs_absorbed` / `_avs_mode` en la tarifa.
- `woocommerce_checkout_order_processed` (pri 20) → escribe al ledger (idempotente vía
  meta `_avs_ledger_done`).
- `wcfm_after_order_quick_actions` → pinta el botón de guía (solo si el pedido usó Envia).
- `woocommerce_after_checkout_validation` (pri 20) → valida el código postal.

### Cómo correr los tests
```bash
C:\xampp\php\php.exe wp-content\plugins\amazonia-vendor-shipping\tests\run-all.php
```

### Bug corregido durante la sesión
El botón "Generar / Ver guía" aparecía en **todos** los pedidos del vendedor, incluso los
que no usaron Envia (p. ej. "Recogida local", pedido #106). Se añadió el chequeo
`order_uses_envia($order_id)` en `class-avs-guide.php` antes de pintar el botón.
Además, el CSS/JS del modal se **incrustó inline** en `render_modal()` para no depender
del encolado ni del slug del dashboard.

---

## 5. Diagnóstico del checkout: "Enter a valid address to view shipping options"

**Síntoma:** en el checkout con dirección de Colombia (CP 180002, Florencia, Caquetá) solo
aparece "Recogida local ($10)" y no hay opción de tarifa de Envia. Mensaje:
*"Enter a valid address to view shipping options"*.

**Por qué el front no muestra opción de cambiar:** WooCommerce solo pinta radio buttons
cuando el array de tarifas tiene **2+ entradas**. Si Envia no agrega ninguna, queda solo
"Recogida local" y no hay nada entre qué elegir. No es un problema de la interfaz.

**Causa raíz confirmada** (diagnóstico sobre la config real):
- `woocommerce_envia_shipping_settings.enviaOrigin = "default"`.
- La instancia del método en la zona "Local" (`instance_id=1`) también tiene
  `enviaOrigin = "default"`.
- `"default"` es el **placeholder** que queda cuando **nunca se seleccionó** una dirección
  de origen real. En `envia-shipping.php:481` Envia manda `origin.address_id = "default"`
  a su API; Envia no lo reconoce, responde error (≈400), la excepción se atrapa en
  `calculate_shipping()` y **no se agrega tarifa** → solo queda "Recogida local".
- Detalle: la cuenta trae `shop=121827`, `company=735600`, `active=yes`, token presente,
  pero el dropdown de origen probablemente no tiene ninguna dirección real registrada en
  la cuenta de Envia (se cargan vía `GET /shop-default-address/{shop}`).

**Cómo se arregla (en el admin, sin tocar código):**
1. **WooCommerce → Ajustes → Envío → Envia.com** → campo *"Select an origin address"*.
2. Si solo aparece "Select a origin address" sin opciones, crear primero una **dirección
   de origen en la cuenta de Envia** (shipping.envia.com).
3. Refrescar la página del admin y seleccionar la dirección real (en el global y, si
   aplica, en la instancia del método dentro de la zona "Local").
4. Guardar y reprobar el checkout con la dirección de Colombia → debería aparecer la
   tarifa de Envia junto a "Recogida local".

**Nota sobre pesos/dimensiones:** el producto "Aretes Epeciales" tiene variantes sin peso
ni dimensiones (Envia asume 1kg/0cm por defecto, no bloquea la cotización por sí solo).
Conviene igual cargarles peso y medidas para tarifas correctas.

---

## 6. Pendientes

- [ ] Configurar la **dirección de origen** de Envia en el admin (bloqueante de la cotización).
- [ ] Cargar peso/dimensiones a los productos que no los tienen.
- [ ] Verificación manual de UI: `tests/test02b-checkout-manual.md` y
      `tests/test04b-guide-manual.md`.
- [ ] Fase 6 (futuro): integración directa con la API de Envia para guardar PDF/tracking
      en el propio pedido y eliminar la exposición del iframe con cuenta central.
