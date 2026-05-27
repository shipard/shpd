# Modul: Položky (economy.items)

Modul spravuje katalog položek používaných v dokladovém a skladovém
systému — zboží, služby, účetní položky a ostatní. Položky jsou
klasifikovány **druhem** (uživatelsky definovatelný číselník) a
**typem** (systémový enum: služba / zásoba / účetní / ostatní).

Modul je úmyslně držen úzký pro Fázi 1: nezahrnuje skladovou evidenci,
ceníkový mechanismus, sazby DPH ani vícenásobné měny — to vše dorazí
později spolu s VAT modulem a `economy.docs`.

## Závislosti

- `core.system`
- `core.units` — položka odkazuje na měrnou jednotku

## Tabulky

| Tabulka | Popis |
|---|---|
| [economy_items_kinds](tables/economy_items_kinds.md) | Druhy položek (Konzultace IT, Materiál, …) |
| [economy_items](tables/economy_items.md) | Hlavní katalog položek |

## Zdrojové soubory

| Soubor | Popis |
|---|---|
| [ItemKindDocument.php](src/ItemKindDocument.php) | Validace druhu — chrání před změnou `item_type` u použitého druhu |
| [ItemKindsForm.php](src/ItemKindsForm.php) | Formulář pro druh položky |
| [ItemKindsViewer.php](src/ItemKindsViewer.php) | Viewer druhů včetně tabu se seznamem položek |
| [ItemKindsProvisioner.php](src/ItemKindsProvisioner.php) | Idempotentní seed 4 systémových druhů |
| [ItemDocument.php](src/ItemDocument.php) | Validace položky + auto-gen kódu + denormalizace `item_type` |
| [ItemsForm.php](src/ItemsForm.php) | Formulář pro položku, recalculate na změnu druhu |
| [ItemsViewer.php](src/ItemsViewer.php) | Viewer položek s JOIN na druh a jednotku |

## Konfigurace

| Klíč | Soubor | Popis |
|---|---|---|
| `economy.items.itemTypes` | [config/itemTypes.jsonc](config/itemTypes.jsonc) | Typ položky — služba / zásoba / účetní / ostatní |
| `economy.items.sourceKinds` | [config/sourceKinds.jsonc](config/sourceKinds.jsonc) | Klíče pro sloupec `source_kind` v `economy_items` — `manual`, `aiExtraction`, `import.oldShipard`, `import.csv`, `import.supplierCatalog` |

## Seedovaná data

Provisioner při `ds-upgrade` naplní **4 systémové druhy**, jeden per
`item_type`, podle [config/itemKindsSeed.jsonc](config/itemKindsSeed.jsonc):
`service`, `stock`, `accounting`, `other`. Tytéž pravidla idempotence
jako u [`core.units`](../../core/units/README.md): respektuje, pokud
uživatel záznam zarchivoval.

## Vazby

- `economy_items.item_kind` → `economy_items_kinds.id` (povinné)
- `economy_items.item_type` — denormalizace z `item_kind` (server-side
  v `beforeSave` + recalculate při změně druhu ve formuláři)
- `economy_items.unit` → `core_units.id` (povinné, default `pcs` u nové
  položky)

## Cena bez DPH (Fáze 1)

Položka má pouze sloupec `sales_price_no_vat`. Cena s DPH a sazba DPH
přijdou s VAT modulem; do té doby se v UI zobrazuje cena natvrdo
v Kč jako domácí měně DS. Měna položky není sloupec — bude řešena
ceníkovým mechanismem v budoucnu.
