# Task: Viewery a formuláře pro mailové konfigurační tabulky

**Stav:** hotovo

## Status / cíl

Vytvořit plnohodnotné **viewery** (seznam + detail) a **editační formuláře**
pro tři konfigurační tabulky modulu `core.mail`, dnes přesunuté do Nastavení
aplikace (sekce Ostatní → Pošta) jako generické `type:"table"` položky:

- **Schránky** — `core_mail_mailboxes`
- **AI backendy** — `core_mail_ai_backends`
- **AI profily** — `core_mail_ai_profiles`

Po dokončení se tyto tři položky v Nastavení otevřou v plnohodnotném vieweru
(ne generickém TableBrowseru) s detailem a fungující editací (Nový / Otevřít).

## Závislosti

- Navazuje na `tasks/settings-subsections.md` — ten přesouvá tyto tabulky do
  Nastavení jako `type:"table"`. Tento task mění jejich `settingsItems`
  z `table` na `viewer`. **Pořadí:** ideálně až po dokončení
  settings-subsections, ale není tvrdá závislost (lze i paralelně, jen se
  pak slučují změny v `module.jsonc` mailu).

## Potvrzená designová rozhodnutí (Anna)

1. **Rozsah** — jen tyto 3 konfigurační tabulky. Ostatní přesunuté tabulky
   (Idempotency klíče, Rezervace analýz, Analýzy zpráv, a Osoby:
   Kontakty/Bankovní účty/Adresy) **zůstávají** jako generický `type:"table"`.
2. **Viewery + formuláře** — kompletní řešení (ne read-only).
3. **AI backend — API klíč NENÍ ve formuláři.** Klíč (`api_key`,
   `encrypted_text`) se nastavuje výhradně přes existující CLI
   `ai-analyzer-set-key`. Důvod: `AIBackendDocument::beforeSave()` vyžaduje
   injektovaný `DsSecretCipher` přes `setSecretCipher()`, který se ve
   form-save cestě (`FormController`) **nevolá** — jen v `AnalysisController`
   a v CLI. Form s polem `api_key` by při uložení klíče hodil
   `RuntimeException`. Form proto pole `api_key` vynechává úplně.
4. **API klíč v detailu vieweru** — jen „nastaven / nenastaven", nikdy
   hodnota. `selectRows()` ani detail nikdy neselektují sloupec `api_key`
   jako hodnotu (jen `api_key IS NOT NULL` pro boolean příznak).
5. **AI profily — název napojeného backendu v seznamu přes JOIN**
   na `core_mail_ai_backends.name`.

## Klíčová zjištění (architektura)

- **Formuláře nepotřebují PHP třídu ani registraci v `forms[]`.**
  `FormController::buildFormDefinition()` řeší form v pořadí: (1) registrovaná
  table-specific PHP form třída → (2) **JSONC soubor `forms/{table}.jsonc`
  podle konvence názvu** → (3) auto-generování z metadat. Stačí tedy vytvořit
  JSONC soubory — najdou se automaticky podle názvu tabulky.
- **Document třídy už existují** a jsou registrované v `documentClasses[]`:
  `MailboxDocument`, `AIBackendDocument`, `AIProfileDocument`. Řeší validaci,
  audit pole, šifrování, invariant "max. jeden is_default". Formuláře se na ně
  napojí automaticky přes save pipeline — **žádná změna document tříd**.
- **Vzor vieweru:** `modules/core/units/src/UnitsViewer.php` (TableViewer
  subclass, ~190 ř., docStates viewGroup taby, renderRow, renderDetail).
- **Vzor formuláře:** `modules/economy/codebooks/forms/economy_codebooks_cash_desks.jsonc`
  (tabs → sections → columns → elements, `separator` s labelem).
- Všechny 3 tabulky mají `docStates` s cfgItem `core.system.docStatesArchive`
  → viewery nastaví `protected ?string $docStatesCfgItem = 'core.system.docStatesArchive';`
  a získají viewGroup taby (aktivní / archiv / koš) automaticky.

## Rozsah

### V rozsahu
- 3 viewery (PHP): `MailboxesViewer`, `AIBackendsViewer`, `AIProfilesViewer`
  v `modules/core/mail/src/`.
- 3 formuláře (JSONC): `core_mail_mailboxes.jsonc`, `core_mail_ai_backends.jsonc`,
  `core_mail_ai_profiles.jsonc` v `modules/core/mail/forms/`.
- Registrace viewerů v `modules/core/mail/module.jsonc` → `viewers[]`.
- Změna `settingsItems` Schránek/AI backendů/AI profilů z `table` na `viewer`.
- Detail-tab labely do `core.mail.viewerDetailLabels` (nový klíč „overview"
  nebo per-viewer klíče).

### Mimo rozsah
- Editace `api_key` přes formulář (řeší CLI; viz rozhodnutí #3).
- Rozšíření form-save cesty o injekci `DsSecretCipher`.
- Viewery pro ostatní přesunuté tabulky (Idempotency, Rezervace, Analýzy,
  Osoby:*) — zůstávají generické `type:"table"`.
- Jakákoli změna document tříd, šifrovací infrastruktury, secrets adresáře.
- Změny frontendu (Viewer.svelte renderuje strukturu z TableViewer beze změn).

## Kroky

### 1. MailboxesViewer

Soubor: `modules/core/mail/src/MailboxesViewer.php`
Namespace: `Shipard\Module\Core\Mail`, `extends TableViewer`.
`protected ?string $docStatesCfgItem = 'core.system.docStatesArchive';`

**selectRows()** — SELECT `id, mailbox_id, name, email_address,
default_primary_type, is_default, docState, docStateMain`. ViewGroup filtr
(viz UnitsViewer vzor), search přes `name, email_address, mailbox_id`.
ORDER BY `docStateMain ASC, is_default DESC, name ASC, id ASC`. Pagination
přes `buildPaginationLimit()`.

**renderRow()**:
- `t1` = `name`
- `i1` = badge „výchozí" (class `success`) když `is_default`
- `t2` = `email_address` (class `muted`)
- `t3` = `mailbox_id`; pokud `default_primary_type` není null, připojit
  lokalizovaný název přes cfgItem `core.mail.primaryTypes`
  (`$this->config?->cfgItem('core.mail.primaryTypes')[$key]['name']`)
- `stateStyle` z docState (viz UnitsViewer `resolveStateStyle()`)

**renderDetail()** — properties tabs:
- skupina „Identifikace": Kód schránky, Název, E-mailová adresa, Popis
- skupina „Konfigurace": Výchozí primární typ (lokalizovaný), Výchozí schránka
  (Ano/Ne)
- skupina „Stav": Vytvořeno, Změněno (audit)

### 2. AIBackendsViewer

Soubor: `modules/core/mail/src/AIBackendsViewer.php`

**selectRows()** — SELECT `id, backend_id, name, provider, model, base_url,
max_tokens, temperature, is_default, is_active, docState, docStateMain,
(api_key IS NOT NULL AND api_key != '') AS has_api_key`.
> **api_key NIKDY neselektovat jako hodnotu** — jen boolean příznak
> `has_api_key`. Viz rozhodnutí #4.
Search přes `name, backend_id, model, provider`.
ORDER BY `docStateMain ASC, is_default DESC, name ASC, id ASC`.

**renderRow()**:
- `t1` = `name`
- `i1` = badges: „výchozí" (success) když `is_default`; „aktivní" (primary)
  když `is_active`, jinak „neaktivní" (muted)
- `t2` = `provider` + " / " + `model` (muted)
- `t3` = `backend_id`

**renderDetail()**:
- skupina „Identifikace": Kód backendu, Název
- skupina „Provider": Provider, Model, Base URL
- skupina „Přístup": **API klíč: „nastaven" / „nenastaven"** (z `has_api_key`,
  nikdy hodnota)
- skupina „Ladění": Max. tokenů, Teplota
- skupina „Příznaky": Výchozí backend (Ano/Ne), Aktivní (Ano/Ne)
- skupina „Stav": audit

### 3. AIProfilesViewer

Soubor: `modules/core/mail/src/AIProfilesViewer.php`

**selectRows()** — SELECT s **JOIN** na backend:
```sql
SELECT p.`id`, p.`profile_id`, p.`name`, p.`language`, p.`prompt_version`,
       p.`is_default`, p.`is_active`, p.`docState`, p.`docStateMain`,
       b.`name` AS backend_name
FROM `core_mail_ai_profiles` p
LEFT JOIN `core_mail_ai_backends` b ON b.`id` = p.`backend`
```
> Pozor: `$this->table` je „core_mail_ai_profiles"; alias `p`. ViewGroup
> filtr a search musí kvalifikovat sloupce aliasem `p.` (např.
> `p.\`docState\``). `buildViewGroupFilter()` vrací nekvalifikovaný
> `\`docState\`` — buď upravit dotaz tak, aby alias nebyl potřeba (bez JOIN
> v základu a backend_name doplnit zvlášť), NEBO vložit JOIN a filtr ručně
> složit s `p.` prefixem. Doporučení: složit WHERE ručně s `p.` prefixem
> (search přes `p.name, p.profile_id`).
Search přes `p.name, p.profile_id`.
ORDER BY `p.docStateMain ASC, p.is_default DESC, p.name ASC, p.id ASC`.

**renderRow()**:
- `t1` = `name`
- `i1` = badges „výchozí" / „aktivní" (jako AIBackendsViewer)
- `t2` = `backend_name` (muted) + " · " + `language`
- `t3` = `profile_id` + " · " + `prompt_version`

**renderDetail()**:
- skupina „Identifikace": Kód profilu, Název, Backend (`backend_name`)
- skupina „Záběr": Podporované typy dokumentů (`supported_doc_types` — text,
  zobrazit jak je), Jazyk
- skupina „Prompt": Verze promptu (NE celá šablona — `prompt_template` je
  longtext, do properties nepatří; max zmínit délku nebo vynechat)
- skupina „Příznaky": Výchozí profil, Aktivní
- skupina „Stav": audit
> `output_schema`, `confidence_thresholds`, `prompt_template` (longtext/text)
> do detailu **nedávat** jako properties — jsou to dlouhé strojové hodnoty.
> Lze později přidat samostatný tab „Prompt"/„Schéma" typu `html` s
> `<pre>`, ale to je mimo rozsah tohoto tasku.

### 4. Editační formuláře (JSONC)

Soubory v `modules/core/mail/forms/`. Vzor:
`economy_codebooks_cash_desks.jsonc`. Najdou se automaticky podle názvu
tabulky — **žádná registrace v `forms[]` netřeba**.

#### 4a. `core_mail_mailboxes.jsonc`
title „Schránka" / titleNew „Nová schránka". Jeden tab, sloupce:
`mailbox_id` (required), `name` (required), `email_address` (required),
`description`, separator „Konfigurace", `default_primary_type` (select —
auto z cfgItem), `is_default`.
> Audit pole (created/modified/created_by) a docState do formu nepatří —
> řeší document/save pipeline.

#### 4b. `core_mail_ai_backends.jsonc`
title „AI backend" / titleNew „Nový AI backend". Sloupce:
`backend_id` (required), `name` (required), separator „Provider",
`provider` (required), `model` (required), `base_url`, separator „Ladění",
`max_tokens`, `temperature`, separator „Příznaky", `is_default`, `is_active`.
> **POLE `api_key` VYNECHAT** (viz rozhodnutí #3). Pokud bude potřeba uživatele
> informovat, lze přidat `separator` s labelem „API klíč se nastavuje přes CLI"
> — volitelné.

#### 4c. `core_mail_ai_profiles.jsonc`
title „AI profil" / titleNew „Nový AI profil". Sloupce:
`profile_id` (required), `name` (required), `backend` (required — FK,
select/lookup na backendy), separator „Záběr", `supported_doc_types`
(textarea), `language` (required), separator „Prompt", `prompt_version`,
`prompt_template` (textarea), separator „Výstup", `output_schema` (textarea),
`confidence_thresholds` (textarea), separator „Příznaky", `is_default`,
`is_active`.
> `backend` je FK na `core_mail_ai_backends` — pokud existuje lookup nebo
> stačí select, použít `{"type": "select", "column": "backend"}` nebo lookup
> dle konvence v projektu. Ověřit, zda mail modul má lookup pro backendy;
> pokud ne, select s auto-resolved options z reference (jako u jiných FK).

### 5. Registrace viewerů — `modules/core/mail/module.jsonc`

Do `viewers[]` přidat tři záznamy (vedle stávajícího `core.mail.incoming`):
```jsonc
{
    "id": "core.mail.mailboxes",
    "name": "Mailboxes", "name:cs": "Schránky", "name:en": "Mailboxes",
    "icon": "mailbox",
    "table": "core_mail_mailboxes",
    "class": "Shipard\\Module\\Core\\Mail\\MailboxesViewer"
},
{
    "id": "core.mail.aiBackends",
    "name": "AI backends", "name:cs": "AI backendy", "name:en": "AI backends",
    "icon": "robot",
    "table": "core_mail_ai_backends",
    "class": "Shipard\\Module\\Core\\Mail\\AIBackendsViewer"
},
{
    "id": "core.mail.aiProfiles",
    "name": "AI profiles", "name:cs": "AI profily", "name:en": "AI profiles",
    "icon": "robot",
    "table": "core_mail_ai_profiles",
    "class": "Shipard\\Module\\Core\\Mail\\AIProfilesViewer"
}
```
> Ikony `mailbox` / `robot` — ověřit v `frontend/src/icons.js` (`resolveIcon`).
> Pokud nejsou, použít existující sémantickou ikonu (např. `mail`, `settings`)
> nebo vynechat.

### 6. settingsItems — z `table` na `viewer`

V `modules/core/mail/module.jsonc` upravit tři řádky `settingsItems`
(po settings-subsections tasku tam jsou jako `table`):
```jsonc
{ "viewer": "core.mail.mailboxes",  "section": "other", "subsection": "other.mail", "order": 10 },
{ "viewer": "core.mail.aiBackends", "section": "other", "subsection": "other.mail", "order": 40 },
{ "viewer": "core.mail.aiProfiles", "section": "other", "subsection": "other.mail", "order": 50 },
```
> Ostatní mailové settingsItems (Analýzy zpráv `core_mail_message_analyses`,
> Idempotency `core_mail_incoming_idempotency`, Rezervace
> `core_mail_analysis_claims`) **zůstávají jako `table`** — beze změny.
> `core_mail_incoming_messages` má viewer `core.mail.incoming` a do settings
> nepatří (zůstává v hlavní navigaci).

### 7. Detail-tab labely

Do `modules/core/mail/config/viewerDetailLabels.jsonc` přidat klíč „overview"
(dnes tam mail-specific klíče content/attachments/… jsou, ale „overview" chybí):
```jsonc
"overview": { "name": "Overview", "name:cs": "Přehled", "name:en": "Overview" }
```
Viewery v renderDetail() použijí `$this->defaultOverviewLabel()` (čte z
`core.system.viewerDetailLabels`), takže tento krok je volitelný — ověřit,
zda `core.system.viewerDetailLabels` má „overview". Pokud ano, krok 7 přeskočit.

## Akceptační kritéria

1. V Nastavení → Ostatní → Pošta se Schránky, AI backendy, AI profily
   otevřou v plnohodnotném vieweru (seznam + detail), ne v TableBrowseru.
2. Seznam zobrazuje správné t1/i1/t2/t3 dle návrhu; badge výchozí/aktivní.
3. ViewGroup taby (aktivní / archiv / koš) fungují.
4. Search funguje nad uvedenými sloupci.
5. Detail AI backendu ukazuje „API klíč: nastaven/nenastaven", **nikdy
   hodnotu**; sloupec `api_key` se v žádném SQL neselektuje jako hodnota.
6. AI profily v seznamu ukazují název napojeného backendu (JOIN).
7. „Nový" otevře prázdný formulář; „Otevřít" existující záznam; uložení
   projde přes příslušnou document třídu (validace povinných polí funguje).
8. Formulář AI backendu **nemá pole API klíč**.
9. `php -l` čistý na 3 viewerech; `npm run build` projde; cílené testy
   projdou (pre-existing 37 Opis failures ignorovat).

## Verifikace

```bash
# PHP syntaxe
for f in MailboxesViewer AIBackendsViewer AIProfilesViewer; do
  php -l modules/core/mail/src/$f.php
done

# JSONC formuláře — vizuální kontrola + běh aplikace
# (form se najde podle názvu tabulky)

# Cílené testy
vendor/bin/phpunit --filter 'Viewer|Mail|Form'

# Frontend build
cd frontend && npm run build 2>&1 | tail -10

# Ruční ověření:
#   GET /_ui/viewer/core.mail.mailboxes/meta   → toolbar, viewGroups, filters
#   GET /_ui/viewer/core.mail.aiBackends/rows  → ověř, že api_key NENÍ v datech
#   GET /_ui/viewer/core.mail.aiProfiles/rows  → ověř backend_name z JOIN
#   Otevři Nastavení → Pošta → každý viewer, vyzkoušej Nový/Otevřít/uložit.
```

## Doporučené pořadí commitů

1. `feat(mail): add viewers for mailboxes, AI backends and AI profiles`
   — kroky 1–3, 5, 7 (viewery + registrace). Po tomto commitu se seznamy
   zobrazí ve vieweru, ale settingsItems ještě míří na `table` → ověřit přes
   přímý `GET /_ui/viewer/...`.
2. `feat(mail): add edit forms for mail config tables`
   — krok 4 (JSONC formuláře). Po tomto commitu funguje Nový/Otevřít.
3. `feat(settings): switch mail config items to viewers in settings nav`
   — krok 6 (settingsItems table→viewer). Po tomto commitu se v Nastavení
   otevírají viewery.

Co-Authored-By: Claude

## Mimo rozsah (pro pozdější tasky)

- Editace API klíče přes formulář (rozšíření save pipeline o `DsSecretCipher`
  injekci). Dnes přes CLI `ai-analyzer-set-key`.
- Detail tab „Prompt"/„Schéma" (type `html`, `<pre>`) pro dlouhé hodnoty
  AI profilu (`prompt_template`, `output_schema`, `confidence_thresholds`).
- Viewery pro ostatní přesunuté tabulky (Idempotency, Rezervace, Analýzy).
- `docs/` aktualizace — registrace nových viewerů, pokud je vedená nějaká
  souhrnná tabulka viewerů.
