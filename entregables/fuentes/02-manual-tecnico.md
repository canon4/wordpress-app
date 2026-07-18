<!-- subtitle: Instalación, configuración, operación y mantenimiento -->
<!-- version: 1.0 -->

# Manual Técnico

## 1. Propósito

Este manual permite a una persona desarrolladora que llega nueva al proyecto **instalar, configurar, operar y mantener** el marketplace Amazonia sin necesidad de reconstruir el contexto desde cero.

---

## 2. Arquitectura

### 2.1 Visión general

Amazonia es un marketplace multivendedor construido sobre WordPress. La plataforma no vende directamente: intermedia entre comunidades vendedoras y clientes finales.

| Capa | Software | Responsabilidad |
|---|---|---|
| CMS | WordPress | Base del sitio, usuarios, contenidos |
| Comercio | WooCommerce | Productos, carrito, checkout, pedidos |
| Multivendedor | WCFM Multivendor | Tiendas, comisiones, panel del vendedor, ledger |
| Logística | Plugin Envia (tercero) | Conexión con transportadoras |
| **Envíos propios** | **Amazonia Vendor Shipping** | **Lógica multivendedor de envíos y guías** |
| **Asistencia IA** | **WCFM AI Assistant** | **Generación de descripciones de producto** |
| Presentación | Tema Amazonia | Interfaz y flujos de usuario |

### 2.2 Principio de diseño rector

**No se modifica el núcleo de WordPress, WooCommerce, WCFM ni Envia.** Toda la funcionalidad propia se acopla mediante *hooks* (acciones y filtros). Esto permite actualizar el software de terceros sin perder el trabajo propio.

La única excepción son unos parches de compatibilidad con PHP 8 aplicados al plugin de Envia (operador `??` en claves de array no definidas). **Estos parches se pierden si el plugin se actualiza** y deben reaplicarse. El plugin propio no depende de ellos.

### 2.3 Modelo de negocio de los envíos

El modelo replica el de MercadoLibre:

- La cuenta de Envia es **centralizada** (una sola para todo el marketplace).
- El envío se **cotiza automáticamente** según el origen de cada vendedor.
- **Quién paga es configurable**: el cliente paga, el vendedor absorbe, o tarifa fija compartida.
- Lo que el vendedor absorbe se **descuenta de su balance** en el ledger de WCFM.
- Cada vendedor **genera y descarga la guía** de sus pedidos desde su panel, sin iniciar sesión en Envia.

---

## 3. Instalación y entorno

### 3.1 Requisitos

| Componente | Versión / valor |
|---|---|
| PHP | 7.4 o superior |
| Servidor web | Apache (XAMPP), puerto 80 |
| Base de datos | MySQL / MariaDB |
| Node.js | Solo para regenerar los documentos de entrega |

### 3.2 Base de datos

| Parámetro | Valor en desarrollo |
|---|---|
| Esquema | `wooecomerce` |
| Prefijo de tablas | `wp_` |
| Cliente CLI | `C:\xampp\mysql\bin\mysql.exe` |
| Tabla de ledger | `wp_wcfm_marketplace_vendor_ledger` |
| Tabla pedido↔vendedor | `wp_wcfm_marketplace_orders` |

### 3.3 Variables de entorno

XAMPP no carga archivos `.env` automáticamente, así que `wp-config.php` lo hace manualmente. Variables relevantes:

| Variable | Uso |
|---|---|
| `WORDPRESS_DB_HOST` | Host de la base de datos |
| `WORDPRESS_DB_NAME` | Nombre del esquema |
| `WORDPRESS_DB_USER` | Usuario |
| `WORDPRESS_DB_PASSWORD` | Contraseña |
| `WP_HOME` / `WP_SITEURL` | URL pública del sitio |

**Trampa importante.** El `.env` admite comentarios, pero **solo en líneas propias**. Un comentario al final de una línea de valor se recorta correctamente desde ` #` (espacio seguido de almohadilla), pero conviene evitarlo. El host de la base de datos **nunca debe llevar esquema**: se escribe `localhost` o `1.2.3.4:3306`, nunca `http://1.2.3.4:3306`.

El archivo mantiene dos bloques: el de **producción** (comentado, con prefijo `# [PROD]`) y el **local** activo. Para cambiar de entorno se invierten los comentarios.

**Nunca se versiona el `.env`.** El `.gitignore` cubre `.env` y cualquier variante `.env.*`, salvo la plantilla `.env.example`.

### 3.4 Exposición pública (túnel)

Envia necesita alcanzar el sitio desde internet. Se usa ngrok:

```
ngrok http 80
curl -s http://localhost:4040/api/tunnels
```

La URL obtenida se coloca en `WP_HOME` y `WP_SITEURL`. **La URL cambia cada vez que se reinicia el túnel** en el plan gratuito.

`wp-config.php` detecta la cabecera `X-Forwarded-Proto: https` del proxy y fija `$_SERVER['HTTPS'] = 'on'`, lo cual evita los errores de *contenido mixto* detrás de la terminación TLS de ngrok.

---

## 4. Configuración del módulo de envíos

Pantalla: **WooCommerce → Envíos Amazonia**.

| Ajuste | Opción | Descripción |
|---|---|---|
| Modo de cobro | `avs_shipping_mode` | Quién paga el envío por defecto |
| Tarifa fija | `avs_shipping_fixed_rate` | Importe que paga el cliente en modo compartido |
| Transportadoras por defecto | `avs_default_carriers` | Las que se ofrecen si el vendedor no elige |
| API Key de Envia | `avs_envia_api_key` | Clave para **generar guías** |
| Entorno | `avs_envia_api_sandbox` | Conmuta entre sandbox y producción |

### 4.1 Las dos credenciales de Envia (fuente habitual de confusión)

Envia usa **dos credenciales distintas y no intercambiables**:

| Credencial | Dónde se configura | Para qué sirve | Host |
|---|---|---|---|
| Token OAuth | Plugin de Envia | Cotización de tarifas | `api-clients.envia.com`, `queries.envia.com` |
| **API Key** | Envíos Amazonia | **Generación de guías** | `api.envia.com` |

El token OAuth **no autentica** contra `api.envia.com` (responde 401). La API Key se obtiene en el panel de Envia, sección *Integraciones / API*.

Además, el **host depende del tipo de clave**: una clave de pruebas solo funciona contra `https://api-test.envia.com`, y una de producción solo contra `https://api.envia.com`. Usar la combinación cruzada devuelve 401. El conmutador *Entorno de Envia* selecciona el host.

### 4.2 Configuración por vendedor

Cada vendedor configura desde su panel de WCFM (sección de envío):

- **Costo de envío:** hereda del marketplace o lo sobrescribe.
- **Tarifa fija propia.**
- **Transportadora de su tienda:** elige una (la que tenga oficina de recogida cerca) y el cliente solo verá esa. Si no elige, se usan las del marketplace.

El **origen de envío** se registra automáticamente en Envia a partir de la dirección de la tienda, y puede resincronizarse con el botón *"Sincronizar con Envia"*.

### 4.3 Prerrequisito de producción pendiente

La cotización automática está **bloqueada por configuración en el panel de Envia**, no por código. En el panel *Envia Ecommerce Pro*, sobre la tienda "Amazonia market", falta:

1. Crear una regla en **"Reglas para envío Automático"** con las transportadoras a ofrecer.
2. Activar el conmutador **"Cotización Automática en el Checkout de la tienda"**.

Hasta entonces, el endpoint de cotización responde *"no carriers enabled"* (código 1365). Se descartó que la causa fuera el origen o el código: se probó con tres orígenes distintos y el error es el mismo.

---

## 5. Flujo funcional de extremo a extremo

1. **Cotización.** El cliente introduce su dirección. El sistema cotiza por vendedor, usando el origen de cada uno.
2. **Filtro de transportadora.** Se descartan las tarifas cuya transportadora no esté permitida para ese vendedor.
3. **Aplicación del modo de cobro.** Se ajusta el importe que ve el cliente y se registra cuánto absorbe el vendedor.
4. **Validación.** Si un vendedor no tiene origen válido y su paquete queda sin tarifas, **el checkout se bloquea** con un aviso claro.
5. **Pedido.** Se crea con el método `envia_shipping` y con los metadatos de transportadora, servicio, origen y vendedor.
6. **Ledger.** Lo que absorbe el vendedor se descuenta de su balance (operación idempotente).
7. **Guía.** El vendedor pulsa *"Generar guía"*; el sistema llama a `POST /ship/generate/` con **su** origen, guarda el PDF y el número de seguimiento **en su ítem de envío**, y le ofrece la descarga.

> La generación de guía es **manual y deliberada**: crea un envío real y consume saldo. Por eso pide confirmación explícita.

---

## 6. Operación y mantenimiento

### 6.1 Ejecutar las pruebas

```
C:\xampp\php\php.exe wp-content\plugins\amazonia-vendor-shipping\tests\run-all.php
```

Debe mostrar **11/11 PASS** y terminar con código 0.

> Si todos los tests aparecen en verde de forma sospechosa, verifique que la base de datos responde. El runner exige la marca `FASE n: PASS` precisamente porque WordPress, al no poder conectar, termina con código de salida 0 y antes eso se contaba como éxito.

### 6.2 Inspeccionar los metadatos de envío de un pedido

```
C:\xampp\php\php.exe wp-content\plugins\amazonia-vendor-shipping\tests\peek-shipping-meta.php
```

### 6.3 Diagnóstico rápido

| Síntoma | Causa probable | Acción |
|---|---|---|
| "Error al establecer una conexión con la base de datos" | `DB_HOST` con esquema `http://` o comentario dentro del valor | Revisar el `.env` |
| El checkout solo ofrece "Recogida local" | Envia no devuelve tarifas | Revisar reglas de envío automático en el panel de Envia |
| La guía devuelve 401 | Clave de pruebas contra host de producción (o viceversa) | Ajustar el conmutador de entorno |
| Error 422 al registrar el origen | Campos incorrectos en el payload | Envia exige `postal_code` y `city`, no `postalCode` |
| No aparece el botón de guía | El pedido no usó `envia_shipping` | Comportamiento correcto |

### 6.4 Caché de tarifas

WooCommerce **cachea las tarifas de envío por sesión y carrito**. Los scripts de prueba deben limpiar las claves `shipping_for_package_*` o verán resultados obsoletos.

### 6.5 Rendimiento del tema

La documentación de rendimiento del tema está en `wp-content/themes/amazonia-theme/`: `GUIA-RENDIMIENTO.md`, `CHANGELOG-RENDIMIENTO.md` y `performance/PLAN-CORRECCIONES.md`.

---

## 7. Regenerar los documentos de entrega

Las fuentes están en `entregables/fuentes/` en formato Markdown y se versionan en git. Los `.docx` son artefactos generados:

```
node md2docx.js entregables/fuentes/01-informe-de-pruebas.md entregables/01-informe-de-pruebas.docx
```

Para editar un documento se modifica el Markdown y se regenera; **no se edita el `.docx` a mano**, porque se sobrescribe.
