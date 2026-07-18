/**
 * Conversor Markdown -> Word (.docx) para los entregables del marketplace Amazonia.
 *
 *   node md2docx.js <entrada.md> <salida.docx>
 *
 * La portada, el índice (TOC) y los estilos son comunes a los cuatro documentos, de modo que
 * las fuentes se mantienen en Markdown (versionables en git) y los .docx se regeneran.
 *
 * Soporta: encabezados #..####, párrafos, listas con viñeta y numeradas, tablas, bloques de
 * código, citas, reglas horizontales, y en línea **negrita**, *cursiva* y `código`.
 */
const fs = require('fs');
const path = require('path');
const {
  Document, Packer, Paragraph, TextRun, HeadingLevel, AlignmentType,
  Table, TableRow, TableCell, WidthType, ShadingType, BorderStyle,
  TableOfContents, PageBreak, LevelFormat, PageOrientation,
} = require('docx');

const ACCENT = '1F6F4A';   // verde Amazonia
const GREY   = '595959';
const CODE_BG = 'F2F2F2';

/* ------------------------------------------------------------------ inline */

/** Convierte **negrita**, *cursiva* y `código` en TextRun. */
function inline(text, base = {}) {
  const runs = [];
  const re = /(\*\*[^*]+\*\*|`[^`]+`|\*[^*]+\*)/g;
  let last = 0, m;
  while ((m = re.exec(text)) !== null) {
    if (m.index > last) runs.push(new TextRun({ ...base, text: text.slice(last, m.index) }));
    const tok = m[0];
    if (tok.startsWith('**')) {
      runs.push(new TextRun({ ...base, text: tok.slice(2, -2), bold: true }));
    } else if (tok.startsWith('`')) {
      runs.push(new TextRun({ ...base, text: tok.slice(1, -1), font: 'Consolas', shading: { type: ShadingType.CLEAR, fill: CODE_BG } }));
    } else {
      runs.push(new TextRun({ ...base, text: tok.slice(1, -1), italics: true }));
    }
    last = m.index + tok.length;
  }
  if (last < text.length) runs.push(new TextRun({ ...base, text: text.slice(last) }));
  return runs.length ? runs : [new TextRun({ ...base, text: '' })];
}

/* ------------------------------------------------------------------ tablas */

function buildTable(rows) {
  const header = rows[0];
  const body = rows.slice(1);
  const cols = header.length;
  const TOTAL = 9360;                       // ancho útil con márgenes de 1"
  const w = Math.floor(TOTAL / cols);
  const widths = Array(cols).fill(w);
  widths[cols - 1] = TOTAL - w * (cols - 1); // cuadrar el redondeo

  const cell = (text, isHead, i) => new TableCell({
    width: { size: widths[i], type: WidthType.DXA },
    shading: isHead ? { type: ShadingType.CLEAR, fill: ACCENT } : undefined,
    margins: { top: 60, bottom: 60, left: 90, right: 90 },
    children: [new Paragraph({
      spacing: { before: 20, after: 20 },
      children: inline(text, isHead ? { bold: true, color: 'FFFFFF', size: 19 } : { size: 19 }),
    })],
  });

  return new Table({
    columnWidths: widths,
    width: { size: TOTAL, type: WidthType.DXA },
    rows: [
      new TableRow({ tableHeader: true, children: header.map((c, i) => cell(c, true, i)) }),
      ...body.map((r) => new TableRow({
        children: Array.from({ length: cols }, (_, i) => cell(r[i] ?? '', false, i)),
      })),
    ],
  });
}

/* ------------------------------------------------------------------ parser */

function parse(md) {
  const lines = md.split(/\r?\n/);
  const out = [];
  let i = 0;

  while (i < lines.length) {
    const line = lines[i];

    // Bloque de código
    if (/^```/.test(line)) {
      i++;
      const code = [];
      while (i < lines.length && !/^```/.test(lines[i])) code.push(lines[i++]);
      i++; // cierre
      code.forEach((c, idx) => out.push(new Paragraph({
        shading: { type: ShadingType.CLEAR, fill: CODE_BG },
        spacing: { before: idx === 0 ? 100 : 0, after: idx === code.length - 1 ? 100 : 0 },
        children: [new TextRun({ text: c || ' ', font: 'Consolas', size: 18 })],
      })));
      continue;
    }

    // Tabla
    if (/^\s*\|/.test(line) && i + 1 < lines.length && /^\s*\|[\s:|-]+\|\s*$/.test(lines[i + 1])) {
      const rows = [];
      const cells = (l) => l.trim().replace(/^\||\|$/g, '').split('|').map((c) => c.trim());
      rows.push(cells(lines[i])); i += 2;
      while (i < lines.length && /^\s*\|/.test(lines[i])) rows.push(cells(lines[i++]));
      out.push(buildTable(rows));
      out.push(new Paragraph({ spacing: { after: 120 }, children: [] }));
      continue;
    }

    // Encabezados
    const h = line.match(/^(#{1,4})\s+(.*)$/);
    if (h) {
      const lvl = h[1].length;
      const levels = [HeadingLevel.HEADING_1, HeadingLevel.HEADING_2, HeadingLevel.HEADING_3, HeadingLevel.HEADING_4];
      out.push(new Paragraph({
        heading: levels[lvl - 1],
        spacing: { before: lvl === 1 ? 320 : 240, after: 120 },
        pageBreakBefore: lvl === 1 && out.length > 0,
        children: inline(h[2]),
      }));
      i++;
      continue;
    }

    // Regla horizontal -> separador sutil
    if (/^\s*---+\s*$/.test(line)) {
      out.push(new Paragraph({
        border: { bottom: { style: BorderStyle.SINGLE, size: 6, color: 'D0D0D0' } },
        spacing: { before: 80, after: 120 },
        children: [],
      }));
      i++;
      continue;
    }

    // Cita
    if (/^>\s?/.test(line)) {
      out.push(new Paragraph({
        indent: { left: 360 },
        border: { left: { style: BorderStyle.SINGLE, size: 12, color: ACCENT, space: 8 } },
        spacing: { before: 80, after: 80 },
        children: inline(line.replace(/^>\s?/, ''), { italics: true, color: GREY }),
      }));
      i++;
      continue;
    }

    // Lista con viñeta
    if (/^\s*[-*]\s+/.test(line)) {
      const depth = Math.floor((line.match(/^\s*/)[0].length) / 2);
      out.push(new Paragraph({
        bullet: { level: Math.min(depth, 2) },
        spacing: { before: 40, after: 40 },
        children: inline(line.replace(/^\s*[-*]\s+/, '')),
      }));
      i++;
      continue;
    }

    // Lista numerada
    if (/^\s*\d+\.\s+/.test(line)) {
      out.push(new Paragraph({
        numbering: { reference: 'num', level: 0 },
        spacing: { before: 40, after: 40 },
        children: inline(line.replace(/^\s*\d+\.\s+/, '')),
      }));
      i++;
      continue;
    }

    // Línea en blanco
    if (!line.trim()) { i++; continue; }

    // Párrafo (junta líneas contiguas)
    const buf = [line];
    i++;
    while (i < lines.length && lines[i].trim() && !/^(#{1,4}\s|```|\s*[-*]\s|\s*\d+\.\s|>|\s*\|)/.test(lines[i]) && !/^\s*---+\s*$/.test(lines[i])) {
      buf.push(lines[i++]);
    }
    out.push(new Paragraph({
      spacing: { before: 60, after: 60, line: 276 },
      alignment: AlignmentType.JUSTIFIED,
      children: inline(buf.join(' ')),
    }));
  }

  return out;
}

/* ------------------------------------------------------------------ portada */

function coverPage(title, subtitle, version) {
  const t = (text, opts) => new Paragraph({ alignment: AlignmentType.CENTER, ...opts, children: inline(text, opts && opts.run) });
  return [
    new Paragraph({ spacing: { before: 2400 }, children: [] }),
    t('MARKETPLACE AMAZONIA', { spacing: { after: 120 }, run: { size: 26, color: ACCENT, bold: true, characterSpacing: 60 } }),
    t(title, { spacing: { after: 160 }, run: { size: 52, bold: true } }),
    t(subtitle, { spacing: { after: 900 }, run: { size: 24, color: GREY, italics: true } }),
    t('WordPress · WooCommerce · WCFM Multivendor · Envia', { spacing: { after: 60 }, run: { size: 20, color: GREY } }),
    t(`Versión ${version} — ${new Date().toLocaleDateString('es-CO', { year: 'numeric', month: 'long', day: 'numeric' })}`, { run: { size: 20, color: GREY } }),
    new Paragraph({ children: [new PageBreak()] }),
  ];
}

function tocPage() {
  return [
    new Paragraph({ heading: HeadingLevel.HEADING_1, spacing: { after: 200 }, children: inline('Contenido') }),
    new TableOfContents('Contenido', { hyperlink: true, headingStyleRange: '1-3' }),
    new Paragraph({ children: [new PageBreak()] }),
  ];
}

/* ------------------------------------------------------------------ main */

const [, , inPath, outPath] = process.argv;
if (!inPath || !outPath) {
  console.error('uso: node md2docx.js <entrada.md> <salida.docx>');
  process.exit(1);
}

const raw = fs.readFileSync(inPath, 'utf8');

// Metadatos opcionales al inicio: <!-- subtitle: ... --> y <!-- version: ... -->
const subtitle = (raw.match(/<!--\s*subtitle:\s*(.+?)\s*-->/) || [])[1] || '';
const version  = (raw.match(/<!--\s*version:\s*(.+?)\s*-->/) || [])[1] || '1.0';
const body = raw.replace(/<!--[\s\S]*?-->/g, '');

// El primer H1 es el título de portada; no se repite en el cuerpo.
const titleMatch = body.match(/^#\s+(.+)$/m);
const title = titleMatch ? titleMatch[1] : path.basename(inPath, '.md');
const content = body.replace(/^#\s+.+$/m, '');

const doc = new Document({
  creator: 'Marketplace Amazonia',
  title,
  description: subtitle,
  features: { updateFields: true },   // hace que Word rellene el TOC al abrir
  numbering: {
    config: [{
      reference: 'num',
      levels: [{ level: 0, format: LevelFormat.DECIMAL, text: '%1.', alignment: AlignmentType.START,
                 style: { paragraph: { indent: { left: 460, hanging: 260 } } } }],
    }],
  },
  styles: {
    default: {
      document: { run: { font: 'Calibri', size: 21 } },
      heading1: { run: { font: 'Calibri', size: 34, bold: true, color: ACCENT }, paragraph: { spacing: { before: 320, after: 140 } } },
      heading2: { run: { font: 'Calibri', size: 27, bold: true, color: '2E2E2E' }, paragraph: { spacing: { before: 260, after: 110 } } },
      heading3: { run: { font: 'Calibri', size: 23, bold: true, color: GREY }, paragraph: { spacing: { before: 200, after: 90 } } },
      heading4: { run: { font: 'Calibri', size: 21, bold: true, italics: true, color: GREY }, paragraph: { spacing: { before: 160, after: 80 } } },
    },
  },
  sections: [{
    properties: {
      page: {
        size: { width: 12240, height: 15840, orientation: PageOrientation.PORTRAIT }, // Carta
        margin: { top: 1440, bottom: 1440, left: 1440, right: 1440 },
      },
    },
    children: [...coverPage(title, subtitle, version), ...tocPage(), ...parse(content)],
  }],
});

Packer.toBuffer(doc).then((buf) => {
  fs.writeFileSync(outPath, buf);
  console.log(`OK  ${path.basename(outPath).padEnd(42)} ${(buf.length / 1024).toFixed(0)} KB`);
});
