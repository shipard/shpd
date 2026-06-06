# Task: Extrakce AI backendů do core/ai

## Kontext

Vnitřní chat (orchestrátor) bude potřebovat LLM backend — provider, model,
šifrovaný klíč. Ta infrastruktura už existuje, ale bydlí pod `core/mail`
(`core_mail_ai_backends` + `AIBackendDocument`/`Lookup`/`Viewer`). Kdyby ji chat
reuse-oval jak je, nový modul `core/chat` by závisel na `core/mail` jen kvůli
credentials — **obrácená závislost** (chat a mail jsou sourozenci, oba mají stát
na sdílené AI vrstvě).

Tento task vytahuje backendy do nového sdíleného modulu **`core/ai`**, na kterém
pak stojí mail i chat. Je to prerekvizita chatu — žádná funkční změna, čistě
přesun ownershipu + migrace.

**Důležité k `ds-upgrade`:** umí jen `create_table`/`create_index`/`ALTER`
(diff přes `SchemaComparator`) — **žádný rename ani drop**. Tabulku tedy
nepřejmenuje sám; data se musí přenést ručně (viz Migrace).

**Co se NEstěhuje:** profily (`core_mail_ai_profiles`) jsou mail-specifické
(extrakční prompty, `output_schema`, `supported_doc_types`) a **zůstávají v
mailu**. Jen referencují backend přes FK — ta reference bude nově
cross-module (mail → core.ai), což je v pořádku.

## Migrace dat (zachovat zašifrované klíče)

`ds-upgrade` neumí rename, proto **jednorázově ručně per DS**, PŘED upgradem:

```sql
RENAME TABLE core_mail_ai_backends TO core_ai_backends;
```

Tím se zachovají řádky, zašifrované klíče i **`id`** → FK z
`core_mail_ai_profiles.backend` a `core_mail_message_analyses.backend`
(app-level, drží int id) zůstanou platné. Po renmeu `ds-upgrade` uvidí tabulku
se správným jménem a jen případně ALTER-uje. (Reálně běží na jednom DS — pustí
se jednou. Žádný bespoke migrační příkaz.)

PRD tuto SQL uvede do sekce „Hotovo když"/README jako operační krok; kód migraci
neautomatizuje.

## Před implementací přečti

- **`modules/core/mail/module.jsonc`** — registrace backends tabulky/viewer/form/
  lookup (odsud se odeberou) + `dependencies`.
- **`modules/core/mail/tables/core_mail_ai_backends.jsonc`** — definice tabulky
  (sloupce, tableId, indexy `unq_backend_id`/`idx_is_default`/`idx_is_active`),
  přesune se a přejmenuje na `core_ai_backends`.
- **`modules/core/mail/src/AIBackendDocument.php`, `AIBackendLookup.php`,
  `AIBackendsViewer.php`** — přesun do `core/ai`, namespace
  `Shipard\Module\Core\Ai\`.
- **`modules/core/mail/tables/core_mail_ai_profiles.jsonc`** +
  **`modules/core/mail/forms/core_mail_ai_profiles.jsonc`** +
  **`modules/core/mail/src/AIProfilesViewer.php`** — FK/lookup na backend
  přepojit na `core_ai_backends`.
- **`modules/core/mail/tables/core_mail_message_analyses.jsonc`** — `backend` FK
  (display/lookup) přepojit.
- **`src/Api/Controller/AnalysisController.php`** — `BACKENDS_TABLE` konstanta
  + `use` AIBackendDocument + `resolveBackend()`/`claim()` dotazy.
- **`src/Command/DataSource/AiAnalyzerSetKeyCommand.php`,
  `AiAnalyzerBootstrapCommand.php`, `AiProfileReloadCommand.php`,
  `DsResetCommand.php`** + **`AIAnalyzerProvisioner.php`** (a `provisionAiAnalyzer`
  v `DsUpgradeCommand.php`) — table name + namespace.
- **`modules/core/units/module.jsonc`** — vzor `module.jsonc` malého `core/*`
  modulu (id, dependencies, tables, viewers, forms, settingsItems).
- **Testy:** `tests/Unit/Command/DataSource/AiAnalyzerSetKeyCommandTest.php`,
  `tests/Unit/Module/Core/Mail/AIAnalyzerProvisionerTest.php`,
  `tests/Unit/Core/Database/SqlGeneratorTest.php`,
  `SchemaIntrospectorTest.php` — reference na jméno tabulky.

## Scope

**V rozsahu:** nový modul `core/ai`; přesun backends tabulky (+ rename) a tří
tříd; repoint všech referencí (profiles, analyses, controller, CLI, provisioner);
úprava `mail/module.jsonc` (odebrat backends, přidat dependency `core.ai`);
dokumentace rename SQL; aktualizace testů.

**Mimo rozsah:** chat / orchestrátor; přesun profilů (zůstávají v mailu); bespoke
migrační příkaz; přečíslování tableId (viz Rozhodnutí #3); jakákoli funkční změna
chování backendů.

## Co implementovat

### 1. Nový modul `core/ai`

`modules/core/ai/module.jsonc` (vzor dle `core/units`):

```jsonc
{
  "id": "core.ai",
  "name": "AI", "name:cs": "AI", "name:en": "AI",
  "description": "Shared AI infrastructure: LLM provider backends (credentials, model, encrypted API key) reused by mail analysis, chat, and future AI features",
  "dependencies": ["core.system"],
  "tables": ["core_ai_backends"],
  "viewers": [ { "id": "core.ai.backends", "table": "core_ai_backends", "class": "Shipard\\Module\\Core\\Ai\\AIBackendsViewer", ... } ],
  "forms":   [ { "table": "core_ai_backends", "class": "Shipard\\Module\\Core\\Ai\\AIBackendForm" /* pokud existuje */ } ],
  "settingsItems": [ { "viewer": "core.ai.backends", "section": "ai" /* nebo dle stávajícího umístění */ } ]
}
```

(Lookup `AIBackendLookup` registrovat tam, kde je dnes registrovaný — přesunout
záznam z `mail/module.jsonc`.)

### 2. Tabulka → `core_ai_backends`

Přesun `core_mail_ai_backends.jsonc` → `modules/core/ai/tables/core_ai_backends.jsonc`,
přejmenovat tabulku v definici na `core_ai_backends`. **Sloupce, indexy i
`tableId` ponechat beze změny** (viz Rozhodnutí #3). Zkontrolovat self-reference
na jméno uvnitř jsonc.

### 3. Třídy → `Shipard\Module\Core\Ai\`

Přesun `AIBackendDocument`, `AIBackendLookup`, `AIBackendsViewer` (+ případný
`AIBackendForm`) do `modules/core/ai/src/`, změna namespace. `AIBackendDocument`
si nese tabulkovou konstantu/jméno — přepsat na `core_ai_backends`. Najít a
přepsat všechny `use Shipard\Module\Core\Mail\AIBackend*` napříč kódem.

### 4. Repoint referencí

- **`AnalysisController`**: `BACKENDS_TABLE = 'core_ai_backends'`; `use`
  `Shipard\Module\Core\Ai\AIBackendDocument`; ověřit `claim()`/`resolveBackend()`.
- **`core_mail_ai_profiles`** (tabulka + form + `AIProfilesViewer`): `backend`
  lookup/FK → `core_ai_backends` (cross-module, OK).
- **`core_mail_message_analyses`**: `backend` FK display/lookup → `core_ai_backends`.
- **CLI + provisioner**: `AiAnalyzerSetKeyCommand`, `AiAnalyzerBootstrapCommand`,
  `AIAnalyzerProvisioner`, `provisionAiAnalyzer()` v `DsUpgradeCommand`,
  `AiProfileReloadCommand`, `DsResetCommand` (pokud má backends v `keepOnReset`)
  — table name + namespace.

### 5. `mail/module.jsonc`

Odebrat registraci backends tabulky, vieweru, formu i lookupu. Přidat
`"core.ai"` do `dependencies`. Profily a jejich registrace **zůstávají**.

### 6. Testy

Aktualizovat reference na jméno tabulky (`core_mail_ai_backends` →
`core_ai_backends`) a namespace. Celá suite zelená.

## Hotovo když

1. Existuje modul `core/ai` s tabulkou `core_ai_backends`, třídami v namespace
   `Shipard\Module\Core\Ai\` a registrací viewer/form/lookup.
2. `core/mail` už backendy neregistruje, závisí na `core.ai`; profily zůstaly v
   mailu a jejich `backend` FK míří na `core_ai_backends`.
3. `AnalysisController` + CLI + provisioner cílí na `core_ai_backends` a novou
   namespace; analýza pošty (claim → result → applyExtracted) funguje beze změny.
4. **Operační krok migrace** zdokumentován: `RENAME TABLE core_mail_ai_backends
   TO core_ai_backends;` se pustí jednou per DS před `ds-upgrade`; zachová klíče
   i `id`.
5. `ds-upgrade` po renameu tabulku rozpozná (jen případný ALTER), `ds-create`
   nového DS založí rovnou `core_ai_backends` a provisioner do něj naseje default
   backend.
6. Celá test suite zelená.

## Doporučené pořadí implementace

1. Vytvořit `core/ai` modul + přesunout tabulku (rename na `core_ai_backends`) +
   třídy (namespace).
2. Repoint všech referencí (controller, profiles, analyses, CLI, provisioner) +
   `mail/module.jsonc`.
3. Aktualizovat testy, spustit suite.
4. Na dev DS: `RENAME TABLE …` → `ds-upgrade` → ověřit, že analýza pošty i
   set-key jedou nad `core_ai_backends`.

## Rozhodnutí k designu (potvrzená)

1. ✓ **Migrace = rename, zachovat data** (varianta A). Jednorázové `RENAME TABLE`
   per DS jako dokumentovaný operační krok, ne bespoke příkaz (reálně jeden DS).
2. ✓ **Stěhují se jen backendy**; profily zůstávají v `core/mail` a referencují
   `core_ai_backends` cross-module.
3. ✓ **tableId ponechán** — RENAME zachovává identitu tabulky; přečíslování by
   bylo zbytečná churn a riziko. Pro budoucí `core/ai` tabulky se vyhradí nový
   rozsah (jen v dokumentaci modulu), stávající backends id se nemění.
4. ✓ **Modul `core.ai`**, namespace `Shipard\Module\Core\Ai\`, dependency
   `core.system`; `core.mail` nově závisí na `core.ai`.

## Otevřené body (k ověření, neblokující)

- **Polymorfní reference na tableId backendů** — grep `table_id = <id>` / použití
  toho čísla jako polymorfního odkazu (attachments apod.). Téměř jistě žádné
  (backendy nejsou cíl příloh), ale potvrdit před spolehnutím na „ponechat
  tableId".
- **`DsResetCommand` `keepOnReset`** — jestli backends figurují, přejmenovat
  záznam.
- **Přesné napojení `backend` lookupu** v profiles formu i `message_analyses`
  (display value) — ověřit, že po repoinpointu UI ukazuje název backendu.
- **`settingsItems` umístění** vieweru backendů — zachovat, kam dnes patří v
  nastavení (přesunout 1:1 z mailu).
