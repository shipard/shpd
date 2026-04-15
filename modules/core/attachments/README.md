# core.attachments — Přílohy dokumentů

Modul pro správu příloh dokumentů. Umožňuje připojit libovolné soubory (PDF, obrázky, office dokumenty atd.) k libovolnému záznamu v systému.

## Funkce

- Upload souborů přes multipart API
- Soft-delete s možností obnovení
- Generování náhledů (thumbnails) pro PDF, SVG a obrázky
- Detekce duplicit přes SHA-256 checksum
- Extrakce metadat (rozměry obrázků, počet stran PDF)
- Řazení příloh (manuální pořadí + abeceda)

## Tabulky

| Tabulka | tableId | Popis |
|---------|---------|-------|
| `core_attachments_files` | 110 | Přílohy dokumentů |

## Závislosti

- `core.system` — reference na `core_system_users` (sloupec `created_by`)

## Souborová struktura

```
modules/core/attachments/
├── module.jsonc
├── README.md
├── tables/
│   ├── core_attachments_files.jsonc
│   └── core_attachments_files.md
└── src/
    └── AttachmentDocument.php
```

## Úložiště souborů

Soubory se ukládají v rámci zdroje dat:

```
data-sources/{ds-id}/
├── att/                          # Přílohy
│   └── {YYYY}/{MM}/{DD}/        # Stromová struktura podle data
│       └── {table_name}/        # Podadresář podle cílové tabulky
│           └── {file_name}      # Soubor s 5-char hash suffixem
└── cache/
    └── thumbnails/              # Cache generovaných náhledů
```

## API

Viz `docs/attachments.md` pro kompletní API dokumentaci.
