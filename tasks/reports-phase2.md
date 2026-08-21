# Reporty Fáze 2 — výsledovka + rozvaha

**Stav:** hotovo

> PRD pro jednu Claude Code session. Design: `docs/reports.md` (D1–D16,
> zejména §3.3 messages, §3.4 computed řádky). Staví na hotové Fázi 1 —
> jádro `src/Core/Reports/`, `SubtotalAggregator`, klíč `reports`
> v module.jsonc, `GeneralLedgerBuilder`, REST `GET /_reports`.

## Kontext

Dva další buildery nad stejným jádrem: **výsledovka** (interní podoba,
třídy 5/6, výsledek jako `computed` řádek) a **rozvaha** (interní podoba,
třídy 0–4 dělené na Aktiva/Pasiva dle `account_kind`, výsledek běžného
období jako `computed` řádek v pasivech — D13). Rozvaha přináší **vestavěné
kontrolní invarianty** (D15): aktiva = pasiva a shoda dopočteného výsledku;
porušení = `ReportMessage` severity error. Právě kvůli nim to stavíme —
agregovaně odhalí nevyrovnané deníky.

Jádro se nemění; jediný refaktor je vytažení sdílených helperů
z `GeneralLedgerBuilder`, ať se agregace deníku a názvy účtů nekopírují
třikrát.

## Cíl

1. Refaktor: sdílený `JournalReportSupport` (agregace deníku per fiskální
   měsíce, názvy účtů, error messages z `is_error` řádků).
2. `ProfitLossBuilder` + deklarace `economy.accounting.profitLoss`.
3. `BalanceSheetBuilder` + deklarace `economy.accounting.balanceSheet`.
4. Testy.

## Návaznost

- **Prerekvizita:** Fáze 1 hotová (ověřeno na dev DS 2026-08-21).
- **Odemyká:** Fázi 3 (viewer — bude mít co zobrazovat u všech tří
  reportů), kontrolu DPH v M1 (výsledovka/kniha vedle přiznání).
- REST funguje bez zásahu — nové reporty se objeví v katalogu samy
  (deklarace → registry → controller).

## Před implementací přečti

- `docs/reports.md` §3 (ReportResult, messages, computed), D6 (strany,
  balance = md − d), D13, D15
- Fáze 1 kód: `src/Core/Reports/SubtotalAggregator.php` (přesná signatura
  `rollup(detailRows, prefixLengths, labelResolver, totalLabel)` —
  **použij, neupravuj**),
  `modules/economy/accounting/src/Reports/GeneralLedgerBuilder.php`
  (vzor builderu; z něj vytáhneš helpery),
  `src/Core/Reports/ReportParamValidator.php` (co už řeší — nedubluj)
- `modules/economy/accounting/config/accountKinds.jsonc` — hodnoty
  `account_kind`: 0 Aktiva, 1 Pasiva, 2 Náklady, 3 Výnosy, 5 Aktivně
  pasivní (+ další, pro tuhle fázi nepodstatné)
- `modules/economy/accounting/tables/economy_accounting_accounts.jsonc`
  (`number`, `name`, `account_kind`; rozvrh obsahuje i řádky tříd,
  skupin a syntetik — zdroj labelů subtotalů, ověřeno ve Fázi 1)
- `modules/economy/accounting/config/reports.jsonc` (přidáš deklarace)

## Scope

**Uvnitř:** `JournalReportSupport` (+ úprava `GeneralLedgerBuilder`, aby ho
používal — chování beze změny); dva buildery; dvě deklarace; testy.

**Mimo:** jádro `src/Core/Reports/` (beze změn); viewer/UI (Fáze 3); MCP
a diff (Fáze 4); oficiální výkazy dle vyhlášky (doména `filing`);
vnitropodnikové účty (kind 7/8) a podrozvaha (kind 6) — do výsledovky ani
rozvahy nevstupují; střediska; „v tisících" — **není parametr builderu**:
data nesou vždy plnou přesnost (D6), zobrazení v tisících je věc vieweru
(Fáze 3), do deklarace ho nedávej.

## Co implementovat

### A. `JournalReportSupport`
(`modules/economy/accounting/src/Reports/JournalReportSupport.php`)

Vytáhni z `GeneralLedgerBuilder` jako sdílenou final třídu (kompozice,
žádná dědičnost mezi buildery — duch D1):

- `aggregate(ReportRequest, list<int> $monthIds, ?string $classPrefixRegex = null)`
  — stávající agregační dotaz + volitelný filtr tříd
  (`account_number LIKE` per první znak; implementuj jako seznam povolených
  prvních znaků, ne regex v SQL),
- `loadAccountNames(ReportRequest)` — beze změny,
- `errorMessages(...)` — beze změny (kód `journal.accountNotFound`).

`GeneralLedgerBuilder` přepni na support; **testy Fáze 1 musí zůstat
zelené beze změn asercí** — to je důkaz, že refaktor nemění chování.

### B. `ProfitLossBuilder`
(`modules/economy/accounting/src/Reports/ProfitLossBuilder.php`)

Deklarace: id `economy.accounting.profitLoss`, name:cs „Výsledovka",
granularity `month|quarter|halfYear|year`, params
`detail: analytic|synthetic` (default analytic).

- **Účty:** jen třídy **5 a 6** (první znak čísla účtu). Otevírací doklady
  se tříd 5/6 netýkají, ale filtr je čistě prefixový — kdyby se v datech
  objevily, vstoupí (správně: výsledovka = obraty tříd 5/6 za období).
- **Sloupce** (obě `money`):
  - `period` — obraty za zvolený interval (`monthIdsInRange`),
  - `ytd` — obraty od začátku fiskálního roku do konce intervalu
    (`monthIdsBefore` + `monthIdsInRange`; „before" ve Fázi 1 správně
    vynechává uzavírací období). Odpovídá sloupcům „Měsíc / Rok" starého
    Shipardu (otevřený bod Fáze 1 → vyřešen takto).
- **Řádky:** detail účty tříd 5+6 dle čísla (Náklady před Výnosy —
  přirozené řazení), subtotaly přes `SubtotalAggregator`
  (`[3, 2, 1]` / synthetic `[2, 1]`), **bez** generického total řádku —
  místo něj:
- **`computed` řádek „Výsledek hospodaření za období"** (level 0, account
  null, kind Computed), za každý sloupec:
  `balance = (d − md) tříd 6 − (md − d) tříd 5` = výnosy − náklady
  (kladné = zisk). `md`/`d` computed řádku = 0 (výsledek není obrat stran;
  jediná smysluplná hodnota je balance). Pozn.: `SubtotalAggregator` vrací
  total vždy — total řádek zahoď, nebo (čistější) rollup volej se signaturou
  bez totalu, pokud to umí; jinak dropni poslední řádek s komentářem proč.
- Nulové řádky (period i ytd 0) neemituj — konzistentně s knihou.
- `is_error` řádky tříd 5/6: jako v knize (řádek + message).

### C. `BalanceSheetBuilder`
(`modules/economy/accounting/src/Reports/BalanceSheetBuilder.php`)

Deklarace: id `economy.accounting.balanceSheet`, name:cs „Rozvaha",
granularity `month|quarter|halfYear|year`, params `detail` (dtto).

- **Účty:** třídy **0–4**.
- **Sloupce:** `opening` (počáteční stav = vše před intervalem, jako
  v knize) a `closing` (stav ke konci intervalu = opening + obraty
  intervalu). Turnover sloupec rozvaha nemá.
- **Sekce Aktiva / Pasiva** — zařazení per analytický účet:
  - `account_kind` 0 → Aktiva; 1 → Pasiva;
  - `account_kind` 5 (aktivně pasivní — 336, 341, 343…), NULL či jiný →
    dle **znaménka closing balance**: ≥ 0 Aktiva, < 0 Pasiva.
    Zjednodušení v1 (opening strana se může lišit) — okomentuj v kódu.
  - `account_kind` čti z rozvrhu jen pro analytiky (detail řádky);
    subtotaly vznikají uvnitř sekce.
- **Prezentace balance v pasivech:** pasivní účty mají balance md − d
  záporné; aby rozvaha dávala smysl, buildery **v pasivní sekci otáčej
  znaménko balance** (md/d nech syrové). Tohle NENÍ porušení D6 —
  builder definuje sémantiku sloupce své sekce (stejně jako otočí
  výsledek), renderer nic nedopočítává. Okomentuj.
- **Struktura řádků:** sekce Aktiva (detaily + subtotaly přes aggregator,
  total řádek relabeluj „AKTIVA CELKEM", kind Total, level 0), pak sekce
  Pasiva (dtto, „PASIVA CELKEM"), přičemž do pasiv před total vstupuje:
- **`computed` řádek „Výsledek hospodaření běžného období"** (kind
  Computed, level dle detailu sekce — zařaď jako poslední položku pasiv):
  `balance = výnosy − náklady` tříd 5/6 kumulativně od začátku roku do
  konce intervalu (stejný vzorec jako výsledovka ytd; opening sloupec =
  totéž k začátku intervalu, tj. z `monthIdsBefore`). Vstupuje do
  „PASIVA CELKEM".
- **Invarianty (D15)** — po sestavení ověř pro oba sloupce:
  1. `AKTIVA CELKEM == PASIVA CELKEM` (tolerance 0,005),
  2. konzistence dopočtu: Σ balance tříd 0–4 (syrové, před otočením)
     + Σ balance tříd 5–6 == 0 (vyrovnaný deník).
  Porušení → `ReportMessage` error `balanceSheet.notBalanced` /
  `balanceSheet.journalImbalance`, v textu sloupec a rozdíl. Report se
  **vrátí i tak** (status errors, HTTP 200) — přesně proto existuje.

### D. Deklarace — `config/reports.jsonc`

Přidej obě položky (vzor hlavní knihy; `name`, `name:cs`, builder FQCN,
granularity, params). Po změně `ds-upgrade` na dev DS.

## Testy

PHPUnit (`--filter 'ProfitLossBuilderTest|BalanceSheetBuilderTest|GeneralLedgerBuilderTest'`,
`timeout_sec=120`):

1. **GeneralLedgerBuilderTest** — beze změn, zelený (důkaz refaktoru A).
2. **ProfitLossBuilderTest** — seed: náklady 5xx, výnosy 6xx ve dvou
   měsících + účet třídy 3 (nesmí vstoupit). Ověř: period vs ytd (interval
   = 2. měsíc → period jen měsíc 2, ytd oba), computed výsledek = výnosy −
   náklady v obou sloupcích, zisk i ztráta (dva scénáře), synthetic režim,
   nulový účet neemitován, žádný generický total.
3. **BalanceSheetBuilderTest** — seed vyrovnaného deníku: aktivum (kind 0),
   pasivum (kind 1), aktivně pasivní účet (kind 5) jednou s kladným a jednou
   se záporným zůstatkem (skončí v opačných sekcích), náklad + výnos
   (→ computed výsledek). Ověř: AKTIVA CELKEM == PASIVA CELKEM v opening
   i closing, computed výsledek == výsledek z ProfitLoss vzorce, otočené
   znaménko v pasivech, status ok. Druhý scénář: záměrně nevyrovnaný deník
   → status errors + oba message kódy dle situace, totaly přesto spočtené.

## Commit strategie

1. `economy.accounting: JournalReportSupport — sdílená agregace pro report buildery`
   (A, testy knihy zelené)
2. `economy.accounting: report výsledovka (ProfitLossBuilder)` (B + testy)
3. `economy.accounting: report rozvaha (BalanceSheetBuilder, invarianty D15)`
   (C + D + testy)
4. `docs: reports.md — stav Fáze 2`

## Hotovo když

- [ ] `ds-upgrade` projde; `GET /_reports` vrací tři reporty
- [ ] testy Fáze 1 zelené bez úprav asercí; nové suity zelené
- [ ] dev DS: výsledovka srpen 2026 — computed výsledek == ručnímu SQL
      (`SUM(cr−dr)` tříd 6 − `SUM(dr−cr)` tříd 5); ytd == období 1–8
- [ ] dev DS: rozvaha — AKTIVA CELKEM == PASIVA CELKEM, status ok
      (deník na dev DS je vyrovnaný, ověřeno 2026-08-21)
- [ ] zisk z rozvahy == zisk z výsledovky za stejný interval
- [ ] `docs/reports.md`: stav Fáze 2 hotova + zaznamenat upřesnění
      (ytd sloupec výsledovky řeší otevřený bod Fáze 1; sémantika
      znaménka v pasivní sekci)

## Otevřené body (nerozhodují o Fázi 2)

- Aktivně pasivní účty: strana dle closing balance je zjednodušení —
  správně per sloupec (opening může být na druhé straně). Vyhodnotit na
  reálných datech alfy, případně Fáze 2.1.
- Výsledovka: členění provozní/finanční (`results_type` v rozvrhu) —
  až s doménou `filing`, interní podoba ho nepotřebuje.
- Uzavírací období (period_type 2) v rozvaze ke konci roku — v1 je mimo
  interval (validator nabízí jen běžné měsíce 1–12); rozvaha „po uzávěrce"
  se vyřeší s uzávěrkami.
