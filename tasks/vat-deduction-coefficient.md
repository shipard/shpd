# Task: Krácený nárok na odpočet — koeficient (ř. 52 DP3) — #59 D13

**Stav:** hotovo — 2026-09-08; odchylky od zadání níže

**Odchylky od zadání (potvrzené 2026-09-08):** registrace ve formuláři jako
`select` (ne lookup — registrací je pár, vzor `ReportPeriodsForm`); koeficient
se zadává jako desetinné číslo 0,00–1,00 (ne v procentech s převodem /100);
odkaz z registrace je textová poznámka (frontend nemá „tlačítko → viewer").
Index `(vat_registration, year)` **není unique** — soft-delete (stav 90) by
blokoval nový záznam téhož roku; duplicitu živých hlídá validace dokumentu.
Kontrola na zdroji 689089 (01–04/2026): ř. 64 = 260 865,94 / 135 796,54 /
120 202,50 / 143 584,20 vs. podáno 260 864 / 135 796 / 120 203 / 143 583 —
rozdíl jen zaokrouhlení řádků na Kč.
**Issue:** #59 — D13 (komentář „Kontrola DP3 proti podaným tvrzením", upravený 2026-09-08)
**Návaznost:** `economy.vat` M1 (`VatReturnCalculator`, `VatReturnLiveBuilder`,
`vat-reports-cz.jsonc`), `economy.codebooks` (`economy_codebooks_vat_registrations`).
Bez závislosti na importu. Ověření proti podaným tvrzením zdroje 689089 (01–04/2026).

## Cíl

Krácený nárok na odpočet (kódy s `dp3.col = "reduced"`, v ČR `cz-118/119/341/342`)
dnes nová strana správně třídí do kráceného sloupce ř. 40–45, ale bez ř. 52 se do
ř. 63 nedostane → daňová povinnost je o krácený nárok vyšší než podané tvrzení.
Doplnit **koeficient odpočtu** jako obecný (EU) parametr registrace k DPH per
kalendářní rok, ř. 52 = Σ krácený × zálohový koeficient, ř. 63 = 46 + 52 + 53 + 60.

Před implementací **přečti**:

- `modules/economy/vat/src/VatReturnCalculator.php` (hlavičkový komentář, `DEDUCTION_ROWS`,
  výpočet 46/62/63/64/65, `roundRow`), `Reports/VatReturnLiveBuilder.php`,
  `Reports/VatReportSupport.php` (jak se z `ReportRequest` dostane perioda a registrace),
  `VatOutputsMapping.php`, `config/vat-reports-cz.jsonc`, `config/reports.jsonc`
- `modules/economy/vat/docs/README.md` §Instance daňových tvrzení, §Architektura, §Mimo scope
- `modules/economy/codebooks/tables/economy_codebooks_vat_registrations.{jsonc,md}`,
  `src/VatRegistration{Document,sForm,sViewer}.php`, `VatAgendaNavGate.php`
- `modules/economy/vat/tables/economy_vat_report_periods.jsonc` — vzor tabulky modulu
  (`tableId`, `docState`, provisioner `ReportPeriodsProvisioner`)
- `docs/table-definitions.md`, `docs/edit-forms.md`, `docs/reports.md` §7 (ReportResult,
  `computed` řádky, messages)
- `tests/Unit/Module/Economy/Vat/VatReturnCalculatorTest.php` (fixture styl)

## Rozhodnutí (D13, potvrzeno 2026-09-08)

- **D13a Kde parametr žije.** Koeficient patří na **registraci k DPH × kalendářní rok**
  — ne na fiskální období (vypořádací období je podle § 76 ZDPH vždy kalendářní rok,
  firma s hospodářským rokem má dvě fiskální období a jeden koeficient) a ne na DS
  (firma může mít registrací víc, každá má svůj). Je to konstrukce směrnice
  2006/112/ES čl. 173–175 (odpočitatelný podíl, předběžný podíl z minulého roku,
  roční vyrovnání), tedy **obecná pro EU**; národní je jen mapování na řádky formuláře
  (`vat-reports-cz.jsonc`).
- **D13b Dvě hodnoty per rok.** *Zálohový* (během roku; § 76 odst. 6 = vypořádací
  z minulého roku) a *vypořádací* (spočtený na konci roku, vstup do ř. 53). Uložený
  vypořádací roku N je implicitní zálohový roku N+1; explicitní zálohový ho přebije
  (první rok, odhad správce daně). Není-li nic, koeficient **1,00** (= plný nárok —
  stav firmy bez osvobozených plnění; totéž, co podával starý Shipard).
- **D13c Scope.** ř. 52 ano. ř. 53 (vypořádání) a ř. 60 (úprava odpočtu) **ne** —
  model na ně počítá (sloupec `coefficient_settled`), výpočet přijde s ročním
  vypořádáním. Starý Shipard krácený sloupec nikdy nevyplňoval (`VatReturnReport.php:733`),
  takže import nic nepřenáší; default 1,00 reprodukuje podaná tvrzení.
- **D13d Fail loudly.** Koeficient mimo interval ⟨0; 1⟩ je validační chyba; rok bez
  záznamu je legitimní stav (default), ne chyba — report to ale **řekne** ve zprávách
  (`ReportMessage` info: „Koeficient odpočtu 1,00 (default — bez záznamu pro rok N)").

## Scope

### 1. Tabulka `economy_vat_deduction_coefficients` (economy.vat)

| sloupec | typ | popis |
|---|---|---|
| `vat_registration` | int, reference `economy_codebooks_vat_registrations`, required | Registrace |
| `year` | smallint, required | Kalendářní rok |
| `coefficient_provisional` | decimal(5,4) NULL | Zálohový koeficient roku; NULL = odvodit (viz resolver) |
| `coefficient_settled` | decimal(5,4) NULL | Vypořádací koeficient roku; NULL = nevypořádáno |
| `note` | varchar(200) NULL | Poznámka (odkud hodnota je — rozhodnutí FÚ, výpočet) |
| `docState` / `docStateMain` | standard | 10 koncept / 40 v pořádku |

Unique `(vat_registration, year)`. `tableId` další volný v `economy.vat`.
Bez `keepOnReset` (jsou to uživatelská data, ne infrastruktura). Ukládá se
`decimal(5,4)` — ZDPH koeficient zaokrouhluje na dvě desetinná místa **nahoru**
(§ 76 odst. 3: procenta zaokrouhlená na celé procento nahoru); validace vyžaduje
hodnotu ve tvaru `0.xx00` nebo `1.0000`, jinak chyba `coefficient_precision`
(formulář zadává v procentech, ukládá /100).

Dokumentace tabulky `economy_vat_deduction_coefficients.md`.

### 2. `DeductionCoefficientResolver` (economy.vat)

```php
final class DeductionCoefficientResolver {
    /** @return array{value: float, source: 'provisional'|'previous_settled'|'default'} */
    public function provisional(int $registrationId, int $year): array;
}
```

Pořadí: explicitní `coefficient_provisional` roku → `coefficient_settled` roku N−1 →
`1.0000` (`source: default`). Jen záznamy `docState = 40`. Jediná autorita —
report i budoucí ř. 53 přes ni.

### 3. `VatReturnCalculator`

- `calculate(array $docs, float $coefficient = 1.0)`.
- computed **52** = `round(Σ taxReduced(DEDUCTION_ROWS) × coefficient, 2)` ve sloupci
  `taxFull` (`base` 0). Krácený sloupec řádků 40–45 zůstává vykázaný (formulář ho má).
- 63 = 46 + 52 + 53 + 60 (53/60 stále `EMPTY_ROW`, komentář aktualizovat).
- Hlavičkový komentář: krácený nárok už není mimo scope; ř. 53/60 ano.

### 4. `VatReturnLiveBuilder` / `VatReportSupport`

- Z periody (`economy_vat_report_periods`) vzít `vat_registration` a rok
  `date_begin`; `DeductionCoefficientResolver::provisional()`; předat kalkulátoru.
- `ReportMessage` (info) vždy, když v dokladech periody existuje nenulový krácený
  nárok: „Krácený nárok 39,57 × koeficient 1,00 (default) → ř. 52 = 39,57"; při
  `source: default` navíc doporučení „nastavte koeficient roku N v Nastavení DPH".
  Bez kráceného nároku žádná zpráva (nešumět).
- Řádek 52 v `reports.jsonc` / labely: „Krácený odpočet (ř. 40–45 × koeficient)" /
  „Reduced deduction (rows 40–45 × coefficient)".

### 5. UI — Nastavení DPH → Koeficienty odpočtu

- Viewer `DeductionCoefficientsViewer` (`navSection` DPH/nastavení vedle registrací;
  `VatAgendaNavGate` respektovat), řádek: registrace · rok · zálohový · vypořádací
  · poznámka; skupiny per registrace.
- Formulář `DeductionCoefficientsForm`: registrace (lookup, jen 40), rok, zálohový %
  a vypořádací % (numeric 0–100, krok 1; prázdné = NULL), poznámka. Validace D13d.
- V detailu registrace (`VatRegistrationsForm`) jen odkaz/tlačítko „Koeficienty
  odpočtu" — bez vnořeného seznamu (jednodušší, registrace je v `economy.codebooks`
  a nemá na `economy.vat` závislost; odkaz řeší `navGate`/hidden když modul chybí).

### 6. Dokumentace

- `modules/economy/vat/docs/README.md`: nová sekce „Koeficient odpočtu (D13)" —
  model, resolver, ř. 52, co je mimo (53/60), odkaz na směrnici; §Mimo scope upravit.
- `economy_codebooks_vat_registrations.md`: odkaz na koeficienty.
- `docs/vat.md` (existuje-li) / `docs/README.md` index.
- Issue #59: komentář „D13 hotovo" s výsledkem kontroly 04/2026 (ř. 64 = 143 583,xx).

### 7. Testy

- Unit `VatReturnCalculatorTest`: fixture 04/2026 (ř. 40: plný 91 184,06 + krácený
  39,57; ř. 41: 27 573,87; ř. 43: 12 220,14; výstup 274 601,84): koef 1,00 → 52 = 39,57,
  63 = 131 017,64, 64 = 143 584,20 (nezaokrouhlené řádky; zaokrouhlení na Kč pro
  podání je samostatné téma); koef 0,80 → 52 = 31,66; koef bez kráceného → 52 = 0
  a 63 = 46.
- Unit `DeductionCoefficientResolverTest`: explicitní zálohový; loňský vypořádací;
  default; ignorování záznamů mimo stav 40.
- Unit `DeductionCoefficientDocumentTest`: validace intervalu a přesnosti, unique.
- Integration `VatReturnLiveReportTest` (nad `4l3j`): perioda s dokladem `cz-118`
  bez záznamu → zpráva default + ř. 52; se záznamem 0,80 → přepočet.
- Filtry `--filter 'VatReturnCalculator|DeductionCoefficient'`, `timeout_sec: 120`.

## Commit strategie

1. Tabulka + dokument + resolver + unit testy.
2. Kalkulátor ř. 52/63 + builder + zprávy + testy.
3. Viewer + formulář + odkaz z registrace.
4. Dokumentace + komentář do #59.

## Mimo scope

- ř. 53 (roční vypořádání) a ř. 60 (úprava odpočtu).
- Zaokrouhlení řádků DP3 na celé Kč pro XML podání (poznámka v #59).
- Národní varianty formulářů mimo CZ (model je připraven, mapování až s další zemí).

## Hotovo když

- [x] `report-run economy.vat.returnLive --period=<04/2026 zdroje 689089>` dává ř. 52 =
      39,57 a ř. 64 ≈ 143 583 (rozdíl jen zaokrouhlení na Kč) — shoda s podaným
      tvrzením; totéž 01–03/2026 (podáno 260 864 / 135 796 / 120 203).
- [x] Bez záznamu koeficientu report hlásí default; se záznamem 0,80 se ř. 52 změní
      (`tests/Integration/Reports/VatDeductionCoefficientTest.php`).
- [x] Koeficient jde zadat v UI per registrace × rok, mimo ⟨0; 1⟩ nebo s nesprávnou
      přesností se neuloží (smoke přes `/_ui/form/.../save` na 4l3j).
- [x] Stávající testy `economy.vat` procházejí; dokumentace aktualizována.
