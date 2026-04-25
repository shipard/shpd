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

### Modul `mail` — AI analýza

Závislost: **Fáze 1 a 2a hotové**.

| Task                          | Stav    | Závislosti                          |
|-------------------------------|---------|-------------------------------------|
| `ds-encrypted-secrets.md`     | připrav. | žádné — generický fundament         |
| `mail-phase3a.md`             | připrav. | `ds-encrypted-secrets` (dokončené)  |

**Doporučené pořadí:** nejdřív celý `ds-encrypted-secrets`, ověřit
v praxi (kanárková tabulka, rotation, migrace), **pak** teprve
`mail-phase3a`. Důvod: secrets infrastruktura je cross-cutting concern
a chybí-li, AI tasky se musí vracet.

Po `mail-phase3a` následuje `ai_analyzer/tasks/phase1.md` (samostatný
repozitář — Python daemon).

---

## Hotové tasky — feature work

Drží se jako reference. Když Claude Code potřebuje pochopit, **proč** je
něco postavené tak, jak je, často to najde v původním PRD.

### Modul `mail`

| Task                  | Co řeší                                                |
|-----------------------|--------------------------------------------------------|
| `mail-phase1.md`      | Tabulky, viewer, editor, fake data — evidence došlé pošty |
| `mail-phase2a.md`     | API endpoint `/api/v1/_mail/incoming`, idempotency, auto-provisioning |

`mail_router/tasks/phase1.md` (Fáze 2b) žije v jiném repozitáři a
implementuje samotný daemon, který tento endpoint volá.

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
