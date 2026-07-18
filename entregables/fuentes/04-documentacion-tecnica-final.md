<!-- subtitle: Documento integrador del sistema -->
<!-- version: 1.0 -->

# Documentación Técnica Final

## 1. Introducción

### 1.1 El sistema

**Amazonia** es un marketplace multivendedor que conecta comunidades productoras de la región amazónica con clientes finales. La plataforma no vende: intermedia, y proporciona a cada comunidad las herramientas para gestionar su propia tienda.

Está construido sobre WordPress con WooCommerce y WCFM Multivendor, más tres componentes propios: el módulo de envíos multivendedor, el asistente de IA y el tema.

### 1.2 Objetivos

1. Dar a cada comunidad una tienda propia dentro de un marketplace común.
2. Automatizar la logística de envíos, que es la principal barrera operativa para vendedores rurales.
3. Mantener la trazabilidad económica: cada comunidad debe saber qué gana y qué paga.
4. No comprometer la propiedad de los fondos de las comunidades.

### 1.3 Alcance de este documento

Cubre lo **implementado y verificado**. La integración de Mercado Pago Split está diseñada pero **no construida**, y se menciona únicamente como trabajo futuro (sección 7).

Este documento se acompaña de otros tres entregables: el **Informe de Pruebas**, el **Manual Técnico** y el **Código Fuente Documentado**.

---

## 2. Proceso de negocio y roles

### 2.1 Ciclo de vida

1. **Alta del vendedor.** Un usuario solicita ser vendedor; el administrador aprueba; el vendedor configura su tienda.
2. **Catálogo.** El vendedor publica productos, con aprobación previa si el administrador lo exige.
3. **Compra.** El cliente puede llevar productos de varios vendedores en un mismo carrito. El sistema cotiza el envío **por vendedor**.
4. **Logística.** Cada vendedor genera la guía de su propio paquete y lo despacha.
5. **Liquidación.** WCFM calcula comisiones y gestiona los retiros.

### 2.2 Roles

| Rol | Responsabilidad |
|---|---|
| Administrador | Gobierna la plataforma: aprueba vendedores, fija políticas de envío y comisiones, gestiona credenciales |
| Vendedor (comunidad) | Gestiona su tienda, su catálogo, sus pedidos y sus guías de envío |
| Cliente | Compra, paga y recibe |

---

## 3. Arquitectura y decisiones de diseño

### 3.1 Plugin propio por *hooks*, no bifurcación del núcleo

**Decisión.** Toda la funcionalidad propia se acopla mediante *hooks*. No se modifica el código de WordPress, WooCommerce, WCFM ni Envia.

**Razón.** Bifurcar el núcleo condena el proyecto a no poder actualizarse nunca, lo cual es inaceptable en software que recibe parches de seguridad. El costo de esta decisión es que hay que trabajar dentro de las limitaciones de las extensiones ajenas; se asume conscientemente.

### 3.2 Cuenta de Envia centralizada con costo configurable

**Decisión.** Una sola cuenta de Envia para todo el marketplace, pero con el origen de cada vendedor registrado por separado y el costo repartible.

**Razón.** Exigir a cada comunidad que abra y mantenga su propio contrato con una transportadora es inviable. La cuenta central elimina esa barrera. Al mismo tiempo, registrar el origen de cada vendedor permite que las tarifas y las guías sean correctas, y el ledger mantiene la trazabilidad económica individual.

### 3.3 La guía se genera por API, no por iframe

**Decisión.** La guía se genera llamando a `POST /ship/generate/`, no abriendo el panel de Envia en un iframe.

**Razón.** El iframe de Envia exige iniciar sesión con la cuenta central, que solo posee el administrador, y no devuelve a WordPress ni el PDF, ni el número de seguimiento, ni el costo. Con la API, el vendedor obtiene su guía sin credenciales de Envia y el sistema conserva los datos.

### 3.4 La guía pertenece al paquete, no al pedido

**Decisión.** Los datos de la guía se almacenan en el **ítem de envío** del vendedor.

**Razón.** Un pedido multivendedor comparte un único identificador y contiene un ítem de envío por vendedor. Almacenar la guía en el pedido provocaba que la primera generada bloqueara a las demás y que un vendedor pudiera descargar el PDF de otro, con los datos personales del cliente. Ver Informe de Pruebas, hallazgo S-2.

### 3.5 Los permisos fallan cerrados

**Decisión.** Cuando una dependencia de autorización no está disponible, se **deniega** el acceso.

**Razón.** El sistema contenía el patrón `! function_exists($f) || $f(...)`, que concede acceso cuando la función no existe. Como no existía, cualquier vendedor podía generar la guía de cualquier pedido. Es la lección de diseño más importante de este ciclo.

---

## 4. Modelo de datos

### 4.1 Tablas relevantes

| Tabla | Contenido |
|---|---|
| `wp_wcfm_marketplace_orders` | Una fila por par (pedido, vendedor). **Fuente de verdad de la propiedad de un pedido.** |
| `wp_wcfm_marketplace_vendor_ledger` | Balance de cada vendedor |

### 4.2 Datos propios

Los identificadores, metadatos y opciones del módulo de envíos se detallan en el **Código Fuente Documentado**, sección 3.6. En resumen:

- **Configuración global:** opciones `avs_*`.
- **Configuración del vendedor:** metadatos de usuario `_avs_*`.
- **Resultado de la guía:** metadatos del **ítem de envío** `_avs_label_*`.

---

## 5. Estado de la implementación

| Fase | Funcionalidad | Estado |
|---|---|---|
| 0 | Bootstrap del plugin | Completa |
| 1 | Configuración global y por vendedor | Completa |
| 2 | Reparto del costo en el checkout | Completa |
| 3 | Descuento al ledger | Completa |
| 4 | Botón de guía en el panel | Completa |
| 5 | Validación del checkout | Completa |
| 6 | Generación de guía por API | Completa |
| 7 | Origen por vendedor | Completa |
| 8 | Transportadora por vendedor | Completa |
| 9 | Control de acceso a la guía | Completa (esta campaña) |
| 10 | Aislamiento de guías multivendedor | Completa (esta campaña) |

**Verificación:** 11 de 11 pruebas automatizadas aprobadas.

---

## 6. Resultados de las pruebas

El detalle está en el **Informe de Pruebas**. Síntesis:

| Hallazgo | Severidad | Estado |
|---|---|---|
| Cualquier vendedor podía generar la guía de cualquier pedido | Crítica | Corregido |
| Fuga de guías entre vendedores en pedidos compartidos | Alta | Corregido |
| Guía con peso y valor del pedido completo | Media | Corregido |
| El suite de pruebas reportaba éxito sin ejecutarse | Alta | Corregido |
| Respaldo del `.env` fuera del control de versiones | Media | Corregido |
| Clave de IA en la URL con Gemini | Baja | Aceptado |

El resultado más significativo de la campaña fue descubrir que **la evidencia de pruebas anterior era falsa**: el suite declaraba "9/9 aprobado" mientras siete de sus nueve pruebas morían antes de ejecutar una sola aserción, porque WordPress termina con código de salida 0 al no poder conectar con la base de datos.

---

## 7. Limitaciones conocidas y trabajo futuro

### 7.1 Bloqueo de configuración en Envia

La cotización automática está **desactivada en el panel de Envia**, no en el código. Hasta que se cree una regla de envío automático y se active el conmutador de cotización en el checkout, ningún pedido usará `envia_shipping`. Es el prerrequisito para poder ejercitar los flujos de guía con datos reales.

### 7.2 Datos de producto incompletos

Hay productos sin peso ni dimensiones. Envia asume valores por defecto, lo que produce tarifas y guías imprecisas. Debería exigirse al publicar.

### 7.3 Cancelación de guías

No está implementada la cancelación (`ship/cancel`). Una guía generada por error no puede anularse desde la plataforma.

### 7.4 Mercado Pago Split

Es el siguiente hito. Se eligió sobre las alternativas porque **no exige que el marketplace sea custodio del dinero del vendedor**: el pago se divide en el instante de la transacción, dentro de Mercado Pago.

La razón es jurídica antes que técnica: las comunidades son las propietarias legales de sus fondos y el marketplace es solo un gestor. Con una pasarela sin división nativa, el dinero se acumularía en la cuenta del administrador, creando una responsabilidad legal que se quiere evitar.

**No está implementado.** El diseño por fases está en `docs/integracion-mercadopago-split.md`.

> Antes de incorporarlo, conviene revisar todos los endpoints con el criterio de la sección 3.5. Los fallos de control de acceso encontrados en este ciclo afectaban a guías de envío; los mismos fallos sobre un módulo de pagos afectarían a dinero.

---

## 8. Glosario

| Término | Definición |
|---|---|
| **WCFM** | *WooCommerce Frontend Manager*. Capa multivendedor: tiendas, comisiones, panel del vendedor |
| **Ledger** | Libro de balance de cada vendedor dentro de WCFM |
| **Guía** | Etiqueta de envío en PDF que la transportadora exige para despachar |
| **Origen** | Dirección desde la que se recoge el paquete; en este sistema, la de cada vendedor |
| **Split** | División automática de un pago entre varios destinatarios |
| **Sandbox** | Entorno de pruebas de un proveedor externo, sin efectos reales |
| **Fail-open** | Control de seguridad que, ante un fallo, **concede** el acceso. Es un antipatrón |
| **Idempotencia** | Propiedad por la que repetir una operación no produce efectos adicionales |

---

## 9. Anexos

Este documento forma parte de un conjunto de cuatro entregables:

| Documento | Contenido |
|---|---|
| Informe de Pruebas | Pruebas de funcionalidad, usabilidad y seguridad, con hallazgos y evidencia |
| Manual Técnico | Instalación, configuración, operación y mantenimiento |
| Código Fuente Documentado | Estructura, convenciones y responsabilidades del código propio |
| Documentación Técnica Final | Este documento |

Las fuentes en Markdown se versionan en `entregables/fuentes/`; los archivos `.docx` son artefactos regenerables.
