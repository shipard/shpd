/**
 * Clipboard helpers for chat markdown tables.
 *
 * tableToTsv() is pure (unit-testable without DOM); tableToHtmlElement()
 * builds a real <table> via document.createElement + textContent — never
 * string concatenation with unescaped model text.
 */

/** Plain text of one cell: span texts joined, tabs/newlines collapsed to spaces. */
function cellText(spans) {
  return spans
    .map((s) => s.text)
    .join('')
    .replace(/[\t\n\r]+/g, ' ');
}

/**
 * @param {{header: Array, rows: Array}} token table block token from markdown.js
 * @returns {string} TSV — rows joined by \n, cells by \t, no formatting
 */
export function tableToTsv(token) {
  const line = (cells) => cells.map(cellText).join('\t');
  return [line(token.header), ...token.rows.map(line)].join('\n');
}

/**
 * @param {{header: Array, rows: Array}} token table block token from markdown.js
 * @returns {HTMLTableElement}
 */
export function tableToHtmlElement(token) {
  const table = document.createElement('table');

  const appendRow = (parent, cells, tag) => {
    const tr = document.createElement('tr');
    for (const spans of cells) {
      const cell = document.createElement(tag);
      cell.textContent = cellText(spans);
      tr.appendChild(cell);
    }
    parent.appendChild(tr);
  };

  const thead = document.createElement('thead');
  appendRow(thead, token.header, 'th');
  table.appendChild(thead);

  const tbody = document.createElement('tbody');
  for (const row of token.rows) appendRow(tbody, row, 'td');
  table.appendChild(tbody);

  return table;
}

/**
 * Copy a table token to the clipboard as text/html + text/plain (TSV).
 * Falls back to plain-text writeText when ClipboardItem is unavailable
 * or the rich write fails.
 * @returns {Promise<void>} rejects only when even the fallback fails
 */
export async function copyTableToClipboard(token) {
  const tsv = tableToTsv(token);
  if (typeof ClipboardItem !== 'undefined' && navigator.clipboard?.write) {
    try {
      const html = tableToHtmlElement(token).outerHTML;
      await navigator.clipboard.write([
        new ClipboardItem({
          'text/html': new Blob([html], { type: 'text/html' }),
          'text/plain': new Blob([tsv], { type: 'text/plain' }),
        }),
      ]);
      return;
    } catch {
      // fall through to plain-text fallback
    }
  }
  await navigator.clipboard.writeText(tsv);
}
