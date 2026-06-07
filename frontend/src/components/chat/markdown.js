/**
 * Minimal, safe Markdown subset parser → token tree.
 *
 * Supported: paragraphs, **bold**, *italic* / _italic_, `inline code`,
 * fenced ``` code blocks ```, and `-`/`*` (unordered) / `1.` (ordered) lists.
 * Everything else — including any HTML like `<script>` — is kept as plain
 * TEXT tokens. Markdown.svelte renders these tokens into Svelte elements
 * (textContent only, never {@html}), so there is no XSS surface.
 *
 * Block tokens:
 *   { type: 'paragraph', spans: Span[] }
 *   { type: 'code_block', text: string }
 *   { type: 'list', ordered: boolean, items: Span[][] }
 * Span tokens:
 *   { type: 'text' | 'strong' | 'em' | 'code', text: string }
 */

/** @returns {Array<{type: string, text: string}>} */
function parseInline(text) {
  const spans = [];
  let buf = '';
  let i = 0;
  const flush = () => {
    if (buf !== '') {
      spans.push({ type: 'text', text: buf });
      buf = '';
    }
  };

  while (i < text.length) {
    const c = text[i];

    // `inline code` — wins over emphasis so `*x*` inside code stays literal.
    if (c === '`') {
      const end = text.indexOf('`', i + 1);
      if (end !== -1) {
        flush();
        spans.push({ type: 'code', text: text.slice(i + 1, end) });
        i = end + 1;
        continue;
      }
    }

    // **bold** — checked before single `*`.
    if (c === '*' && text[i + 1] === '*') {
      const end = text.indexOf('**', i + 2);
      if (end !== -1 && end > i + 2) {
        flush();
        spans.push({ type: 'strong', text: text.slice(i + 2, end) });
        i = end + 2;
        continue;
      }
    }

    // *italic* or _italic_
    if (c === '*' || c === '_') {
      const end = text.indexOf(c, i + 1);
      if (end !== -1 && end > i + 1) {
        flush();
        spans.push({ type: 'em', text: text.slice(i + 1, end) });
        i = end + 1;
        continue;
      }
    }

    buf += c;
    i++;
  }

  flush();
  return spans;
}

const UL_RE = /^\s*[-*]\s+(.*)$/;
const OL_RE = /^\s*\d+\.\s+(.*)$/;

/** @returns {Array<object>} block tokens */
export function parseMarkdown(text) {
  const lines = String(text ?? '').replace(/\r\n/g, '\n').split('\n');
  const blocks = [];
  let i = 0;

  while (i < lines.length) {
    const line = lines[i];

    // Fenced code block.
    if (line.trim().startsWith('```')) {
      const code = [];
      i++;
      while (i < lines.length && !lines[i].trim().startsWith('```')) {
        code.push(lines[i]);
        i++;
      }
      i++; // skip closing fence (or run off the end)
      blocks.push({ type: 'code_block', text: code.join('\n') });
      continue;
    }

    // Blank line — paragraph separator.
    if (line.trim() === '') {
      i++;
      continue;
    }

    // List (a run of consecutive bullet or numbered items).
    const isUl = UL_RE.test(line);
    const isOl = !isUl && OL_RE.test(line);
    if (isUl || isOl) {
      const ordered = isOl;
      const re = ordered ? OL_RE : UL_RE;
      const items = [];
      while (i < lines.length) {
        const m = lines[i].match(re);
        if (!m) break;
        items.push(parseInline(m[1]));
        i++;
      }
      blocks.push({ type: 'list', ordered, items });
      continue;
    }

    // Paragraph — consume until a blank line or another block starts.
    const para = [];
    while (
      i < lines.length &&
      lines[i].trim() !== '' &&
      !lines[i].trim().startsWith('```') &&
      !UL_RE.test(lines[i]) &&
      !OL_RE.test(lines[i])
    ) {
      para.push(lines[i]);
      i++;
    }
    blocks.push({ type: 'paragraph', spans: parseInline(para.join(' ')) });
  }

  return blocks;
}
