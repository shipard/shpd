# Task: Zaokrouhlení celkové částky u faktur z došlé pošty

**Stav:** částečně — implementováno, zbývá ověření a nasazení promptu

**Cíl:** Faktury se zaokrouhlenou částkou k úhradě (typicky na celé Kč)
projdou review a apply bez falešného `totals_mismatch` warningu a vzniklý
doklad reprodukuje částku z faktury — přes existující mechanismus
`total_rounding_mode` / `applyTotalRounding()` v `DocDocument`.

---

## Návaznost

- `modules/core/exchange/src/Document/DocumentApplier.php` (`transform()`)
- `modules/core/exchange/src/Document/DocumentValidator.php`
  (`checkTotalsCoherence()`)
- `modules/docs/core/src/DocDocument.php` (`applyTotalRounding`,
  `applyRounding`) + `modules/docs/core/config/roundingModes.jsonc`
- `modules/core/mail/profiles/default_czech_invoices.jsonc` (prompt)
- `frontend/src/components/exchange/DocumentExchangePreview.svelte`
- Kontace zaokrouhlení už existují: `rounding.cost` / `rounding.revenue`
  v `modules/economy/accounting/config/accountingRules.cz.jsonc` — tento
  task se jich nedotýká.

## Kontext — diagnostika z alfy (07/2026)

Reálná přijatá faktura z testovacího prostředí (hodnoty
anonymizované, poměry zachované):

```
totals:   totalBase 889.69, totalVat 110.36,
          totalAmount 1000.00, totalRounding −0.05
vatRecap: 48.40 (cz-120) + 951.65 (cz-110) = 1000.05
Σ řádků:  1000.05
```

AI zaokrouhlení extrahovala správně (`totalRounding: −0.05`), ale dál
s ním nikdo nepracuje:

1. **`DocumentValidator::checkTotalsCoherence`** porovnává deklarovaných
   1 000,00 proti třem variantám (Σ `row.totalPrice`, Σ s DPH per řádek,
   Σ `vatRecap[].total`) s tolerancí 0,01 — všechny dají 1 000,05 →
   falešný warning `totals_mismatch` v review modalu.
2. **`DocumentApplier::transform`** nemapuje z `totals` nic; vzniklý
   doklad má `total_rounding_mode = 0` → dopočte se na 1 000,05, tedy
   **jiná částka k úhradě než na faktuře**.
3. **Review modal** zaokrouhlení v součtech nezobrazuje (i18n klíč
   `exchange.preview.totals.rounding` existuje, nepoužitý).

ISDOC větev (`IsdocReader` → `totalRounding` z `PayableRoundingAmount`)
má tutéž díru za parserem — apply jde přes stejný `DocumentApplier`.

## Potvrzená rozhodnutí

| | |
|---|---|
| D1 | `total_rounding_mode` se v applieru **odvozuje nezávisle** z čísel (computed vs declared); extrahovaný `totals.totalRounding` je jen informativní (UI, audit), nikdy vstup derivace |
| D2 | Konzervativně: mode se nastaví jen když se computed a declared liší o > 0,01 a < 1,00 a některý mod declared přesně reprodukuje; jinak mode 0 + warning zůstává |
| D3 | Nové módy `docs.core.roundingModes`: **3 = Nahoru na 1** (ceil), **4 = Dolů na 1** (floor); směrové varianty na 0,01 se nedělají |
| D4 | Matematický mod (1) má přednost před směrovými (3, 4) — u shodného výsledku je konvencí |
| D5 | Validator: varianta prochází i tehdy, když je declared celé číslo a `|declared − varianta| < 1,00` (tj. declared je floor/ceil/round varianty) — nezávisí na extrahovaném `totalRounding` |
| D6 | Prompt: explicitní pravidlo pro `totalRounding` + zákaz zaokrouhlení jako položkového řádku; bump `v3.0.0` → `v3.1.0` |
| D7 | Review modal: řádek „Zaokrouhlení" v totals summary, jen když je `totalRounding` nenulové |
| D8 | ISDOC bez zvláštní větve — derivace v applieru pokrývá obě cesty (u ISDOC derivace z autoritativních čísel dá týž výsledek) |

## Scope

**In:**
1. Módy 3/4 v `roundingModes.jsonc` + větve v `DocDocument::applyRounding`.
2. Derivace `total_rounding_mode` v `DocumentApplier::transform`.
3. Rounding-aware `checkTotalsCoherence` v `DocumentValidator`.
4. Prompt pravidlo + `prompt_version v3.1.0` + changelog v `ai-prompts.md`.
5. Řádek Zaokrouhlení v `DocumentExchangePreview` (cs + en překlad).
6. Unit testy + aktualizace dokumentace.

**Out:**
- Změny schématu canonical (pole `totals.totalRounding` už existuje).
- Kontace zaokrouhlení (accountingRules) — hotové dřív.
- Zaokrouhlení DPH (`vat_rounding_mode`) — jiné téma.
- Editace modu v review modalu (uživatel ho případně změní po apply
  ve formuláři dokladu).

---

## Návrh

### 1. Nové módy zaokrouhlení

`modules/docs/core/config/roundingModes.jsonc` — doplnit:

```jsonc
"3": { "name": "Round up to 1",   "name:cs": "Nahoru na 1", "name:en": "Round up to 1" },
"4": { "name": "Round down to 1", "name:cs": "Dolů na 1",   "name:en": "Round down to 1" }
```

`DocDocument::applyRounding` — nové větve match:

```php
3 => ceil($amount),   // Nahoru na celé jednotky
4 => floor($amount),  // Dolů na celé jednotky
```

Pozn.: u záporných částek (dobropisy) platí matematická sémantika PHP
`ceil`/`floor` (`ceil(-1000.05) = -1000.0`) — derivace v applieru
(bod 2) mod vybírá porovnáním výsledku s declared, takže vždy sedí;
sémantiku zdokumentovat v komentáři.

### 2. Derivace modu v `DocumentApplier`

Nová privátní metoda `deriveTotalRoundingMode(array $canonical): ?int`,
volaná z `transform()`; výsledek se přidá do `$data` jako
`total_rounding_mode` (null → klíč vypadne přes stávající `array_filter`,
platí default 0).

```
computed =
    Σ vatRecap[].total        pokud recap neprázdný a všechny řádky mají numeric total
    ?? totalBase + totalVat   pokud obě numeric
    ?? Σ rows: totalPrice × (1 + vat.pct/100)   (jako validator, varianta 2)

declared = totals.totalAmount

null computed nebo declared        → null (bez derivace)
diff = declared − computed
|diff| ≤ 0.01                      → null (nezaokrouhleno)
|diff| ≥ 1.00                      → null (to není zaokrouhlení; warning zůstává)
round(computed, 0) == declared     → 1        (D4: přednost)
ceil(computed)     == declared     → 3
floor(computed)    == declared     → 4
jinak                              → null
```

Porovnání `==` s tolerancí 0,001 (float). Mode 1 zároveň absorbuje
haléřový nesoulad mezi zde spočteným `computed` a tím, co si následně
dopočte `DocDocument` z řádků — výpočet se neduplikuje, jen se volí mod.

Typový případ: computed 1 000,05, declared 1 000,00, diff −0,05 →
`round(1000.05) = 1000` → **mode 1**; `DocDocument` pak sám dopočte
`total_amount = 1000.00`, `total_rounding = −0.05`. ✓

### 3. Validator — rounding-aware koherence

`checkTotalsCoherence`: ke stávajícím `matchBase` / `matchWithVat` /
`matchRecap` (tolerance 0,01) přidat pro každou variantu `v`:

```
matchRounded(v) = declaredF je celé číslo (|declaredF − round(declaredF)| ≤ 0.001)
                  && |declaredF − v| < 1.00
```

(Celé declared v pásmu < 1,00 od varianty je vždy její floor nebo ceil —
kritérium pokrývá všechny tři módy bez výčtu.) Warning vystřelí jen když
neprojde žádná varianta ani její zaokrouhlená podoba. Aktualizovat
docblock metody a komentář ve schématu
(`shpd.docs.document.v1.jsonc`, řádek ~14, zmínka o `totals_mismatch`).

### 4. Prompt v3.1.0

`modules/core/mail/profiles/default_czech_invoices.jsonc`:

- Do PRAVIDLA doplnit (znění dopilovat při implementaci):
  - `totals.totalRounding`: pokud je na faktuře zaokrouhlení celkové
    částky (řádek „Zaokrouhlení", rozdíl mezi součtem položek s DPH
    a částkou k úhradě), vrať rozdíl se znaménkem (zaokrouhleno dolů =
    záporná hodnota). Bez zaokrouhlení vrať 0 nebo pole vynech.
  - Zaokrouhlení NIKDY nevracet jako položkový řádek v `rows` — patří
    výhradně do `totals.totalRounding`. (Bez tohoto pravidla hrozí
    „Zaokrouhlení −0,05" jako item řádek → falešná položka na dokladu
    a rozbitá derivace modu.)
- `prompt_version`: `v3.0.0` → `v3.1.0`.
- Changelog sekce v `modules/core/mail/docs/ai-prompts.md`.
- Nasazení dle standardního workflow (`ds-upgrade` sync profilu).

### 5. Review modal

`DocumentExchangePreview.svelte`, totals summary (za řádkem Celkem):

```svelte
{#if canonical.totals?.totalRounding}
  <div>
    {t('exchange.preview.totals.rounding')}:
    <strong>{formatMoney(canonical.totals.totalRounding, canonical.currency)}</strong>
  </div>
{/if}
```

Klíč v `cs.js` existuje (`'Zaokrouhlení'`), ověřit/doplnit `en.js`
(`'Rounding'`). Nenulovost řeší truthiness `{#if}` (0 / null / undefined
nezobrazí).

### 6. Testy

- `tests/Unit/Module/Docs/Core/DocDocumentDomesticAmountsTest.php`
  (případně nový `DocDocumentRoundingTest`): módy 3 a 4 —
  `applyTotalRoundingPub` nahoru/dolů, kladné i záporné částky.
- `tests/Unit/Module/Core/Exchange/Document/DocumentApplierTest.php`:
  derivace modu — typový scénář (recap → mode 1), ceil scénář
  (declared = computed + 0,60 → mode 3), floor scénář, diff ≥ 1,00 →
  bez modu, shoda v toleranci → bez modu, chybějící totals → bez modu,
  fallback pořadí computed (recap → base+vat → řádky).
- `tests/Unit/Module/Core/Exchange/Document/DocumentValidatorTest.php`:
  declared celé + varianta v pásmu < 1,00 → bez warningu; declared
  necelé + diff 0,05 → warning zůstává; diff ≥ 1,00 → warning.

### 7. Dokumentace

- `docs/exchange-format.md` — poznámka k `totals.totalRounding`
  (sémantika znaménka; applier z něj nečte, mode se odvozuje).
- `modules/core/mail/docs/ai-analysis.md` — krátká zmínka v části apply
  (derivace `total_rounding_mode`).

## Ověření

```
php -l  (dotčené PHP soubory)
vendor/bin/phpunit --filter 'DocumentApplierTest|DocumentValidatorTest|DocDocument'
cd frontend && timeout 90 npm run build 2>&1 | tail -10
```

Ruční test na alfě: typový případ z diagnostiky — review modal bez
`totals_mismatch`, zobrazené Zaokrouhlení −0,05 CZK; po apply doklad
s `total_rounding_mode = 1` a `total_amount` / `total_rounding`
odpovídající faktuře.

## Vedlejší nález (mimo scope, k samostatnému prověření)

U diagnostikovaného případu je nejspíš špatně extrahovaný `vat.mode`:
řádky nesou ceny **s DPH** (Σ řádků = Σ recap totals s daní, ne
totalBase), ale AI vrátila `fromBase` — při apply by se DPH přičetla
podruhé (celková částka o celou sazbu vyšší).
Kandidát na samostatný prompt fix (pravidlo pro rozpoznání cen s DPH /
`fromTotal`), se zaokrouhlením nesouvisí.
