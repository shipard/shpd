# Tabulka: Šanony (base_registry_binders)

Uživatelské organizační složky Spisovny (Pojištění, Auta, BOZP…). Ploché —
žádná hierarchie (design D3). Dokument na šanon odkazuje sloupcem `binder`
v [`base_registry_documents`](base_registry_documents.md); `binder = NULL`
je legitimní stav („Nezařazené").

Unikátnost názvu mezi živými šanony (`docState != 90`) vynucuje
`BinderDocument::validate` — ne DB unique index, kvůli koši.

## Struktura

| Sloupec | Typ | Popis |
|---|---|---|
| `id` | INT PK | |
| `name` | VARCHAR(100) NOT NULL | Název — unikátní mezi živými záznamy (aplikačně) |
| `icon` | VARCHAR(30) NULL | Klíč ikony z frontend `iconMap` |
| `order_pos` | SMALLINT default 0 | Pořadí v UI (spodní taby vieweru Spisovny) |
| `notice` | VARCHAR(250) NULL | Poznámka |
| `docState` / `docStateMain` | TINYINT | `core.system.docStatesArchive` |
| `created` | DATETIME NOT NULL | Plní `BinderDocument::beforeSave` |

## Indexy

| Index | Typ | Sloupce | Poznámka |
|---|---|---|---|
| `idx_name` | index | docStateMain, order_pos, name | Řazení živých šanonů pro taby vieweru |

## Návaznosti

| Tabulka | Vazba | Popis |
|---|---|---|
| [`base_registry_documents`](base_registry_documents.md) | `documents.binder → binders.id` | Dokumenty zařazené v šanonu |

## Mazání a reset

Bez `keepOnReset` — šanony jsou migrovaná data ze starého `wkf.docs`
(`wkf_docs_folders`, zploštění hierarchie); po `ds-reset` je obnoví
re-import. Mazání jen do koše (docState 90).
