# Shipard — CLAUDE.md

## Projekt

Modulární multi-tenant SaaS účetní systém — backend a CLI utility, webové API a Svelte 5 frontend.

- **Namespace:** `Shipard\` → `src/`, testy `Shipard\Tests\` → `tests/`
- **PHP 8.5+**, strict_types povinně, PSR-4 autoloading
- **Závislosti:** `dibi/dibi` (DB vrstva), `symfony/console` (CLI), `phpunit/phpunit` (dev)

## Dokumentace

Podrobné specifikace jsou v adresáři `docs/`. Přečti příslušný dokument PŘED implementací.

| Dokument | Obsah |
|----------|-------|
| `docs/roadmap.md` | **Roadmapa** — milníky, pravidlo prioritizace. Než začneš nový task: ověř, do kterého milníku patří |
| `tasks/README.md` | **Konvence pro tasky** — hlavička `**Stav:**`, struktura zadání, anonymizace citlivých údajů, pojmenování souborů |
| `docs/help-authoring.md` | **Uživatelská dokumentace** v `help/` — žánrová hranice proti `docs/`, front matter stránky, šablona, generovaný rozcestník. Přečti před psaním čehokoli do `help/` |
| `docs/architecture.md` | Mapa tříd, vrstvy, závislosti, tok dat — přečti pokud potřebuješ pochopit jak komponenty spolupracují |
| `docs/modules.md` | Modulový systém — struktura modulů, závislosti, JSONC formát, vícejazyčnost (i18n), kompilace konfigurace, CLI příkaz `ds-upgrade` |
| `docs/table-definitions.md` | Formát definice databázových tabulek — datové typy, sloupce, indexy, extensions, validace, bezpečné změny |
| `docs/document-system.md` | Dokumentový systém — Document třídy, hooky, validace, TableGateway, child tabulky, DocumentRegistry |
| `docs/alerts.md` | Systém upozornění — JSONC `alertChecks`, PHP `AlertCheck`, `AlertReconciler`, snooze/dismiss, CLI `alerts-run` |
| `docs/frontend.md` | Frontend architektura — Svelte 5, komponenty, ikony (Font Awesome), API komunikace |
| `docs/edit-forms.md` | Editační formuláře — FormDefinition, taby, sekce, sloupce, `TableForm`, JSONC formy, recalculate, doc states, **HeaderInfo** (sekce 21), **Lookup pole** (sekce 22) |
| `docs/edit-forms-cookbook.md` | Editační formuláře — cookbook s izolovanými vzory pro psaní forem (JSONC i PHP `TableBuilder`); rychlý úvod, sekce/sloupce/inline/separator recepty, časté chyby. Pro hluboký referenční materiál viz `edit-forms.md`. |
| `docs/operations/secrets.md` | Per-DS šifrování `encrypted_text` sloupců — `DsSecretCipher`, klíčový soubor, rotace, health check, threat model |
| `docs/migration-guide.md` | Backup a přenos DS na jiný server — tarball, DB dump, perms, ověření |
| `docs/dashboard.md` | Dashboard — home feed akčních karet (fáze 2), kartový kontrakt, zdroje, akce + undo, tasks widget, API kontrakt |
| `docs/app-settings.md` | Settings pages + branding — `SettingsStore`, `settingsPages` v module.jsonc, klíče `app.*`, branding sloty, `/_app` endpointy, jak přidat další stránku |
| `docs/accounting.md` | Účtování dokladů — rowOperations, účtovací předpis, `AccountingEngine`, deník, lifecycle (stav 40), endpoint reaccount, tab Zaúčtování + `JournalViewer` (Fáze 1–3 hotové), DPH analytiky per vatCode + reverse charge + konvence OSS |
| `docs/auth.md` | Autentizace — auth politika per DS (`auth` v main.json), OIDC relying party (start/callback/exchange, PKCE, handoff), `SessionService`, mapování identit + JIT, break-glass CLI, test s dockerovým Keycloakem, samoobsluha účtů Fáze 0b (pozvánky, forgot/reset/change hesla, sessions) |
| `docs/mail/outbound.md` | Odchozí pošta — fronta (`core_mail_outbox` + log), per-sender SMTP transporty, relay konfigurace `mail.relay`, `MailOutboxService`/`TransportResolver`, cron worker, password endpoint, runbook |

## Architektura — rychlý přehled

```
src/
├── Command/                    # CLI příkazy (Symfony Console)
│   ├── Server/                 # shpd-server: ds-create, server-init, next-table-id
│   └── DataSource/             # shpd-ds: ds-upgrade
├── Core/
│   ├── Config/                 # ServerConfig, DataSourceConfig, ConfigCompiler, ConfigRuntime
│   ├── Database/               # TableDefinition, ColumnDefinition, IndexDefinition,
│   │                           # ExtensionDefinition, TableMerger, SchemaComparator,
│   │                           # SchemaValidator, SqlGenerator, DatabaseManager
│   ├── Document/               # Document, DefaultDocument, TableGateway, DocumentRegistry,
│   │                           # ValidationResult, ValidationError, DocumentResult
│   ├── I18n/                   # LocalizedFieldResolver, ConfigLocalizer
│   ├── Module/                 # ModuleDefinition, ModuleLoader, ModuleResolver
│   └── Utils/                  # JsoncParser, IdGenerator
modules/{skupina}/{modul}/src/  # Document třídy modulů (PersonDocument, IssuedInvoiceDocument...)
                                # Skupiny: core, base, economy, docs, tasks, world, install
```

Závislosti tečou shora dolů: Command → Document → Module/Config/Database → I18n/Utils.

## Klíčové konvence

### Tasky a jejich stav

Zadání žijí v `tasks/`, každé má hned za nadpisem H1 řádek `**Stav:**`
(`hotovo` / `částečně` / `naplánováno` / `zrušeno`). Tento řádek je **zdroj pravdy**
o stavu — generuje se z něj souhrn nedokončených tasků v `tasks/README.md`.

**Poslední krok každé implementace je aktualizace `**Stav:**` v příslušném
tasku — ve stejném commitu jako kód.** Poté:

```bash
python3 scripts/tasks-index.py
```

Pre-commit hook ověří, že souhrn odpovídá hlavičkám. Rozpor hlavičky **s kódem** ale
neuhlídá žádný skript — jen tento návyk. Audit v srpnu 2026 našel osm tasků,
které tvrdily „k implementaci“ u věcí dávno v kódu.

Než začneš nový task: ověř v `docs/roadmap.md`, do kterého milníku patří. Otevřená
položka z M0 (věcná správnost) má přednost před vším ostatním.

Podrobnosti: `tasks/README.md` → Hlavička se stavem.

### Uživatelská dokumentace (`help/`)

`help/` odpovídá na otázku **„jak to udělám“**, `docs/` na **„jak je to
udělané“**. Do `help/` nepatří názvy tříd, tabulek, endpointů ani hodnoty
`docState` — uživatel pracuje se stavem **V pořádku**, ne s `docState=40`.

Čte to člověk na GitHubu **i** vnitřní AI asistent, proto:

- Názvy sekcí, tlačítek a stavů **ověř ve zdroji** popisků (`module.jsonc`
  `name:cs`, `frontend/src/i18n/cs.js`), neodhaduj je.
- Co nefunguje, patří do `help/co-dnes-nejde.md`. Bez toho si asistent
  chybějící funkci vymyslí.
- Uživatelská stránka se aktualizuje **ve stejném commitu** jako změna
  funkce, kterou popisuje. Poté `python3 scripts/help-index.py`
  (pre-commit hook ověřuje rozcestník i metadata stránek).

Formát hlavičky stránky a šablona: `docs/help-authoring.md`.

### Konfigurace na serveru
- Server config: `/etc/shipard/server.json` (práva 0600)
- Data sources: `/opt/shipard/data-sources/{id}/config/main.json` (práva 0600)

### ID zdroje dat
- Formát: `xxxx-xxxx-xxxx-xxxx` (a-z0-9, 4 skupiny po 4)
- DB name: pomlčky → podtržítka (`abcd_efgh_ijkl_mnop`)
- DB user: `shpd_` + první 2 skupiny bez pomlček (`shpd_abcdefgh`)

### Databáze
- MariaDB přes Dibi (`driver: mysqli`)
- `CHARACTER SET utf8mb4 COLLATE utf8mb4_czech_ci`
- Žádné FOREIGN KEY — referenční integrita na aplikační úrovni
- Admin účet jen pro CREATE DATABASE / CREATE USER, runtime přes DS uživatele

### Modulový systém
- Moduly v `modules/{skupina}/{modul}/`, ID v tečkové notaci (`economy.docs`)
- Definiční soubory: `.jsonc` (ručně psané), `.json` (generované)
- Vícejazyčnost: suffix `:lang` (`"name:cs": "Název"`), holé pole povinné jako fallback
- Fallback: požadovaný jazyk → `en` → holé pole
- Závislosti: jednoduché (bez verzí), tranzitivní, cyklické = chyba
- Extensions: jen přidávání sloupců/indexů do cizích tabulek

### Databázové tabulky
- Pojmenování: `{skupina}_{modul}_{tabulka}` (snake_case)
- Každá tabulka má `tableId` (unikátní SMALLINT, globálně)
- PK: vždy `id INT AUTO_INCREMENT`
- `enumInt` → SMALLINT, `enumString` → CHAR(len) CHARACTER SET ascii
- `numeric(precision, scale)` pro finance
- `ds-upgrade`: CREATE/ADD/bezpečný MODIFY, nikdy nesmaže

### Kódové konvence
- Datové třídy (*Definition): readonly, factory `fromArray()`, validace v konstruktoru
- Příkazy: Symfony Console, testovatelné přes subclassing
- Žádná business logika v datových třídách

### Dokumentový systém
- Data vždy jako PHP `array` — žádné property per sloupec (kvůli extensions)
- Hooky: `validate()` → `beforeSave()` → DB → `afterSave()`, `beforeDelete()` → DB → `afterDelete()`, `onLoad()`
- `validate()` vrací `ValidationResult` s chybami (column + message + code), pro UI focus
- Chyby bez vazby na pole (form-level): `column = ValidationError::FIELD_FORM` (`'_form'`) — frontend je vykreslí v banneru formuláře, ne vedle inputu. Kontrakt `field` viz `docs/edit-forms.md` sekce 8
- Chyby v řádcích: tečková notace `rows.0.unit_price`
- Hlavička + řádky: vždy v jedné DB transakci
- Child tabulky sync: bez `id` = INSERT, s `id` = UPDATE, chybějící = DELETE
- Document třídy: registrace v `module.jsonc` (`documentClasses`), polymorfismus přes `typeColumn`
- PHP třídy modulů: `modules/{skupina}/{modul}/src/`, namespace `Shipard\Module\{Skupina}\{Modul}\`

### Editační formuláře — `select` vs `lookup`
- **`select`**: enumy (`enumInt`/`enumString`) a malé cfgItem-based číselníky. Options se předají v `FormDefinition`.
- **`lookup`**: FK na velké tabulky (Osoby, Adresy, Položky…) s typeahead vyhledáváním. Klient volá `GET /_ui/lookup/{table}/search`, server pre-resolvuje vybrané hodnoty do `dataResolved`.
- Registrace lookup tříd v `module.jsonc` → `lookups: [{table, class}]`; konkrétní implementace dědí z `Shipard\Core\Form\Lookup\TableLookup`.
- Cascade filtery (např. partner → adresy partnera) jdou přes existující `recalculate` flow; žádný extra mechanismus.
- Lookup pole podporují inline **edit** (tužka u vybrané hodnoty) a **create** („+ Vytvořit nový“ v dropdownu) přes vnořený `FormDialog`. Opt-in přes `editForm`/`createForm` flagy v lookup definici. Přidaný `editTriggers` flag (default false) ovládá, zda edit detailů triggerne recalculate v rodiči — zapíná se tam, kde edit propisuje data zpět do rodiče (položka → řádek). U Partnera vypnutý, jinak by edit detailů vynuloval adresu/banku přes destruktivní cascade.
- `lookup` element **nelze** umístit do `inline` skupiny.
- Detailně viz `docs/edit-forms.md` kapitola 22.

### Editační formuláře — polymorfismus per typ
- Formuláře nad polymorfní tabulkou (např. `docs_core_heads` s `doc_type`)
  se registrují přes `typeColumn` + `classes` + `defaultClass` v
  `module.jsonc` → `forms[]`. `FormLoader::mergeForms()` slévá registrace
  z více modulů per-table (paralela k `DocumentLoader::mergeDocumentClasses()`).
- Vzor: `docs.core` registruje `DocsHeadsForm` jako defaultClass,
  `docs.invoicesOut` přidává `invno → IssuedInvoiceForm`, `docs.invoicesIn`
  přidává `invni → ReceivedInvoiceForm`. Hierarchie tříd:
  `TableForm → DocsHeadsFormBase → {DocsHeadsForm, IssuedInvoiceForm, ReceivedInvoiceForm}`.
- Per-typ subclassy jsou tenké — overridují jen, co se má lišit (titulky
  přes `getFormTitle()` / `getNewFormTitle()`, do budoucna jednotlivé
  `buildXxxTab()` metody). Společná logika žije v base.
- `FormRegistry::createForm($table, $data, $db, $config)` dispatchuje podle
  `$data[$typeColumn]`. Existující `{table, class}` registrace fungují beze
  změny (PersonsForm, ItemsForm, …).
- `DocsHeadsFormBase` má hook `buildExtraTabs(array $data, bool $isNew): array`
  (default `[]`) pro přidání per-typ tabů na konec formuláře za Přílohy.
  První uživatelé:
  - `ReceivedInvoiceForm` (FPB) — sekce DPH (vat_registration),
    Bankovní spojení (bank_account), Měna (home_currency readOnly).
  - `IssuedInvoiceForm` (FVB) — jen sekce Měna (home_currency readOnly);
    vat_registration a bank_account zůstávají v hlavičce, mění se podle
    odběratele / měny dokladu.
- Detailně viz `docs/edit-forms.md` kapitola 23.

### Citlivá data (encrypted_text)
- Pro nové citlivé sloupce (API klíče, hesla, tokeny) **vždy** typ `encrypted_text` v JSONC schema. Nikdy plain `text`/`varchar`.
- Encrypt/decrypt se **nedělá automaticky v TableGateway** — Document class odpovídá za:
  - `beforeSave()`: pokud je pole dirty a non-null/non-empty, `DsSecretCipher::forConfig($cfg)->encrypt(...)`
  - Controller: `$cipher->decrypt($row['col'])` až těsně před použitím
- Vzor: `tests/Fixtures/Module/Test/Secrets/TestSecretDocument.php`
- **Anti-patterny:** necachuj plaintext (session/cookie/log), neposílej ho do view/template, nepřenášej v URL/query
- Form pro editaci citlivého pole: prázdné pole + placeholder `●●●●●● (zadat pro změnu)`, prázdný submit nemění hodnotu; zápis přes form save povoluje **opt-in whitelist** `TableForm::getEditableSensitiveColumns()` (jinak 400 SENSITIVE_COLUMN) — viz `docs/edit-forms.md` kap. 24
- Backup, migrace, rotace, troubleshooting: `docs/operations/secrets.md`
- **Commitované texty** (tasky, docs, commit messages): nikdy citlivé údaje z reálných dat (jména firem/osob, čísla faktur, reálné částky, názvy DS, id záznamů) — diagnostické příklady anonymizovat se zachováním poměrů; viz `tasks/README.md` → Konvence

### CLI příkazy
- `shpd-server`: `version`, `help`, `ds-create --name [--module] [--ds-id]`, `server-init`, `next-table-id`, `hosting-sync [--dry-run] [--stats]`
- `shpd-ds` (z adresáře DS): `version`, `help`, `ds-upgrade`, `ds-setting list|get <key>|set <key> <value> [--unset]`, `ds-secrets-health`, `ds-secrets-rotate [--dry-run]`, `alerts-run [--check=id|--all]`, `alerts-prune [--days=N] [--dry-run]`, `mail-outbox-run [--limit=N]`, `mail-outbox-retry --id N`, `mail-send-test --to x@y [--from ...]`, `registry-extract-texts [--all] [--limit=N]`, `user-create [--password] [--if-not-exists] [--identity-issuer/-subject/-provider]` (bez hesla → poslat pozvánku), `user-set-admin`, `auth-emergency-login`, `hosting-oidc-init`, `hosting-oidc-client`, `hosting-server-key`, `hosting-router-key`, `hosting-ai-gw-init`, `hosting-ai-token`, `hosting-stats [--json]`

### Frontend — ikony
- Font Awesome SVG/JS, tree-shaking přes Vite
- Centrální registr: `frontend/src/icons.js` — pojmenování podle významu (`iconAdd`, `iconUser`, `iconListCheck`), ne vzhledu
- Komponenta: `Icon.svelte` (inline SVG), rozšířený `Button.svelte` (prop `icon`)
- Navigace: server posílá `"icon": "klíč"` v JSON, frontend překládá přes `resolveIcon()` s fallbackem `iconTable`
- Nová ikona: import v `icons.js` + export + záznam v `iconMap`
- Viewery dědí default ikonu pro řádky z `module.jsonc` viewers[].icon
  (stejná jako v sidebaru). Per-row override v `renderRow()`
  (např. PersonsViewer podle person_type).

### Frontend — Navigace (sidebar)

- Sekce hlavního sidebaru pocházejí z cfgItem **`global.navSections`**
  (`modules/install/base/config/navSections.jsonc`), NE z prefixu module ID.
  Analogie k `global.settingsSections`. `NavigationController` (`GET
  /_ui/navigation`) seskupuje viewery/tabulky podle jejich `navSection`,
  řadí sekce dle `navSections.order` a položky dle `navOrder`.
- `navSection` + `navOrder` se deklarují na vieweru v `module.jsonc`
  `viewers[]` (tabulky bez vieweru v `tables/*.jsonc`). Sentinel
  `navSection: "_top"` = root-level leaf nad sekcemi (Došlá pošta, Úkoly);
  Dashboard a Chat jsou hardcoded root leaves. Bez `navSection` → fallback
  do sekce `system`.
- `hideFromNavigation: true` funguje i na **vieweru** (nejen na tabulce) —
  skryje jen ten viewer; sdílenou tabulku dál zobrazují ostatní viewery
  (souhrnný `docs.core.heads` skrytý, Faktury přijaté/vydané nad sdílenou
  `docs_core_heads` viditelné).
- API tvar odpovědi je shodný se starým prefix-groupingem → `Sidebar.svelte`
  beze změny. Detaily: `docs/frontend.md`, `docs/modules.md`.

### Frontend — Dashboard (feed, fáze 2)

- Home obrazovka, výchozí po loginu (root-level leaf, `type: 'dashboard'`).
  **Prioritizovaný feed akčních karet** + tasks widget pod ním (fáze 1 widget
  mřížka nahrazena).
- `GET /_ui/dashboard` → `{summary{aiText,counts}, cards[], tasks}`. Karty
  agregují napevno registrované `FeedSource` zdroje (`MailSuggestionsSource`,
  `AlertsSource` — `src/Core/Feed/` + moduly). **Řadí a stropuje server**
  (`sortAndCap` dle `KIND_ORDER` urgent/review/ready/info + `timestamp` DESC,
  `MAX_CARDS ~30` + „a další…" karta). `buildTasksWidget` re-use fáze 1.
- Kartový kontrakt `{id, source, kind, icon, stateStyle, title, subtitle,
  timestamp, context, actions[]}`. Chování akcí odvozuje frontend z `action.kind`
  (`apply_extracted`/`review_extracted`/`reject_extracted`/`reanalyze`/
  `trash_message`/`archive_message`/`open_viewer`/`open_form`). Mail akce bez
  `label` (FE lokalizuje dle `action.id`); alert akce nesou vlastní label
  (passthrough).
- **Jednoklik apply** posílá `safe` (`applyExtractedDocument(ndx)` bez
  userActions); `unresolved_required` (422) → **fall-through** do
  `DocumentExchangePreviewModal`. **Undo** = `POST …/unapply` (doklad do koše
  90, extracted → 20, zpráva 40→20). Toast s „Otevřít"/„Vrátit" (app nemá toast
  infra → lokální v `Dashboard.svelte`).
- `RejectReasonPrompt` je sdílený (feed i `ViewerDetail`).
- **AI shrnutí (fáze 2b)**: `GET /_ui/dashboard/summary` — SSE stream
  (`text`/`done`/`error`) generovaného shrnutí feedu. `DashboardSummaryService`
  (`src/Core/Dashboard/`) staví digest (county + top 6 karet + datum + jazyk),
  cache dle `sha256(digest)` v `core_ai_dashboard_summary` per jazyk (datum
  v hashi = regenerace aspoň denně); LLM přes `AiBackendResolver`
  (`src/Core/Ai/`) + `streamChat` (`temperature=null`, `tools=null`,
  `maxTokens ~300`). Prázdný feed / chybějící backend či klíč / chyba →
  `done{text:null}` = tichá degradace na statické county. FE:
  `streamDashboardSummary()` v `api/dashboard.js` + stream v
  `AiSummaryCard.svelte` (re-open při každém loadu dashboardu).
- Doc-state `.docState_*` třídy globální (`styles/base.css`) — sdílené
  `ViewerRow`/`WidgetRow`/`FeedCard`. Feed ikony (`check/question/warning/info`)
  v `icons.js`.
- Detaily: `docs/dashboard.md`

### Frontend — Settings mód

- Aplikace má tři navigační módy: `app` (běžná práce), `settings` (Nastavení
  aplikace, DS-scoped) a `account` (Nastavení účtu, per-user)
- Mode drží `navigation.svelte.js`, každý mód má vlastní `activeItem`;
  `enterSettings`/`enterAccount`/`exitToApp`
- Sidebar mode-aware načítá `/_ui/navigation` (app), `/_ui/settings/navigation`
  (settings) nebo `/_ui/account/navigation` (account)
- Číselníky určené do Nastavení mají `settingsItems[]` v `module.jsonc`,
  sekce v `modules/install/base/config/settingsSections.jsonc`
- Položky uvedené v `settingsItems[]` se automaticky skrývají z hlavního
  navigačního stromu
- Třetí typ položky: **settings page** (`settingsItems` s `"page"`,
  definice v `settingsPages[]`) — server-driven stránka vlastností,
  hodnoty v `core_system_settings` přes `SettingsStore`. První stránka:
  Aplikace (název, ikona, logo — branding sloty). Viz `docs/app-settings.md`
- Settings page má `scope` (`ds` | `user`, default `ds`). User-scope čte/píše
  přes `UserSettingsStore` (`core_system_user_settings`, scoped na `user_id`,
  klíče `account.*`); `SettingsStore`/`UserSettingsStore` sdílí rozhraní
  `KeyValueStore`. Field typy `theme`/`language` jsou řízené widgety vázané
  na klientské stores
- **Account mód** (`account`): Nastavení účtu — vlastní strom
  (`global.accountSections` + `accountItems[]`), endpoint
  `/_ui/account/navigation`, page `accountBasic` (vzhled + jazyk, scope user).
  Detaily `docs/app-settings.md` sekce 8
- Sub-tabulky spravované výhradně přes parent záznam (např. `economy_codebooks_fiscal_months`)
  mají v JSONC definici `"hideFromNavigation": true` — nezobrazují se ani v hlavním
  sidebaru, ani v Nastavení

### Frontend — Vzhledy (themes)

- Tři režimy: Shipard (`light`) / Tmavý (`dark`) / Vlastní (`custom`);
  `auto` zanikl (migrace na follow)
- Custom barví **jen sidebar** přes runtime inline tokeny na `<html>`
  (`deriveSidebarTokens()` v `utils/themeColor.js`, OKLab/OKLCH) —
  solid barva nebo vertikální gradient + opacity mix k bázi; tělo
  stránky se nebarví nikdy — chrání doc-state systém
- **Dvouúrovňový (Fáze 4):** efektivní vzhled = `follow ? (DS default ??
  Shipard) : user override`. **DS default** = `app.theme` (scope ds,
  Nastavení aplikace → Aplikace, edituje `DsThemeField` přes Uložit), na
  klienta přes `appInfo` → `themeStore.setDsDefault()`. **User
  `account.theme`** (scope user) nese `follow`: `{follow:true}` = sleduj
  DS default, `{follow:false, mode, custom}` = override; legacy bez follow
  = override; nový uživatel/absence = follow. Přepínač „Vlastní vzhled"
  v `ThemeField`. **Dropdown vzhledu v patce sidebaru zanikl** — vzhled je
  nastavení, panel otevírá `ThemeField`
- Persistence: **server je zdroj pravdy** (per-user `account.theme`,
  DS-wide `app.theme`), localStorage = **anti-flash cache** (per-DS klíč
  v dev): override cache `shpd_theme(_custom/_tokens)`, DS default cache
  `shpd_ds_theme(_tokens)`. Po loginu `accountPrefs.load()` + `appInfo.load()`
  sesynchronizují server → store + cache; změny z panelu/stránky Základní
  píší zpět na server (`themeStore.setMode/setCustom/setFollow` přes
  `api/account.js`). **Čtyři synchronizovaná místa** localStorage:
  `theme.svelte.js`, `index.html` bootstrap, `api/config.js`, DS cache
  klíče `shpd_ds_theme*`
- Sdílené komponenty: `ThemeModeSegments` (segmented control),
  `ThemeSwatches` (controlled editor — používá ThemePanel i DsThemeField)
- Detaily: `docs/design-system.md` (sekce 9), `docs/frontend.md` (sekce 11),
  `docs/app-settings.md` (sekce 8)

### Frontend — Vícejazyčnost

- Language store: `frontend/src/stores/language.svelte.js` (mode `cs` / `en` / `auto`)
- Slovníky: `frontend/src/i18n/{cs,en}.js` — ploché objekty, tečková notace klíčů
- Helper `t(key, params?)` přes ICU MessageFormat (`intl-messageformat`), import z `i18n/index.js`
- Volba per-zařízení (localStorage `shpd_language`), `setMode()` reloadne stránku
- Anti-flash bootstrap v `frontend/index.html` nastavuje `<html lang>` před prvním renderem
- Backend dostává volbu přes `Accept-Language` header v `api/client.js`
- Lint: `cd frontend && npm run check:i18n` — kontroluje paritu klíčů cs ↔ en
- Mapování chybových kódů: `frontend/src/i18n/errors.js` `translateError(error)` přeloží `error.code` přes klíče `error.<CODE>`, fallback na server `error.message`

### Backend — Vícejazyčnost server-driven labels

Lokalizace UI textů, které generuje backend (toolbar tlačítka, taby formulářů, taby detailu vieweru), jde **přes cfgItems v jsonc, ne přes hardcoded stringy v PHP**:

- **Toolbar default** (`Add`/`Open`): `core.system.viewerDefaults.toolbarActions` — `TableViewer::getToolbarActions()` z cfgItem; module overrides v `core.mail.viewerDefaults` apod.
- **AutoFormBuilder General tab**: `core.system.formDefaults.generalTabLabel`
- **Detail taby vieweru**: `*.viewerDetailLabels.tabs.*` per-modul (`core.system` má sdílený `overview`, ostatní moduly mají specifické klíče). Helper `TableViewer::detailTabLabel()` / `defaultOverviewLabel()`
- **JSONC form titulky/taby**: `JsoncFormLoader::load($lang)` aplikuje `ConfigLocalizer::localize()` rekurzivně na `:cs`/`:en` varianty v `title`, `titleNew`, `tabs[].label`, `elements[].label` (separátory)
- **Fallback při chybějícím compiled configu**: anglický řetězec přímo v PHP — lokalizace funguje degradovaně, ne crash

`DataSourceConfig::getDefaultLanguage()` čte volitelné pole `defaultLanguage` z `config/main.json` (default `'en'`); `resolveLanguage()` v `public/index.php` ho použije jako fallback když chybí `Accept-Language`.

Po přidání nové cfgItem registrace v `module.jsonc` je nutný **`vendor/bin/shpd-ds ds-upgrade`** v dev DS, aby se cfgItem dostala do `compiled.{cs,en}.json`.

## Příkazy pro vývoj

```bash
composer install
vendor/bin/phpunit              # všechny testy musí projít
php bin/shpd-server version     # → Shipard v0.1.0
php bin/shpd-server help
php bin/shpd-ds version         # vyžaduje CWD s config/main.json
```

## Testování

- Testy v `tests/Unit/`, zrcadlí strukturu `src/`
- DB testy: mockování přes reflexi nebo subclassing (viz `TestableDsCreateCommand`)
- void metody v mocku: jen `->method('foo')` bez `willReturn`
- JSONC parser: testovat komentáře v řetězcích, trailing čárky
- I18n: testovat fallback chain (cs → en → holé pole)

**Sekvence po dokončení tasku:**

1. `php -l` na změněných PHP souborech
2. `vendor/bin/phpunit --filter 'ClassName'` cíleně, pak celá sada
3. `cd frontend && npm run build` — pokud se měnil frontend
4. **Aktualizovat `**Stav:**` v task filu** + `python3 scripts/tasks-index.py`

## Otevřené úkoly

Směr a priority řeší **[`docs/roadmap.md`](docs/roadmap.md)** — tady už se seznam nedrží,
rozcházel se se skutečností. Stav jednotlivých zadání je v `tasks/README.md`
(generovaný souhrn) a v hlavičce `**Stav:**` každého task filu.
