/**
 * Minimální JSONC — komentáře a zbytkové čárky. Bez závislosti, protože
 * runner jich má mít co nejmíň (R1).
 *
 * Obě fáze sledují řetězce, takže `"https://example.dev"` nepřijde o půlku
 * a `{"note": "a, }"}` o čárku. Blokové komentáře se nahrazují jejich
 * novými řádky, aby čísla řádků v chybě z `JSON.parse` pořád seděla.
 */

/**
 * Vyhodí komentáře a nechá na jejich místě stejný počet nových řádků.
 * Exportované kvůli testu té vlastnosti — jinak vnitřní.
 *
 * @param {string} text
 */
export function stripComments(text) {
  let out = '';
  let inString = false;

  for (let i = 0; i < text.length; i++) {
    const c = text[i];

    if (inString) {
      if (c === '\\') { out += c + (text[i + 1] ?? ''); i++; continue; }
      if (c === '"') inString = false;
      out += c;
      continue;
    }

    if (c === '"') { inString = true; out += c; continue; }

    if (c === '/' && text[i + 1] === '/') {
      while (i < text.length && text[i] !== '\n') i++;
      out += '\n';
      continue;
    }

    if (c === '/' && text[i + 1] === '*') {
      i += 2;
      while (i < text.length && !(text[i] === '*' && text[i + 1] === '/')) {
        if (text[i] === '\n') out += '\n';
        i++;
      }
      i++;
      continue;
    }

    out += c;
  }

  return out;
}

/** @param {string} text */
function stripTrailingCommas(text) {
  let out = '';
  let inString = false;

  for (let i = 0; i < text.length; i++) {
    const c = text[i];

    if (inString) {
      if (c === '\\') { out += c + (text[i + 1] ?? ''); i++; continue; }
      if (c === '"') inString = false;
      out += c;
      continue;
    }

    if (c === '"') { inString = true; out += c; continue; }

    if (c === ',') {
      let j = i + 1;
      while (j < text.length && /\s/.test(text[j])) j++;
      if (text[j] === '}' || text[j] === ']') continue;
    }

    out += c;
  }

  return out;
}

/**
 * @param {string} text
 * @returns {unknown}
 * @throws {SyntaxError} Z `JSON.parse`, s čísly řádků odpovídajícími originálu.
 */
export function parseJsonc(text) {
  return JSON.parse(stripTrailingCommas(stripComments(text)));
}
