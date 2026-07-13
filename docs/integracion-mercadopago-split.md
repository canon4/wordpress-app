# Integración Mercado Pago Split — Marketplace Amazonia

> Documento generado a partir de la investigación de arquitectura de pagos para el marketplace de productos artesanales amazónicos.
> Stack: WordPress + WooCommerce 10.8.1 + WCFM Multivendor 3.7.3 + Tema Amazonia

---

## Contexto y decisiones de arquitectura

### Por qué Mercado Pago Split

El marketplace tiene comunidades indígenas y afro-amazónicas como vendedoras. El problema central es quién es responsable del dinero:

- **Modelo Agregador (Wompi):** el dinero llega al marketplace → lo retiene → lo dispersa. El admin del marketplace es custodio legal de fondos ajenos.
- **Modelo Split (Mercado Pago):** el dinero se divide *en el instante del pago* dentro de MP. El marketplace nunca toca el dinero del vendedor.

**Se eligió MP Split porque:**
1. Cada comunidad es propietaria de su dinero desde el primer segundo.
2. El marketplace actúa como gestor, no como custodio financiero.
3. MP tiene documentación activa para Colombia con PSE, tarjetas y wallet.
4. Hay plugin oficial de WooCommerce para el cobro al cliente.

### Sobre Nequi

Nequi (Bancolombia) no está disponible en Mercado Pago Colombia. El flujo recomendado para Nequi es complementario y manual:

- El grueso de pagos va por **MP Split** (automático, split real, sin custodia).
- Nequi se ofrece como método alternativo vía **Wompi** (que sí incluye Nequi nativo por ser también de Bancolombia).
- Para los pagos Nequi via Wompi, el split es manual (por ciclos quincenales desde el panel Wompi o via su API "Pagos a Terceros").

---

## Código existente relevante (no recrear)

### WCFM — Patrón de payment gateway para retiros

| Archivo | Propósito |
|---|---|
| `plugins/wc-multivendor-marketplace/core/class-wcfmmp-abstract-gateway.php` | Clase base abstracta — extender para nuevos gateways |
| `plugins/wc-multivendor-marketplace/core/class-wcfmmp-gateways.php` | Loader de gateways — registrar el nuevo aquí |
| `plugins/wc-multivendor-marketplace/helpers/wcfmmp-core-functions.php` | `get_wcfm_marketplace_withdrwal_payment_methods()` — agregar `mercado_pago` aquí |
| `plugins/wc-multivendor-marketplace/includes/payment-gateways/class-wcfmmp-gateway-stripe_split.php` | **Referencia directa** — mismo patrón para MP Split |

**Interfaz que debe implementar cualquier gateway WCFM:**
```php
public function validate_request(): bool
public function process_payment($withdrawal_id, $vendor_id, $withdraw_amount, $withdraw_charges, $transaction_mode): array
// retorna: ['status' => 'success|failed', 'message' => '...']
```

**Datos de vendedor ya almacenados en:**
```php
user_meta: wcfmmp_profile_settings
{
  payment: {
    method: 'mercado_pago',        // nuevo
    mercado_pago: {
      access_token: '...',         // OAuth token de la comunidad
      mp_user_id: '...',           // ID de cuenta MP del vendedor
      refresh_token: '...',        // para renovar cada 6 meses
      token_expires: '...'         // timestamp de expiración
    }
  }
}
```

### Plugin propio de referencia de patrones

| Plugin | Patrón que aporta |
|---|---|
| `plugins/wcfm-ai-assistant/` | REST API, nonce, singleton, hooks WCFM |
| `plugins/amazonia-vendor-shipping/` | Settings por vendedor, checkout integration, modular class structure |

**Estructura de plugin a replicar:**
```
plugins/amazonia-mercadopago/
├── amazonia-mercadopago.php          ← main file, singleton
├── includes/
│   ├── class-amp-oauth.php           ← flujo OAuth con MP
│   ├── class-amp-gateway.php         ← WC_Payment_Gateway (cobro al cliente)
│   ├── class-amp-split.php           ← lógica de split por carrito
│   ├── class-amp-webhook.php         ← receptor de notificaciones MP
│   ├── class-amp-wcfm-gateway.php    ← WCFMmp_Abstract_Gateway (retiros)
│   └── class-amp-settings.php        ← admin settings
├── assets/
│   ├── js/amp-checkout.js
│   └── css/amp-checkout.css
└── templates/
    └── vendor-connect.php            ← botón OAuth en panel WCFM
```

### Templates del tema ya sobreescritos (no modificar)

```
themes/amazonia-theme/woocommerce/checkout/
├── form-pay.php          ← lista de métodos de pago (MP aparece aquí automáticamente)
├── payment-method.php    ← render de cada método
└── thankyou.php          ← página de confirmación
```

---

## Plan de integración por fases

### Fase 0 — Prerequisitos (sin código)

**Objetivo:** tener credenciales y entorno listos antes de escribir una línea.

**Pasos:**
1. Crear cuenta en [developers.mercadopago.com.co](https://developers.mercadopago.com.co)
2. Crear una aplicación de tipo **Marketplace / Split de Pagos**
3. Obtener del panel:
   - `APP_ID` del marketplace
   - `ACCESS_TOKEN` del marketplace (producción y sandbox)
   - `PUBLIC_KEY` del marketplace
4. Activar "Split de Pagos" en la aplicación (puede requerir solicitud a MP)
5. Configurar la URL de redirección OAuth:
   `https://TU-DOMINIO/wp-json/amazonia-mp/v1/oauth/callback`
6. Verificar que ngrok está activo y apunta a `localhost` (ya tienen ngrok configurado)
7. Registrar la URL ngrok como redirect_uri en MP para pruebas locales

**Entregable:** credenciales sandbox listas, app MP configurada.

---

### Fase 1 — Plugin base `amazonia-mercadopago`

**Objetivo:** crear el plugin siguiendo el patrón de `amazonia-vendor-shipping`. Sin lógica de pago aún — solo estructura y settings.

**Archivos a crear:**

**`amazonia-mercadopago.php`** — main file con singleton:
```php
class Amazonia_MercadoPago {
    private static $instance;
    public static function instance() { ... }

    private function __construct() {
        add_action('plugins_loaded', [$this, 'init']);
    }

    public function init() {
        // Cargar clases
        // Registrar hooks
    }
}
Amazonia_MercadoPago::instance();
```

**`class-amp-settings.php`** — guarda en `wp_options.amazonia_mp_settings`:
```php
[
  'app_id'               => '',
  'access_token'         => '',
  'public_key'           => '',
  'sandbox_access_token' => '',
  'sandbox_public_key'   => '',
  'sandbox_mode'         => true,
  'commission_percent'   => 10,   // % que retiene el marketplace
]
```

**Entregable:** plugin activable, página de settings en WP Admin, sin errores.

---

### Fase 2 — OAuth: vinculación de comunidades

**Objetivo:** cada comunidad conecta su cuenta MP al marketplace con un clic.

**Flujo OAuth:**
```
Panel WCFM del vendedor
  → botón "Conectar con Mercado Pago"
  → redirige a MP OAuth URL
  → vendedor inicia sesión en MP y acepta
  → MP redirige a /wp-json/amazonia-mp/v1/oauth/callback?code=XXX
  → el plugin intercambia el code por access_token
  → guarda en wcfmmp_profile_settings del vendedor
  → redirige al panel WCFM con mensaje de éxito
```

**`class-amp-oauth.php`** — dos endpoints REST:

```php
// Genera URL de autorización para el vendedor
register_rest_route('amazonia-mp/v1', '/oauth/authorize', [
    'methods'             => 'GET',
    'callback'            => [$this, 'get_authorize_url'],
    'permission_callback' => [$this, 'is_vendor'],
]);

// Recibe el callback de MP y guarda el token
register_rest_route('amazonia-mp/v1', '/oauth/callback', [
    'methods'             => 'GET',
    'callback'            => [$this, 'handle_callback'],
    'permission_callback' => '__return_true',
]);
```

**Template del botón en panel WCFM** (hook: `wcfm_vendor_settings_fields_payment`):
```php
// Si el vendedor ya está conectado → mostrar estado + botón desconectar
// Si no → mostrar botón "Conectar con Mercado Pago"
```

**Datos guardados por vendedor** (reusar estructura existente de `wcfmmp_profile_settings`):
```php
$settings['payment']['mercado_pago'] = [
    'access_token'  => $token_response['access_token'],
    'mp_user_id'    => $token_response['user_id'],
    'refresh_token' => $token_response['refresh_token'],
    'token_expires' => time() + (180 * DAY_IN_SECONDS), // 6 meses
];
```

**Entregable:** vendedor puede conectar/desconectar su cuenta MP desde su panel WCFM.

---

### Fase 3 — WooCommerce Payment Gateway (cobro al cliente)

**Objetivo:** el método "Mercado Pago" aparece en el checkout y divide el pago automáticamente.

**`class-amp-gateway.php`** — extiende `WC_Payment_Gateway`:

```php
class AMP_Gateway extends WC_Payment_Gateway {

    public function __construct() {
        $this->id                 = 'amazonia_mercadopago';
        $this->method_title       = 'Mercado Pago';
        $this->has_fields         = false;
        $this->supports           = ['products'];
        // ...
    }

    public function process_payment($order_id) {
        $order       = wc_get_order($order_id);
        $vendor_id   = $this->get_vendor_from_order($order);    // detectar comunidad
        $access_token = $this->get_vendor_access_token($vendor_id); // de wcfmmp_profile_settings

        // Crear preferencia en MP con el split configurado
        $preference = $this->create_mp_preference($order, $access_token);

        return [
            'result'   => 'success',
            'redirect' => $preference['init_point'], // URL de pago MP
        ];
    }
}
```

**`class-amp-split.php`** — construye el objeto de preferencia MP:

```php
public function create_preference($order, $vendor_access_token) {
    $commission = get_option('amazonia_mp_settings')['commission_percent'];
    $total      = $order->get_total();
    $mp_fee     = $total * ($commission / 100);

    // POST a MP API con el access_token del VENDEDOR
    // y application_fee para retener la comisión del marketplace
    return mp_api_post('/checkout/preferences', [
        'items'           => $this->build_items($order),
        'application_fee' => $mp_fee,           // comisión del marketplace
        'back_urls'       => [...],
        'notification_url'=> home_url('/wp-json/amazonia-mp/v1/webhook'),
    ], $vendor_access_token);
}
```

**Punto importante:** la llamada a MP se hace con el `access_token` del **vendedor**, no del marketplace. MP automáticamente acredita el neto al vendedor y la `application_fee` al marketplace.

**Entregable:** método MP visible en checkout, redirige a MP, pago procesado en sandbox.

---

### Fase 4 — Webhook de confirmación

**Objetivo:** MP notifica al sitio cuando el pago se confirma, rechaza o reembolsa.

**`class-amp-webhook.php`** — endpoint REST:

```php
register_rest_route('amazonia-mp/v1', '/webhook', [
    'methods'             => 'POST',
    'callback'            => [$this, 'handle_notification'],
    'permission_callback' => '__return_true',
]);

public function handle_notification($request) {
    $type = $request->get_param('type');
    $id   = $request->get_param('data')['id'];

    if ($type === 'payment') {
        $payment = $this->mp_get_payment($id);

        switch ($payment['status']) {
            case 'approved':
                $this->complete_order($payment);   // wc_order->payment_complete()
                $this->register_wcfm_commission($payment); // registrar en WCFM
                break;
            case 'rejected':
                $this->fail_order($payment);
                break;
            case 'refunded':
                $this->refund_order($payment);
                break;
        }
    }
}
```

**Registro de comisión WCFM** — reusar hook existente:
```php
// WCFM ya tiene su sistema de comisiones activo.
// Solo asegurarse de que el order_status llegue a 'completed'
// y el hook 'wcfmmp_order_item_processed' se dispare normalmente.
// No duplicar la lógica de comisiones — WCFM la maneja sola.
do_action('woocommerce_payment_complete', $order_id);
```

**Entregable:** órdenes se actualizan automáticamente, WCFM registra comisiones.

---

### Fase 5 — Frontend y UX

**Objetivo:** experiencia fluida en el checkout sin modificar templates ya sobreescritos.

**Verificaciones:**
- [ ] El método MP aparece en `woocommerce/checkout/form-pay.php` (automático al registrar el gateway)
- [ ] Logo de MP se muestra correctamente (usar `$this->icon` en el gateway)
- [ ] La página de retorno post-pago usa el template existente `thankyou.php`
- [ ] En móvil: MP redirige a la app nativa si está instalada (comportamiento nativo de MP)

**JS mínimo** (`assets/js/amp-checkout.js`):
```javascript
// Solo si se usa Checkout Bricks (embebido, sin redirección)
// Para Checkout Pro (redirección), no se necesita JS custom
```

**Entregable:** checkout funcional en desktop y móvil, flujo completo de pago.

---

### Fase 6 — Testing y verificación

**Objetivo:** confirmar que el split llega correctamente a cada parte.

**Cuentas de prueba necesarias:**
1. Cuenta MP del **marketplace** (comprador de prueba)
2. Cuenta MP de una **comunidad/vendedor** de prueba
3. Tarjeta de prueba de MP Colombia

**Escenario de prueba principal:**

```
1. Vendedor de prueba conecta su cuenta MP (OAuth)
2. Cliente compra producto de esa comunidad por $100.000 COP
3. Paga con tarjeta de prueba MP
4. Verificar en dashboard MP del marketplace: recibió $8.000 (comisión 8%)
5. Verificar en dashboard MP de la comunidad: recibió $88.510 (neto - fee MP)
6. Verificar en WooCommerce: orden en estado "completada"
7. Verificar en WCFM: comisión registrada correctamente
8. Verificar webhook: llegó la notificación y procesó el estado
```

**Herramienta para probar webhooks localmente:**
```bash
# Ya tienen ngrok configurado — solo apuntar la URL en MP:
ngrok http 80
# URL del webhook: https://[ngrok-id].ngrok-free.app/wp-json/amazonia-mp/v1/webhook
```

**Tarjetas de prueba MP Colombia:**

| Número | Resultado |
|---|---|
| 4013 5406 8274 6260 | Aprobado |
| 4000 0000 0000 0002 | Rechazado |
| 4009 1753 3280 6176 | Pendiente |

**Entregable:** prueba exitosa end-to-end con split verificado en ambas cuentas.

---

## Resumen de fases

| Fase | Nombre | Depende de | Complejidad |
|---|---|---|---|
| 0 | Prerequisitos MP | — | Baja |
| 1 | Plugin base + settings | Fase 0 | Baja |
| 2 | OAuth vinculación vendedores | Fase 1 | Media |
| 3 | WooCommerce Gateway (cobro) | Fases 1 + 2 | Alta |
| 4 | Webhook confirmación | Fase 3 | Media |
| 5 | Frontend y UX | Fase 3 | Baja |
| 6 | Testing | Fases 1–5 | Media |

---

## Código a NO recrear

| Qué existe | Dónde | Cómo reusar |
|---|---|---|
| Abstract Gateway WCFM | `class-wcfmmp-abstract-gateway.php` | Extender para gateway de retiro |
| Stripe Split como referencia | `class-wcfmmp-gateway-stripe_split.php` | Leer antes de escribir la clase MP |
| Estructura de user_meta | `wcfmmp_profile_settings` | Agregar `mercado_pago` dentro del array `payment` existente |
| REST API pattern | `wcfm-ai-assistant` plugin | Mismo patrón de `register_rest_route` + nonce |
| Settings pattern | `amazonia-vendor-shipping` | `class-avs-config.php` como modelo |
| Checkout templates | `themes/amazonia-theme/woocommerce/checkout/` | No tocar — MP aparece automáticamente |
| WCFM comisiones | `class-wcfmmp-withdraw.php` | No duplicar — disparar `woocommerce_payment_complete` y dejar que WCFM actúe |

---

## Investigación de pasarelas (resumen de decisiones)

### Pasarelas evaluadas

| Pasarela | Tipo | Split nativo | Nequi | Disponible CO |
|---|---|---|---|---|
| **Mercado Pago Split** | LATAM | Sí | No | Sí |
| Wompi | Nacional CO | No (agrega+dispersa) | Sí | Sí |
| Stripe Connect | Global | Sí | No | Limitado |
| Wava | Nacional CO | No | Sí (nativo) | Sí |
| ePayco | Nacional CO | No | No | Sí |
| PayPal | Global | No (manual) | No | Sí |

### Por qué se descartó ePayco

ePayco no soporta Nequi (el método prioritario) y no tiene split payments nativos. Wompi lo supera en ambos aspectos para el mercado colombiano. ePayco solo tendría sentido si los compradores pagaran mucho en efectivo (Efecty/Baloto), que no es el caso prioritario del marketplace.

### Por qué Nequi no se integra directamente por vendedor

La API de Nequi Conecta no tiene modelo de "plataforma" — cada vendedor necesita su propio contrato con Nequi y sus propias credenciales API. Pedirle eso a una comunidad indígena amazónica es inviable operativamente. La solución práctica es:

1. **MP Split** para el grueso de pagos (tarjetas, PSE, wallet MP) — automático.
2. **Wompi** como método complementario para Nequi — custodia en marketplace, dispersión por ciclos.

### PSE vs Nequi

No son equivalentes. PSE es un protocolo bancario (requiere cuenta de ahorros/corriente), Nequi es una billetera digital. Aunque ambas son de Bancolombia, son entidades operativas distintas. Una transacción Nequi no aparece en el estado de cuenta Bancolombia y viceversa.

---

## Referencias técnicas

- [MP Split Payments Docs CO](https://www.mercadopago.com.co/developers/es/docs/split-payments/split-1-1/additional-content/security/oauth/introduction)
- [Integrate Checkout en marketplace](https://www.mercadopago.com.mx/developers/en/docs/split-payments/integration-configuration/integrate-marketplace)
- [Wompi Pagos a Terceros](https://docs.wompi.co/en/docs/colombia/que-es-pagos-a-terceros/)
- [Wompi WooCommerce Docs](https://docs.wompi.co/en/docs/colombia/woocommerce-wordpress-plugin/)
- [Nequi Conecta — Developers](https://conecta.nequi.com.co/)
- [Nequi QR Dinámico API](https://docs.conecta.nequi.com.co/?api=qrPayments)
