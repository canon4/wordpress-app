# Entregables técnicos — Marketplace Amazonia

Los cuatro documentos de entrega. Las **fuentes** están en Markdown y se versionan en git;
los archivos `.docx` son **artefactos regenerables**: no se editan a mano, se sobrescriben.

| Documento | Fuente |
|---|---|
| Informe de pruebas (funcionalidad, usabilidad, seguridad) | `fuentes/01-informe-de-pruebas.md` |
| Manual técnico | `fuentes/02-manual-tecnico.md` |
| Código fuente documentado | `fuentes/03-codigo-fuente-documentado.md` |
| Documentación técnica final | `fuentes/04-documentacion-tecnica-final.md` |

## Regenerar los `.docx`

Requiere Node.js y la librería `docx` (no está en el proyecto: se instala aquí).

```bash
cd entregables
npm install docx

node md2docx.js fuentes/01-informe-de-pruebas.md          01-informe-de-pruebas.docx
node md2docx.js fuentes/02-manual-tecnico.md              02-manual-tecnico.docx
node md2docx.js fuentes/03-codigo-fuente-documentado.md   03-codigo-fuente-documentado.docx
node md2docx.js fuentes/04-documentacion-tecnica-final.md 04-documentacion-tecnica-final.docx
```

`md2docx.js` es un conversor Markdown → Word: genera portada, índice automático (TOC),
encabezados, tablas, listas y bloques de código, con estilo común a los cuatro documentos.

Cada fuente lleva sus metadatos al inicio, en comentarios HTML:

```
<!-- subtitle: subtítulo de la portada -->
<!-- version: 1.0 -->
```

## Al abrir el `.docx` en Word

El índice se rellena solo la primera vez que Word abre el documento (los campos se
actualizan automáticamente). Si apareciera vacío, pulsar `Ctrl+E` para seleccionar todo
y luego `F9` para actualizar los campos.
