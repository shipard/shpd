# Došlá pošta: předzpracování Fáze 2 — render těla do PDF

**Issue:** shipard/shpd#33 (Fáze 2; render služba #34 je hotová)
**Stav:** hotovo — kód, testy, dev i alfa E2E, ruční proklik UI (2026-08-29, 6 commitů + checklist); nezávislý Bolt E2E z Fáze 1 sleduje `tasks/mail-preprocess.md`

## Cíl

Dokončit předzpracování došlé pošty o render HTML do PDF: nová akce
`renderBodyToPdf`, parametr `renderIfHtml` u `fetchLinkedDocument`,
aktivace systémových pravidel Apple/Google (dosud archivovaná) a
mechanismus, kterým provisioner umí fáze-2 pravidla aktivovat na DS,
kde už `ds-upgrade` běžel.

## Rozhodnutí (potvrzena 29. 8. 2026 v chatu, doplňují D1–D13 z issue)

- **D14** Aktivace fáze-2 pravidel: nový sloupec `system_phase` na
  `core_mail_preprocess_rules`. Provisioner odarchivuje pravidlo jen
  když je archivované ∧ `origin=system` ∧ `system_phase > 1` ∧ katalog
  říká fázi ≤ 1. Uživatelem archivované živé pravidlo má
  `system_phase = 1` → nekřísí se, princip D2 platí dál.
- **D15** Re-kotvení Apple: `body_regex: Apple Distribution International`
  (právnická osoba na každé EU faktuře; ověřeno na reálných vzorcích
  z alfy — `no_reply@email.apple.com` v těle přímé pošty NENÍ, původní
  kotva by nematchla). Google beze změny kotvy (bez živých vzorků 2026).
- **D16** `renderBodyToPdf` v1: vstup jen `body_html` (prázdné = selhání
  akce), render profil `Untrusted`, bez assets — vzdálené obrázky se
  záměrně nenačtou (egress Chromia zakázán: žádné tracking pixely,
  deterministický výstup), `cid:` mapování v1 neřešíme. Idempotence dle
  `(ruleId, action)` — tělo je po intake neměnné, `sourceUrl` nedává smysl.
- **D17** `renderIfHtml` u fetch: opt-in parametr (default false); finální
  `text/html` se s ním pošle do renderu místo dnešního tvrdého selhání.
- **D18** `convertOfficeToPdf` zůstává rezervované (konverze příloh =
  renditions doména #34/D7, ne preprocess).

## Návaznost

- Fáze 1: `tasks/mail-preprocess.md` — schéma, runner, fetch akce,
  provisioner. Zde jen rozšíření, žádné změny chování zpráv bez
  matchujícího pravidla.
- Render služba: `RenderClient` + `RenderProfile::Untrusted` (#34) —
  degradace přes `RenderResult`, nikdy výjimka; nenakonfigurovaná služba
  = selhání akce = stav 40, zpráva vždy doteče do AI fronty.
- JSONC změny (`preprocessActions`, katalog) vyžadují config rebuild +
  `ds-upgrade`, jinak je `cfgItem()` nevidí.

## Před implementací přečti

- `tasks/mail-preprocess.md` (kontext Fáze 1 + odchylky provedení)
- `modules/core/mail/src/Preprocess/PreprocessRunner.php`,
  `PreprocessRulesProvisioner.php`, `PreprocessRunnerFactory.php`,
  `Action/FetchLinkedDocumentAction.php`
- `src/Core/Render/RenderClient.php`, `RenderProfile.php`,
  `RenderResult.php`, `PdfOptions.php`; `docs/render.md`
- `modules/core/mail/config/systemPreprocessRules.jsonc`,
  `preprocessActions.jsonc`
- `src/Command/DataSource/MailPreprocessCommand.php` (má `ServerConfig`)
- `modules/core/mail/docs/preprocess.md`

## Scope

### 1. Schéma (D14)

`modules/core/mail/module.jsonc`:

- `core_mail_preprocess_rules` + sloupec `system_phase` tinyint
  NOT NULL DEFAULT 1 — fáze, ve které pravidlo naposledy provisionoval
  systém; u uživatelských pravidel zůstává 1 a nic neznamená.
- Aktualizovat `modules/core/mail/tables/core_mail_preprocess_rules.md`.

Schéma jde v prvním commitu před kódem (ds-upgrade, žádný rollback).

### 2. Provisioner — aktivace fáze-2 pravidel (D14)

`PreprocessRulesProvisioner.php`:

- Katalog: `phase` volitelný int ≥ 1 (validace v `loadCatalog`).
- INSERT: zapisuje `system_phase` = fáze z katalogu (default 1);
  archivace při `phase > 1` beze změny.
- Nová větev **aktivace**: řádek existuje ∧ `docState = 70` ∧
  `origin = 'system'` ∧ `system_phase > 1` ∧ katalog `phase ≤ 1`
  → UPDATE obsahových polí + `system_phase = 1` + `docState = 40`,
  `docStateMain = 3`, `modified`. Nový bucket výsledku `activated`.
- Řádek ve stavu 40: obsahový update jako dnes; navíc dorovnat
  `system_phase` dle katalogu, když se liší (bez změny stavu).
- Ostatní stavy (koncept, smazáno, archiv se `system_phase = 1`)
  → skip beze změny, jako dnes.

### 3. Akce `renderBodyToPdf` (D16)

Nový `modules/core/mail/src/Preprocess/Action/RenderBodyToPdfAction.php`:

- `KEY = 'renderBodyToPdf'`; konstanta `HTML_MAX_BYTES = 2 * 1024 * 1024`.
- Konstruktor: `AttachmentService`, `RenderClient`.
- Postup `execute(message, ruleId, params)`:
  1. Idempotence: nesmazaná příloha s provenance
     `(generatedBy=preprocess, action=KEY, ruleId)` → success
     „already present" (vzor fetch akce, bez `sourceUrl`).
  2. `body_html` prázdné/NULL → failure „message has no HTML body".
  3. Délka nad `HTML_MAX_BYTES` → failure.
  4. `RenderClient::renderHtml($html, [], RenderProfile::Untrusted)` —
     `RenderResult` failure → `ActionResult::failure("render failed:
     {errorKind}: {note}")` (Unconfigured je jedno z provozních selhání).
  5. Uložení: tempnam + `AttachmentService::upload` (vzor fetch, včetně
     úklidu tmp), název souboru ze `subject` (sanitizace zakázaných
     znaků jako `fileNameFor`, trim, max 150, vynucené `.pdf`; prázdný
     subject → `message-body.pdf`).
  6. `mergeMetadata`: `{generatedBy: "preprocess", ruleId, action,
     bodySha256: sha256(body_html), renderedAt}`.
- Sdílené kusy s fetch akcí (sanitizace názvu) vytáhnout do statické
  utility, nekopírovat.

### 4. Fetch — parametr `renderIfHtml` (D17)

`Action/FetchLinkedDocumentAction.php`:

- Konstruktor + `?RenderClient` (nullable kvůli stávajícím testům).
- Param `renderIfHtml` bool, default false.
- Ve `fetch()`: finální `text/html` s `renderIfHtml = false` → dnešní
  selhání (upravit poznámku — už ne „Phase 2"). S `renderIfHtml = true`:
  neprázdné tělo do `HTML_MAX_BYTES` → render `Untrusted`; selhání
  renderu = poznámka kandidáta a pokračuje se dalším kandidátem
  (konzistentní se selháním fetch), úspěch → uložení PDF.
- Provenance u renderovaného výsledku navíc `rendered: true`
  (+ `finalUrl`, `fetchedAt` jako dnes). `fileNameFor` už `.pdf`
  vynucuje — beze změny.
- Kontroly allowlist/regex/size cap beze změny, render je až za nimi.

### 5. Wiring

`PreprocessRunnerFactory`:

- `create(...)` + parametr `ServerConfig` (volající
  `MailPreprocessCommand` ho má) → `RenderClient::fromServerConfig`.
- `defaultActions(...)` + `RenderClient`: registrace
  `RenderBodyToPdfAction`, předání klienta do fetch akce. Testovací
  seam: `RenderClient` s injektovaným fake enginem (podporuje to už #34).

### 6. Konfigurace a katalog (D15, D18)

- `config/preprocessActions.jsonc`: `renderBodyToPdf` → `phase: 1`
  (validace pravidel ho začne pouštět), popis bez odkazu na Fázi 2;
  `convertOfficeToPdf` zůstává `phase: 2` (D18).
- `config/systemPreprocessRules.jsonc`:
  - `apple-invoice-body`: odebrat `phase`, `body_regex:
    "Apple Distribution International"` (D15).
  - `google-play-order`: odebrat `phase`, kotva beze změny.
  - Aktualizovat úvodní komentář (aktivace přes `system_phase`).

### 7. UI

Bez nových obrazovek. Popisky akcí jsou v `preprocessActions.jsonc`
(úprava popisu = `npm run check:i18n`). Badge a označení příloh
z Fáze 1 fungují beze změny (provenance formát je stejný).

## Testy

PHPUnit s úzkými `--filter`, `timeout_sec: 120`.

- **Provisioner:** INSERT fáze-2 pravidla zapíše `system_phase = 2` +
  archiv; flip katalogu na fázi 1 → `activated` (stav 40, obsah dle
  katalogu, `system_phase = 1`); ručně archivované pravidlo se
  `system_phase = 1` se nekřísí; opakovaný běh po aktivaci `unchanged`;
  řádek ve 40 s odlišným `system_phase` se dorovná.
- **RenderBodyToPdfAction** (fake `RenderClient`/engine): prázdné tělo,
  size cap, render failure → failure s poznámkou; úspěch → příloha
  s provenance + název ze subjectu; idempotence (druhý běh skip);
  Unconfigured klient → failure, žádná výjimka.
- **Fetch `renderIfHtml`:** finální HTML bez flagu → selhání (nová
  poznámka); s flagem render OK → PDF příloha s `rendered: true`;
  render failure → poznámka kandidáta; finální PDF → render se nevolá;
  prázdné HTML tělo → selhání.
- **Runner/wiring:** plán s `renderBodyToPdf` se vykoná přes registry;
  selhání akce → stav 40 a zpráva projde gate fronty (rozšíření
  stávajícího testu).
- **Validace pravidla:** pravidlo s akcí `renderBodyToPdf` projde
  dokumentovou validací po flipu fáze.

## Commit strategie

1. Schéma: `system_phase` + docs tabulky
2. Provisioner: aktivace fáze-2 pravidel (+ testy)
3. Akce `renderBodyToPdf` + wiring + flip `preprocessActions` (+ testy)
4. Fetch `renderIfHtml` (+ testy)
5. Katalog: aktivace Apple/Google + re-kotvení Apple (+ testy)
6. Dokumentace

Commity referencují #33.

## Odchylky provedení od zadání

- **Validace akcí** nejde přes `phase` v cfgItem `preprocessActions`, ale
  přes `PreprocessRuleDocument::IMPLEMENTED_ACTIONS` — `renderBodyToPdf`
  tam přidán; `convertOfficeToPdf` dál odmítán (D18). Flip `phase: 1`
  v JSONC je jen popisný.
- **D16a UTF-8 hlavička:** tělo bez `<meta charset>` se před renderem
  obalí `<meta charset="utf-8">` (do `<head>`, resp. celým dokumentem).
  Chromium by u souboru bez deklarace hádal kódování a rozbil diakritiku;
  ověřeno na dev DS (PDF s českými znaky v pořádku).
- **Sdílená utilita** je třída `Action/GeneratedAttachments` (provenance
  lookup, uložení s úklidem temp, sanitizace názvu), ne jen statická
  sanitizace — fetch akce přepojena, chování beze změny. Strop délky
  názvu teď přípona `.pdf` nikdy neodřízne.
- **`renderIfHtml` bez render klienta** (starší wiring) = selhání
  s poznámkou; `RenderClient` je 4. nullable parametr fetch akce.
- **DS z Fáze 1** (Apple/Google založené před sloupcem `system_phase`):
  mají archivované řádky se `system_phase = 1` — od uživatelem
  archivovaných nerozlišitelné, D14 je správně nechá být. Jednorázový
  SQL backfill (`system_phase = 2` pro archivované systémové bez zásahů)
  před `ds-upgrade`; postup v `modules/core/mail/docs/preprocess.md`
  → Systémový katalog. Na dev DS 4l3j proveden.
- `MailPreprocessCommand` načítá `ServerConfig` jednou
  (`loadServerConfig()`), sdílí ho resolver, log i render klient.

## Dokumentace k aktualizaci

- `modules/core/mail/docs/preprocess.md` — akce `renderBodyToPdf`,
  `renderIfHtml`, aktivace fáze-2 pravidel (`system_phase`), poznámka
  o záměrně zablokovaných vzdálených obrázcích
- `modules/core/mail/tables/core_mail_preprocess_rules.md` — `system_phase`
- `docs/render.md` — preprocess mezi konzumenty služby
- README modulu — zmínka

## Hotovo když

- [x] Testy zelené, `npm run check:i18n`
- [x] Dev DS: `ds-upgrade` aktivuje dříve archivovaná fáze-2 pravidla
      (`[ACTIVATE]` ×2 po SQL backfillu `system_phase`, viz Odchylky);
      opakovaný běh `[OK]`; archivované se `system_phase = 1` provisioner
      nechává (unit + první běh na 4l3j před backfillem = `[SKIP]`)
- [x] Zpráva s HTML tělem a matchem Apple pravidla na dev DS (4l3j,
      zpráva id 54 založená přímým INSERTem, `mail-preprocess --force`):
      PDF příloha s provenance, diakritika OK, tracking pixel nenačten,
      `isdoc: none`, stav 30, druhý `--force` smazal a přegeneroval;
      ruční proklik v prohlížeči (badge „Vygenerováno" v detailu
      zprávy) OK
- [x] Alfa — prerekvizity nasazení: render sekce v server configu +
      síťová dostupnost render služby (`doctor`: responding at
      `http://10.199.6.210:3000`); nasazeno 2026-08-29, `ds-upgrade`
      založil pravidla rovnou aktivní na všech 4 DS (Fáze 1 na alfě
      nikdy neběžela → backfill nebyl potřeba)
- [x] Alfa E2E (2026-08-29): `mail-preprocess --force` nad reálnými Apple
      fakturami `MSG-20260331-0003` (qrce, id 18236 → příloha 14847)
      a `MSG-20260327-0003` (dtje, id 75732 → příloha 62431) — stav 30,
      PDF 1 strana s českou diakritikou, provenance včetně `bodySha256`,
      `preprocess_log` kompletní, gate fronty průchozí
- [ ] Bolt E2E z Fáze 1 zůstává otevřený bod (nezávislý na Fázi 2)
