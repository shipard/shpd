# Task: Enrichment řádků z historie — dominantní položka dodavatele

**Stav:** hotovo

**Cíl:** `RowHistoryEnricher` navrhne položku i u dodavatelů, jejichž texty
řádků se neopakují (spotřební materiál, PHM), ale položka je v historii
prakticky konstantní. Nová úroveň matchování „dominantní položka partnera"
— čistě statistická, bez textového signálu, s confidence `low`.

---

## Návaznost

- `modules/core/exchange/src/Enrich/RowHistoryEnricher.php`
  (vrstva 0, `tasks/row-history-enrichment.md`)
- `tasks/enrichment-row-text-candidates.md` (tier-major matchování kandidátů)
- `modules/core/mail/src/ExtractedDocumentStatusResolver.php`
  (`capStatusByRowCoverage`, D7 původního designu)
- Frontend badge: `frontend/src/components/exchange/enrichBadge.js` +
  `DocumentExchangePreview.svelte` (`tasks/enrichment-preview-badge.md`)

## Kontext — diagnostika z alfy (DS A, 07/2026)

Textové matchování strukturálně selhává u dvou tříd dodavatelů:

**UNI HOBBY (hobbymarket, spotřební materiál):** 171 přijatých faktur,
179 řádků s položkou. Rozložení: **168× (94 %) Materiál**, 6× Technické
zhodnocení majetku (částky 8–72 tis.), 2× Evidovaný hmotný majetek a tři
jednorázové výjimky. Přitom 148 unikátních textů ze 179 řádků — texty se
neopakují, exact/norm/fuzzy nemají na co zabrat.

**PHM (položka Pohonné hmoty):** používá ji 23+ dodavatelů, u typických
benzinek s dominancí ~100 % (ALITRON 59/59, ORLEN 49/49, Shell 20/20,
PETRA 154/161…). Per-partner dominance tedy PHM pokrývá bez jakékoli
cross-supplier logiky.

**Strukturální poznatek:** historické `docs_core_rows.description` jsou
texty účetní („PHM SPZ 4Z43461" — 299×, „Tiché víno"), zatímco AI extrakce
plní texty z faktury dodavatele. U těchto dodavatelů jsou oba textové
prostory disjunktní — lepší fuzzy matching nepomůže, je potřeba signál
nezávislý na textu.

**Protipříklad (mimo scope):** Kateřina Svatoňová — 6 řádků invni, dvě
položky 4:2. Malá historie + dělená dominance; prahy ji záměrně vyloučí
(viz D2, D4).

## Scope

**In:**
1. Nová úroveň matchování `historyDominantItem` v `RowHistoryEnricher`
   (po exactRaw/exactNorm/fuzzy), confidence `low` (nová hodnota).
2. Guard přes částku řádku (potlačení návrhu u částkových outlierů).
3. Strop statusu: řádek doplněný s confidence `low` se **nepočítá** jako
   pokrytý — dokument zůstává `pending_review`.
4. Frontend badge: nový druh shody (label + tooltip).
5. Unit testy + aktualizace dokumentace.

**Out:**
- Dva a více opakovaných itemů u jednoho dodavatele (dělená dominance) —
  bez textového signálu nerozhodnutelné, v1 neřeší (D4).
- Ruční „výchozí položka" na kartě partnera (D6) — směr Vrstvy 2 (pravidla).
- Cross-supplier logika pro nové dodavatele bez historie (D7).
- Změny schématu / API kontraktu — audit blok se rozšiřuje jen o volitelné
  klíče (zpětně kompatibilní).

---

## Návrh

### 1. `RowHistoryEnricher` — nová úroveň matchování

Nové konstanty:

```php
/** Minimální počet řádků historie pro statistiku dominance. */
private const DOMINANCE_MIN_ROWS = 10;

/** Minimální podíl dominantní položky na řádcích historie. */
private const DOMINANCE_MIN_SHARE = 0.8;
```

`loadHistory()`: do SELECTu přidat `[r.total_price]` (guard ho potřebuje).
Dotaz jinak beze změny — dominance se počítá nad už načteným oknem
(max `HISTORY_LIMIT` = 200 nejnovějších řádků, INNER JOIN na živé položky
zajišťuje, že všechny řádky item mají).

Nová metoda:

```php
/**
 * Úroveň 3 — dominantní položka partnera (D1). Bez textového signálu:
 * pokud historie má >= DOMINANCE_MIN_ROWS řádků a jedna položka pokrývá
 * >= DOMINANCE_MIN_SHARE z nich, navrhne se s confidence `low`.
 *
 * Guard přes částku (D3): návrh se potlačí, když total řádku převyšuje
 * maximum historických total_price dominantní položky — chytá majetkové
 * / investiční řádky u jinak materiálových dodavatelů. Chybějící částka
 * na řádku canonical → guard se neuplatní (navrhne se).
 *
 * Vrací stejný tvar jako findMatch(); matchedText je null (žádný text
 * se nematchoval). Hist řádek = nejnovější výskyt dominantní položky
 * (řazení h.id DESC).
 *
 * @param list<array<string, mixed>> $history
 * @param array<string, mixed> $row
 * @return array{0: array<string, mixed>, 1: string, 2: string, 3: null}|null
 */
private function findDominantItem(array $history, array $row): ?array
```

Kroky:

1. `count($history) < self::DOMINANCE_MIN_ROWS` → null.
2. Četnosti per `item_code`; dominantní = nejčetnější;
   `share = cnt / count($history)`; `share < DOMINANCE_MIN_SHARE` → null.
   (Pozn.: při prahu 0.8 nemůže mít dvě položky zároveň — tie-handling
   není potřeba.)
3. Guard: `max(total_price)` přes řádky historie s dominantní položkou;
   `totalPrice` řádku canonical (cast na float), pokud je přítomná a
   `> max` → null. NULL historické total_price z maxima vynechat;
   pokud žádné číselné total_price v historii není, guard se neuplatní.
4. Návrat `[$hist, 'historyDominantItem', 'low', null]`, kde `$hist` je
   první (nejnovější) řádek historie s dominantní položkou. Trojice
   vat/account se propíše stávající cestou v `enrichRow()` (jen do
   prázdných polí — beze změny).

`enrichRow()` — zapojení jako fallback po textových úrovních; dominance
nevyžaduje kandidátní texty (řádek bez textu návrh dostat může):

```php
$candidates = $this->rowTextCandidates($row);
$match = $candidates !== [] ? $this->findMatch($candidates, $history) : null;
$match ??= $this->findDominantItem($history, $row);
```

Audit blok: k stávajícím klíčům přidat u dominance volitelný klíč

```jsonc
"dominance": { "share": 0.94, "rows": 179 }   // jen u historyDominantItem
```

— podklad pro tooltip badge a ladění. `revertOwnSuggestions()` beze změny
(pracuje jen se `suggested`; idempotence platí i pro dominanci).

Docblock třídy: doplnit úroveň 3 do popisu matchování.

### 2. `ExtractedDocumentStatusResolver::capStatusByRowCoverage` (D5)

Řádek doplněný dominancí má `item.ourCode` vyplněné, ale nesmí dokument
pustit nad `pending_review`. Rozšíření:

1. Z `$canonical['_resolve']['rows']` sestavit mapu
   `index => enrichment` (jen array entries s array `enrichment`).
2. Ve stávající smyčce (iterace `rows` s indexem):
   - `ourCode` prázdné → cap (beze změny),
   - **nově:** enrichment pro index existuje a
     `enrichment['confidence'] === 'low'` → cap.

Řádky s `ourCode` od AI mají `skipped: 'hasOurCode'` a `confidence: null`
→ cap se jich netýká. Textové matche (`high`/`medium`) beze změny.
ISDOC flow (`IsdocImportService`) volá tutéž metodu — enrichment bloky
tam typicky nejsou, chování se nemění.

### 3. Frontend badge

- `enrichBadge.js` → `matchKindKey()`: `'historyDominantItem'` → `'dominant'`
  (jinak stávající exact/fuzzy). Testy `node --test` rozšířit.
- `DocumentExchangePreview.svelte`: i18n klíč pro nový druh shody —
  label ve smyslu „častá položka dodavatele"; pokud tooltip skládá stupeň
  shody, doplnit větev pro `dominant` (může zobrazit podíl z
  `enrichment.dominance.share`, není podmínkou).
- Slovníky cs/en: nové klíče dle vzoru stávajících exact/fuzzy.

### 4. Dokumentace

- `modules/core/mail/docs/ai-analysis.md` — sekce „Obohacení řádků
  z historie": doplnit úroveň 3, prahy, guard, dopad na status.
- `tasks/row-history-enrichment.md` — poznámka pod D5 s odkazem sem
  (stejný vzor jako odkaz na kandidátní texty).

---

## Testy

`RowHistoryEnricherTest` (rozšíření):

1. **testDominantItemSuggestedWhenTextTiersFail** — 12 řádků historie,
   10× položka A, texty disjunktní s řádkem → `historyDominantItem`,
   `confidence: low`, `matchedText: null`, trojice z nejnovějšího řádku
   s položkou A, audit `dominance.share`/`rows`.
2. **testDominanceBelowShareThresholdNoSuggestion** — 12 řádků, 7:5 →
   žádný návrh (`matchedBy: null`).
3. **testDominanceBelowMinRowsNoSuggestion** — 6 řádků, 6× jedna položka
   → žádný návrh (scénář Svatoňová).
4. **testDominanceAmountGuardSuppresses** — total řádku > max historických
   total_price dominantní položky → žádný návrh; sesterský případ: řádek
   bez `totalPrice` → návrh projde.
5. **testTextMatchBeatsDominance** — fuzzy zásah existuje → vyhraje
   `historyFuzzy`, dominance se nekonzultuje.
6. **testDominanceRowWithoutTextCandidates** — řádek bez description/name
   → dominance návrh přesto projde.
7. **Idempotence** — dvojí běh přes `revertOwnSuggestions` = identický
   výstup i s dominance návrhem.

`ExtractedDocumentStatusResolverTest` (rozšíření):

8. ready confidence + řádek s enrichment `confidence: low` → status 20;
   řádky pokryté `high`/`medium` → beze změny.

`enrichBadge.test.js` (rozšíření): `matchKindKey('historyDominantItem')
=== 'dominant'`.

Filtry úzké (`--filter RowHistoryEnricherTest` apod.).

---

## Ověření na alfě

Po nasazení: re-analýza (nebo preview) nové faktury UNI HOBBY v DS
DS A → `_resolve.rows[*].enrichment.matchedBy = historyDominantItem`,
`suggested.ourCode = 16` (Materiál), status extracted dokumentu
`pending_review`; badge v preview ukazuje nový druh shody.
(Pozn.: k 07/2026 v DS A žádný extracted doc od UNI HOBBY není —
ověření vyžaduje novou došlou fakturu nebo re-analýzu po příchodu.)

---

## Commit strategie

1. **Commit 1:** `RowHistoryEnricher` (loadHistory + findDominantItem +
   zapojení + docblock) + unit testy.
2. **Commit 2:** strop statusu v `ExtractedDocumentStatusResolver` + testy.
3. **Commit 3:** frontend badge (`enrichBadge.js` + komponenta + i18n)
   + testy + dokumentace.

## Hotovo když

- [ ] Řádek bez textového zásahu u dodavatele s dominancí ≥ 80 % / ≥ 10
      řádků dostane návrh `historyDominantItem` / `low`.
- [ ] Částkový outlier návrh nedostane (guard přes max total_price
      dominantní položky).
- [ ] Dokument s low-confidence řádkem zůstává `pending_review`.
- [ ] Textové úrovně mají vždy přednost; stávající testy zelené beze změny.
- [ ] Badge v preview rozlišuje nový druh shody (cs/en).
- [ ] Idempotence (revertOwnSuggestions) platí i pro dominance návrhy.
- [ ] ai-analysis.md popisuje úroveň 3 včetně prahů a guardu.

## Rozhodnutí k designu (potvrzená)

- ✓ **D1 — nová úroveň `historyDominantItem`**: statistika nad stávajícím
  oknem historie partnera (max 200 řádků), fallback po textových úrovních,
  confidence `low` (nová hodnota). Žádný nový SQL dotaz.
- ✓ **D2 — prahy**: podíl dominantní položky ≥ 0.8, minimálně 10 řádků
  historie. (UNI HOBBY 94 % projde, benzinky ~100 % projdou, Svatoňová
  6 řádků / 67 % záměrně propadne.)
- ✓ **D3 — guard přes částku**: návrh potlačit, když total řádku převyšuje
  maximum historických total_price dominantní položky. Chytá drahé
  majetkové výjimky (Technické zhodnocení 25–72 tis. u UNI HOBBY); levné
  výjimky (reprezentace 170 Kč) žádný práh nechytí — řeší review (D5).
  Chybějící částka řádku → guard se neuplatní. Samoopravné: potvrzené
  vyšší nákupy posunou max v historii.
- ✓ **D4 — jen top-1 dominance**: dodavatelé se dvěma opakovanými itemy
  se v1 neřeší — bez textového signálu je volba mezi nimi hádání a špatný
  návrh svádí k bezmyšlenkovitému potvrzení.
- ✓ **D5 — status**: confidence `low` nepočítá řádek jako pokrytý,
  dokument zůstává `pending_review`; uživatel návrh vidí (badge) a
  potvrzuje. Textové matche beze změny.
- ✓ **D6 — bez ruční konfigurace**: žádné pole „výchozí položka" na
  partnerovi; explicitní pravidla patří do Vrstvy 2.
- ✓ **D7 — nový dodavatel bez historie mimo scope**: cross-supplier
  logika se neřeší; první faktury přiřazuje uživatel, smyčka se učí
  z potvrzených dokladů.
- ✓ **D8 — audit klíč `dominance`**: volitelný blok
  `{share, rows}` v enrichment auditu; zpětně kompatibilní,
  `revertOwnSuggestions()` ho ignoruje.
