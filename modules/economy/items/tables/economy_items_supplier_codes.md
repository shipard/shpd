# Tabulka: Dodavatelské kódy položek (economy_items_supplier_codes)

Per-partner mapování mezi naší položkou (`economy_items`) a kódem, pod
kterým ji uvádí konkrétní dodavatel na svých dokladech. Tabulku spravuje
**výhradně Exchange apply pipeline** — `core.exchange` modul při uložení
dokladu od dodavatele zaznamená mapping pro každý řádek, jehož uživatel
ve `_resolve.rows[].item.userAction` rozhodl `useExisting:<id>` a kde
canonical obsahuje `supplierCode`.

Příště se stejný supplierCode od stejného partnera napaří automaticky
v `ItemResolver` kroku 2 — uživatel už nemusí rozhodovat.

`tableId = 407`. Tabulka je `hideFromNavigation: true`, není v sidebaru
ani v Nastavení.

## Struktura

| Sloupec | Typ | Popis |
|---|---|---|
| `person` | int, NOT NULL, ref → `base_persons_persons` | Dodavatel |
| `item` | int, NOT NULL, ref → `economy_items` | Naše položka |
| `supplier_code` | varchar(50), NOT NULL | Kód, pod kterým dodavatel položku uvádí (např. `KONZ-001`) |
| `supplier_name` | varchar(200), nullable | Textový název v dokladu pro audit / debug — neslouží k matchování |
| `created` | datetime, NOT NULL | Čas zaznamenání mappingu |

## Indexy

| Index | Typ | Sloupce |
|---|---|---|
| `unq_person_supplier_code` | unique | `person`, `supplier_code` |
| `idx_item` | index | `item` |

Unique `(person, supplier_code)` je klíčový pro `INSERT IGNORE` v Applieru
— jeden dodavatel může mít pro jeden kód jen jednu naši položku.

## Návaznosti

| Tabulka | Vazba | Popis |
|---|---|---|
| [base_persons_persons](../../../base/persons/tables/base_persons_persons.md) | `supplier_codes.person → persons.id` | Dodavatel |
| [economy_items](economy_items.md) | `supplier_codes.item → items.id` | Položka |
