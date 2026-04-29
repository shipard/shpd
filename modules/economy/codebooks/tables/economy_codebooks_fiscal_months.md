# Tabulka: economy_codebooks_fiscal_months

Měsíc fiskálního roku. Bez vlastních `docStates` — lifecycle dědí
přes rodičovský fiskální rok. Každý rok má právě 14 měsíců
(1× Otevření + 12× Běžné + 1× Uzavření).

## Sloupce

| Sloupec | Typ | Popis |
|---|---|---|
| `fiscal_year` | int, reference `economy_codebooks_fiscal_years` | Vlastnický fiskální rok |
| `date_begin` | date | Začátek období |
| `date_end` | date | Konec období |
| `period_type` | enumInt default 1, cfgItem `economy.codebooks.fiscalPeriodTypes` | 0=Otevření, 1=Běžné, 2=Uzavření |
| `calendar_year` | int | Denormalizováno z `date_begin` v `beforeSave` |
| `calendar_month` | smallint | Denormalizováno z `date_begin` v `beforeSave`, 1–12 |

## Indexy

- `idx_fiscal_year` na `fiscal_year, date_begin`
- `idx_dates` na `date_begin, date_end` — připravený lookup pro
  budoucí mapování doklad → fiskální měsíc

## Denormalizace `calendar_year`/`calendar_month`

`FiscalMonthDocument::beforeSave` vždy přepíše `calendar_year` a
`calendar_month` hodnotami odvozenými z `date_begin` (formát
`YYYY-MM-DD`). Důvody:

- Sloupce slouží jako rychlý filtr/index pro dotazy typu „doklady
  za prosinec 2026" bez nutnosti DATE_FORMAT funkcí v WHERE.
- Při manuální editaci `date_begin` přes sub-form se hodnoty
  automaticky aktualizují — uživatel je nemusí psát.

Ve formuláři jsou pole `readOnly` jen pro orientaci; hodnota se
doplní po uložení.

## Otevření a Uzavření jako jednodenní

Period typy 0 (Otevření) a 2 (Uzavření) mají vždy
`date_begin == date_end`:

- Otevření = `year.date_begin` (první den roku)
- Uzavření = `year.date_end` (poslední den roku)

Slouží počátečním stavům a závěrkovým operacím — doklady, které
patří „před začátek" nebo „po skončení" pravidelného účetního
rytmu, do nich logicky spadnou.

## Související

- [economy_codebooks_fiscal_years](economy_codebooks_fiscal_years.md) — rodičovský rok
- [FiscalMonthDocument](../src/FiscalMonthDocument.php) — validace + beforeSave
- [forms/economy_codebooks_fiscal_months.jsonc](../forms/economy_codebooks_fiscal_months.jsonc) — sub-form pro editační formulář roku
