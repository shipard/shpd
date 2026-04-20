# Dokumentace

Technické specifikace projektu Shipard.

| Dokument | Obsah |
|----------|-------|
| [architecture.md](architecture.md) | Mapa tříd, vrstvy, závislosti, tok dat — jak komponenty spolupracují |
| [modules.md](modules.md) | Modulový systém — struktura, závislosti, JSONC formát, i18n, kompilace konfigurace, CLI příkaz `ds-upgrade` |
| [table-definitions.md](table-definitions.md) | Formát definice databázových tabulek — datové typy, sloupce, indexy, extensions, validace, bezpečné změny |
| [document-system.md](document-system.md) | Dokumentový systém — hooky, validace, before/after save, životní cyklus záznamu |
| [doc-states.md](doc-states.md) | Stavy dokumentů — docState/docStateMain, cfgItem stavový automat, viewGroup taby, REST API přechodů |
| [rest-api.md](rest-api.md) | REST API — endpointy, autentizace, formát odpovědí, filtrování, řazení, stránkování |
| [attachments.md](attachments.md) | Systém příloh dokumentů — upload, download, náhledy, úložiště souborů, API endpointy |
| [frontend.md](frontend.md) | Frontend architektura — Svelte 5, komponenty, viewer systém, ikony, API komunikace |
| [documentation.md](documentation.md) | Pravidla pro dokumentaci modulů a tabulek — kde leží README.md, co obsahuje .md k tabulce, vzory |

Nginx konfigurace jsou v [`nginx/`](nginx/) (app.conf, development.conf, production.conf).

## Dokumenty k jednotlivým modulům

| Modul | Dokument | Obsah |
|-------|----------|-------|
| `core.mail` | [`mail/api-contract.md`](mail/api-contract.md) | Kontrakt HTTP endpointu `/_mail/incoming` pro externí mail-router (Fáze 2a — stabilní) |

Dokumentace konkrétního modulu žije obvykle v jeho adresáři
(`modules/{skupina}/{modul}/docs/`) — zde v `docs/` jsou pouze ty,
které přesahují hranice modulu (integrace s externími službami, API kontrakty).

---

[← README.md](../README.md) · [Průvodce vývojáře](../DEVELOPERS.md)
