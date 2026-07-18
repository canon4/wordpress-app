<!-- subtitle: Estructura, convenciones y documentación del código propio -->
<!-- version: 1.0 -->

# Código Fuente Documentado

## 1. Alcance

Este documento describe el **código propio** del marketplace Amazonia: su organización, sus convenciones y la responsabilidad de cada pieza. No cubre el código de terceros (WordPress, WooCommerce, WCFM, plugin de Envia), que no se modifica.

El código está documentado **en el propio fuente** mediante bloques de documentación (`docblocks`) en español. Este documento es el mapa que permite navegarlo.

---

## 2. Convenciones de código

| Convención | Detalle |
|---|---|
| Prefijos | Clases `AVS_*`, funciones `avs_*`, opciones `avs_*`, metadatos `_avs_*` |
| Separación | Lógica pura sin dependencias de WordPress, aislada de la lógica de *hooks* |
| Acoplamiento | Todo por *hooks*; nunca se edita el núcleo de terceros |
| Idempotencia | Las operaciones con costo se protegen con metadatos (`_avs_ledger_done`, `_avs_label_url`) |
| Seguridad | Consultas preparadas, escapado en la salida, permisos que **fallan cerrados** |
| Documentación | `docblock` con propósito, `@param` y `@return` en toda clase y método público |

### 2.1 Por qué la lógica pura vive aparte

El archivo `includes/avs-functions.php` contiene funciones **sin ninguna dependencia de WordPress**. Esto no es un capricho de estilo: permite ejecutarlas con el intérprete de PHP directamente, sin cargar WordPress ni instalar PHPUnit, que no está disponible en el entorno.

Gracias a esa separación, los tests `test02` y `test04` siguieron funcionando incluso cuando la base de datos estaba caída, y fueron los únicos resultados fiables durante el diagnóstico.

---

## 3. Plugin: Amazonia Vendor Shipping

Ruta: `wp-content/plugins/amazonia-vendor-shipping/`

### 3.1 Estructura de archivos

```
amazonia-vendor-shipping.php     Bootstrap: singleton, constantes, carga en plugins_loaded
includes/
  avs-functions.php              Funciones puras (testeables sin WordPress)
  class-avs-config.php           Configuración global y por vendedor
  class-avs-origin.php           Origen de envío de cada vendedor en Envia
  class-avs-quote.php            Cotización por vendedor
  class-avs-checkout.php         Ajuste de tarifas en el checkout
  class-avs-ledger.php           Descuento al balance del vendedor
  class-avs-validation.php       Validación del checkout
  class-avs-label.php            Generación de guías por API y control de acceso
  class-avs-guide.php            Botón de guía en el panel del vendedor
tests/
  test00 .. test10               Un test por fase, más los de seguridad
  run-all.php                    Ejecuta todo el suite
  peek-shipping-meta.php         Utilidad de inspección
```

### 3.2 Mapa de responsabilidades

| Archivo | Responsabilidad | Test |
|---|---|---|
| `avs-functions.php` | Cálculos y normalizaciones puras | `test02`, `test04` |
| `class-avs-config.php` | Modo de cobro, tarifa fija, transportadoras, credenciales | `test01` |
| `class-avs-origin.php` | Registrar y sincronizar el origen del vendedor | `test06` |
| `class-avs-quote.php` | Cotizar el paquete de cada vendedor | — |
| `class-avs-checkout.php` | Aplicar el modo de cobro y filtrar transportadoras | `test07` |
| `class-avs-ledger.php` | Descontar al vendedor lo que absorbe | `test03` |
| `class-avs-validation.php` | Bloquear el checkout si falta el origen | `test05` |
| `class-avs-label.php` | Generar la guía y **decidir quién puede hacerlo** | `test08`, `test09`, `test10` |
| `class-avs-guide.php` | Pintar el botón de guía | `test09`, `test10` |

### 3.3 Funciones puras clave

| Función | Qué hace |
|---|---|
| `avs_calc_split($mode, $quoted, $fixed)` | Reparte el costo entre cliente y vendedor. Garantiza que el cliente nunca pague más que el costo real. |
| `avs_postcode_required($country)` | Indica si Envia exige código postal en ese país. Replica su lista de exenciones. |
| `avs_normalize_state($state)` | Convierte el estado de WooCommerce (`CO-CAQ`) al formato de Envia (`CAQ`). |
| `avs_carrier_key($carrier)` | Normaliza el nombre de la transportadora. Envia devuelve `serviEntrega`; se compara como `servientrega`. |
| `avs_carrier_allowed($carrier, $allowed)` | Decide si la transportadora está permitida. Lista vacía significa "sin restricción". |
| `avs_build_label_payload(...)` | Construye el cuerpo de la petición de guía. `settings.printSize` es obligatorio para Envia. |
| `avs_map_origin_form_payload($schema, $addr)` | Mapea la dirección al esquema que Envia define por país. Enviar campos de más devuelve error 422. |

### 3.4 Hooks registrados

| Hook | Prioridad | Efecto |
|---|---|---|
| `woocommerce_package_rates` | 200 | Ajusta el costo de las tarifas de Envia y guarda los metadatos del reparto |
| `woocommerce_checkout_order_processed` | 20 | Escribe el descuento al ledger (idempotente) |
| `woocommerce_after_checkout_validation` | 20 | Bloquea el checkout si el origen del vendedor no es válido |
| `wcfm_after_order_quick_actions` | 20 | Pinta el botón de guía en el panel del vendedor |
| `wcfmmp_settings_fields_shipping` | 20 | Añade los campos del vendedor a su panel |
| `rest_api_init` | — | Registra los endpoints `avs/v1` |

### 3.5 Endpoints REST

| Endpoint | Método | Quién puede |
|---|---|---|
| `avs/v1/generate-label` | POST | Administrador, o el vendedor **que participa en el pedido** |
| `avs/v1/sync-origin` | POST | Cualquier vendedor autenticado (solo sobre su propio origen) |

### 3.6 Modelo de datos

**Opciones (configuración global):**

| Opción | Contenido |
|---|---|
| `avs_shipping_mode` | Modo de cobro por defecto |
| `avs_shipping_fixed_rate` | Tarifa fija al cliente |
| `avs_default_carriers` | Transportadoras por defecto |
| `avs_envia_api_key` | Clave de API para generar guías |
| `avs_envia_api_sandbox` | Conmutador de entorno |

**Metadatos de usuario (por vendedor):**

| Metadato | Contenido |
|---|---|
| `_avs_shipping_mode` | Modo de cobro propio |
| `_avs_shipping_fixed_rate` | Tarifa fija propia |
| `_avs_carrier` | Transportadora elegida |
| `_avs_envia_origin_id` | Identificador de su dirección de origen en Envia |
| `_avs_envia_origin_sig` | Firma de la dirección, para detectar cambios |

**Metadatos del ítem de envío (por vendedor y pedido):**

| Metadato | Contenido |
|---|---|
| `_avs_label_url` | URL del PDF de la guía |
| `_avs_tracking` | Número de seguimiento |
| `_avs_tracking_url` | Enlace de rastreo |
| `_avs_label_carrier` | Transportadora que emitió la guía |

> **Decisión de diseño importante.** Estos metadatos viven en el **ítem de envío**, no en el pedido. Un pedido multivendedor comparte un mismo identificador y lleva un ítem de envío por vendedor. Guardarlos en el pedido hacía que la primera guía tapara a las demás y que un vendedor viera el PDF de otro, con la dirección del cliente. Ver el Informe de Pruebas, hallazgo S-2.

---

## 4. Plugin: WCFM AI Assistant

Ruta: `wp-content/plugins/wcfm-ai-assistant/`

| Archivo | Responsabilidad |
|---|---|
| `wcfm-ai-assistant.php` | Bootstrap, endpoints REST, permisos y límite de uso |
| `includes/class-ai-api.php` | Cliente de los proveedores de IA |
| `includes/class-admin-settings.php` | Pantalla de configuración y registro de consumo |
| `templates/ai-modal.php` | Ventana del asistente en el panel del vendedor |

Genera descripciones de producto a partir del contexto de la tienda (comunidad, tradiciones, ubicación). Admite varios proveedores; la clave se guarda en la opción `wcfm_ai_api_key`.

Incorpora un **límite mensual por vendedor** (50 generaciones por defecto, configurable), que no se aplica a los administradores. El consumo se registra en `wcfm_ai_usage_log`.

---

## 5. Lecciones de diseño extraídas de la auditoría

### 5.1 `function_exists()` no es un control de acceso

El código contenía este patrón, duplicado en dos lugares:

```
return ! function_exists( 'wcfm_is_order_for_vendor' ) || wcfm_is_order_for_vendor( $order_id );
```

La función no existe en WCFM. La expresión **concedía acceso a todo el mundo**. La regla que se deriva es inequívoca: **si una dependencia de seguridad no está disponible, se deniega el acceso, nunca se concede**.

La corrección resuelve la propiedad del pedido contra la tabla `wcfm_marketplace_orders`, que es la fuente de verdad, y **unifica la regla en un solo lugar** (`AVS_Label::can_manage_order()`), del que dependen tanto el endpoint como la interfaz. La lógica duplicada fue lo que permitió que el mismo error viviera dos veces.

### 5.2 No adivinar ante la ambigüedad

Cuando un pedido tiene varios paquetes y no se indica de qué vendedor es la guía, el sistema **rechaza la operación** en lugar de tomar "el primero". Tomar el primero era exactamente el origen del fallo S-2.

### 5.3 La entrada del cliente no decide la identidad

En el endpoint de guías, el identificador del vendedor se toma **de la sesión**, nunca del cuerpo de la petición. Solo un administrador puede indicarlo explícitamente, y únicamente para desambiguar.
