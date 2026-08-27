import assert from 'node:assert/strict';
import { test } from 'node:test';

import { parseJsonc, stripComments } from '../src/jsonc.mjs';

test('řádkové i blokové komentáře', () => {
  const parsed = parseJsonc(`{
    // řádkový
    "a": 1, /* blokový */
    /* víc
       řádků */
    "b": 2
  }`);
  assert.deepEqual(parsed, { a: 1, b: 2 });
});

test('komentář uvnitř řetězce se nesmí odstranit', () => {
  const parsed = parseJsonc('{ "url": "https://example.dev/a", "note": "/* ne */" }');
  assert.deepEqual(parsed, { url: 'https://example.dev/a', note: '/* ne */' });
});

test('zbytkové čárky v objektu i poli', () => {
  assert.deepEqual(parseJsonc('{ "a": [1, 2, ], }'), { a: [1, 2] });
});

test('čárka před závorkou uvnitř řetězce zůstane', () => {
  assert.deepEqual(parseJsonc('{ "s": "a, ]" }'), { s: 'a, ]' });
});

test('escapovaná uvozovka neukončí řetězec', () => {
  assert.deepEqual(parseJsonc('{ "s": "a\\" // ne" }'), { s: 'a" // ne' });
});

test('komentáře po sobě nechají stejný počet řádků', () => {
  // Jinak by se čísla řádků v chybě z JSON.parse rozešla se souborem.
  const source = '{\n// jeden\n/* dva\n   tři */\n"a": 1\n}';
  const stripped = stripComments(source);
  const lines = (text) => text.split('\n').length;
  assert.equal(lines(stripped), lines(source));
});
