# Tabulka: Druhy položek (economy_items_kinds)

Číselník druhů položek. Druh slouží jako uživatelsky pojmenovatelný
"kbelík" — např. *Konzultace IT*, *Materiál*, *Energie* — a každý druh
patří do právě jednoho systémového **typu** (`item_type`).

`tableId = 312`. Stavový model: `core.system.docStatesArchive`.

## Struktura

| Sloupec | Typ | Popis |
|---|---|---|
| `name` | varchar(100), NOT NULL | Název druhu (např. "Konzultace IT") |
| `item_type` | enumInt, default 3 | Typ — viz [`economy.items.itemTypes`](../config/itemTypes.jsonc): 0 Služba, 1 Zásoba, 2 Účetní, 3 Ostatní |
| `valid_from` | date | Platnost od |
| `valid_to` | date | Platnost do |
| `system_code` | varchar(25), UNIQUE | NULL = uživatelský druh; NOT NULL = systémový (provisioner) |

## Pravidlo: změna `item_type`

`item_type` u druhu nelze změnit, pokud existuje **alespoň jedna
položka** s tímto druhem. `ItemKindDocument::validate` ověřuje:

```sql
SELECT COUNT(*) AS n FROM economy_items WHERE item_kind = :id
```

Při pokusu o změnu na použitém druhu vrátí validátor chybu s kódem
`in_use` a HTTP 422.

V UI je pro **existující záznam** pole `item_type` vždy readOnly
(jednodušší UX); validace v Document zachytí i případ, kdy by někdo
omezení obešel přímým API voláním.

## Systémové druhy

Provisioner při `ds-upgrade` zajistí 4 systémové druhy — jeden per
`item_type`:

| `system_code` | Název (cs) | `item_type` |
|---|---|---|
| `service`    | Služba         | 0 (Služba) |
| `stock`      | Zásoba         | 1 (Zásoba) |
| `accounting` | Účetní položka | 2 (Účetní) |
| `other`      | Ostatní        | 3 (Ostatní) |

Systémové druhy slouží jako fallback — uživatel může vytvořit vlastní
druhy, ale ani po smazání systémového druhu se v UI neztratí cesta
k položkám daného typu.

## Indexy

| Index | Typ | Sloupce |
|---|---|---|
| `unq_system_code` | unique | `system_code` |
| `idx_item_type` | index | `item_type` |
| `idx_doc_state` | index | `docStateMain` ASC, `name` ASC |
| `ft_name` | fulltext | `name` |

## Návaznosti

| Tabulka | Vazba | Popis |
|---|---|---|
| [economy_items](economy_items.md) | `items.item_kind → kinds.id` | Položky daného druhu |
