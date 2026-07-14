# Modul `base.registry` — Spisovna

Evidence trvalých dokumentů firmy — smlouvy, pojistky, revizní zprávy,
cenové nabídky, úřední písemnosti. Dokumenty se organizují do **šanonů**
(uživatelská osa) a klasifikují **druhem** (`docKinds`, systémová osa
řídící metadata a expiraci).

> Autoritativní design MVP (fáze, AI cesta, migrace ze starého `wkf.docs`):
> [`docs/registry-mvp.md`](../../../docs/registry-mvp.md). Po dokončení MVP
> se obsah designu přesune sem.

## Tabulky

| Tabulka | tableId | Obsah |
|---|---|---|
| [`base_registry_binders`](tables/base_registry_binders.md) | 427 | Šanony — ploché organizační složky |
| [`base_registry_documents`](tables/base_registry_documents.md) | 428 | Dokumenty Spisovny |

## cfgItemy

- **`base.registry.docKinds`** — řízený slovník druhů dokumentů. Per druh:
  `fields` (klíče v `metadata` JSON = přesné názvy polí AI output schématu),
  `promote` (mapování vybraných polí na promoted sloupce `ref_number` /
  `valid_from` / `valid_to`), `expiration.warnDaysBefore` (sémantika
  expirace nad promoted `valid_to`).
- **`base.registry.sourceKinds`** — zdroj dokumentu (`manual`, `mail`,
  `import`; budoucí `databox`, `scan`).

## Doc states

Obě tabulky používají standardní archivační sadu
`core.system.docStatesArchive` (10 Koncept, 80 V opravě, 40 V pořádku =
Zařazeno, 70 V archívu = ukončená platnost, 90 Koš). Žádný vlastní cfgItem
stavů.

## Navigace

- Viewer **Spisovna** (`base.registry.documents`) — root-level položka
  hlavní navigace (`navSection: "_top"`), hned za Došlou poštou.
- Viewer **Šanony** (`base.registry.binders`) — v Nastavení aplikace,
  sekce Ostatní → Spisovna.

## Reset

Modul nemá `keepOnReset` — šanony i dokumenty jsou migrovaná data ze
starého `wkf.docs`; po `ds-reset` je obnoví re-import (dedupe přes
`metadata.legacyId`).
