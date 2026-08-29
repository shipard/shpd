# Došlá pošta: technické předzpracování zpráv před AI analýzou

**Issue:** shipard/shpd#33 (rendering služba: #34)
**Stav:** částečně — Fáze 1 hotová (2026-08-29, 6 commitů); Fáze 2 (`renderBodyToPdf`, `renderIfHtml`, aktivace Apple/Google) blokována #34

## Cíl

Zavést asynchronní stage „předzpracování" mezi intake došlé zprávy a AI
frontou. Řízeno pravidly (`core_mail_preprocess_rules`): stažení dokladu
z odkazu v těle zprávy, render HTML těla do PDF (Fáze 2). Výsledkem jsou
nové obsahové přílohy zprávy, které vidí uživatel, AI analyzer i Spisovna.

Motivace a reálné vzorky (jeden z DS na alfě): viz issue #33
— Bolt `MSG-20260817-0004` (faktura za odkazem, awstrack redirect), Apple
`MSG-20260817-0003` a Google Play `MSG-20260817-0005` (faktura v HTML těle,
klasifikace selhala / bez dokumentu).

## Návaznost

- Rozhodnutí D1–D13 potvrzena v designové diskuzi 17.–18. 8. 2026, plné
  znění v issue #33. Zde jen provedení.
- Rendering služba a HTML renditions příloh: #34 (samostatný PRD vznikne
  po review volby nástroje). Akce `renderBodyToPdf` a parametr
  `renderIfHtml` u fetch akce jsou Fáze 2 tohoto úkolu.
- Message-centric model: `tasks/mail-message-centric.md` — vygenerované
  přílohy jsou běžné obsahové přílohy zprávy, žádná zvláštní větev.
- Renditions ≠ preprocess: render HTML *příloh* do PDF sem nepatří (D7,
  #34). Preprocess vyrábí jen nové přílohy dle pravidel (D4).

## Fázování

- **Fáze 1 (tento PRD, implementovatelná hned):** tabulka pravidel +
  provisioning katalogu, osa `preprocess_state`, intake matcher + plán,
  CLI `mail-preprocess` + spawn + rescue sweep, akce `fetchLinkedDocument`
  (bez `renderIfHtml`), odklad ISDOC, gate AI fronty, viewer + form.
- **Fáze 2 (po #34):** akce `renderBodyToPdf`, parametr `renderIfHtml`
  u fetch, aktivace systémových pravidel Apple/Google (do té doby jsou
  v katalogu, ale archivovaná — viz níže).

## Scope — Fáze 1

### 1. Schéma

`modules/core/mail/module.jsonc`:

- Nová tabulka `core_mail_preprocess_rules` (vzor `core_mail_sender_rules`):
  - `rule_id` varchar — stabilní klíč pro upsert systémových pravidel,
    unikátní; u uživatelských generovaný
  - `origin` tinyint (1 system / 2 user)
  - match podmínky: `sender_email`, `sender_domain`, `subject_regex`,
    `body_regex` (všechny nullable; validace „aspoň jedna vyplněná" ve
    formu/dokumentu, D11)
  - `actions` longtext JSON — uspořádaný seznam `{action, ...params}` (D3)
  - hit statistiky: `hit_count`, `last_hit_at` (vzor sender rules)
  - doc states `docStatesArchive`; matchují jen ve stavu 40 (D2)
  - přidat do `keepOnReset` (D2)
- Sloupce na `core_mail_incoming_messages`:
  - `preprocess_state` tinyint NOT NULL DEFAULT 0
    (0 netýká se / 10 čeká / 20 běží / 30 hotovo / 40 hotovo s chybami, D9)
  - `preprocess_log` longtext NULL — JSON `{plan: [{ruleId, actions}],
    results: [{action, ok, note, attachmentId?}], attempts, startedAt,
    finishedAt}`
- Konfigurační enumy: `config/preprocessActions.jsonc`
  (`fetchLinkedDocument`, `renderBodyToPdf`, rezervace
  `convertOfficeToPdf`; render* označit jako Fáze 2),
  `config/preprocessStates.jsonc`. Registrace v `module.jsonc` (vzor
  `senderRuleDispositions`).
- Dokumentace tabulky: `modules/core/mail/tables/core_mail_preprocess_rules.md`.

Pozn.: `ds-upgrade` je migrační mechanismus, žádný rollback; schéma jde
v prvním commitu před kódem.

### 2. Systémový katalog pravidel + provisioning

- `modules/core/mail/config/systemPreprocessRules.jsonc` — katalog
  (v1: `bolt-invoice-link` živé; `apple-invoice-body`,
  `google-play-order` v katalogu s příznakem Fáze 2 → zakládají se
  rovnou archivovaná, aktivace až s `renderBodyToPdf`).
  Kotvení přes `body_regex` (vzorky jsou `Fwd:` forwardy, D11) —
  např. Bolt: `body_regex: invoice\.bolt\.eu`.
- `PreprocessRulesProvisioner` (vzor `AIAnalyzerProvisioner`): idempotentní
  upsert dle `rule_id` v `ds-upgrade`, **mimo `skipProvisioning` gate**
  (pravidla musí vznikat i na import-mode DS). Respektuje stav: archivované
  systémové pravidlo se nekřísí, aktualizuje se jen obsah pravidel ve
  stavu 40 (D2).

### 3. Intake — matcher a plán

`modules/core/mail/src/PreprocessRuleMatcher.php`:

- Vstup: sender_email, subject, body_html+body_plain. AND přes vyplněné
  podmínky pravidla, regexy case-insensitive; matchnout může víc pravidel,
  plán = sjednocení (pořadí dle pravidla, D1/D11).
- Výstup: plán `[{ruleId, actions[]}]` nebo null.

`src/Api/Controller/MailController.php::receiveIncoming`:

- Po sestavení zprávy (v tx): zavolat matcher; při matchi uložit
  `preprocess_state=10` + `preprocess_log.plan`; jinak default 0 —
  dnešní chování beze změny.
- Při `preprocess_state=10` **přeskočit** intake ISDOC větev (D10) —
  převezme ji runner.
- Post-commit: detached spawn `bin/shpd-ds mail-preprocess --message <id>`
  (D8). Spawn = fire-and-forget helper (proc_open, stdout/stderr do
  logu, žádné čekání); selhání spawnu jen zalogovat — zprávu dohledá
  sweep.
- `analysis_state` se počítá beze změny (osy jsou ortogonální, D9).

### 4. Gate AI fronty

`MailController` — `/queue` a výpočet `total_available`: přidat podmínku
`preprocess_state NOT IN (10, 20)` (D9). Zdokumentovat
v `docs/mail/api-contract.md`.

### 5. CLI runner

`src/Command/DataSource/MailPreprocessCommand.php` (`mail-preprocess`):

- `--message <id>`: claim přes
  `UPDATE ... SET preprocess_state=20, ... WHERE id=? AND preprocess_state=10`
  — 0 affected rows = tichý konec (prohraný závod / už hotovo).
- Vykonává **uložený plán** z `preprocess_log`, ne re-match (D12).
  Výsledek per akce do `preprocess_log.results`.
- Po akcích: pokus o ISDOC import (`IsdocImportService`) nad novými
  přílohami (D10), pak `preprocess_state=30`, při dílčím selhání 40.
  Selhání **nikdy neblokuje** — zpráva vždy doteče do AI fronty.
- `--force`: re-match dle aktuálních pravidel + smazání dříve
  vygenerovaných příloh dle provenance a přegenerování (D12). Funguje
  i na stavech 30/40 (ruční opakování) a na zprávách se stavem 0
  (ladění nového pravidla nad starou zprávou).
- `--sweep`: rescue — `state=10` starší než N minut (spawn selhal) a
  `state=20` starší než timeout (proces umřel) → reset na 10, inkrement
  `preprocess_log.attempts`, spawn; nad max pokusů (konst. 3) → stav 40.
- Registrace v `DsApplicationFactory` + řádek do `HelpCommand`.

Rescue sweep zapojit do stávajícího minutového cron slotu vedle
`mail-analysis-reap` (D8 — sweep je jen záchrana, ne primární spouštěč).

### 6. Akce `fetchLinkedDocument`

`modules/core/mail/src/Preprocess/FetchLinkedDocumentAction.php`:

- Parametry: `linkHrefRegex` (povinný), `allowedDomains` (povinný),
  `renderIfHtml` (rezervováno, Fáze 2 — ve Fázi 1 stažené HTML = selhání
  akce s poznámkou).
- Postup: extrakce kandidátních href z `body_html` → follow redirects
  s rozbalením tracking wrapperů (awstrack apod.) → `linkHrefRegex` a
  `allowedDomains` se vyhodnocují na **finální URL, kontrola po každém
  hopu** (D6, SSRF) → anonymní GET, globální konstanty: timeout, max
  velikost, content-type whitelist (v1: `application/pdf`) → uložení
  přes `AttachmentService` jako obsahová příloha zprávy.
- Provenance do `core_attachments_files.metadata`:
  `{generatedBy:"preprocess", ruleId, action, sourceUrl}` (D5).
  Idempotence: existuje-li nesmazaná příloha se shodným
  `(ruleId, action, sourceUrl)`, akce se přeskočí.
- Selhání (expirovaný odkaz, timeout, cizí doména) = provozní stav:
  zápis do results, žádná výjimka ven (D6).

### 7. UI

- `PreprocessRuleDocument`, `PreprocessRulesViewer` + form (rozsah = dnešní
  sender rules: seznam, detail, editace match polí a akcí jako JSON, D13).
  Registrace vieweru v `module.jsonc` (`section: other.mail`).
- `IncomingMessagesViewer`: badge stavu předzpracování v detailu
  (z `preprocess_state` + `preprocess_log`), vygenerované přílohy
  označit dle provenance metadat.
- Bez učícího handleru a dashboard karet (D13).

## Testy

- Matcher: AND sémantika, sender nepovinný / aspoň jedna podmínka,
  case-insensitive regexy, forward vzorek matchne přes `body_regex`,
  víc pravidel → sjednocený plán.
- Runner: claim race (dvojí spuštění → jedno tiše končí), plán se vykonává
  ze snapshotu (změna pravidla po intake nemění plán), selhání akce →
  stav 40 + zpráva projde gate fronty, ISDOC volán po akcích.
- Fetch: mock HTTP — redirect chain + allowlist kontrola per hop, odmítnutí
  cizí finální domény, size cap, content-type, idempotence dle provenance,
  `--force` smaže a přegeneruje.
- Gate fronty: `queue`/`total_available` nevydá zprávu ve stavech 10/20,
  vydá ve 30/40.
- Provisioner: idempotentní upsert, archivované systémové pravidlo se
  nekřísí, běží mimo `skipProvisioning`.
- PHPUnit s úzkými `--filter`, timeout 120 s.

## Commit strategie

1. Schéma: tabulka + sloupce + enumy + keepOnReset + docs tabulky
2. Matcher + intake integrace + gate fronty (+ testy)
3. CLI runner + spawn helper + sweep + HelpCommand (+ testy)
4. Akce fetchLinkedDocument + provenance/idempotence (+ testy)
5. Provisioner + systémový katalog (+ testy)
6. Viewer/form + badge v detailu zprávy

Commity referencují #33.

## Dokumentace k aktualizaci

- `modules/core/mail/docs/` — nová `preprocess.md` (koncept, stavy, pravidla,
  akce, provoz/sweep) + zmínka v README modulu
- `docs/mail/api-contract.md` — gate fronty
- `modules/core/mail/docs/ai-analysis.md` — pořadí ISDOC u předzpracování

## Odchylky provedení od zadání (Fáze 1)

- `origin` je `enumString` (`system`/`user`, cfgItem
  `preprocessRuleOrigins`) a `preprocess_state` je `enumInt` s cfgItem —
  vzor modulu (sender rules, `analysis_state`), labely do UI zdarma.
- Runner volá ISDOC import nad **všemi** obsahovými přílohami, ne jen
  nad vygenerovanými — intake větev byla pro zprávu s plánem přeskočena
  (D10), původní přílohy by jinak ISDOC nikdy neprošly.
- `mail-analysis-reap` v cron slotu `minute` chyběl (README ho slibovalo)
  — doplněn spolu s `mail-preprocess --sweep`; `CronCommand::SLOT_JOBS`
  umí položky s volbami.
- Allowlist domén se kontroluje na **finální** URL; per hop se kontroluje
  schéma a veřejná IP (SSRF). Tracking wrapper z cizí domény je průchozí
  jen jako 3xx — obsah z něj se nikdy nepřijme.
- `--force` odmítá zprávu s aktivním AI claimem (`analysis_state = 20`);
  `hit_count` počítá jen intake match.

## Hotovo když

- [x] Nová zpráva bez matchujícího pravidla se chová bit-přesně jako dnes
      (state 0, ISDOC při intake, fronta beze změny) — `IngestPreprocessTest`
- [ ] Bolt vzorek (`MSG-20260817-0004`-typ zprávy) na testovacím DS:
      pravidlo matchne, PDF faktura stažena jako příloha s provenance,
      zpráva doteče do AI fronty až po dokončení — **čeká na alfu**
      (na dev DS ověřen jen intake plán + spawn; fetch proti reálné Bolt
      URL vyžaduje živý odkaz ze vzorku)
- [x] Expirovaný/nefunkční odkaz: stav 40, zpráva ve frontě, výsledek
      čitelný v `preprocess_log` — unit (`PreprocessRunnerTest`,
      `FetchLinkedDocumentActionTest`)
- [x] `mail-preprocess --message` opakovaně = žádné duplikáty
      (idempotence dle provenance); `--force` přegeneruje
- [x] Sweep zvedne uměle zaseknutou zprávu (state 20) — unit; na dev DS
      `--sweep` bez nálezu OK
- [x] `ds-upgrade` na čistém i import-mode DS založí systémová pravidla;
      opakovaný běh nic neduplikuje; archivované se nekřísí — dev DS 4l3j
      + `DsUpgradeCommandTest` pod `skipProvisioning`
- [x] Testy zelené, `npm run check:i18n` (nové UI texty)
- [x] Dokumentace aktualizována (`modules/core/mail/docs/preprocess.md`,
      `docs/mail/api-contract.md`, `docs/cli.md`, `ai-analysis.md`, README)
