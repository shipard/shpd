# Došlá pošta: předzpracování Fáze 2 — render těla do PDF

**Issue:** shipard/shpd#33 (Fáze 2; render služba #34 je hotová)
**Stav:** k implementaci

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

## Dokumentace k aktualizaci

- `modules/core/mail/docs/preprocess.md` — akce `renderBodyToPdf`,
  `renderIfHtml`, aktivace fáze-2 pravidel (`system_phase`), poznámka
  o záměrně zablokovaných vzdálených obrázcích
- `modules/core/mail/tables/core_mail_preprocess_rules.md` — `system_phase`
- `docs/render.md` — preprocess mezi konzumenty služby
- README modulu — zmínka

## Hotovo když

- [ ] Testy zelené, `npm run check:i18n`
- [ ] Dev DS: config rebuild + `ds-upgrade` aktivuje dříve archivovaná
      fáze-2 pravidla (dev DS 4l3j je má z Fáze 1 archivovaná);
      opakovaný běh `unchanged`; ručně archivované pravidlo zůstává
      archivované
- [ ] Zpráva s HTML tělem a matchem Apple pravidla na dev DS: PDF
      příloha s provenance, viditelná v detailu zprávy, ISDOC krok
      proběhl, zpráva prošla gate fronty
- [ ] Alfa — prerekvizity nasazení (mimo tento PRD, mutace per-akce):
      render sekce v server config alfy + síťová dostupnost
      `shpd-render` (10.199.6.211) z alfy — dnes nedostupné
- [ ] Alfa E2E: `mail-preprocess --force` nad reálnými Apple fakturami
      `MSG-20260331-0003` (qrce) a `MSG-20260327-0003` (dtje) → PDF
      příloha, zpráva projde frontou; výsledek čitelný v `preprocess_log`
- [ ] Bolt E2E z Fáze 1 zůstává otevřený bod (nezávislý na Fázi 2)
