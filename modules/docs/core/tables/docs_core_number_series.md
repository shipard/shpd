# Tabulka: docs_core_number_series

Číselná řada dokladů. Drží konfiguraci pro generování čísla dokladu
určitého typu. Jeden typ může mít víc řad (FVB tuzemsko / FVB EUR / …);
řada je **vázaná pevně na jeden typ dokladu**.

Stavy: standardní `core.system.docStatesArchive` (Koncept → V pořádku
→ V archívu / Smazáno) — žádný Potvrzeno / Storno.

## Sloupce

### `identity`

| Sloupec | Typ | Popis |
|---|---|---|
| `doc_type` | enumString(20) → `docs.core.docTypes` | Typ dokladu (`invno`, `invni`, …) |
| `name` | varchar(100) | Lidský název řady |
| `notice` | varchar(250), nullable | Volná poznámka |

### `numbering`

| Sloupec | Typ | Popis |
|---|---|---|
| `doc_number_code` | varchar(10), nullable | Vkládá se do `%C` placeholderu vzorce |
| `doc_number_pattern` | varchar(50) | Vzorec čísla dokladu — viz placeholdery níže |
| `reset_scope` | enumString(15) → `docs.core.resetScopes` default `fiscal_year` | `none` (průběžný counter) / `fiscal_year` (restart každý rok) |

### `validity`

`valid_from`, `valid_to` — volitelné období platnosti řady.

### Systémové

`docState`, `docStateMain` (default 10/1).

## Vzorec čísla dokladu

| Placeholder | Zdroj | Příklad |
|---|---|---|
| `%D` | `doc_id_code` z cfgItem `docs.core.docTypes` | `1` (FVB), `2` (FPB) |
| `%C` | `doc_number_code` na řadě | `A`, `EUR` |
| `%y` | Rok 2-místně z `fiscal_years.doc_number_prefix` | `26` |
| `%Y` | Rok 4-místně | `2026` |
| `%3..%6` | `sequence_number` doplněný nulami | `0001`, `00001` |

Default vzorec pro nový typ je `%D%y%C%4` (přidělován provisionerem
z `docs.core.docTypes.{type}.doc_number_pattern_default`).

Resolver vzorce přijde ve Fázi 2 (`assignDocumentNumber`).

## Validace

Implementováno v `NumberSeriesDocument::validate`:

- `name`, `doc_type`, `doc_number_pattern` jsou povinné
- Pokud vzorec obsahuje `%C`, `doc_number_code` je povinný
- Vzorec smí obsahovat jen známé placeholdery (`D`, `C`, `y`, `Y`, `3`, `4`, `5`, `6`)
- `reset_scope` je `none` nebo `fiscal_year`
- `valid_from <= valid_to`

## Indexy

- `idx_doc_type` — `(doc_type)`, lookup per typ
- `idx_doc_state` — `(docStateMain ASC, name ASC)`, viewer řazení

## Provisioner

`NumberSeriesProvisioner` (volaný z `ds-upgrade`) idempotentně zajistí, že
pro každý typ dokladu z cfgItem `docs.core.docTypes` existuje aspoň
jedna řada (kromě `Smazáno`, docState=90). Default je vytvořena rovnou
jako `V pořádku` (40/3) s patternem z `doc_number_pattern_default`.

Uživatel si může výchozí řadu zarchivovat a založit vlastní; provisioner
pak nezasáhne.

## Související

- [docs_core_heads](docs_core_heads.md) — odkazuje sem přes `number_series`
- [docs_core_number_counters](docs_core_number_counters.md) — atomický counter per řadu+rok
- [NumberSeriesDocument](../src/NumberSeriesDocument.php), [NumberSeriesForm](../src/NumberSeriesForm.php), [NumberSeriesViewer](../src/NumberSeriesViewer.php), [NumberSeriesProvisioner](../src/NumberSeriesProvisioner.php)
- `docs/docs-mvp.md` sekce 5 — kompletní design číselných řad
