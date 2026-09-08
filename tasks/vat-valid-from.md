# Task: `validFrom` per typ výstupu — žádné anachronické koncepty KH před 2016 — #58

**Stav:** hotovo
**Issue:** #58 (návaznost #55 D9 — on-demand koncepty instancí tvrzení)
**Návaznost:** `economy.vat` (`VatPeriodAssigner`, `ReportPeriodsProvisioner`,
`VatPeriodRecalculator`, `vat-reports-cz.jsonc`). Bez změny DB schématu, bez
změny staré strany importu. Udělat **před Fází 2 (Podání)** — picker podání
nesmí nabízet KH z roku 2013.

## Cíl

On-demand zakládání instancí (D9) nezná zákonné počátky výstupů: doklad
z roku 2013 s kódem mapovaným do KH založí koncept `cs` 2013, ačkoli kontrolní
hlášení existuje od 1. 1. 2016. Ověřeno po reimportu 2026-09-08: dev DS
`btpg-p` 38 konceptů `cs` od 2013, `e8w1-i` 50 od 2012 (celé roky 2012–2015
= šum v pickeru, alertech a právní nesmysl). Přiznání a SH omezení nemají
(stará data před 2012 jsou legitimní).

Před implementací **přečti**:

- issue #58; `modules/economy/vat/docs/README.md` §Instance daňových tvrzení
- `modules/economy/vat/src/VatPeriodAssigner.php` (`compute`, `membership`,
  `effectiveDate`), `ReportPeriodsProvisioner.php` (`covering`, `create`,
  `clampRange`, `ensureForRegistration`), `VatPeriodRecalculator.php`
  (pravidlo „přepíše se jen nekonzistentní ukazatel"), `VatOutputsMapping.php`,
  `DocsHeadsVatPeriodHandler.php` (kde vzniká provisioner + mapping)
- `config/vat-reports-cz.jsonc` — dnešní sekce `vatOutputs`, `dp3Rows`
- testy `tests/Unit/Module/Economy/Vat/{VatPeriodAssignerTest,
  ReportPeriodsProvisionerTest, VatOutputsMappingTest,
  VatReportsMappingCompletenessTest}.php`

## Rozhodnutí

- **V1 — kde žije `validFrom`:** v per-country mapping configu
  `vat-reports-cz.jsonc` jako nová sekce vedle `vatOutputs` / `dp3Rows`:
  ```jsonc
  "reportTypes": {
      "cs": { "validFrom": "2016-01-01" }   // zavedení kontrolního hlášení (§ 101c ZDPH)
      // return, rs: bez omezení
  }
  ```
  Je to národní pravidlo výkaznictví (KH je český nástroj), ne vlastnost
  typu instance (`config/reportTypes.jsonc` zůstává jen názvosloví).
- **V2 — assigner rozhoduje o členství, ne provisioner:** clamped efektivní
  datum `< validFrom` ⇒ `cs_period` NULL a `covering()` se pro `cs` **nevolá**
  (žádný koncept, žádný alert). Provisioner dostane `validFrom` jen jako
  pojistku pro generátor (V3).
- **V3 — generátor nikdy nezačíná před `validFrom`:** `create()` pro datum
  `< validFrom` vrací `null`; kandidát s `begin < validFrom` se ořízne
  (`clampRange`, stejně jako platnost registrace). Cron/seed dnes generují
  jen dnešek/zítřek, ale pravidlo platí obecně.
- **V4 — přepočet:** `VatPeriodRecalculator` považuje ukazatel na instanci
  typu s `validFrom`, jejíž doklad má efektivní datum `< validFrom`, za
  **nekonzistentní** → nastaví NULL. Bez toho by po nasazení anachronické
  instance dál držely doklady a guard by bránil jejich zrušení. Reimport
  (ds-reset) problém řeší úplně; přepočet je pro DS, které se nereimportují.
- **V5 — import runner beze změny** (přenáší jen skutečné staré reporty;
  před 2016 žádné `cs` neexistují). Stará strana se nemění.

## Scope

### 1. Config + resolver

- `config/vat-reports-cz.jsonc`: sekce `reportTypes` (V1) s komentářem.
- `VatOutputsMapping::validFrom(string $type): ?string` — ISO datum nebo
  null; neznámý typ (mimo `return`/`cs`/`rs`) v sekci = `InvalidArgumentException`
  v konstruktoru (fail loudly na překlep v configu).

### 2. Přiřazení (`VatPeriodAssigner`)

- `compute()`: po výpočtu `$effective` — pro každý typ `cs`/`rs` s členstvím:
  je-li `$effective < validFrom(type)`, ukazatel zůstane NULL a lookup se
  nevolá. Mapping null (chybí kompilovaný config) → jako dnes (členství false,
  žádné omezení).
- Pomocná `isBeforeValidFrom(string $type, string $date): bool` (public,
  použije ji i recalculator).

### 3. Provisioner (`ReportPeriodsProvisioner`)

- Konstruktor: `array $validFromByType = []` (`['cs' => '2016-01-01']`);
  `DocsHeadsVatPeriodHandler` ho naplní z mapping configu; cron/seed/upgrade
  cesty (`VatPeriodsEnsureCommand`, `DsUpgradeCommand`,
  `VatRegistrationSeedHandler`) ho předají, mají-li config k dispozici —
  jinak prázdné (generují jen dnešek/zítřek, omezení je bezpředmětné;
  zdokumentuj v docblocku).
- `create()`: `$date < validFrom` → `null`; `clampRange` dostane
  `max(valid_from registrace, validFrom typu)` jako dolní mez.

### 4. Přepočet (`VatPeriodRecalculator`) — V4

- Při hodnocení konzistence ukazatele `cs_period`/`rs_period`: instance sice
  datum obsahuje, ale `isBeforeValidFrom` ⇒ nekonzistentní ⇒ NULL.

### 5. Testy

- `VatOutputsMappingTest`: parse `reportTypes.cs.validFrom`; neznámý typ →
  výjimka; chybějící sekce → `validFrom()` null pro vše.
- `VatPeriodAssignerTest`: doklad 2015-12-31 s kódem `kh ≠ null` →
  `cs_period` NULL, lookup pro `cs` nevolán (fake lookup počítá volání),
  `vat_period` přiřazen; doklad 2016-01-01 → `cs_period` přiřazen;
  `rs` bez omezení i v 2015.
- `ReportPeriodsProvisionerTest`: `create('cs', '2015-06-15')` → null, nic
  v DB; kandidát čtvrtletní `Q4/2015`… netýká se (KH je měsíční); kandidát
  s `begin < validFrom` oříznut (`clampRange` unit).
- Recalculator: doklad 2014 ukazující na `cs` instanci 2014 → po přepočtu
  NULL; doklad 2017 na `cs` 2017 → beze změny.
- `VatReportsMappingCompletenessTest`: klíče `reportTypes` ⊆ `{return,cs,rs}`.

### 6. Dokumentace

- `modules/economy/vat/docs/README.md`: odstavec `validFrom` v §Instance
  (V1–V4), zmínka v §Mapovací konfigurace; komentář v configu.
- `tasks/README.md` a `docs/README.md` neaktualizuj (David).

## Mimo scope

- Konec platnosti výstupu (`validTo`) — bez použití, nepřidávat „pro jistotu".
- Ruční úklid konceptů na dev DS (řeší reimport, případně V4 přepočet).
- Změny staré strany (task 30 runner).

## Commity

1. Config `reportTypes` + `VatOutputsMapping::validFrom` + testy.
2. Assigner + provisioner + handler wiring + testy.
3. Recalculator V4 + test.
4. Dokumentace.

## Hotovo když

- [x] Testy zelené (mapping, assigner, provisioner, recalculator, completeness)
      — 2026-09-08; recalculator kryje integrační
      `tests/Integration/Reports/VatReportPeriodsValidFromTest.php`.
- [x] `ds-upgrade` na dev DS `4l3j` projde (rekompilace cfgItem) — 2026-09-08.
- [x] Uložení dokladu s DUZP 2015 a kódem `cz-120` na dev DS: `cs_period`
      NULL, žádný nový koncept, žádný alert `draft_report_periods` — ověřeno
      integračním testem (on-demand cesta assigner + provisioner) na `4l3j`.
- [ ] Po reimportu 689089 (`btpg-p`): `SELECT COUNT(*) FROM
      economy_vat_report_periods WHERE report_type='cs' AND date_begin <
      '2016-01-01'` = 0; počty `cs` = importované reporty + koncepty jen pro
      nepodaná období od 2016.
