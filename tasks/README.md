# Tasks — pracovní zadání pro Claude Code

Tato složka obsahuje zadání (PRD, task prompts) pro implementační práce.
Každý soubor je samostatný úkol pro jednu Claude Code session, případně
PRD popisující větší fázi rozdělenou na sub-tasky.

Tasky se po dokončení **nemažou** — slouží jako historický záznam designu
a rozhodnutí. Když narazíš na nesoulad mezi taskem a aktuálním stavem
kódu, věř kódu (kód je živý, task je momentka).

---

## Aktivní práce

| Task                              | Co řeší                                              |
|-----------------------------------|------------------------------------------------------|
| `viewer-number-series-tabs.md`    | Spodní lišta záložek číselných řad v per-type doc viewerech (`ReceivedInvoicesViewer`, `IssuedInvoicesViewer`) + předvyplnění aktivní řady při create. Refactor `DocsHeadsViewer` na opt-in přes `$scopedDocType`. |
| `docs-detail-document.md`         | Detail dokladu jako textová faktura — nový content type `document` (strany Dodavatel/Odběratel, řádky, DPH rekapitulace, náhledy příloh na konci), `PersonSnapshotBuilder`, `DocumentDetail.svelte` + sdílená `AttachmentGrid.svelte`. |
| `custom-theme-phase2.md`          | Vlastní vzhledy fáze 2 — gradientové presety (stránkovaný grid se šipkami), token `--shpd-sidebar-bg-image`, opacity slider (OKLab mix k bázi), odvozování z efektivní barvy. |
| `custom-theme-phase3.md`          | Vlastní vzhledy fáze 3 — per-user persistence (tabulka `core_system_user_settings` + `UserSettingsStore`, scope `user` u settings pages), nový mód `account` (Nastavení účtu) s vlastním stromem (`global.accountSections` + `accountItems[]`), stránka Základní s field typy `theme`/`language`, server jako zdroj pravdy + localStorage anti-flash cache. |
| `custom-theme-phase4.md`          | Vlastní vzhledy fáze 4 — DS-wide default vzhledu (`app.theme` ve scope `ds`, Nastavení aplikace → Aplikace), `follow` flag v `account.theme` (sleduj DS default / vlastní override), DS default na klienta přes `appInfo` + anti-flash cache `shpd_ds_theme`, odstranění dropdownu vzhledu z patky sidebaru. |


---

## Hotové tasky — feature work

Drží se jako reference. Když Claude Code potřebuje pochopit, **proč** je
něco postavené tak, jak je, často to najde v původním PRD.

### Vzhledy (themes)

Custom téma sidebaru — uživatel si barevně odliší zdroje dat.
Obsah stránky drží brand, barví se jen sidebar přes runtime tokeny.

| Task                          | Co řeší                                |
|-------------------------------|----------------------------------------|
| `custom-theme-phase1.md`      | Dropdown Shipard/Tmavý/Vlastní, panel s presety + color pickerem, OKLCH odvozování sidebar tokenů, localStorage per-DS |

### CLI utility — vylepšení dev workflow

Zjednodušení post-pull workflow (jeden shell skript místo čtyř ručních
kroků), volitelná git-hook automatizace, hromadný `ds-upgrade` přes
všechny DS, tichý default výstup `ds-upgrade`. Centrální CLI reference
v [`docs/cli.md`](../docs/cli.md).

| Task                            | Co řeší                                              |
|---------------------------------|------------------------------------------------------|
| `dev-update-script.md`          | `scripts/dev-update.sh` + git hooks v `.githooks/`   |
| `ds-upgrade-all.md`             | `shpd-server ds-upgrade-all` + `docs/cli.md`         |
| `ds-upgrade-quiet-default.md`   | Tichý default výstup `ds-upgrade`, kompletní jen s `-v` |

### Doklady MVP — faktury vydané a přijaté

Kompletní dokladový subsystém — polymorfní jádro `docs.core` + per-typ
moduly `docs.invoicesOut` (faktura vydaná `invno`) a `docs.invoicesIn`
(faktura přijatá `invni`). Řídící dokument v
[`docs/docs-mvp.md`](../docs/docs-mvp.md). DPH model pro **CZ** včetně
PDP, EU intracom, dovozu/vývozu. 6 fází.

| Task                              | Fáze | Co řeší                                              |
|-----------------------------------|------|------------------------------------------------------|
| `persons-is-own-extension.md`     | 1    | `is_own` flag a `court_registration` na `base.persons` |
| `world-vat-cz.md`                 | 2    | Modul `world.vat` s CZ DPH kódy a procenty            |
| `docs-core-phase1.md`             | 3    | Skeleton `docs.core` — tabulky, cfgItem, číselné řady |
| `docs-core-phase2.md`             | 4    | Výpočty cen, DPH, rekapitulace, snapshoty, atomické číslo |
| `docs-core-phase3.md`             | 5    | UI — `DocsHeadsForm`, `DocRowsForm`, `DocsHeadsViewer` |
| `docs-invoices.md`                | 6    | Per-typ moduly `docs.invoicesOut` a `docs.invoicesIn` s polymorfním dispatch |

### Unifikované logování

Produkční-grade `ErrorLogger` — úrovně (DEBUG/INFO/WARN/ERROR), JSON
formát (jeden řádek per záznam) s `ds`, `request`, `exception` polem,
`/opt/shipard/log/shipard.log` jako default cesta, automatické logování
v `index.php` catch handleru a `TableGateway::saveDocument`. Detaily a
způsoby čtení v [`docs/logging.md`](../docs/logging.md).

| Task                       | Co řeší                                            |
|----------------------------|----------------------------------------------------|
| `unified-logging.md`       | Refactor MVP `ErrorLogger` do produkční kvality, deploy guide, migrace existujících `error_log()` volání |

### Modul `economy.items` + `core.units`

Příprava na dokladový systém — katalog položek, číselník druhů, měrné
jednotky.

| Task                       | Co řeší                                |
|----------------------------|----------------------------------------|
| `economy-items-phase1.md`  | Tabulky, viewer, formulář, fake data    |

### Modul `mail`

| Task                       | Co řeší                                                |
|----------------------------|--------------------------------------------------------|
| `mail-phase1.md`           | Tabulky, viewer, editor, fake data — evidence došlé pošty |
| `mail-phase2a.md`          | API endpoint `/api/v1/_mail/incoming`, idempotency, auto-provisioning |
| `ds-encrypted-secrets.md`  | Šifrování citlivých sloupců — `encrypted_text` typ, `DsSecretCipher`, kanárková tabulka, rotace klíčů |
| `mail-phase3a.md`          | AI analýza došlé pošty — backendy, profily, claims, extrahované dokumenty, integrace s `ai_analyzer` daemonem |

`mail_router/tasks/phase1.md` (Fáze 2b) žije v jiném repozitáři a
implementuje samotný daemon, který volá endpoint `/api/v1/_mail/incoming`.

`ai_analyzer:tasks/phase1.md` (Python daemon) implementuje samotnou AI
analýzu, která konzumuje claims a vrací výsledky do tabulek z `mail-phase3a`.

### Editační formuláře

PRD `docs/edit-forms.md` rozdělené na tři fáze:

| Task                      | Co řeší                                            |
|---------------------------|----------------------------------------------------|
| `edit-forms-phase1.md`    | Backend jádro — `FormController`, `FormDefinition`, `AutoFormBuilder` |
| `edit-forms-phase2.md`    | JSONC loader, registrace, recalculate hook         |
| `edit-forms-phase3.md`    | Document classes, validace, sub-tabs               |
| `form-builder-hardening.md` | Whitelist pro `inputType`, dedikované buildery (`addTextArea`, `addDate`, …) — vyplynulo z provozu na `mail-phase1` |

### Frontend

| Task                              | Co řeší                                |
|-----------------------------------|----------------------------------------|
| `frontend-phase1-tasks.md`        | Skeleton SPA, routing, layout          |
| `frontend-phase1-viewer.md`       | `TableViewer` Svelte komponenta        |
| `frontend-phase3-app-sidebar.md`  | Hlavní navigace                        |
| `frontend-phase4-forms.md`        | `FormRenderer`, `FormDialog`           |
| `frontend-phase5-viewers.md`      | Konkrétní viewery (Persons, …)            |

### Drobné

| Task                          | Co řeší                                |
|-------------------------------|----------------------------------------|
| `add-reference.md`            | Přidání reference (FK) do schema       |
| `add-user.md`                 | Vytvoření uživatele přes CLI           |
| `install-for-developers.md`   | `DEVELOPERS.md`, `scripts/install-packages.sh` |

---

## Konvence

### Pojmenování souborů

- **Fázové PRD:** `<oblast>-phase<N>[<sub>].md` (`mail-phase2a.md`,
  `edit-forms-phase3.md`)
- **Jednorázové úkoly:** popisný kebab-case (`form-builder-hardening.md`,
  `add-user.md`)
- Všechny v ASCII (žádná diakritika v názvech), aby šly snadno tab-completit

### Struktura PRD

Větší PRD typicky obsahují:

- **Status / Cíl fáze** — krátká věta, co tato fáze přidává
- **Návaznost** — odkazy na předchozí PRD a externí závislosti
- **Scope** — V rozsahu / Mimo rozsah, přesně vymezené
- **Datový model** — JSONC tabulky, FK, indexy
- **API / kontrakty** — endpointy, request/response, error paths
- **Task breakdown** — commitovatelné jednotky s akceptačními kritérii
- **Rozhodnutí k designu** — body s `✓ potvrzeno` po finalizaci

Menší tasky stačí jako jednostránkové prompty s nadpisem "Co je potřeba
udělat" a check-listem "Hotovo když".

### Referencování externích repozitářů

Když task v `nov_shipard/tasks/` odkazuje na něco v jiném repu, používej
prefix `<projekt>:cesta`:

- `mail_router:tasks/phase1.md`
- `ai_analyzer:tasks/phase1.md`
- `shpd:docs/mail/api-contract.md` (zpětný odkaz z jiných repo tasků)

### Otevřené otázky

V `## Otevřené otázky` (nebo `## Rozhodnutí k designu`) sekci na konci
PRD. Po finalizaci přepsat na **`## Rozhodnutí k designu (potvrzená)`**
s `✓` na začátku každého bodu — ať příští čtenář ví, že to není
otevřené.

---

## Mapa projektů

| Projekt v `remote-dev-bridge` | Repo                      | Role                          |
|-------------------------------|---------------------------|-------------------------------|
| `nov_shipard`                 | `shipard/shpd`            | Hlavní aplikace (PHP backend + Svelte frontend) |
| `mail_router`                 | (samostatný repo)         | Mail-router daemon (Python)   |
| `ai_analyzer`                 | (samostatný repo)         | AI analyzer daemon (Python)   |

Tasky obvykle žijí v repu, kterého se týkají primárně. Cross-repo
změny dělej tak, že nejdřív stabilizuješ jeden repo (typicky shpd jako
"server"), pak proti němu vyvíjíš klienty.

---

## Když se vracíš po pauze

1. Přečti **Aktivní práci** na začátku — co je rozpracované
2. Pokud rozpracované není, podívej se do *Hotových tasků* na poslední
   commit-ovaný PRD (typicky to bude clue, kde projekt skončil)
3. Čti `CLAUDE.md` v kořeni repa pro celkový architectural overview
4. Při startu nové fáze: nejdřív design diskuze (otevřené otázky), pak
   PRD, pak implementace
