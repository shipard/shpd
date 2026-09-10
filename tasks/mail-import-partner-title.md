# Task: Došlá pošta — partner a titulek u importovaných zpráv (#43 / D6)

**Stav:** částečně — implementace, testy a dokumentace hotové 2026-09-10 (5 commitů), backfill ověřen na dev DS; zbývá nasazení na alfu, re-import a SQL kontroly ze sekce „Ověření na alfě“

## Status / cíl

`mail-message-title-partner.md` zavedl sloupce `partner_person`,
`partner_name` a `ai_title` a plní je ve dvou vrstvách — při `POST /result`
(AI), při ISDOC importu a autoritativně při **Použít**. Jeho **D6** ale
backfill historických zpráv vědomě odložil do návrhu importu ze starého
Shipardu. Importované zprávy tak zůstávají prázdné trvale, ne jen do
příští analýzy: `MailRunner` posílá `analysis_state = 0` (task 26 staré
strany), takže se do AI fronty nikdy nedostanou a `/result` je nikdy
nepotká.

Cíl: **importovaná zpráva navázaná na doklad nese partnera i titulek
odvozený z toho dokladu**, a to jak v okamžiku importu, tak zpětně
u dat, která už v DS leží.

Druhá strana: `old_shipard: modules/imports/newShipard/tasks/35-mail-partner-import.md`
(partner u zpráv **bez** navázaného dokladu — jediné, co server odjinud
nezjistí).

GitHub Issue: shipard/shpd#43 (uzavřená, tenhle task doplňuje její D6).

## Měření (alfa, 4 DS, 2026-09-10)

| | zprávy | navázané na doklad | ruční (`source_type = 1`) |
|---|---|---|---|
| celkem | 108 304 | 14 443 (13 %) | 3 285 |

- **100 %** navázaných dokladů má vyplněného partnera, `doc_number`
  i `total_amount` — zdroj je úplný, ne best-effort.
- Kde je vyplněný `sender_person`, **liší se od partnera dokladu**
  v 51–100 % případů podle DS. To je přesně bug z #43 na historických
  datech.
- Navázané doklady nejsou jen `invni`: v jednom DS 1 362 × `invno`
  a napříč DS 726 × `cmnbkp`. Proto D4.
- 769 z 1 451 ručních importovaných zpráv v největším DS je navázaných —
  týká se jich D9.

## Návaznost

- `mail-message-title-partner.md` — rodičovský task, rozhodnutí D1–D8;
  tenhle task doplňuje jeho **D6** a nemění žádné jeho pravidlo.
- `mail-phase4-import-endpoint.md` — endpoint `POST /_mail/import`.
- `mail-isdoc-import.md` — vzor deterministické cesty obcházející AI
  (`MessagePartnerWriter` + `MessageTitleComposer` injektované do service).
- `old_shipard: …/tasks/26-mail-import-no-analysis.md` — proč importovaná
  zpráva nikdy neprojde `/result`.
- `old_shipard: …/tasks/35-mail-partner-import.md` — druhá strana.

## Před implementací přečti

- `src/Api/Controller/MailController.php` — `importMessage()` (skládá
  `$data`, `validate` → `beforeSave` → `insert`; sem patří doplnění polí
  **před** insertem) a `resolveIncomingMainState()`.
- `modules/core/mail/src/MessageProposalApplier.php` — `targetPartnerId()`
  (ř. ~517): vzor čtení partnera z cílového záznamu **včetně validace
  názvu tabulky regexem** — nová třída ho zobecňuje.
- `modules/core/mail/src/MessageTitleComposer.php` — `forDataSource()`,
  `clean()`, `formatAmount()`, `typeLabel()`; `MAX_LENGTH = 200`.
- `modules/core/mail/src/MessagePartnerWriter.php` — `NAME_MAX_LENGTH`,
  `normalizeName()`, guardy vrstvy 1.
- `modules/docs/core/config/docTypes.jsonc` — cfgItem `docs.core.docTypes`,
  klíče `invno`/`invni`/`cmnbkp`/`cash`/`cashreg` a jejich `name`.
- `modules/docs/core/tables/docs_core_heads.jsonc` — `doc_type`,
  `doc_number`, `partner`, `total_amount`, `doc_currency`.
- `public/index.php` ř. ~965 — wiring `MessageTitleComposer::forDataSource()`
  a `MessagePartnerWriter::create()` do ISDOC factory (stejný vzor).
- `src/Cli/DsApplicationFactory.php` a
  `src/Command/DataSource/MailPreprocessCommand.php` — registrace a kostra
  DS příkazu.

## Potvrzená designová rozhodnutí (David, 2026-09-10)

- **D1 — Odvozuje server, ne runner.** `/_mail/import` si `partner_*`
  a `ai_title` dopočítá sám z `target_table_id` / `target_row`, které už
  v payloadu má. Důvody: (a) titulek je DS-wide data **v jazyce AI
  profilu** (nález z alfy u ISDOC — „Received invoice", protože intake
  nenese `Accept-Language`), a ten starý Shipard nezná; (b) formátování
  zůstává v jednom místě; (c) dělá backfill (D7) triviálním — nepotřebuje
  starý Shipard vůbec. Runner posílá jen to, co server nezjistí (D5).
- **D2 — Partner navázané zprávy = `docs_core_heads.partner`.** Stejná
  autorita jako vrstva 2 (Použít) v rodičovském D5. Směr obchodu se
  neřeší — `partner` je protistrana z definice, takže pravidlo sedí i na
  `invno` a `cmnbkp`.
- **D3 — `partner_name` = `full_name` Osoby**, ne `supplier_snapshot` /
  `customer_snapshot`. Pokrytí 100 % vs. 97 %; u importu je snapshot
  stejně odvozený z téhož jména. Pro navázané zprávy je `partner_name`
  spíš pojistka (viewer preferuje Osobu, fulltext jede přes LEFT JOIN) —
  plní se kvůli invariantu a pro případ smazané Osoby.
- **D4 — Titulek podle typu dokladu (`docs.core.docTypes`), ne podle
  `core.mail.primaryTypes`.** Nová metoda
  `MessageTitleComposer::fromDocument()`. Přes mail primaryTypes by
  navázané vydané faktury a účetní doklady dostaly štítek „Ostatní".
- **D5 — Nenavázané zprávy: partnera dodá runner** z doclinku
  `wkf-issues-from`, ale nikdy toho, kdo je odesílatelem zprávy.
  Detail a diskriminátor jsou v tasku 35 staré strany; sem patří jen
  příjem polí `partner_person` / `partner_name` v payloadu.
- **D6 — Nenavázané zprávy: `ai_title` nevzniká.** Není z čeho —
  canonical neexistuje a nikdy nevznikne. Případná pozdější fáze
  z e10doc extension sloupců issue (`docId`, `docPrice`, `docCurrency`)
  je samostatné rozhodnutí, gate na měření.
- **D7 — Backfill je samostatný idempotentní DS příkaz**, ne runner
  a ne `ds-upgrade`. Pokryje tři věci najednou: historii z importu,
  zprávy aplikované přes **Použít před #43** a zprávy, jejichž doklad se
  doimportoval později (částečné běhy s `--limit`).
- **D8 — Vrstvy platí i tady.** Hodnoty v payloadu jsou **návrh**
  (vrstva 1), cílový doklad je **autorita** (vrstva 2) — u navázané
  zprávy doklad přebíjí payload bez ohledu na pořadí. Sdílený writer
  přitom zapisuje **jen do NULL sloupců**, takže je bezpečný i v backfillu
  nad daty, kde už uživatel mohl partnera vybrat ručně.
- **D9 — Pravidlo D3 rodičovského tasku zůstává beze změny** i pro
  importované ruční zprávy. Staré `source = 0 (Ručně)` mapuje runner na
  `source_type = 1`, takže `ai_title` přebije předmět vždy — u 769 z 1 451
  ručních zpráv v největším DS. Vědomě: titulek z dokladu („Faktura
  přijatá 2019-0123 — Dodavatel s.r.o., 13 105 CZK") je pro dohledání
  lepší než ručně psaný předmět, který zůstává v Technických údajích
  i ve fulltextu.

## Scope

**V rozsahu**

- `MessageTitleComposer::fromDocument()` + label z `docs.core.docTypes`.
- Nová sdílená třída `MessageTargetWriter` (fakta z cílového záznamu).
- `MailController::importMessage()`: příjem `partner_person` /
  `partner_name` z payloadu + odvození z cílového dokladu.
- DS příkaz `mail-target-backfill` (`--dry-run`, `--limit`, `--batch`).
- Testy, dokumentace (`ai-analysis.md`, `cli.md`).

**Mimo rozsah**

- Titulek u nenavázaných zpráv (D6).
- Registry cíle (`base_registry_documents`) — import je nikdy nevytváří;
  writer je přeskočí s debug hláškou, ne chybou.
- Refaktor `MessageProposalApplier::targetPartnerId()` na novou třídu —
  volitelný úklid, ne součást zadání (viz P5).
- Změna `primary_type` mapování v runneru (`invno`/`cmnbkp` → `other`) —
  mail primaryTypes pro vydané doklady klíč nemají; titulek to díky D4
  neřeší.
- Nový `source_type` pro import.

## Datový tok

```
MailRunner (stará strana)
   navázaná zpráva   → payload bez partner_*        (server si odvodí)
   nenavázaná zpráva → payload partner_person/_name (D5, task 35)
        │
        ▼
POST /_mail/import
   $data ← payload (partner_person, partner_name)                [vrstva 1]
   $data ← MessageTargetWriter::factsFor(target_table_id,         [vrstva 2]
              target_row)  — přebíjí vrstvu 1
   → validate → beforeSave → INSERT

MessageTargetWriter::factsFor('docs_core_heads', id)
   SELECT h.doc_type, h.doc_number, h.total_amount, h.doc_currency,
          h.partner, p.full_name
     FROM docs_core_heads h
     LEFT JOIN base_persons_persons p ON p.id = h.partner
   → partner_person = h.partner
     partner_name   = p.full_name                                 (D3)
     ai_title       = MessageTitleComposer::fromDocument(row)      (D4)

shpd-ds mail-target-backfill
   WHERE target_row IS NOT NULL
     AND (partner_person IS NULL OR partner_name IS NULL OR ai_title IS NULL)
   → MessageTargetWriter::backfill(id)  — UPDATE jen NULL sloupců  (D8)
```

## Co je potřeba udělat

### 1. `MessageTitleComposer::fromDocument()`

Nová veřejná metoda vedle `compose()`, stejná třída (sdílí `clean()`,
`formatAmount()`, `MAX_LENGTH` i jazykový wiring `forDataSource()`):

```php
/**
 * Titulek z hlavičky dokladu (D4). Tvar:
 *   `{label typu} {doc_number} — {partner}, {total_amount} {doc_currency}`
 * Chybějící části se vynechají; bez ConfigRuntime se vynechá label typu.
 *
 * @param array<string, mixed> $doc řádek docs_core_heads + partner_full_name
 */
public function fromDocument(array $doc): ?string
```

- Label: `docs.core.docTypes[$doc['doc_type']]['name']` — privátní
  `docTypeLabel()` po vzoru stávajícího `typeLabel()`.
- Head = `label + doc_number`, tail = `partner_full_name` + částka,
  spojení `head — tail` — **přesně stejná skládačka jako `compose()`**;
  vytáhni ji do privátní `assemble(?string $head, ?string $tail)`, ať
  se tvar nerozejde.
- Částka: `formatAmount($doc['total_amount'], $doc['doc_currency'])`.
- Prázdný výsledek → `null`.

### 2. `MessageTargetWriter` (`modules/core/mail/src/`)

```php
final class MessageTargetWriter
{
    private const MESSAGES_TABLE = 'core_mail_incoming_messages';
    private const DOCS_TABLE = 'docs_core_heads';

    public function __construct(
        private readonly DataSourceConnection $db,
        private readonly MessageTitleComposer $titleComposer,
    ) {}

    /** @return array{partner_person: ?int, partner_name: ?string, ai_title: ?string} */
    public function factsFor(?string $targetTableId, ?int $targetRow): array;

    /** UPDATE jen NULL sloupců; true = něco se zapsalo. */
    public function backfill(int $messageNdx): bool;
}
```

- `factsFor()` vrací trojici samých `null` pro: prázdný / jiný než
  `docs_core_heads` cíl, `targetRow <= 0`, neexistující doklad.
  **Název tabulky validuj regexem `/^[a-z0-9_]+$/`** i když se dnes
  porovnává na konstantu (payload je vstup zvenčí) — vzor
  `MessageProposalApplier::targetPartnerId()`.
- Výjimky polykej a loguj `ErrorLogger::warn` — fakta jsou best-effort,
  import zprávy kvůli nim nesmí spadnout (symetrie
  s `MessagePartnerWriter`).
- `backfill()` čte `target_*` zprávy, zavolá `factsFor()` a udělá jeden
  UPDATE se `SET partner_person = COALESCE(partner_person, %i)` …
  nebo (čitelnější) poskládá `$set` jen z těch sloupců, které jsou na
  zprávě NULL a fakt je non-null. Prázdný `$set` → `false` bez dotazu.
- Wiring: `MessageTargetWriter::forDataSource($db, $dsConfig)` po vzoru
  composeru — uvnitř `MessageTitleComposer::forDataSource(...)` (jazyk
  výchozího aktivního AI profilu DS, `$profileNdx = null`).

### 3. `MailController::importMessage()`

- Do `$data` přidej příjem payloadu (vrstva 1):
  ```php
  'partner_person' => isset($body['partner_person']) ? (int) $body['partner_person'] : null,
  'partner_name'   => MessagePartnerWriter::normalizeName($body['partner_name'] ?? null),
  ```
  `partner_person <= 0` → `null`.
- Po sestavení `$data` a **před** `validate()` přebij fakty z cíle:
  ```php
  $facts = $this->targetWriter()->factsFor(
      $data['target_table_id'], $data['target_row'],
  );
  foreach ($facts as $col => $value) {
      if ($value !== null) {
          $data[$col] = $value;
      }
  }
  ```
  (`$value === null` nechává vrstvu 1 na pokoji — D8.)
- `targetWriter()` postav lazy z `$this->db` + `$this->dsConfig`; bez
  `dsConfig` degraduj na composer bez configu (titulek bez labelu typu),
  ne na výjimku.
- Doplň tabulku polí v hlavičkovém komentáři / OpenAPI o `partner_person`
  a `partner_name` s poznámkou „u navázané zprávy se ignoruje, vyhrává
  partner dokladu".

### 4. DS příkaz `mail-target-backfill`

`src/Command/DataSource/MailTargetBackfillCommand.php`, registrace
v `src/Cli/DsApplicationFactory.php` (vedle ostatních `mail-*`).

- Options: `--dry-run` (nic nezapisuje, jen počítá a vypíše prvních
  10 titulků k namátkové kontrole), `--limit`, `--batch` (default 500).
- Výběr: keyset přes `id` (ne OFFSET — tabulka má na alfě 100k+ řádků):
  ```sql
  SELECT id, target_table_id, target_row, partner_person, partner_name, ai_title
    FROM core_mail_incoming_messages
   WHERE id > :after AND target_row IS NOT NULL
     AND (partner_person IS NULL OR partner_name IS NULL OR ai_title IS NULL)
   ORDER BY id LIMIT :batch
  ```
- Souhrn: `scanned`, `updated`, `skipped` (cíl mimo docs / doklad
  nenalezen), `unchanged`.
- Exit 0 i když se nic nezměnilo (idempotence); nenulový jen pro
  infra chybu.
- Doplň do `docs/cli.md`.

### 5. Dokumentace

- `modules/core/mail/docs/ai-analysis.md` — do sekcí „Partner zprávy"
  a „Titulek zprávy" přidej odstavec o třetí cestě (import / cílový
  doklad) vedle `/result` a ISDOC; zmíň, že D6 rodičovského tasku je
  tímhle uzavřené.
- `docs/cli.md` — `mail-target-backfill`.
- `tasks/README.md` — řádek v tabulce Došlá pošta (**David**).

## Testy

`tests/` podle stávajícího členění, spouštět úzkým `--filter`:

1. `MessageTitleComposerTest`: `fromDocument()` — plný tvar; bez
   `doc_number`; bez partnera; bez částky; bez ConfigRuntime (vypadne
   label typu, zbytek zůstane); neznámý `doc_type`; ořez na 200 znaků.
2. `MessageTargetWriterTest`: `factsFor()` pro docs cíl; `null` trojice
   pro registry cíl, prázdný cíl, `target_row = 0` a neexistující doklad;
   doklad bez partnera (partner_person i partner_name `null`, titulek
   pořád vznikne).
3. `MessageTargetWriterTest::backfill()`: doplní jen NULL sloupce;
   **nepřepíše ručně nastavený `partner_person`**; druhý běh nezmění nic
   (idempotence); zpráva bez `target_row` se nedotkne.
4. API test `/_mail/import`: navázaná zpráva dostane partnera a titulek
   z dokladu; fakta z dokladu **přebijí** `partner_person` v payloadu;
   nenavázaná zpráva dostane `partner_person`/`partner_name` z payloadu
   a `ai_title` zůstane NULL; payload bez nových polí projde beze změny
   chování (regrese `mail-phase4`).
5. Viewer smoke: importovaná navázaná zpráva má t2 = jméno Osoby
   a t1 = titulek u generického předmětu.

## Commit strategy

1. `MessageTitleComposer::fromDocument()` + `assemble()` + testy.
2. `MessageTargetWriter` + testy.
3. `importMessage()` — payload pole + odvození z cíle + API testy.
4. `mail-target-backfill` + registrace + `docs/cli.md`.
5. `ai-analysis.md`.

## Hotovo když

- [x] Importovaná zpráva navázaná na doklad má po `POST /_mail/import`
      `partner_person` = partner dokladu, `partner_name` = `full_name`
      Osoby a `ai_title` ve tvaru `{typ} {číslo} — {partner}, {částka} {měna}`.
- [x] Titulek nese český název typu dokladu i pro `invno` a `cmnbkp`
      (ne „Ostatní"), v jazyce výchozího AI profilu DS.
- [x] Nenavázaná zpráva přebere `partner_person` / `partner_name`
      z payloadu; `ai_title` zůstane NULL.
- [x] U navázané zprávy vyhraje partner dokladu nad payloadem.
- [x] `shpd-ds mail-target-backfill --dry-run` nic nezapíše a vypíše
      počty; ostrý běh doplní jen NULL sloupce a druhý běh hlásí
      `updated = 0`.
- [x] Backfill nepřepíše ručně vybraného partnera ani existující titulek.
- [x] `php -l`, `phpunit --filter` dotčených tříd zelené;
      `check-sensitive.py` bez nálezu.

## Ověření na alfě (po nasazení a re-importu)

```sql
-- navázané importované zprávy: musí být 0 řádků bez partnera / titulku
SELECT COUNT(*) FROM core_mail_incoming_messages
 WHERE target_table_id = 'docs_core_heads' AND target_row IS NOT NULL
   AND (partner_person IS NULL OR ai_title IS NULL);

-- namátka na tvar titulku (bez osobních dat do chatu — jen počty tvarů)
SELECT COUNT(*) FROM core_mail_incoming_messages
 WHERE ai_title LIKE '%—%' AND target_row IS NOT NULL;

-- kontrola D2: partner zprávy = partner dokladu
SELECT COUNT(*) FROM core_mail_incoming_messages m
  JOIN docs_core_heads h ON h.id = m.target_row
 WHERE m.target_table_id = 'docs_core_heads'
   AND m.partner_person <> h.partner;   -- očekávej 0
```

## Pasti

- **P1 — Pořadí runnerů.** Doklady se importují **před** poštou, takže
  cílový doklad při `/_mail/import` existuje. U částečných běhů
  (`--limit`, `--from/--to`) může `resolveDocLink` vrátit nenavázáno —
  zpráva pak partnera nedostane a `MailRunner` ji podruhé neposílá
  (LocalIdMap). Tyhle díry zavírá výhradně backfill (D7).
- **P2 — Jazyk titulku.** `MessageTargetWriter` musí composer stavět přes
  `forDataSource()`, ne `new MessageTitleComposer($configRuntime)`
  z requestu. Jinak se zopakuje nález z alfy: intake od runneru nenese
  `Accept-Language`, DS bez `defaultLanguage` padá na `en` a titulek
  vyjde anglicky (D1).
- **P3 — Odvození patří před `validate()`.** Po `beforeSave()` už se
  `$data` jen inzertuje; UPDATE po insertu by fungoval taky, ale
  zbytečně a nekonzistentně s tím, že zprávu vkládáme rovnou v cílovém
  stavu.
- **P4 — `target_table_id` je vstup zvenčí.** Do `%n` / názvu tabulky
  smí jít jen po regex validaci, i když dnes porovnáváme na konstantu.
- **P5 — Duplicita s `targetPartnerId()`.** `MessageProposalApplier` má
  vlastní čtení partnera z cíle. Nesjednocuj to v tomhle tasku (Použít
  má jinou sémantiku — přepisuje bez guardu), jen na duplicitu upozorni
  komentářem u obou.
- **P6 — Backfill na 100k+ řádcích.** Keyset přes `id`, dávky, žádný
  `OFFSET` a žádné načtení celé tabulky; jinak OOM stejně jako kdysi
  `fetchIssues` na staré straně.
- **P7 — Guard „jen NULL".** Bez něj by backfill přepsal ručně vybraného
  partnera (rodičovský D8) a titulek, který zprávě dala AI po importu.
- **P8 — Citlivá data.** Do testů a fixtures jen fiktivní partneři,
  IČO a částky; do tasku a commitů žádné názvy DS.
