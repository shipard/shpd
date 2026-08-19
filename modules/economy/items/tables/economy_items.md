# Tabulka: Položky (economy_items)

Katalog položek pro fakturaci a budoucí skladovou evidenci. Každá
položka patří do jednoho **druhu** (`item_kind`), přebírá z něj svůj
**typ** (`item_type`) a má povinnou **měrnou jednotku** (`unit`).

`tableId = 311`. Stavový model: `core.system.docStatesArchive`.

## Struktura

### Identifikace (identity)

| Sloupec | Typ | Popis |
|---|---|---|
| `code` | varchar(25), NOT NULL, UNIQUE | Kód položky. Pokud uživatel nezadá, vygeneruje se 6 hex znaků (viz níže). |
| `name` | varchar(200), NOT NULL | Český název položky |
| `sku` | varchar(50), nullable | Volitelný SKU kód pro propojení s externími katalogy / e-shopem (indexovaný; používá Exchange resolver). |
| `ean` | varchar(20), nullable | Volitelný EAN / GTIN čárový kód (indexovaný; používá Exchange resolver). |

### Klasifikace (classification)

| Sloupec | Typ | Popis |
|---|---|---|
| `item_kind` | int, NOT NULL, ref → `economy_items_kinds` | Druh položky — určuje `item_type` |
| `item_type` | enumInt, default 3 | Typ položky — denormalizace z `item_kind`. V UI readOnly, plněn serverem. |
| `content_tags` | json, nullable | Obsahové štítky — list klíčů z cfgItem `core.exchange.contentTags`. Používá obsahová eskalace párování (štítek → položka). Serializuje `ItemDocument` (prázdný výběr → NULL). |

### Detaily (details)

| Sloupec | Typ | Popis |
|---|---|---|
| `description` | varchar(200) | Volný popis pro doklady |
| `valid_from` | date | Platnost od |
| `valid_to` | date | Platnost do |

### Cena (pricing)

| Sloupec | Typ | Popis |
|---|---|---|
| `sales_price_no_vat` | numeric(15, 4) | Prodejní cena bez DPH v domácí měně DS |
| `unit` | int, NOT NULL, ref → `core_units` | Měrná jednotka. Default `pcs` (Kus) u nové položky. |

### Původ záznamu (lineage)

| Sloupec | Typ | Popis |
|---|---|---|
| `source_kind` | varchar(40), nullable | Klíč z [economy.items.sourceKinds](../config/sourceKinds.jsonc) — `manual`, `aiExtraction`, `import.oldShipard`, `import.csv`, `import.supplierCatalog` |
| `source_ref` | varchar(60), nullable | Identifikátor ve zdroji — typicky ndx ve starém Shipardu, ID vendor katalogu, řádkové číslo CSV apod. |
| `source_imported_at` | datetime, nullable | Čas posledního importu / synchronizace |

Lineage sloupce vyplňuje `ItemApplier` (modul `core.exchange`) při apply
canonical payloadu — viz [exchange-format-items.md §12](../../../../docs/exchange-format-items.md#12-lineage).
Manuálně pořízené položky přes UI mají `source_kind = NULL` (nebo zachovanou
hodnotu z dřívějšího importu — manuální editace přes UI lineage nepřepisuje).

## Auto-gen kódu

Pokud uživatel ponechá pole `code` prázdné, `ItemDocument::beforeSave`:

1. Až 10× zkusí `bin2hex(random_bytes(3))` — 6 hex znaků; pro každý
   pokus ověří unikátnost přes `SELECT id FROM economy_items WHERE code = …`.
2. Pokud žádný 6-hex kód nebyl unikátní, fallback `bin2hex(random_bytes(4))`
   — 8 hex znaků.
3. V krajním případě se 8-hex kód doplní o 2 další hex znaky (max 10
   znaků; sloupec má délku 25).

Manuálně zadaný kód `code` projde jen, pokud je unikátní napříč všemi
záznamy (`code` má UNIQUE index).

## Denormalizace `item_type`

`item_type` na položce je **odvozená** hodnota z `item_kind`. Slouží
k rychlému filtrování položek bez JOINu na `economy_items_kinds`.

Konzistenci zajišťují dvě místa:

- `ItemDocument::beforeSave` — při každém uložení znovu načte `item_type`
  z `economy_items_kinds` podle `item_kind` a přepíše hodnotu v `data`.
  I kdyby uživatel poslal v requestu jinou hodnotu (sloupec není
  `system: true`), server ji přepíše.
- `ItemsForm::recalculate` — při změně `item_kind` v UI rovnou doplní
  novou hodnotu `item_type` do formuláře, takže uživatel okamžitě
  vidí, jaký typ položky bude mít.

V UI je pole `item_type` vždy readOnly.

## Indexy

| Index | Typ | Sloupce |
|---|---|---|
| `unq_code` | unique | `code` |
| `idx_item_kind` | index | `item_kind` |
| `idx_item_type` | index | `item_type` |
| `idx_unit` | index | `unit` |
| `idx_sku` | index | `sku` |
| `idx_ean` | index | `ean` |
| `idx_doc_state` | index | `docStateMain` ASC, `name` ASC |
| `ft_name` | fulltext | `name`, `description` |

## Návaznosti

| Tabulka | Vazba | Popis |
|---|---|---|
| [economy_items_kinds](economy_items_kinds.md) | `items.item_kind → kinds.id` | Druh položky |
| [core_units](../../../core/units/tables/core_units.md) | `items.unit → units.id` | Měrná jednotka |
