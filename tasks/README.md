# Tasks — pracovní zadání pro Claude Code

Tato složka obsahuje zadání (PRD, task prompts) pro implementační práce.
Každý soubor je samostatný úkol pro jednu Claude Code session, případně
PRD popisující větší fázi rozdělenou na sub-tasky.

Tasky se po dokončení **nemažou** — slouží jako historický záznam designu
a rozhodnutí. Když narazíš na nesoulad mezi taskem a aktuálním stavem
kódu, věř kódu (kód je živý, task je momentka).

---

## Aktivní práce

Tasky, které jsou rozpracované nebo na řadě.

### Doklady MVP — faktury vydané a přijaté

Klíčový úkol systému — pořizování dokladů, výpočet DPH, číselné řady,
stavový životní cyklus, snapshoty fakturačních údajů. Řídícím dokumentem
celé práce je [`docs/docs-mvp.md`](../docs/docs-mvp.md), který popisuje
návrh kompletní MVP architektury a rozděluje implementaci do 6 navazujících
fází.

MVP cílí na **dva typy dokladů** (`invno` faktura vydaná, `invni` faktura
přijatá), kompletní DPH model pro **CZ** (vč. PDP, EU intracom, dovozu/vývozu),
číselné řady, snapshot dodavatele/odběratele, polymorfní jádro `docs.core`
+ dvě tenké subclass moduly `docs.invoicesOut` a `docs.invoicesIn`.

| Task                              | Fáze v MVP | Stav     | Závislosti                                          |
|-----------------------------------|-----------|----------|-----------------------------------------------------|
| `persons-is-own-extension.md`     | 1         | hotovo   | base.persons (existuje)                             |
| `world-vat-cz.md`                 | 2         | hotovo   | world.base, world.trade (existují)                  |
| `docs-core-phase1.md` (skeleton)  | 3         | hotovo   | persons-extension, world.vat, economy.codebooks, items |
| `docs-core-phase2.md` (výpočty)   | 4         | hotovo   | docs-core-phase1                                    |
| `docs-core-phase3.md` (UI)        | 5         | připrav. | docs-core-phase2                                    |
| `docs-invoices.md`                | 6         | TODO     | docs-core-phase3                                    |

Fáze 1–4 jsou hotové — backend dokladů včetně výpočtů, číselných řad
a snapshotů. Tasky pro fáze 5 a 6 vzniknou postupně po dokončení Fáze 5 —
designové detaily se mohou v reakci na zjištění z předchozí fáze upravit.

### Modul `economy.items` + `core.units`

Příprava na dokladový systém — katalog položek, číselník druhů, měrné
jednotky. Bez těchto třech tabulek nemá smysl začínat s fakturami nebo
objednávkami.

| Task                       | Stav     | Závislosti                                |
|----------------------------|----------|-------------------------------------------|
| `economy-items-phase1.md`  | hotovo   | edit-forms (hotové), doc-states (hotové)  |

Po dokončení této fáze bude možné začít plánovat samotné doklady (faktury,
objednávky). V navazujících fázích modulu položek se bude řešit: VAT sazby
per země a cena s DPH (řeší `world-vat-cz.md`), skladová evidence,
ceníkový mechanismus.

---

## Hotové tasky — feature work

Drží se jako reference. Když Claude Code potřebuje pochopit, **proč** je
něco postavené tak, jak je, často to najde v původním PRD.

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
