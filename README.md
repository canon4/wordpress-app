# Marketplace Amazonia

Marketplace multivendedor construido sobre WordPress + WooCommerce + WCFM, orientado a
comunidades de artesanos ("Maestros Artesanos"). La plataforma no vende directamente:
intermedia entre comunidades vendedoras y clientes finales.

Sobre esa base se desarrollaron tres piezas propias: un tema a medida, un sistema de envíos
multivendedor estilo MercadoLibre y un asistente de IA para generar descripciones de producto.

**Principio de diseño rector:** no se modifica el núcleo de WordPress, WooCommerce, WCFM ni
Envia. Toda la funcionalidad propia se acopla mediante *hooks* (acciones y filtros), para poder
actualizar el software de terceros sin perder el trabajo propio.

---

## Arquitectura

| Capa | Software | Responsabilidad |
|---|---|---|
| CMS | WordPress | Base del sitio, usuarios, contenidos |
| Comercio | WooCommerce | Productos, carrito, checkout, pedidos |
| Multivendedor | WCFM Multivendor | Tiendas, comisiones, panel del vendedor, ledger |
| Logística | Plugin Envia (tercero) | Conexión con transportadoras |
| Pagos | Mercado Pago (+ Split) | Cobro y reparto entre vendedores |
| **Envíos propios** | **`amazonia-vendor-shipping`** | **Lógica multivendedor de envíos y guías** |
| **Pagos propios** | **`amazonia-mercadopago-split`** | **Split payments por vendedor** |
| **IA** | **`wcfm-ai-assistant`** | **Generación de descripciones de producto** |
| Presentación | `amazonia-theme` | Interfaz y flujos de usuario |

### Código propio

| Ruta | Qué es |
|---|---|
| [wp-content/themes/amazonia-theme](wp-content/themes/amazonia-theme) | Tema a medida (Tailwind compilado local, fuentes self-hosted, CPT `comunidad`, favoritos, plantillas Woo sobreescritas) |
| [wp-content/plugins/amazonia-vendor-shipping](wp-content/plugins/amazonia-vendor-shipping) | Envíos multivendedor sobre Envia + WCFM |
| [wp-content/plugins/amazonia-mercadopago-split](wp-content/plugins/amazonia-mercadopago-split) | Mercado Pago Split: cada vendedor conecta su cuenta y cobra directo |
| [wp-content/plugins/wcfm-ai-assistant](wp-content/plugins/wcfm-ai-assistant) | Descripciones culturales de producto con IA, con cuota por vendedor |

El resto de plugins (`woocommerce`, `wc-multivendor-*`, `wc-frontend-manager`,
`shipping-system-live-rates-fulfillment-envia`, `woocommerce-mercadopago`, `loco-translate`)
son de terceros.

### Modelo de envíos

Replica el de MercadoLibre:

- Cuenta de Envia **centralizada** (una sola para todo el marketplace).
- El envío se **cotiza automáticamente** según el origen de cada vendedor.
- **Quién paga es configurable**: cliente paga, vendedor absorbe, o tarifa fija compartida.
- Lo que el vendedor absorbe se **descuenta de su balance** en el ledger de WCFM.
- Cada vendedor **genera y descarga la guía** de sus pedidos desde su panel, sin entrar a Envia.

---

## Requisitos

| Componente | Versión / valor |
|---|---|
| PHP | 7.4+ (el contenedor usa 8.2) |
| Servidor web | Apache con `mod_rewrite` y `AllowOverride All` |
| Base de datos | MySQL / MariaDB |
| Docker | Solo para el despliegue en contenedor |
| Node.js | Solo para compilar Tailwind y regenerar los `.docx` de entrega |

---

## Instalación

### Opción A — Local con XAMPP (desarrollo)

1. **Clonar el repositorio** dentro de `htdocs`:

   ```bash
   git clone <url-del-repo> C:/xampp/htdocs/wordpress
   cd C:/xampp/htdocs/wordpress
   ```

2. **Crear la base de datos** y un usuario con permisos sobre ella:

   ```bash
   C:/xampp/mysql/bin/mysql.exe -u root -e "CREATE DATABASE wooecomerce CHARACTER SET utf8mb4;"
   ```

3. **Configurar las variables de entorno.** Copiar la plantilla y rellenarla:

   ```bash
   cp .env.example .env
   ```

   ```ini
   WORDPRESS_DB_HOST=localhost
   WORDPRESS_DB_NAME=wooecomerce
   WORDPRESS_DB_USER=...
   WORDPRESS_DB_PASSWORD=...
   WP_HOME=http://localhost/wordpress
   WP_SITEURL=http://localhost/wordpress
   ```

4. **Crear `wp-config.php`** a partir de `wp-config-sample.php`. No está versionado (contiene
   los salts). Generar salts nuevos en <https://api.wordpress.org/secret-key/1.1/salt/>.
   XAMPP no carga `.env` por sí solo: es `wp-config.php` quien lo lee.

5. **Importar la base de datos** si se parte de un respaldo, y arrancar Apache y MySQL desde el
   panel de XAMPP.

6. **Activar** el tema *Amazonia* y los plugins desde `wp-admin`, en este orden: WooCommerce →
   WCFM → Envia → plugins propios.

### Opción B — Docker (producción)

El `docker-compose.yml` asume una base de datos MySQL ya existente en una red Docker externa
llamada `red_global` (no levanta MySQL por su cuenta).

```bash
docker network create red_global   # solo si aún no existe
cp .env.example .env               # rellenar con los datos de producción
docker compose up -d --build
```

El sitio queda en `http://localhost:8081`. La imagen (`php:8.2-apache`) instala las extensiones
de PHP necesarias, habilita `mod_rewrite`, sube los límites de subida a 64 MB y expone un
healthcheck. `wp-content/uploads` se persiste en el volumen `wp_uploads`.

En producción, `WORDPRESS_DB_HOST` es el **nombre del contenedor** MySQL dentro de `red_global`
(por ejemplo `mysql_global`).

---

## Configuración post-instalación

- **Envia** — cargar la API key de la cuenta centralizada en los ajustes del plugin.
- **Envíos Amazonia** — definir la política de cobro (cliente paga / vendedor absorbe / tarifa
  fija) y verificar que cada vendedor tenga dirección de origen completa.
- **Mercado Pago Split** — configurar las credenciales del marketplace; cada vendedor conecta
  su propia cuenta desde su panel.
- **IA** — configurar la API key del proveedor y la cuota por vendedor.
- **Permalinks** — tras cualquier cambio estructural, guardar de nuevo en *Ajustes → Enlaces
  permanentes*. En [scripts/](scripts) hay utilidades de diagnóstico y de flush forzado.

---

## Pruebas

Los plugins propios traen suites ejecutables por CLI (no usan PHPUnit; son scripts que cargan
WordPress y reportan PASS/FAIL):

```bash
C:/xampp/php/php.exe wp-content/plugins/amazonia-vendor-shipping/tests/run-all.php
C:/xampp/php/php.exe wp-content/plugins/wcfm-ai-assistant/tests/run-all.php
```

Cubren configuración, split de envíos, ledger, hash de guías, validación, origen, transportadora,
generación de etiqueta, **control de acceso** y multivendedor. Los archivos `*-manual.md` junto a
los tests describen las verificaciones que requieren navegador.

El tema incluye además una suite de rendimiento propia en
[wp-content/themes/amazonia-theme/performance](wp-content/themes/amazonia-theme/performance)
(Lighthouse, waterfall de red, auditoría de imágenes).

---

## Seguridad

Puntos ya auditados y corregidos (ver historial de git):

- `wp-config.php` y `.env` **no se versionan** — el `.gitignore` cubre todas sus variantes.
- Envíos: control de acceso corregido y aislamiento de guías entre vendedores.
- IA: límite de gasto, cuota atómica (no evadible por concurrencia) y aislamiento del prompt
  frente a inyección.

Al desplegar, revisar que `.env` y `wp-config.php` no queden legibles desde la web y que los
salts sean únicos por entorno.

---

## Documentación

| Documento | Ruta |
|---|---|
| Manual técnico (instalación, operación, mantenimiento) | [entregables/fuentes/02-manual-tecnico.md](entregables/fuentes/02-manual-tecnico.md) |
| Informe de pruebas (funcionalidad, usabilidad, seguridad) | [entregables/fuentes/01-informe-de-pruebas.md](entregables/fuentes/01-informe-de-pruebas.md) |
| Código fuente documentado | [entregables/fuentes/03-codigo-fuente-documentado.md](entregables/fuentes/03-codigo-fuente-documentado.md) |
| Documentación técnica final | [entregables/fuentes/04-documentacion-tecnica-final.md](entregables/fuentes/04-documentacion-tecnica-final.md) |
| Integración Mercado Pago Split | [docs/integracion-mercadopago-split.md](docs/integracion-mercadopago-split.md) |
| Tema Amazonia | [wp-content/themes/amazonia-theme/README.md](wp-content/themes/amazonia-theme/README.md) |

Las fuentes en Markdown son la versión canónica; los `.docx` de `entregables/` son artefactos
regenerables — ver [entregables/README.md](entregables/README.md).

---

## Notas y trampas conocidas

- El `.env` admite comentarios **solo en líneas propias**. `WORDPRESS_DB_HOST` nunca lleva
  esquema: se escribe `localhost` o `1.2.3.4:3306`, nunca `http://1.2.3.4:3306`.
- El plugin de Envia tiene **parches de compatibilidad con PHP 8** aplicados a mano (operador
  `??` sobre claves de array no definidas). **Se pierden al actualizarlo** y hay que reaplicarlos.
  El plugin propio de envíos no depende de ellos.
- `.github/workflows/deploy.yml` está actualmente **vacío** (solo espacios en blanco): el
  pipeline de despliegue no hace nada. Hay que reescribirlo antes de confiar en CI/CD.
- `graphify-out/` son artefactos generados de un grafo de conocimiento; se regeneran y no se
  versionan.
