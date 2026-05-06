# Tabulka: docs_core_heads

Polymorfní hlavička dokladu. Konkrétní typ určuje sloupec `doc_type`
(enumString) — přes cfgItem `docs.core.docTypes` mapuje na Document subclass
v navazujícím modulu (např. `IssuedInvoiceDocument` v `docs.invoicesOut`).

`doc_type` je **denormalizovaný** ze sloupce `number_series.doc_type` —
nastavuje se v `DocDocument::beforeSave` a má příznak `system: true`.

## Skupiny sloupců

### `identity`

| Sloupec | Typ | Popis |
|---|---|---|
| `doc_type` | enumString(20), system | Typ dokladu, denormalizovaný z řady |
| `number_series` | int → `docs_core_number_series` | Řada, ze které pochází číslo |
| `sequence_number` | int, nullable, system | Pořadové číslo v řadě, NULL pro Koncept |
| `doc_number` | varchar(40), system | Resolvované číslo dokladu (`126A0001`) nebo placeholder `!{id_padded}` u Konceptu |
| `doc_text` | varchar(200), nullable | Volný popisný text |

### `partner`

Partner (`base_persons_persons`), jeho adresa (`base_persons_addresses`),
bankovní účet (`base_persons_bank_accounts`) plus tři volné stringové
sloupce pro ruční přepis čísla účtu / IBAN / BIC.

### `dates`

`issue_date`, `due_date`, `accounting_date`, `vat_duzp`, `vat_dppd`,
`period_from`, `period_to`. Defaults plněné v Form recalculate / Document
beforeSave (Fáze 2).

### `accounting`

System sloupce mapování do účetních období: `fiscal_year`, `fiscal_month`,
`vat_period`. Plus uživatelem volitelná `vat_registration`. Resolvery
přijdou ve Fázi 2.

### `vat`

`vat_mode` (0 bez DPH / 1 ze základu / 2 z ceny celkem),
`vat_calc_source` (0 z hlavičky / 1 z řádků), `vat_place` (tuzemsko /
intracom / zahraničí).

### `currency`

`doc_currency`, `home_currency` (system, z DS configu), `exchange_rate`.

### `rounding`

`total_rounding_mode`, `vat_rounding_mode`.

### `totals` — všechny `system: true`

Sumace plněné v `beforeSave` ve Fázi 2. Doc currency: `total_base`,
`total_vat`, `total_amount`, `total_rounding`. Home currency:
`total_base_dom`, `total_vat_dom`, `total_amount_dom`.

### `payment`

`payment_method`, `bank_account` (náš účet, vazba na
`economy_codebooks_bank_accounts`), `variable_symbol`, `specific_symbol`,
`constant_symbol`.

### `snapshots` — system

JSON dumpy partnera a vlastní firmy, sestavované při Koncept → Potvrzeno
(Fáze 2). Drží stav adresy, DIČ, bankovního účtu, court_registration —
nezávisle na pozdějších změnách v `base_persons_*`.

### `notes`

`notice` (interní), `doc_notice` (na doklad).

## Indexy

- `idx_series_seq` — `(number_series, fiscal_year, sequence_number DESC)`,
  primární přístupová cesta vieweru per řada
- **`unq_series_seq` UNIQUE** — `(number_series, fiscal_year, sequence_number)`,
  pojistka proti duplicitním číslům dokladu. NULL hodnoty UNIQUE neporušují,
  takže víc Konceptů koexistuje
- `idx_doc_state` — `(docStateMain, doc_number DESC)`
- `idx_partner` — `(partner)`
- `idx_accounting_date`, `idx_vat_duzp` — pro reporty
- `ft_doc_text` FULLTEXT — `doc_text`

## Stavový model

`docs.core.docStates` (NE `core.system.docStatesArchive`). Klíčové rozdíly:

- + 20 Potvrzeno (přidělené číslo, ale stále editovatelné)
- + 30 Storno (zachovává číslo, sdílí `mainState=4` s V pořádku)
- − 70 V archívu (u dokladů nadbytečné)

Detaily v `docs/docs-mvp.md` sekce 3.

## Vazby na child tabulky

```
docs_core_rows.doc_head      → docs_core_heads.id  (dataKey: rows)
docs_core_vat_recap.doc_head → docs_core_heads.id  (dataKey: vatRecap)
```

TableGateway sync: bez `id` = INSERT, s `id` = UPDATE, chybějící = DELETE.

## Související

- [docs_core_rows](docs_core_rows.md) — řádky
- [docs_core_vat_recap](docs_core_vat_recap.md) — rekapitulace DPH
- [docs_core_number_series](docs_core_number_series.md) — řada, ze které pochází číslo
- [DocDocument](../src/DocDocument.php) — abstract base
- `docs/docs-mvp.md` sekce 6 — kompletní design hlavičky
