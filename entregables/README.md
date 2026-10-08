# Entregables Técnicos y Operativos — Marketplace Amazonia

Documentación oficial y entregables del sistema. Las **fuentes** están en Markdown (`fuentes/`) y se versionan en Git. Los archivos `.html` y `.docx` son **artefactos regenerables** diseñados para visualización y exportación directa a **PDF**.

| Documento | Fuente Markdown | Artefacto HTML (Listo para PDF) |
|---|---|---|
| **Informe Técnico de Pruebas** (Plan, Casos, Resultados, Incidencias, Ajustes, Validación y Evidencias) | `fuentes/01-informe-de-pruebas.md` | `01-informe-de-pruebas.html` |
| **Manual Técnico** (Instalación, Operación y Mantenimiento) | `fuentes/02-manual-tecnico.md` | `02-manual-tecnico.html` |
| **Código Fuente Documentado** (Arquitectura y Responsabilidades) | `fuentes/03-codigo-fuente-documentado.md` | `03-codigo-fuente-documentado.html` |
| **Documentación Técnica Final** (Documento Integrador) | `fuentes/04-documentacion-tecnica-final.md` | `04-documentacion-tecnica-final.html` |
| **Manual de Usuario** (Gestión de Comunidades por Admin, Vendedor, IA y Tutorial) | `fuentes/05-manual-de-usuario.md` | `05-manual-de-usuario.html` |

---

## 🚀 Exportación a PDF desde HTML

Todos los archivos HTML incluyen una barra de herramientas con el botón **"🖨️ Imprimir / Guardar en PDF"** y reglas CSS `@media print` optimizadas con márgenes estándar, portada ejecutiva y salto de páginas automático:

1. Abra cualquiera de los archivos `.html` en su navegador preferido (Chrome, Edge, Firefox).
2. Presione **`Ctrl + P`** o haga clic en el botón superior derecho.
3. Seleccione **"Guardar como PDF"**.
4. Asegúrese de activar la opción **"Gráficos de fondo"** para mantener los colores institucionales y badges.

---

## 🔄 Regenerar los Archivos HTML

Para compilar las fuentes Markdown a HTML auto-contenido:

```bash
# Compilar todos los documentos a la vez:
node entregables/md2html.js

# O compilar un documento específico:
node entregables/md2html.js entregables/fuentes/01-informe-de-pruebas.md entregables/01-informe-de-pruebas.html
```

---

## 📄 Regenerar los Archivos Word (`.docx`)

Requiere Node.js y la librería `docx`:

```bash
cd entregables
npm install docx

node md2docx.js fuentes/01-informe-de-pruebas.md          01-informe-de-pruebas.docx
node md2docx.js fuentes/02-manual-tecnico.md              02-manual-tecnico.docx
node md2docx.js fuentes/03-codigo-fuente-documentado.md   03-codigo-fuente-documentado.docx
node md2docx.js fuentes/04-documentacion-tecnica-final.md 04-documentacion-tecnica-final.docx
node md2docx.js fuentes/05-manual-de-usuario.md           05-manual-de-usuario.docx
```
