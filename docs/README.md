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
| [frontend.md](frontend.md) | Frontend architektura — Svelte 5, komponenty, viewer systém, ikony, API komunikace |
| [documentation.md](documentation.md) | Pravidla pro dokumentaci modulů a tabulek — kde leží README.md, co obsahuje .md k tabulce, vzory |

Nginx konfigurace jsou v [`nginx/`](nginx/) (app.conf, development.conf, production.conf).

---

[← README.md](../README.md) · [Průvodce vývojáře](../DEVELOPERS.md)
