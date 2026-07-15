# Task: Enrichment řádků z historie — matchování přes více kandidátních textů

**Status:** implementováno, čeká na ověření na alfě (krok 3 Ověření)
**Cíl:** `RowHistoryEnricher` musí napárovat položku z historie i v případě,
že AI extrakce vyplnila `item.description` textem, který v historii není
(např. fakturované období), zatímco `item.name` v historii je.

## Návaznost

- `modules/core/exchange/src/Enrich/RowHistoryEnricher.php` (vznikl v rámci
  AI párování položek, vrstva 0)
- Testy: `tests/Unit/Module/Core/Exchange/Enrich/RowHistoryEnricherTest.php`
- Souvisí: `mail-phase3a.md` (AI analýza), `docs/mail/api-contract.md`

## Kontext — diagnostika z alfy (DS lefreal vs. finmago, 07/2026)

Reprodukováno na reálných datech serveru ns-alpha:

**lefreal, zpráva „CEZNET-INTERNEXT - Faktura - 26063631“ (nefunguje):**
- AI extrakce: `item.name` = „Měsíční paušál za Internet - 1000MEGA+“,
  `item.description` = „Fakturované období: 01.07.2026 - 31.07.2026“
- Historie partnera (ČEZNET, 9 faktur `invni` ve stavu 40):
  `docs_core_rows.description` = „Měsíční paušál za Internet - 1000MEGA+“
- `rowText()` preferuje `item.description` → matchuje se fakturované období
  → všechny tři úrovně selhaly (normalizace: „fakturované období“ vs.
  „měsíční paušál za internet mega“, Jaccard 0)
- Výsledek: `enrichment.matchedBy = null`, položka se nepřiřadila

**finmago, faktura 4LAN (funguje):**
- AI extrakce: jen `item.name`, `item.description` chybí
- `rowText()` spadne na `item.name` → `historyExactRaw` zasáhne

Jediný rozdíl je tedy volba textu pro matchování. Fallback řetěz
`row.description ?? item.description ?? item.name` vezme první neprázdný
text a ostatní kandidáty už nezkouší.

Pozn.: historie importovaná ze starého Shipardu má v `description` název
položky; historie vzniklá novou pipeline může mít cokoli, co AI dala do
popisu (transformRows používá stejný fallback). Oprava musí pokrýt obojí.

## Scope

- **Jen** `RowHistoryEnricher` (matchování). `DocumentApplier::transformRows()`
  se nemění — co se zapisuje do `docs_core_rows.description` zůstává.
- Žádné změny schématu ani API kontraktu; audit blok `_resolve.rows[i].enrichment`
  se rozšiřuje o nový volitelný klíč `matchedText` (D3) — zpětně kompatibilní.

## Návrh

### 1. `rowText()` → `rowTextCandidates()`

```php
/**
 * Kandidátní texty řádku pro matchování, v pořadí preference.
 * Neprázdné, trimnuté, deduplikované.
 *
 * @param array<string, mixed> $row
 * @return list<string>
 */
private function rowTextCandidates(array $row): array
{
    $item = is_array($row['item'] ?? null) ? $row['item'] : [];
    $candidates = [];
    foreach ([$row['description'] ?? null, $item['description'] ?? null, $item['name'] ?? null] as $text) {
        if (!is_string($text)) {
            continue;
        }
        $text = trim($text);
        if ($text !== '' && !in_array($text, $candidates, true)) {
            $candidates[] = $text;
        }
    }
    return $candidates;
}
```

### 2. `findMatch()` tier-major přes kandidáty

Podpis se změní na `findMatch(array $candidates, array $history)`.
Pořadí průchodu: **úroveň má přednost před kandidátem** — exactRaw přes
všechny kandidáty, pak exactNorm přes všechny, pak fuzzy přes všechny.
Tím zůstane zachováno „exact vyhrává nad fuzzy“, i když exact sedí až
na `item.name`. Uvnitř úrovně vyhrává dřívější kandidát, uvnitř
kandidáta nejnovější historie (stávající řazení `h.id DESC`).

```php
private function findMatch(array $candidates, array $history): ?array
{
    foreach ($candidates as $text) {
        foreach ($history as $hist) {
            if (trim((string) ($hist['description'] ?? '')) === $text) {
                return [$hist, 'historyExactRaw', 'high', $text];
            }
        }
    }

    // norm => první originální text, který na něj vede (pro matchedText)
    $norms = [];
    foreach ($candidates as $text) {
        $norm = $this->normalizeText($text);
        if ($norm !== '' && !isset($norms[$norm])) {
            $norms[$norm] = $text;
        }
    }
    if ($norms === []) {
        return null;
    }
    foreach ($norms as $norm => $text) {
        foreach ($history as $hist) {
            if ($this->normalizeText((string) ($hist['description'] ?? '')) === $norm) {
                return [$hist, 'historyExactNorm', 'high', $text];
            }
        }
    }

    foreach ($norms as $norm => $text) {
        $tokens = $this->tokenize($norm);
        foreach ($history as $hist) {
            $histTokens = $this->tokenize($this->normalizeText((string) ($hist['description'] ?? '')));
            if ($this->jaccard($tokens, $histTokens) >= self::FUZZY_THRESHOLD) {
                return [$hist, 'historyFuzzy', 'medium', $text];
            }
        }
    }

    return null;
}
```

### 3. Volání v `enrichRow()`

```php
$candidates = $this->rowTextCandidates($row);
$match = $candidates !== [] ? $this->findMatch($candidates, $history) : null;
// ...
[$hist, $matchedBy, $confidence, $matchedText] = $match;
// ...
$enrichment['matchedText'] = $matchedText;
```

Výchozí tvar `$enrichment` na začátku `enrichRow()` dostane
`'matchedText' => null` — blok se zapisuje vždy, i pro nenapárované řádky,
takže klíč je přítomný konzistentně.

Pozn. k idempotenci: `revertOwnSuggestions()` se `matchedText` netýká —
pracuje jen se `suggested` hodnotami; nový klíč je čistě auditní.

### 4. Testy

Nové případy v `RowHistoryEnricherTest`:

1. **testItemNameMatchesWhenDescriptionDoesNot** — reprodukce lefreal:
   `item.description` = fakturované období (v historii není),
   `item.name` = text z historie → `historyExactRaw`, trojice se propsala.
2. **testDescriptionCandidatePreferredWithinTier** — oba kandidáty mají
   exactRaw zásah na různé historické řádky → vyhraje `item.description`
   (dřívější kandidát).
3. **testExactOnNameBeatsFuzzyOnDescription** — `item.description` má jen
   fuzzy zásah, `item.name` exactRaw na jiný řádek → vyhraje exact
   (tier-major, ne candidate-major).
4. **testDuplicateCandidatesDeduplicated** — `row.description` ==
   `item.name` → matchuje se jednou (sanity, chování beze změny).
5. **matchedText v auditu** — v testech 1–3 ověřit, že
   `enrichment.matchedText` obsahuje vyhrávající kandidátní text
   (u fuzzy/norm originální, nenormalizovaný tvar); u nenapárovaného
   řádku je `null`.

Stávajících 13 testů musí projít beze změny (fallback pořadí kandidátů
je zachováno, mění se jen šíře prohledávání).

### 5. Dokumentace

- Docblock třídy: upravit popis matchování (D5) — „kandidátní texty
  řádku v pořadí description → item.description → item.name, tier-major“.
- `docs/mail/api-contract.md` příp. další místa, kde je enrichment popsán —
  ověřit a aktualizovat zmínku o výběru textu.

## Ověření

1. `php -l modules/core/exchange/src/Enrich/RowHistoryEnricher.php`
2. `vendor/bin/phpunit --filter 'RowHistoryEnricherTest'`
3. Po nasazení na alfu: re-analyzáza (nebo preview/apply) zprávy 6480
   v DS lefreal → `_resolve.rows[0].enrichment.matchedBy = historyExactRaw`,
   `matchedText = „Měsíční paušál za Internet - 1000MEGA+“`,
   `suggested.ourCode = 518100`, `suggested.vatCode = cz-110`.

## Akceptace

- [x] Lefreal scénář (description mimo historii, name v historii) se páruje
      — `testItemNameMatchesWhenDescriptionDoesNot`
- [x] Finmago scénář (jen name) funguje beze změny — `testNameOnlyRowMatches`
- [x] Exact zásah na pozdějším kandidátovi vyhrává nad fuzzy na dřívějším
      — `testExactOnNameBeatsFuzzyOnDescription`
- [x] `enrichment.matchedText` v auditu (vyhrávající kandidát / `null`)
      — asserty v nových testech, u fuzzy originální tvar
      (`testMatchedTextOriginalFormOnFuzzyAndNullWhenUnmatched`)
- [x] Stávající testy zelené (13 beze změny, celkem 19), idempotence
      (revertOwnSuggestions) nedotčena
- [x] Docblock třídy aktualizován; `docs/mail/api-contract.md` enrichment
      nepopisuje (ověřeno, beze změny), doplněn `matchedText` + pozn.
      o kandidátech do `tasks/row-history-enrichment.md`

## Rozhodnutí k designu (potvrzená)

- ✓ **D1 — tier-major pořadí**: úroveň matchování má přednost před pořadím
  kandidátů; exact zásah na `item.name` vyhrává nad fuzzy zásahem na
  `item.description`. (Alternativa candidate-major zamítnuta — fuzzy na
  fakturovaném období by mohl přebít exact na názvu položky.)
- ✓ **D2 — transformRows beze změny**: text zapisovaný do
  `docs_core_rows.description` se nemění; opravuje se jen čtení při
  matchování. Změna zápisu by měnila obsah nových dokladů a je mimo scope.
- ✓ **D3 — `matchedText` v auditu**: enrichment blok se rozšiřuje o klíč
  `matchedText` s vyhrávajícím kandidátním textem (originální,
  nenormalizovaný tvar; `null` bez zásahu). Nový volitelný klíč, zpětně
  kompatibilní; `revertOwnSuggestions()` ho ignoruje.
- ✓ **D4 — FUZZY_THRESHOLD zůstává 0.6**: diagnostika neukázala problém
  s prahem, neměnit v rámci této opravy.
