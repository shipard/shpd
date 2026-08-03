# Spisovna — Fáze 3: šum (sender rules, is_bulk, digest)

**Stav:** hotovo

## Kontext

Data z alfy potvrzují motivaci: drtivá většina analyzovaných zpráv končí
jako `other` (DS B 63/71, DS A 15/18) a každá dnes generuje úklidovou
kartu „Není faktura" — feed zaplavuje balast. Tato fáze přidává
**deterministické** zpracování šumu dle `docs/registry-mvp.md` §8 a D6/D7:
pravidla odesílatelů s učením ze zpětné vazby, hlavičkový signál `is_bulk`
a denní digest kartu s plnou vratností.

Autoritativní design je `docs/registry-mvp.md` §8 (outline) — tento PRD ho
rozvádí do implementační podoby; kde PRD upřesňuje, platí PRD.

**Klíčové zjištění (zjednodušuje scope):** POST `/_mail/incoming` nese
povinný `raw_source` (.eml) — hlavičky se parsují **na PHP straně při
ingestu**. Žádné změny v `mail_router` ani `shipard_node`.

## Návaznost

- **Nezávislé na Fázi 1/2 Spisovny** — čistě `core.mail` + dashboard.
  Číslování fází drží program Spisovny (design §8).
- **Staví na:** ingest (`MailController::receiveIncoming`),
  `documentEventHandlers` (`stateChanged`), feed sources (hardcoded
  v `DashboardController`), `docStatesIncoming` (goto 10→80 existuje).
- **Zásada D7 — důvěra po krocích:** auto-archivují **výhradně potvrzená
  pravidla** (deterministika). `is_bulk` sám o sobě nikdy neauto-archivuje;
  je to signál pro návrhy a budoucí triáž.
- **Mimo:** AI klasifikace šumu (nový primary_type + návrhové karty z AI)
  a per-DS opt-in AI auto-archivu — vědomě odloženo jako Fáze 3b, až si
  deterministika vybuduje důvěru; levná triáž malým modelem (D6 kaskáda,
  vrstva 2) rovněž později.

## Před implementací přečti

- `docs/registry-mvp.md` §8 + D6/D7 (§0).
- `src/Api/Controller/MailController.php::receiveIncoming` — ingest flow
  (INSERT zprávy → upload raw_source → přílohy), místo pre-triage;
  `$_FILES['raw_source']` je v requestu k dispozici před INSERTem.
- `modules/core/mail/config/docStatesIncoming.jsonc` — stavy zpráv
  (10 Nová, 20 K řešení, 40 Hotovo, 80 Archiv, 90 Koš; goto 10→80 ano).
- `modules/core/mail/config/analysisStates.jsonc` — **ověř klíč pro
  „neanalyzovat"** (design předpokládá 0) a klíč fronty (10) kvůli
  undo re-queue.
- `modules/core/mail/module.jsonc` `documentEventHandlers` +
  `src/SupplierCodeCaptureHandler.php` +
  `src/Api/DocumentEventHandlerLoader.php` — kontrakt
  `onStateChanged(string $tableId, array $data, int $oldState, int
  $newState)`, běh po commitu, výjimky se logují a polykají.
- `src/Api/Controller/DashboardController.php` (~ř. 121) — registrace feed
  sources napevno (`new MailSuggestionsSource(), new AlertsSource()`).
- `modules/core/mail/src/Feed/MailSuggestionsSource.php` — vzor FeedSource
  (kinds, akce bez labelů, attachments batch).
- Frontend `Dashboard.svelte` — obsluha akcí `trash_message` /
  `archive_message` (vzor pro nové action kinds) + jaké action kinds
  existují (potřebujeme „otevřít viewer" — pokud `open_viewer` není,
  přidává se v kroku 6).
- `modules/core/mail/src/MailboxesViewer.php` + settings registrace —
  vzor settings vieweru pro pravidla.
- **Pozor na názvy:** `core_mail_senders` = odchozí SMTP transporty,
  s pravidly došlé pošty nesouvisí. Nová tabulka je
  `core_mail_sender_rules`.

## Scope

**V rozsahu:**

- tabulka `core_mail_sender_rules` (tableId **429**) + Document + settings
  viewer/form
- sloupce na `core_mail_incoming_messages`: `is_bulk`,
  `auto_disposed_by`, `auto_disposed_at`
- `BulkHeadersDetector` — parse hlaviček z raw `.eml` při ingestu
- pre-triage v ingestu: match potvrzeného pravidla → zpráva rovnou
  v Archivu, bez analýzy, s auditem
- učící handler: opakované ruční košování/archivace stejného odesílatele
  → návrh pravidla (Koncept) → karta k potvrzení
- `MailDigestSource` (nový FeedSource): denní digest karta + karty návrhů
  pravidel
- endpointy: potvrdit/zamítnout návrh, „Vrátit vše" (undo dnešního
  auto-archivu)
- frontend: nové action kinds + i18n
- testy

**Mimo rozsah:**

- AI klasifikace šumu, per-DS opt-in AI auto-archiv (Fáze 3b)
- doménová pravidla jako **návrhy** (ručně je založit lze od začátku)
- jakýkoli zásah do `mail_router` / `shipard_node`
- retroaktivní aplikace pravidel na existující poštu (viz Otevřený bod)
- `tasks/README.md` neaktualizuj

## Návrhová rozhodnutí (upřesnění §8)

1. **Návrh pravidla = Koncept.** Tabulka pravidel používá
   `core.system.docStatesArchive`; navržené pravidlo vzniká v 10 (Koncept),
   potvrzení = přechod 10→40, zamítnutí = 90. Žádný `is_confirmed` flag —
   stavový automat to umí sám. **Matchují výhradně pravidla v 40.**
2. **Audit místo čítače.** Žádná digest tabulka: na zprávě je
   `auto_disposed_by` (FK pravidlo) + `auto_disposed_at`. Digest karta i
   „Vrátit vše" se derivují dotazem; plná auditní stopa zadarmo.
3. **`is_bulk` je jen signál** (D7): ukládá se, zobrazuje, sytí budoucí
   návrhy a triáž — nikdy sám nearchivuje.
4. **Precedence matchování:** přesný e-mail > doména; lowercase
   normalizace na obou stranách; první zásah vyhrává.
5. **Undo obnovuje i analýzu:** auto-archivovaná zpráva analýzu přeskočila,
   takže „Vrátit vše" vrací docState 80→10 **a** `analysis_state` → fronta
   (10), `auto_disposed_*` → NULL. Jednotlivou zprávu lze vrátit i ručně
   ve vieweru (goto 80→10 existuje) — pak ale bez re-queue; to je
   akceptované (uživatel může spustit analýzu ručně).

## Doporučené pořadí

### Krok 0 — prerekvizity

`.jsonc` změny → rebuild kompilované konfigurace + `ds-upgrade` před kódem,
který na ně sahá.

### Krok 1 — schema + cfg

- `modules/core/mail/tables/core_mail_sender_rules.jsonc` (tableId 429)
  + `.md`:
  - `pattern_kind` enumString(10): `email` | `domain` (cfgItem
    `core.mail.senderRulePatternKinds`)
  - `pattern` varchar(190), lowercase (vynucuje Document)
  - `disposition` enumString(20), cfgItem
    `core.mail.senderRuleDispositions` — zatím jediná hodnota `archive`
    (připraveno na budoucí rozšíření)
  - `origin` enumString(10): `user` | `suggested` (cfgItem
    `core.mail.senderRuleOrigins`)
  - `hit_count` int default 0, `last_hit_at` datetime null
  - `notice` varchar(250) null
  - docState/docStateMain (`core.system.docStatesArchive`), created,
    created_by
  - indexy: `idx_match` (docStateMain, pattern_kind, pattern);
    `idx_state` (docState)
- `core_mail_incoming_messages.jsonc` — nové sloupce:
  - `is_bulk` tinyint default 0 (skupina extrakce/meta)
  - `auto_disposed_by` int null, reference `core_mail_sender_rules`
  - `auto_disposed_at` datetime null + index `idx_auto_disposed`
    (auto_disposed_at)
- tři malé cfgItem soubory (patternKinds, dispositions, origins)
- `module.jsonc`: tabulka, config, settings viewer pravidel
  (`section: "other"`, subsekce mail), forms, documentClasses, event
  handler registrace (krok 4)

### Krok 2 — `BulkHeadersDetector` + ingest signál

- `modules/core/mail/src/BulkHeadersDetector.php` —
  `detect(string $rawEml): bool` (čte jen hlavičkový blok, ne celé tělo):
  `List-Unsubscribe` (přítomnost) NEBO `Precedence: bulk|list` NEBO
  `Auto-Submitted` ≠ `no` NEBO `List-Id`. Case-insensitive, fold
  hlaviček přes řádky, robustní na CRLF/LF. Nic jiného (žádné heuristiky
  nad tělem).
- `MailController::receiveIncoming`: před INSERTem zprávy načíst tmp file
  raw_source, `is_bulk` uložit se zprávou. Selhání parseru → `is_bulk=0`
  + warn, ingest nikdy neblokuje.
- Viewer zpráv: drobný badge „hromadná" u `is_bulk=1` (i1/i2 slot dle
  místa — jediná UI změna vieweru v této fázi).

### Krok 3 — matching + pre-triage v ingestu

- `modules/core/mail/src/SenderRuleMatcher.php`:
  `match(string $senderEmail): ?array` — lowercase; přesný e-mail, pak
  doména (část za `@`); jen pravidla docState 40 s
  `disposition='archive'`. Jeden dotaz s prioritním řazením.
- `receiveIncoming`: po zjištění `sender_email` match → zpráva vzniká
  s `docState=80`/`docStateMain=4`, `analysis_state` = „neanalyzovat"
  (ověřený klíč z kroku 0/přečtení), `auto_disposed_by`,
  `auto_disposed_at=NOW()`; update pravidla `hit_count+1`,
  `last_hit_at`. Pokud vkládací cesta vynucuje vznik v 10, vlož v 10 a
  přejdi 10→80 v téže transakci přes Document flow (vzor z Fáze 2).
- Přílohy a raw_source se ukládají beze změny (audit je úplný).
- Match se dělá **jen při ingestu** — pravidlo potvrzené později se na
  starou poštu neaplikuje (viz Otevřený bod 2).

### Krok 4 — učící handler

- `modules/core/mail/src/SenderRuleSuggestionHandler.php` extends
  `AbstractDocumentEventHandler`, registrace v `module.jsonc`:
  `{"table": "core_mail_incoming_messages", "events": ["stateChanged"]}`.
- `onStateChanged`: reaguje jen na přechody do 80/90, kde zpráva
  **není** auto-disposed (`auto_disposed_by IS NULL`) a má neprázdný
  `sender_email`. Pak:
  1. spočti ruční „odklizení" téhož odesílatele:
     `COUNT(*) messages WHERE sender_email = ? AND docState IN (80,90)
     AND auto_disposed_by IS NULL` (lowercase match);
  2. práh **3** (konstanta v handleru) — pod prahem konec;
  3. existuje-li živé pravidlo (10/40/80) pro e-mail nebo jeho doménu →
     konec (žádné duplicitní návrhy);
  4. jinak INSERT pravidla: Koncept (10), `origin='suggested'`,
     `pattern_kind='email'`, `pattern` = lowercase e-mail, `notice`
     s počtem zásahů.
- Handler nikdy neblokuje přechod zprávy (běží po commitu; výjimky
  loguje dispatcher).
- Návrhy jsou vždy **exact e-mail**; doménové pravidlo zakládá uživatel
  ručně ve settings.

### Krok 5 — endpointy

Router: rozšířit větev `/_mail/` (MailController nebo malý
`SenderRulesController` — dle velikosti, vzor tenké slupky):

- `POST /_mail/sender-rules/{id}/confirm` — Koncept 10→40 přes Document
  flow; 404/409 standardně.
- `POST /_mail/sender-rules/{id}/reject` — 10→90.
- `POST /_mail/auto-archive/undo` — body `{date?: "YYYY-MM-DD"}` (default
  dnešek, jen dnešek/včerejšek — starší přes viewer): všechny zprávy
  s `auto_disposed_at` v daném dni → docState 10, `analysis_state` →
  fronta, `auto_disposed_*` NULL, přes Document flow per zpráva;
  response `{restored: N}`.
- `SenderRuleDocument`: validace — pattern povinný, lowercase
  normalizace v beforeSave, formát dle `pattern_kind` (e-mail s `@`,
  doména bez `@`), unikátnost (pattern_kind, pattern) mezi živými.

### Krok 6 — `MailDigestSource` + frontend

- `modules/core/mail/src/Feed/MailDigestSource.php`, registrace
  v `DashboardController` vedle stávajících sources:
  - **Digest karta** (max 1): `COUNT` + vzorek odesílatelů (≤3) zpráv
    s `auto_disposed_at` = dnes; kind=`info`, stateStyle=`archive`;
    titulek „N zpráv automaticky archivováno" (cs/en dle ctx), podtitulek
    vzorek odesílatelů + „…"; akce: `open_viewer` (mail viewer, tab
    Archiv) a `undo_auto_archive` (primary ne — info karta, primary je
    Zobrazit). Žádné auto-disposed dnes → žádná karta.
  - **Karty návrhů**: pravidla docState=10 + `origin='suggested'` →
    kind=`review`, titulek „Vždy archivovat poštu od {pattern}?",
    podtitulek `{hit_count z notice} · doména/e-mail`; akce
    `confirm_sender_rule` (primary), `reject_sender_rule`,
    `open_form` (formulář pravidla — možnost upravit na doménu před
    potvrzením).
- Frontend `Dashboard.svelte` + `api/mail.js`: handlery
  `confirm_sender_rule`, `reject_sender_rule`, `undo_auto_archive`
  (volají endpointy z kroku 5, refresh feedu); `open_viewer` — pokud
  action kind neexistuje, přidat (routing na viewer + volitelný tab);
  i18n `dashboard.card.action.*` cs+en.

### Krok 7 — testy + alfa

Úzké filtry, pak nasazení a ověření na alfě: založit ručně 1–2 pravidla
na notorické odesílatele (mutace po odsouhlasení, D3), sledovat digest.

## Testy

- **Unit:**
  - `BulkHeadersDetector`: fixtures .eml — List-Unsubscribe, Precedence,
    Auto-Submitted=no (→ false), foldnuté hlavičky, CRLF/LF, hlavičkový
    blok vs. tělo (List-Unsubscribe v těle nesmí matchnout);
  - `SenderRuleMatcher`: e-mail > doména, case-insensitivity, jen 40,
    jen `archive`;
  - `SenderRuleDocument`: lowercase, formát dle kind, unikátnost mezi
    živými, koš neblokuje reuse;
  - `SenderRuleSuggestionHandler`: pod prahem nic; práh → Koncept;
    existující pravidlo/návrh → nic; auto-disposed přechody se
    nepočítají; prázdný sender_email → nic.
- **Integrační/API:**
  - ingest s matchujícím pravidlem → zpráva 80, bez analýzy, audit
    sloupce, hit_count/last_hit_at; bez matche → beze změny chování
    (regresní);
  - ingest s bulk hlavičkou → `is_bulk=1`, zpráva normálně do fronty;
  - confirm/reject návrhu; undo: docState+analysis_state+audit
    vynulované, `{restored}` sedí; opakované undo → `{restored: 0}`;
  - digest a návrhové karty ve feedu (source unit-testovatelný s fake
    ctx dle vzoru testů MailSuggestionsSource).
- PHPUnit úzkými `--filter` (BulkHeaders, SenderRule, MailDigest,
  IngestPreTriage…).

## Commit strategie

(1) schema + cfg + Document + settings viewer, (2) BulkHeadersDetector +
ingest signál, (3) matcher + pre-triage + integrační testy ingestu,
(4) učící handler, (5) endpointy, (6) digest source + frontend.
Každý commit zelené testy a funkční `ds-upgrade`; krok 3 nasazovat až
s krokem 6 (auto-archiv bez digest karty by porušil D7 — nic se nesmí
dít tiše).

## Hotovo když

- [ ] `ds-upgrade` projde; tabulka 429 + nové sloupce zpráv existují
- [ ] potvrzené pravidlo archivuje při ingestu bez analýzy, s auditem
      a inkrementem statistik; nepotvrzené/zamítnuté nematchuje
- [ ] `is_bulk` se plní z hlaviček a sám nikdy nearchivuje
- [ ] 3× ruční koš/archiv stejného odesílatele → návrhová karta;
      potvrzení kartou → pravidlo aktivní; duplicitní návrhy nevznikají
- [ ] digest karta: max 1 denně, jen když něco auto-spadlo; „Vrátit vše"
      obnoví zprávy vč. re-queue analýzy
- [ ] pravidla spravovatelná v settings (vč. ručních doménových)
- [ ] ingest bez pravidel: chování beze změny (regresní testy zelené)
- [ ] žádná změna v `mail_router`/`shipard_node`
- [ ] testy zelené (filtry ze sekce Testy), dokumentace modulu
      aktualizovaná (README / docs/mail)

## Otevřené body

1. **Práh učení (3) a okno** — konstanta bez časového okna je první
   aproximace; ladit podle alfy (případně `COUNT` omezit na posledních
   90 dní).
2. **Retroaktivní aplikace pravidla** („archivovat i X existujících zpráv
   od tohoto odesílatele?") — užitečné, ale mutace nad historií; navrhnout
   až po ověření základu, ideálně jako volitelný krok při potvrzení
   pravidla.
3. **Doménové návrhy** — až podle dat (kolik odesílatelů sdílí doménu
   newsletterů); zatím ruční.
