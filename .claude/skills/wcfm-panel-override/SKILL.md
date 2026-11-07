---
name: wcfm-panel-override
description: Cómo modificar o personalizar un panel del dashboard de vendedor de WCFM / WCFM Marketplace (WCFMmp) en Amazonia Market — productos, pedidos, ajustes, tienda, etc. — sin tocar el código del plugin. Usa esta skill siempre que el usuario pida "modificar/personalizar/añadir algo a" un panel de WCFM, un panel del vendedor, el dashboard de WCFM, `store-manager/...`, o mencione editar una plantilla de WCFM/WCFMmp, aunque no diga la palabra "override" explícitamente.
---

# Override de paneles WCFM / WCFMmp (Amazonia Market)

Este proyecto nunca modifica el código de los plugins `wc-frontend-manager` (WCFM) ni
`wc-multivendor-marketplace` (WCFMmp) directamente — ver `AGENTS.md`. Todo cambio a un
panel del dashboard de vendedor se hace copiando la plantilla del plugin dentro del
tema `amazonia-theme`, siguiendo el mecanismo de `locate_template()` que ambos plugins
ya exponen.

## Por qué funciona

Ambas clases de plantillas (`WCFM_Template` en wc-frontend-manager y `WCFMmp_Template`
en wc-multivendor-marketplace) tienen `template_url = 'wcfm/'` y resuelven así:

```
yourtheme/wcfm/<$template_name>   ← prioridad (vía locate_template() de WP core)
$plugin_path/views/<$template_name>  ← fallback (el original del plugin)
```

`$template_name` casi siempre incluye una subcarpeta (p. ej.
`products-manager/wcfm-view-products-manage.php`). Hay que replicar esa ruta
**exacta**, subcarpeta incluida, dentro de `wp-content/themes/amazonia-theme/wcfm/`.
Si la ruta no coincide al carácter, WP no la encuentra, cae al plugin por defecto y
no da ningún error — por eso "no funciona" en silencio.

Ya existe un ejemplo real en este repo: [wcfm/store/wcfmmp-view-store.php](../../../wcfm/store/wcfmmp-view-store.php).

## Flujo de trabajo (dos fases, dos modelos)

**Fase 1 — Planificación: hazla tú mismo, en esta misma conversación (modelo Sonnet).**
Es la parte que requiere criterio: encontrar la llamada correcta, detectar
dependencias y decidir el punto de inserción. No la delegues.

1. Averigua qué panel pide el usuario (por la URL `store-manager/<slug>` o por lo que
   describe) y busca la llamada exacta con Grep/Bash en los plugins del proyecto:
   ```
   grep -rn "get_template(\|get_template_part(" \
     wp-content/plugins/wc-frontend-manager wp-content/plugins/wc-multivendor-marketplace \
     | grep -i "<palabra clave del panel>"
   ```
   Anota el `$template_name` completo, tal como aparece entre comillas.

2. Localiza el archivo original en `.../views/<$template_name>` y revisa si contiene
   `include(`, `include_once(`, `require(` o `require_once(` con **ruta relativa**
   (sin `$WCFM->plugin_path` ni ABSPATH). Esas son dependencias que PHP resuelve
   respecto al directorio del archivo en ejecución — si solo copias el archivo
   principal al tema, esos includes fallan con
   `Warning: include(...): Failed to open stream`. Cada archivo relativo incluido
   hay que copiarlo también a la misma subcarpeta dentro de `amazonia-theme/wcfm/`
   (revisa recursivamente: un include copiado puede a su vez incluir otro).

3. Decide el punto exacto de inserción: busca un ancla de texto única (un campo,
   un `do_action()` cercano, un cierre de bloque) que el `Edit` pueda usar sin
   ambigüedad. Si el plugin ya expone un `do_action( 'after_wcfm_..._...' , ... )`
   justo en el lugar que necesitas, **prefiere engancharte ahí con
   `add_action()` en `functions.php`** en vez de clonar el archivo completo — es
   más corto y sobrevive mejor a actualizaciones del plugin. Clona el archivo entero
   solo cuando necesitas cambiar algo dentro del HTML/lógica existente, no solo
   añadir algo al lado.

4. Ten lista la ruta de destino (`amazonia-theme/wcfm/<misma-ruta-relativa>`) y el
   `old_string`/`new_string` exactos para el `Edit`.

**Fase 2 — Ejecución: delega el trabajo mecánico a un subagente con `model: "haiku"`.**
Una vez planificado, copiar archivos, aplicar el edit ya decidido y validar sintaxis
es trabajo determinista — no requiere razonamiento adicional. Lánzalo con el tool
`Agent`, `subagent_type: "general-purpose"`, `model: "haiku"`, y dale en el prompt:
- la ruta origen de cada archivo a copiar (el principal + sus dependencias relativas),
- la ruta destino exacta dentro de `amazonia-theme/wcfm/`,
- el `old_string`/`new_string` exacto a aplicar (ya decidido en fase 1 — el subagente
  no debe inventar dónde insertar el cambio),
- la orden de validar cada archivo tocado con:
  ```
  "/c/xampp/php/php.exe" -l "<ruta del archivo>"
  ```
  y reportar cualquier error de sintaxis o cualquier otro `include()`/`require()`
  relativo que encuentre y que aún no se haya copiado.

Si el subagente reporta un include faltante, vuelve a fase 1 brevemente para
localizar ese archivo, y relanza fase 2 solo para copiarlo.

## Gotcha de PHP: abrir/cerrar tags al insertar HTML

Las plantillas de WCFM suelen tener tramos largos de PHP puro sin ningún `?>` de por
medio. Si vas a insertar HTML/texto plano (como un `<div>`) en medio de uno de esos
tramos, **cierra el tag antes y vuelve a abrirlo después**:

```php
}
?>
<div class="mi-cambio">...</div>
<?php

	// el código PHP original continúa aquí
```

Nunca escribas `<?php` de nuevo sin haber cerrado antes con `?>` — si ya estás dentro
de un bloque PHP abierto, un `<?php` literal es un error de sintaxis. Por eso el
`php -l` del paso de validación no es opcional.

## Cuándo este mecanismo NO aplica

Algunos paneles — sobre todo módulos de integración de terceros — hacen `include()`
con la ruta del plugin ya resuelta (`$WCFM->plugin_path . '...'`) en vez de pasar por
`get_template()`/`get_template_part()`. Ahí no existe punto de override por tema. En
ese caso:
1. Busca si hay un `do_action()`/`apply_filters()` cerca que sirva como gancho.
2. Si no hay nada, el único recurso es CSS/JS por encima (enqueue en
   `functions.php`, como ya se hace en `assets/css/wcfm-dashboard.css`) — es más
   frágil, pero es lo único disponible sin tocar el plugin.

## Referencia rápida (rutas ya confirmadas en este proyecto)

| Panel | `$template_name` | Clase |
|---|---|---|
| Listado de productos | `products/wcfm-view-products.php` | `WCFM_Template` (wc-frontend-manager) |
| Editor de producto | `products-manager/wcfm-view-products-manage.php` | `WCFM_Template` |
| Tabs del editor de producto (dependencia relativa del anterior) | `products-manager/wcfm-view-products-manage-tabs.php` | incluido por ruta relativa, no por `get_template()` |
| Página de tienda (perfil público del vendedor) | `store/wcfmmp-view-store.php` | `WCFMmp_Template` (wc-multivendor-marketplace) |

Esta tabla crece con cada panel que se override — añade la fila correspondiente
cuando confirmes una ruta nueva.
