# Tabulka: economy_codebooks_fiscal_years

Fiskální (účetní) rok se stavy dokumentů (`core.system.docStatesArchive`).
Záznamy vznikají buď automaticky z `FiscalYearsProvisioner` při
`ds-upgrade`, nebo ručně přes UI.

## Sloupce

### Skupina `identity`

| Sloupec | Typ | Popis |
|---|---|---|
| `name` | varchar(20), UNIQUE | Lidský název roku — `"2026"` při `yearStartMonth=1`, `"2026-2027"` jinak |
| `doc_number_prefix` | varchar(10) | Prefix pro číselné řady dokladů (např. `"26"` či `"27"`); použije ho dokladový systém |

### Skupina `period`

| Sloupec | Typ | Popis |
|---|---|---|
| `date_begin` | date | Začátek fiskálního roku |
| `date_end` | date | Konec fiskálního roku |
| `currency` | varchar(3) default `'czk'` | ISO 4217 lowercase. Sloupec je oddělený od ostatních číselníků měny, protože roky řeší přechod (např. CZK → EUR) per-rok |
| `locked` | boolean default 0 | `true` = doklady spadající do tohoto období nelze editovat. Validace přijde s dokladovým systémem |

### Systémové (bez skupiny)

| Sloupec | Typ | Popis |
|---|---|---|
| `docState` | tinyint default 10 | Stav dokumentu (Koncept 10, V pořádku 40, …) |
| `docStateMain` | tinyint default 1 | Sortovací sloupec stavů |

## Indexy

- `unq_name` UNIQUE na `name`
- `idx_dates` na `date_begin, date_end` — připravený lookup pro
  budoucí mapování doklad → fiskální rok podle účetního data
- `idx_doc_state` na `docStateMain ASC, date_begin DESC` — viewer
  řadí aktivní roky nahoře, novější dříve

## Pravidla

- Období může být i jiné než kalendářní rok (pokud `yearStartMonth ≠ 1`,
  fiskální rok začíná v jiný měsíc a `name` je tvaru `"YYYY-YYYY"`).
- `currency` je lowercase regex `^[a-z]{3}$` — v UI zatím prostý
  text input, picker přijde později.
- `locked` ručně přepíná uživatel; provisioner ho nikdy nenastavuje
  na `true`.
- Provisioner generuje rok přímo jako `V pořádku` (`docState=40,
  docStateMain=3`); manuálně přes UI vznikají roky jako
  `Koncept` (10) díky `CrudController::initDocState`.

## Související

- [economy_codebooks_fiscal_months](economy_codebooks_fiscal_months.md) — měsíce navázané na rok přes `fiscal_year`
- [FiscalYearDocument](../src/FiscalYearDocument.php) — validace
- [FiscalYearsProvisioner](../src/FiscalYearsProvisioner.php) — auto-seed
