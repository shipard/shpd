import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
  foldDiacritics,
  foldWithMap,
  matchItem,
  mapRanges,
  rankResults,
} from '../../src/utils/paletteMatch.js';

// --- foldDiacritics ---

test('foldDiacritics: česká diakritika + lowercase', () => {
  assert.equal(foldDiacritics('Účtárna'), 'uctarna');
  assert.equal(foldDiacritics('Číselníky'), 'ciselniky');
  assert.equal(foldDiacritics('Faktury vydané'), 'faktury vydane');
});

test('foldDiacritics: řetězec bez diakritiky se nemění (jen lowercase)', () => {
  assert.equal(foldDiacritics('Dashboard'), 'dashboard');
  assert.equal(foldDiacritics(''), '');
});

test('foldWithMap: mapa indexů je 1:1 pro NFC vstup', () => {
  const { folded, map } = foldWithMap('Účtárna');
  assert.equal(folded, 'uctarna');
  assert.deepEqual(map, [0, 1, 2, 3, 4, 5, 6]);
});

test('foldWithMap: NFD-dekomponovaný vstup — kombinující znaky se vypustí, mapa sedí', () => {
  const label = 'Ǔctarna'; // „Ǔ" jako U + combining caron
  const { folded, map } = foldWithMap(label);
  assert.equal(folded, 'uctarna');
  // foldnutý index 0 → originální index 0, index 1 ('c') → originál 2
  assert.deepEqual(map.slice(0, 2), [0, 2]);
});

// --- matchItem ---

test('matchItem: prefix > začátek slova > subsequence', () => {
  const prefix = matchItem('fakt', 'faktury vydane');
  const wordStart = matchItem('vyd', 'faktury vydane');
  const subseq = matchItem('fv', 'faktury vydane');
  assert.ok(prefix.score > wordStart.score);
  assert.ok(wordStart.score > subseq.score);
});

test('matchItem: prefix ranges', () => {
  assert.deepEqual(matchItem('uct', 'uctarna').ranges, [[0, 3]]);
});

test('matchItem: začátek slova — souvislý výskyt za ne-alfanumerikem', () => {
  const m = matchItem('vyd', 'faktury vydane');
  assert.deepEqual(m.ranges, [[8, 11]]);
});

test('matchItem: subsequence — sousední shody se slučují do ranges', () => {
  const m = matchItem('fv', 'faktury vydane');
  assert.deepEqual(m.ranges, [[0, 1], [8, 9]]);
  const contiguous = matchItem('tarna', 'uctarna'); // uvnitř slova, ne word-start
  assert.equal(contiguous.score, 1);
  assert.deepEqual(contiguous.ranges, [[2, 7]]);
});

test('matchItem: žádná shoda / prázdný query → null', () => {
  assert.equal(matchItem('xyz', 'uctarna'), null);
  assert.equal(matchItem('', 'uctarna'), null);
});

test('mapRanges: převod foldnutých ranges na originální indexy', () => {
  const { folded, map } = foldWithMap('Ǔctarna');
  const m = matchItem('uct', folded);
  // fold indexy [0,3) → originál [0,4) (kombinující znak uprostřed)
  assert.deepEqual(mapRanges(m.ranges, map), [[0, 4]]);
});

// --- rankResults ---

const items = [
  { id: 'a', label: 'Účtárna' },
  { id: 'b', label: 'Faktury vydané' },
  { id: 'c', label: 'Faktury přijaté' },
  { id: 'd', label: 'Deník účtárny' },
];

test('rankResults: folding — „uctarna" najde Účtárnu, prefix první', () => {
  const r = rankResults(items, 'uctarna', []);
  assert.equal(r[0].id, 'a');
  assert.deepEqual(r[0].ranges, [[0, 7]]);
});

test('rankResults: prefix řadí před word-start (Účtárna vs. Deník účtárny)', () => {
  const r = rankResults(items, 'uct', []);
  assert.deepEqual(r.map((x) => x.id), ['a', 'd']);
});

test('rankResults: remíza → boost z recents, jinak stabilní pořadí', () => {
  const plain = rankResults(items, 'faktury', []);
  assert.deepEqual(plain.map((x) => x.id), ['b', 'c']);
  const boosted = rankResults(items, 'faktury', ['c']);
  assert.deepEqual(boosted.map((x) => x.id), ['c', 'b']);
});

test('rankResults: recents boost nepřebije vyšší skóre', () => {
  const r = rankResults(items, 'uct', ['d']);
  assert.equal(r[0].id, 'a'); // prefix vyhrává i bez recents
});

test('rankResults: limit per skupina', () => {
  const many = Array.from({ length: 15 }, (_, i) => ({ id: `i${i}`, label: `Test ${i}` }));
  assert.equal(rankResults(many, 'test', []).length, 10);
  assert.equal(rankResults(many, 'test', [], 3).length, 3);
});

test('rankResults: prázdný / whitespace query → []', () => {
  assert.deepEqual(rankResults(items, '', ['a']), []);
  assert.deepEqual(rankResults(items, '   ', []), []);
});

test('rankResults: nemutuje vstupní položky (ranges jen na kopii)', () => {
  rankResults(items, 'uct', []);
  assert.equal(items[0].ranges, undefined);
  assert.equal(items[0].score, undefined);
});
