// Čisté funkce fuzzy matchingu pro command paletu — bez Svelte importů,
// testovatelné přes `node --test`.
//
// Folding diakritiky pracuje per znak a vrací mapu indexů foldnutý →
// originál, takže zvýraznění shod (ranges) jde mapovat zpět na původní
// label i pro NFD-dekomponovaný vstup ze serveru.

const COMBINING_MARK = /[̀-ͯ]/;
const WORD_CHAR = /[\p{L}\p{N}]/u;

const SCORE_PREFIX = 3;
const SCORE_WORD_START = 2;
const SCORE_SUBSEQUENCE = 1;

/** Fold řetězce: NFD + strip kombinujících znaků + lowercase. */
export function foldDiacritics(s) {
  return foldWithMap(s).folded;
}

/**
 * Fold s mapou indexů: `map[i]` = index znaku originálu, ze kterého
 * vznikl i-tý znak foldnutého řetězce. Kombinující znaky (samostatné
 * i vzniklé dekompozicí) se vypouštějí.
 */
export function foldWithMap(s) {
  let folded = '';
  const map = [];
  for (let i = 0; i < s.length; i++) {
    const base = s[i].normalize('NFD')[0];
    if (COMBINING_MARK.test(base)) continue;
    for (const c of base.toLowerCase()) {
      folded += c;
      map.push(i);
    }
  }
  return { folded, map };
}

/**
 * Match foldnutého query proti foldnutému labelu.
 * Ranking: prefix > začátek slova > subsequence.
 *
 * @returns {null | {score: number, ranges: Array<[number, number]>}}
 *   `ranges` jsou půlotevřené intervaly [start, end) v indexech
 *   foldnutého řetězce — na originál je převádí mapRanges().
 */
export function matchItem(queryFolded, labelFolded) {
  if (!queryFolded) return null;

  if (labelFolded.startsWith(queryFolded)) {
    return { score: SCORE_PREFIX, ranges: [[0, queryFolded.length]] };
  }

  // Souvislý výskyt query hned za ne-alfanumerickým znakem = začátek slova.
  let idx = labelFolded.indexOf(queryFolded);
  while (idx !== -1) {
    if (!WORD_CHAR.test(labelFolded[idx - 1])) {
      return { score: SCORE_WORD_START, ranges: [[idx, idx + queryFolded.length]] };
    }
    idx = labelFolded.indexOf(queryFolded, idx + 1);
  }

  // Subsequence — znaky query po sobě kdekoli v labelu.
  const indices = [];
  let li = 0;
  for (const qc of queryFolded) {
    li = labelFolded.indexOf(qc, li);
    if (li === -1) return null;
    indices.push(li);
    li += 1;
  }
  return { score: SCORE_SUBSEQUENCE, ranges: mergeIndices(indices) };
}

/** Vzestupné indexy → půlotevřené intervaly, sousední sloučené. */
function mergeIndices(indices) {
  const ranges = [];
  for (const i of indices) {
    const last = ranges[ranges.length - 1];
    if (last && last[1] === i) {
      last[1] = i + 1;
    } else {
      ranges.push([i, i + 1]);
    }
  }
  return ranges;
}

/** Ranges z foldnutého prostoru → indexy původního řetězce (dle map). */
export function mapRanges(ranges, map) {
  return ranges.map(([start, end]) => [map[start], map[end - 1] + 1]);
}

/**
 * Ohodnotí a seřadí položky jedné skupiny podle query. Vrací kopie
 * položek s `{score, ranges}` (ranges v indexech původního `label`),
 * seřazené score sestupně; remíza → napřed položky z `recentIds`,
 * jinak stabilně dle vstupního pořadí. Prázdný query → [] (prázdný
 * vstup obsluhuje volající skupinou Naposledy).
 */
export function rankResults(items, query, recentIds = [], limit = 10) {
  const q = foldDiacritics((query ?? '').trim());
  if (!q) return [];
  const recent = new Set(recentIds);
  const scored = [];
  items.forEach((item, index) => {
    const { folded, map } = foldWithMap(item.label ?? '');
    const m = matchItem(q, folded);
    if (!m) return;
    scored.push({
      item,
      score: m.score,
      ranges: mapRanges(m.ranges, map),
      index,
      isRecent: recent.has(item.id) ? 1 : 0,
    });
  });
  scored.sort((a, b) =>
    (b.score - a.score) || (b.isRecent - a.isRecent) || (a.index - b.index));
  return scored.slice(0, limit)
    .map(({ item, score, ranges }) => ({ ...item, score, ranges }));
}
