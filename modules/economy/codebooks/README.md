# Modul: Číselníky (economy.codebooks)

Modul sdružuje ekonomické číselníky používané dokladovým a skladovým
systémem. V této fázi jsou klíčovou náplní **fiskální období** —
roky a měsíce, na které se mapují účetní data dokladů.

Tabulky `economy_codebooks_warehouses` a `economy_codebooks_cost_centers`
jsou v této fázi placeholdery (schémata existují, UI a Document logika
přijde s dokladovým systémem).

## Závislosti

- `core.system`
- `world.base` — připraveno pro budoucí currency picker (zatím
  `currency` zůstává prostý `varchar(3)` text bez lookupu)

## Tabulky

| Tabulka | Popis |
|---|---|
| [economy_codebooks_warehouses](tables/economy_codebooks_warehouses.jsonc) | Sklady (placeholder, fáze 1 neřeší) |
| [economy_codebooks_cost_centers](tables/economy_codebooks_cost_centers.jsonc) | Střediska (placeholder, fáze 1 neřeší) |
| [economy_codebooks_fiscal_years](tables/economy_codebooks_fiscal_years.md) | Fiskální (účetní) roky |
| [economy_codebooks_fiscal_months](tables/economy_codebooks_fiscal_months.md) | Fiskální měsíce navázané na rok |

## Zdrojové soubory

| Soubor | Popis |
|---|---|
| [FiscalYearDocument.php](src/FiscalYearDocument.php) | Validace fiskálního roku (povinná pole, rozsah dat, regex měny) |
| [FiscalMonthDocument.php](src/FiscalMonthDocument.php) | Validace měsíce + denormalizace `calendar_year`/`calendar_month` |
| [FiscalYearsForm.php](src/FiscalYearsForm.php) | Formulář roku se sub-tabulkou Měsíce |
| [FiscalYearsViewer.php](src/FiscalYearsViewer.php) | Viewer roků s tabem seznamu měsíců |
| [FiscalYearsProvisioner.php](src/FiscalYearsProvisioner.php) | Idempotentní seed aktuálního a následujícího roku |

## Konfigurace

| Klíč | Soubor | Popis |
|---|---|---|
| `economy.codebooks.fiscalPeriodTypes` | [config/fiscalPeriodTypes.jsonc](config/fiscalPeriodTypes.jsonc) | Typ měsíce — Otevření (0) / Běžné (1) / Uzavření (2) |
| `economy.codebooks.fiscalConfig` | [config/fiscalConfig.jsonc](config/fiscalConfig.jsonc) | `yearStartMonth` — výchozí 1 (leden); per-DS override zatím není |

## Auto-generování fiskálních období

Při každém běhu `bin/shpd-ds ds-upgrade` se spustí
`FiscalYearsProvisioner::provision()` s touto logikou:

1. Načte `yearStartMonth` z cfgItem `economy.codebooks.fiscalConfig`
   (default 1).
2. Spočte rozsah aktuálního fiskálního roku podle dnešního data.
3. **Existuje-li v DB rok pokrývající dnešek?**
   - **Ne** → vygeneruje aktuální rok + 14 měsíců. Hotovo.
   - **Ano** → spočte rozsah následujícího roku; pokud neexistuje,
     vygeneruje ho.

Idempotence: lookup před insertem podle vypočítaného `date_begin`.
Druhý běh `ds-upgrade` na DS s aktuálním rokem typicky vygeneruje
rok následující; třetí běh je no-op (`existing: 2`).

Generovaný rok dostává `docState=40, docStateMain=3` (V pořádku).
Manuálně přes UI vznikající rok je `Koncept` (10) — uživatel ho
přepne tlačítkem.

Pro názvy roků a prefixy:

- `yearStartMonth=1`: `name = "YYYY"`, `doc_number_prefix` = poslední
  dvě číslice roku (např. `"26"`)
- jinak: `name = "YYYY-YYYY"` (rok začátku—rok konce, např.
  `"2026-2027"`), prefix = poslední dvě číslice **konce** (`"27"`)

Per-DS override `yearStartMonth` zatím není implementovaný — když
bude potřeba, doplní se mechanismus per-DS cfgItem override.

## Typy fiskálních měsíců

Každý fiskální rok obsahuje právě **14 měsíců**:

| period_type | Význam | Rozsah |
|---|---|---|
| 0 | Otevření | jednodenní = `date_begin == date_end == year.date_begin` |
| 1 | Běžné období | každý kalendářní měsíc roku (12×) |
| 2 | Uzavření | jednodenní = `date_begin == date_end == year.date_end` |

Otevření a Uzavření slouží počátečním a závěrkovým účetním operacím
(počáteční stavy, závěrkové opravy) — fakticky se chovají jako
samostatné jednodenní účetní období na hraně roku, kam doklady
„počátku" a „konce" patří mimo běžné měsíční rytmy.

`calendar_year` a `calendar_month` jsou denormalizované sloupce, do
kterých se v `FiscalMonthDocument::beforeSave` automaticky vyplňuje
rok a měsíc z `date_begin`. Ve formu jsou readOnly.

## Dělení dokladů

Až se začne dělat dokladový systém, každý doklad podle účetního data
„spadne" do konkrétního fiskálního roku a měsíce — proto jsou tyto
tabulky posledním číselníkem před spuštěním dokladové fáze. Samotné
mapování doklad → fiskální období a validace `locked = true` přijde
s dokladovým modulem.
