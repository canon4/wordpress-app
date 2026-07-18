<!-- subtitle: Pruebas de funcionalidad, usabilidad y seguridad -->
<!-- version: 1.0 -->

# Informe de Pruebas

## 1. Introducción

### 1.1 Objetivo

Este informe documenta las pruebas de **funcionalidad**, **usabilidad** y **seguridad** aplicadas al marketplace Amazonia, con foco en el código propio desarrollado para la plataforma. Su propósito es dejar constancia verificable de qué se probó, cómo se probó, qué resultado se obtuvo y qué defectos se encontraron y corrigieron.

### 1.2 Alcance

Se evaluó exclusivamente el **código propio** del proyecto:

| Componente | Ruta | Función |
|---|---|---|
| Amazonia Vendor Shipping | `wp-content/plugins/amazonia-vendor-shipping/` | Envíos multivendedor sobre Envia + WCFM |
| WCFM AI Assistant | `wp-content/plugins/wcfm-ai-assistant/` | Asistente de IA para el dashboard del vendedor |
| Tema Amazonia | `wp-content/themes/amazonia-theme/` | Capa de presentación y flujos de usuario |

Quedan **fuera del alcance** el núcleo de WordPress, WooCommerce, WCFM y el plugin de Envia (software de terceros), así como la integración de Mercado Pago Split, que a la fecha no está implementada.

### 1.3 Entorno de pruebas

| Elemento | Valor |
|---|---|
| Servidor | XAMPP / Apache (puerto 80) |
| PHP (CLI) | `C:\xampp\php\php.exe` |
| Base de datos | MySQL/MariaDB, esquema `wooecomerce`, prefijo `wp_` |
| Exposición pública | Túnel `ngrok http 80` (para las llamadas de Envia) |
| Marco de pruebas | Scripts PHP autónomos (no hay PHPUnit ni WP-CLI instalados) |

---

## 2. Pruebas de funcionalidad

### 2.1 Estrategia

El plugin de envíos se construyó por fases, y cada fase tiene un test automatizado que se ejecuta desde la línea de comandos. Los tests son de dos tipos:

- **Puros:** ejercitan funciones sin dependencias de WordPress (se activan con `define('AVS_TEST', true)`). Validan la lógica de negocio aislada.
- **De integración:** cargan `wp-load.php` y operan contra la base de datos real, verificando hooks, opciones, metadatos y clases registradas.

Comando de ejecución:

```
C:\xampp\php\php.exe wp-content\plugins\amazonia-vendor-shipping\tests\run-all.php
```

### 2.2 Defecto crítico detectado en el propio marco de pruebas

Antes de poder confiar en cualquier resultado, se detectó que **el suite reportaba "9/9 PASS" de forma falsa**.

**Causa raíz.** El runner (`tests/run-all.php`) decidía el resultado únicamente por el *código de salida* del proceso hijo. Cuando WordPress no logra conectar con la base de datos, ejecuta `wp_die()`, vuelca una página HTML de error y **termina con código de salida 0**. En consecuencia, siete de los nueve tests estaban muriendo antes de ejecutar una sola aserción, y el runner los contaba como aprobados.

**Impacto.** La evidencia de pruebas era inválida: se estaba certificando como correcto un código que nunca se ejecutó.

**Corrección.** El runner ahora exige **dos** condiciones para dar por aprobado un test: código de salida 0 **y** la presencia de la marca explícita `FASE n: PASS` en la salida. Además, si detecta una página de error de WordPress, lo informa con un mensaje legible en lugar de volcar el HTML.

**Verificación de la corrección.** Se forzó un host de base de datos inválido y se confirmó que el runner ahora reporta `FAIL` y termina con código 1. Los dos tests puros siguen aprobando, lo cual es el comportamiento correcto: no dependen de WordPress.

### 2.3 Casos de prueba automatizados

Resultado tras corregir el entorno y el runner: **11 de 11 aprobados**.

| Test | Fase | Qué valida | Resultado |
|---|---|---|---|
| `test00-bootstrap.php` | 0 | Carga del plugin y de sus módulos | PASS |
| `test01-config.php` | 1 | Resolución del modo de cobro (override del vendedor sobre el global) | PASS |
| `test02-split.php` | 2 | Reparto del costo de envío entre cliente y vendedor | PASS |
| `test03-ledger.php` | 3 | Descuento al balance (ledger) del vendedor | PASS |
| `test04-hash.php` | 4 | Construcción del hash del panel de Envia | PASS |
| `test05-validation.php` | 5 | Exigencia de código postal según el país | PASS |
| `test06-origin.php` | 7 | Registro del origen de envío por vendedor | PASS |
| `test07-carrier.php` | 8 | Filtro de transportadoras permitidas por vendedor | PASS |
| `test08-label.php` | 6 | Construcción del payload de guía y clase de etiquetas | PASS |
| `test09-access.php` | 9 | **Control de acceso a la guía** (regresión de vulnerabilidad) | PASS |
| `test10-multivendor-label.php` | 10 | **Aislamiento de guías entre vendedores** (regresión) | PASS |

Los tests 09 y 10 se incorporaron en esta campaña de pruebas para cubrir los dos fallos de seguridad descritos en la sección 4.

Al módulo de IA, que no tenía ninguna prueba automatizada, se le construyó un suite propio:

```
C:\xampp\php\php.exe wp-content\plugins\wcfm-ai-assistant\tests\run-all.php
```

| Test | Qué valida | Resultado |
|---|---|---|
| `test01-input.php` | Lista blanca de campos, topes de longitud, neutralización y validación de ajustes | PASS |
| `test02-quota.php` | Reserva atómica de cuota y comportamiento bajo concurrencia | PASS |
| `test03-prompt.php` | Delimitación del contenido del usuario y validación del proveedor | PASS |

**Total: 14 de 14 pruebas automatizadas aprobadas.**

Un apunte metodológico: `test03` **falló en su primera ejecución** y detectó un defecto en la propia corrección recién escrita — las instrucciones de seguridad mencionaban los delimitadores de forma literal, lo que los duplicaba y volvía ambigua la frontera del bloque de datos. Se rediseñó usando un token aleatorio por petición. Es exactamente la función que debe cumplir una prueba de regresión: fallar cuando el arreglo es incorrecto.

### 2.4 Reglas de negocio verificadas

El reparto del costo de envío (`avs_calc_split`) admite tres modos, todos cubiertos por pruebas:

| Modo | Paga el cliente | Absorbe el vendedor |
|---|---|---|
| `customer_pays` | Costo cotizado completo | Nada |
| `vendor_absorbs` | Nada (envío gratis) | Costo cotizado completo |
| `shared_fixed` | Tarifa fija configurada | La diferencia |

Se verificó una invariante importante: en `shared_fixed`, **el cliente nunca paga más que el costo real** del envío, aunque la tarifa fija configurada sea superior.

### 2.5 Pruebas manuales de extremo a extremo

Los flujos que dependen de la interfaz o de la API real de Envia se cubren con listas de verificación manuales, documentadas en el repositorio:

- `tests/test02b-checkout-manual.md` — aplicación del modo de cobro en el checkout.
- `tests/test04b-guide-manual.md` — botón de guía en el panel del vendedor.
- `tests/test06b-origin-manual.md` — sincronización del origen del vendedor con Envia.
- `tests/test07b-carrier-manual.md` — filtro de transportadora por vendedor.
- `tests/test08b-label-manual.md` — generación real de la guía contra la API de Envia.

### 2.6 Limitación conocida del entorno

Actualmente **ningún pedido utiliza el método `envia_shipping`**. La cotización automática está bloqueada por configuración en el panel de Envia Ecommerce Pro (no por código): falta crear una regla de envío automático y activar el conmutador de *cotización automática en el checkout*. Mientras eso siga así, los flujos de guía solo pueden ejercitarse de forma sintética, como hace `test10`.

---

## 3. Pruebas de usabilidad

### 3.1 Método

Se recorrieron las tareas principales de cada rol siguiendo las guías de usuario del proyecto, evaluando claridad de los mensajes, número de pasos y capacidad de recuperación ante el error.

### 3.2 Hallazgos por rol

**Administrador.** La configuración de envíos está centralizada en una única pantalla (*WooCommerce → Envíos Amazonia*), lo cual es acertado. Se valoró positivamente que el campo de API key muestre un indicador explícito de estado (*"✓ Configurada"* / *"✗ Falta configurarla"*), en lugar de dejar al administrador adivinar si el valor quedó guardado.

**Vendedor.** El flujo de generación de guía pide confirmación explícita antes de actuar, con un mensaje que advierte de la consecuencia real: *"Esto crea un envío real con la transportadora y consume saldo de la cuenta de Envia"*. Es el comportamiento correcto para una acción irreversible y con costo.

**Cliente.** Se verificó que, cuando un vendedor no tiene un origen de envío válido, el checkout **se bloquea con un aviso explícito** en lugar de dejar al cliente ante una lista de envíos vacía sin explicación.

### 3.3 Fricciones detectadas

| # | Observación | Severidad | Recomendación |
|---|---|---|---|
| U-1 | WooCommerce solo dibuja los botones de selección de envío cuando hay dos o más tarifas. Con una sola opción, el cliente no percibe que exista elección. | Baja | Comportamiento del núcleo; documentar, no parchear. |
| U-2 | Productos sin peso ni dimensiones producen tarifas imprecisas (Envia asume valores por defecto). | Media | Hacer obligatorios peso y medidas al publicar un producto. |
| U-3 | El mensaje de error de la API de Envia se muestra al vendedor tal cual lo devuelve el proveedor, en inglés y con jerga técnica. | Media | Traducir y simplificar los errores más frecuentes. |

---

## 4. Pruebas de seguridad

### 4.1 Método

Revisión manual del código propio orientada a los riesgos reales de un marketplace multivendedor: control de acceso entre vendedores, exposición de credenciales, validación de entradas, escapado de salidas e idempotencia de operaciones con costo económico. Las hipótesis se confirmaron ejecutando código contra la base de datos real.

### 4.2 Resumen de hallazgos

| ID | Hallazgo | Componente | Severidad | Estado |
|---|---|---|---|---|
| S-1 | Cualquier vendedor podía generar la guía de **cualquier** pedido | Envíos | Crítica | Corregido |
| S-2 | En pedidos multivendedor, un vendedor podía ver y descargar la guía de otro | Envíos | Alta | Corregido |
| S-3 | La guía se generaba con el peso y el valor del pedido completo, no del paquete del vendedor | Envíos | Media | Corregido |
| S-4 | El suite de pruebas certificaba como correcto código que no se ejecutaba | Pruebas | Alta | Corregido |
| S-5 | Copia de seguridad del `.env` quedaba fuera de `.gitignore` | Repositorio | Media | Corregido |
| S-6 | La clave de Gemini viajaba en la URL | IA | Baja | Corregido |
| S-7 | `wp-config.php` versionado con los 8 salts reales en repositorio público | Repositorio | Alta | Corregido |
| S-8 | `reset-admin.php` con credenciales de administrador embebidas, accesible por URL | Repositorio | Crítica | Corregido |
| A-1 | Entrada a la IA sin tope de longitud: abuso de costo | IA | Alta | Corregido |
| A-2 | Límite mensual de IA evadible por concurrencia | IA | Alta | Corregido |
| A-3 | Inyección de prompt: el texto del cliente llegaba verbatim al modelo | IA | Media-Alta | Corregido |
| A-4 | El contexto del vendedor lo enviaba el cliente, no el servidor | IA | Media | Corregido |
| A-5 | API key de IA renderizada en el HTML de la pantalla de administración | IA | Media | Corregido |
| A-6 | Los cuatro ajustes de IA se guardaban sin `sanitize_callback` | IA | Media | Corregido |
| A-7 | El modelo se interpolaba en la URL de Gemini sin validar | IA | Baja | Corregido |
| A-8 | El registro de uso de IA se autocargaba en cada petición | IA | Baja | Corregido |

### 4.3 S-1 — Escalada horizontal de privilegios en la generación de guías

**Severidad: Crítica.** Clasificación OWASP: A01 *Broken Access Control* (referencia directa insegura a objeto).

**Descripción.** El endpoint REST `POST /wp-json/avs/v1/generate-label` protegía su acceso así:

```
return ! function_exists( 'wcfm_is_order_for_vendor' ) || wcfm_is_order_for_vendor( $order_id );
```

La función `wcfm_is_order_for_vendor()` **no existe en WCFM**. Se verificó por búsqueda exhaustiva en todo el árbol de plugins. Por tanto `function_exists(...)` devolvía `false`, su negación devolvía `true`, y la expresión completa **concedía el acceso sin comprobar nada**. La misma lógica estaba duplicada en el control de visibilidad del botón.

Es el patrón clásico de *fail-open*: usar `function_exists()` como guardia de permisos hace que la ausencia de la dependencia se traduzca en acceso concedido.

**Impacto.** Cualquier vendedor autenticado podía generar la guía de cualquier pedido del marketplace, incluidos los de otros vendedores. Consecuencias:

1. **Costo económico real:** generar una guía crea un envío real y consume saldo de la cuenta central de Envia.
2. **Exposición de datos personales del cliente:** la respuesta devuelve la URL del PDF, y ese PDF contiene el nombre, la dirección y el teléfono del comprador.
3. **Escritura en pedidos ajenos:** se guardaban metadatos en pedidos de otros vendedores.

**Prueba de explotabilidad.** Sobre datos reales del sistema se comprobó que el vendedor 4, que no participa en el pedido #148, obtenía acceso concedido con la lógica anterior:

```
Pedido de prueba : #148
Vendedor dueño   : 2
Vendedor ajeno   : 4
wcfm_is_order_for_vendor() existe? : NO
LOGICA VIEJA -> vendedor ajeno 4 sobre pedido #148: PERMITIDO  <-- VULNERABLE
LOGICA NUEVA -> vendedor ajeno 4 sobre pedido #148: denegado   <-- corregido
LOGICA NUEVA -> vendedor dueño 2 sobre pedido #148: permitido  <-- correcto
```

**Corrección.** La propiedad del pedido se resuelve ahora contra la fuente de verdad de WCFM: la tabla `wcfm_marketplace_orders`, que registra una fila por cada par (pedido, vendedor). Se implementó en `AVS_Label::vendor_owns_order()` mediante consulta preparada, y tanto el endpoint REST como el botón del panel delegan en una **única** regla de permiso, eliminando la lógica duplicada.

**Regresión cubierta por** `tests/test09-access.php`, que verifica que un vendedor ajeno es rechazado, el participante es aceptado, el anónimo es rechazado y el administrador es aceptado.

### 4.4 S-2 — Fuga de guías entre vendedores en pedidos compartidos

**Severidad: Alta.**

**Descripción.** En WCFM, un pedido con productos de varios vendedores comparte un mismo `order_id` (se confirmó el caso real del pedido **#144**, con los vendedores 2 y 5). El plugin guardaba la guía **a nivel de pedido** y seleccionaba *el primer* ítem de envío de Envia que encontraba, sin comprobar a qué vendedor pertenecía.

**Impacto.** En un pedido compartido:

1. Un vendedor podía generar la guía tomando el paquete y el **origen de otro vendedor**.
2. Una vez generada, el segundo vendedor veía el botón *"Descargar guía (PDF)"* apuntando al PDF del primero, **con la dirección del cliente**.
3. El segundo vendedor **nunca podía generar la suya**, porque el pedido ya figuraba con guía.

**Corrección.** La guía pasó a almacenarse en el **ítem de envío** (uno por vendedor) en lugar de en el pedido. Cada vendedor genera, ve y descarga únicamente su propio paquete. Cuando un administrador no indica el vendedor en un pedido con varios paquetes, la operación se **rechaza explícitamente** (`avs_ambiguous`) en lugar de adivinar, que era precisamente el origen del fallo.

**Regresión cubierta por** `tests/test10-multivendor-label.php`, que construye un pedido con dos paquetes de Envia y verifica el aislamiento.

### 4.5 S-3 — Paquete calculado sobre el pedido completo

**Severidad: Media.**

El peso y el valor declarado de la guía se calculaban sumando **todos** los productos del pedido. En un pedido multivendedor, la guía de un vendedor salía con el peso y el valor de la mercancía de todos, produciendo una guía incorrecta y más cara. Corregido: el paquete se construye únicamente con las líneas atribuibles al vendedor.

### 4.6 S-4 — Integridad de la evidencia de pruebas

**Severidad: Alta (integridad del proceso, no del producto).** Descrito en la sección 2.2. Se considera un hallazgo de seguridad porque anulaba la capacidad de detectar regresiones: un fallo total de la aplicación se reportaba como éxito.

### 4.7 S-5 — Credenciales fuera del control de versiones

**Severidad: Media.** Durante la intervención se detectó que `.gitignore` cubría únicamente nombres exactos (`.env`, `.env.local`, `.env.production`), de modo que cualquier otra variante —por ejemplo una copia de respaldo— habría quedado expuesta al control de versiones con la contraseña de producción. Corregido ampliando la regla a `.env.*`, con excepción explícita de la plantilla `.env.example`.

### 4.8 S-6 — Clave de Gemini en la cadena de consulta

**Severidad: Baja. Estado: corregido.** Con el proveedor Gemini, la clave de API viajaba como parámetro de URL (`?key=...`), donde puede quedar registrada en logs de proxies, historiales y registros de acceso del servidor.

> **Corrección de una valoración previa de este informe.** En su primera versión, este hallazgo se clasificó como *"riesgo aceptado, restricción externa del proveedor"*, bajo el supuesto de que Google solo admitía la clave en la URL. **Ese supuesto era incorrecto:** la API de Gemini acepta la cabecera `x-goog-api-key`. No era una limitación externa, sino una decisión de implementación evitable. Se reclasificó de *aceptado* a *corregido* y la llamada ahora usa la cabecera.

### 4.9 Auditoría del módulo de IA (hallazgos A-1 a A-8)

El plugin `wcfm-ai-assistant` se auditó con el mismo criterio. Los dos hallazgos de mayor impacto son económicos: permitían consumir sin control la cuenta de API del marketplace.

**A-1 — Entrada sin tope de longitud (Alta).** El endpoint saneaba `product_name` en una variable auxiliar, pero acto seguido reenviaba a la IA **el array crudo completo** de la petición. El constructor del prompt interpolaba once campos del cliente sin límite alguno. Medido: 200 KB en un solo campo producían un prompt de **~50.639 tokens** en una única llamada. `max_tokens: 2000` acota la salida, no la entrada. Corregido con lista blanca de campos y tope por campo, aplicado tanto en el endpoint como —por defensa en profundidad— dentro de la capa de API. El mismo ataque genera ahora **~764 tokens**.

**A-2 — Cuota mensual evadible (Alta).** La comprobación era *leer → llamar a la IA → incrementar*, secuencia no atómica. Con el contador en 49 y límite 50, cinco peticiones concurrentes leían 49 y **las cinco pasaban**. Además el contador vivía en un *transient*, que un caché de objetos puede desalojar, reiniciando la cuota. Corregido con reserva atómica (incremento en una sola sentencia SQL antes de llamar a la IA, con reembolso si la llamada falla). Verificado: de cinco intentos ahora pasa **uno**, y el consumo nunca supera el límite.

**A-3 — Inyección de prompt (Media-Alta).** El texto del cliente se concatenaba junto a las instrucciones, sin separación, de modo que una orden inyectada quedaba al mismo nivel jerárquico que ellas. Mitigado encerrando el contenido del usuario en un bloque delimitado por marcas con un **token aleatorio por petición**, que el cliente no puede predecir ni falsificar, más neutralización de los caracteres delimitadores e instrucciones explícitas al modelo. Verificado: el delimitador falso inyectado queda desarmado (0 apariciones) y solo persiste el cierre legítimo del servidor.

**A-4 — Contexto del vendedor controlado por el cliente (Media).** El nombre de la tienda, su descripción y la historia de la comunidad viajaban en campos ocultos del formulario y el servidor los aceptaba sin verificar, pese a existir ya una función que los derivaba de la sesión. Un vendedor podía atribuirse la historia cultural de otra comunidad, que es precisamente lo que el módulo promete garantizar. Corregido: los campos `vendor_*` se descartan de la petición y los arma el servidor.

**A-5 a A-8 (Media a Baja).** La API key se renderizaba en el atributo `value` de un campo `type="password"` —que solo la oculta visualmente, quedando legible en el código fuente de la página—; ahora no se devuelve al navegador y se muestra únicamente su estado. Los cuatro ajustes carecían de `sanitize_callback`; se añadieron, con lista blanca de proveedor y validación del nombre de modelo (relevante porque se interpola en la ruta de la URL de Gemini). El registro de uso se marcó como no autocargable: con 500 entradas se cargaba en cada petición de WordPress.

### 4.10 S-7 y S-8 — Exposición en el repositorio público

**S-7 — `wp-config.php` versionado (Alta).** El archivo estaba en el repositorio público con los **ocho salts reales** (`AUTH_KEY`, `SECURE_AUTH_KEY`, …, `NONCE_SALT`) desde el commit inicial. Conocerlos permite falsificar cookies de sesión válidas sin la contraseña, es decir, suplantar a un usuario autenticado. Se verificó que las credenciales de base de datos **no** estaban expuestas: se leen con `getenv()` desde un `.env` que nunca se llegó a versionar. Corregido: salts rotados (los expuestos quedan inutilizados), archivo retirado del control de versiones y añadido a `.gitignore`.

**S-8 — `reset-admin.php` (Crítica).** Script en la raíz del sitio, versionado en el repositorio público, con usuario, contraseña y correo de administrador **embebidos en el código**, que creaba o restablecía una cuenta de administrador al ser invocado. Al residir en la raíz web era alcanzable por URL, lo que permitiría a cualquiera tomar el control del sitio. Eliminado del repositorio. La contraseña que contenía debe considerarse comprometida, dado que permanece en el historial.

### 4.11 Controles verificados como correctos

| Control | Verificación |
|---|---|
| Consultas SQL | Se usan sentencias preparadas (`$wpdb->prepare`); no se detectó concatenación de entrada del usuario. |
| Escapado de salida | Se aplica `esc_html`, `esc_url` y `esc_attr` en el panel del vendedor y en la pantalla de administración. |
| Protección CSRF | Las llamadas REST viajan con nonce `X-WP-Nonce`; WordPress lo exige para la autenticación por cookie. |
| Confianza en la entrada | Tras la corrección, el identificador de vendedor se toma **de la sesión**, nunca del cuerpo de la petición. Solo un administrador puede indicarlo explícitamente. |
| Idempotencia | Una guía ya generada no se vuelve a cobrar; el descuento al ledger está protegido por el metadato `_avs_ledger_done`. |
| Almacenamiento de credenciales | Las claves de API se guardan como opciones de WordPress y ya no se devuelven al navegador. |
| Permisos del módulo de IA | `check_vendor_permission()` **falla cerrado**: si la función de WCFM no existiera, recae en `manage_options` en lugar de conceder acceso. Es el patrón correcto que faltaba en S-1. |
| Ausencia de XSS en el módulo de IA | La respuesta del modelo se inserta con `.val()` en campos de formulario, no como HTML. No se detectó vector de XSS. |

---

## 5. Conclusiones

La campaña de pruebas cumplió su objetivo, pero el resultado más relevante no fue confirmar lo que funcionaba, sino **descubrir que la evidencia previa era falsa**. El suite declaraba "9/9 aprobado" mientras siete de sus nueve pruebas ni siquiera llegaban a ejecutarse.

Corregido el marco de pruebas, la revisión de seguridad reveló dos defectos serios de control de acceso, ambos originados en la misma causa: **confiar en una función que no existe** como guardia de permisos. El patrón `! function_exists($f) || $f(...)` concede acceso cuando la dependencia falta, y estaba duplicado en dos lugares.

La auditoría del módulo de IA reveló un segundo patrón, esta vez de naturaleza económica: **confiar en la entrada del cliente para decidir cuánto gasta el marketplace**. El endpoint reenviaba a la IA el cuerpo completo de la petición sin tope de longitud, y la cuota mensual que debía contener el gasto era evadible con peticiones concurrentes. Ninguno de los dos era visible sin ejercitar el código.

Estado final:

- **14 de 14 pruebas automatizadas aprobadas** (11 del módulo de envíos, 3 del de IA), con evidencia verificable.
- **Dieciséis defectos corregidos**, cuatro de severidad crítica o alta, con pruebas de regresión que impiden su reaparición.
- **Ningún riesgo pendiente de aceptación.** El único que figuraba como aceptado (S-6) resultó ser corregible y se corrigió.

### 5.1 Recomendaciones

1. **Prohibir `function_exists()` como guardia de autorización.** Si una dependencia falta, el sistema debe negar el acceso, no concederlo.
2. **Acotar siempre la entrada que llega a un servicio de pago por uso.** Todo campo que alcance una API facturable necesita lista blanca y tope de longitud; el límite de salida no protege del costo de entrada.
3. **Hacer atómico cualquier contador que controle gasto.** El patrón leer-comprobar-escribir no resiste concurrencia; la reserva debe preceder al consumo.
4. **No versionar nunca secretos.** `wp-config.php` y los scripts con credenciales embebidas deben quedar fuera del control de versiones desde el primer commit.
5. **Desbloquear la cotización de Envia** en el panel Ecommerce Pro para ejercitar los flujos de guía de extremo a extremo con datos reales.
6. **Exigir peso y dimensiones** al publicar productos, para que las tarifas y las guías sean correctas.
7. **Revisar el resto de endpoints** con estos criterios antes de incorporar Mercado Pago Split, que manejará dinero real.
