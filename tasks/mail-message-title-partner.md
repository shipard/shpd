# Task: Došlá pošta — partner dokladu a titulek zprávy z AI analýzy (Issue #43)

**Stav:** částečně — fáze 1 (partner) hotovo 2026-09-10, fáze 2 (titulek) naplánována

## Status / cíl

Zprávy ze skeneru chodí e-mailem s generickým předmětem (`Message from …`)
a stejným technickým odesílatelem; ruční nahrání z počítače mají v předmětu
to, co uživatel zrovna napsal (typicky název souboru). `sender_person` se
odvozuje z e-mailu odesílatele, což je u přeposlané pošty kolega nebo
skener — nikdy dodavatel, který doklad vystavil. Výsledek: u naskenovaných
a ručních zpráv **není v seznamu ani ve fulltextu nic, podle čeho by šly
najít** (Sebik, #43).

Cíl: každá analyzovaná zpráva nese **partnera** (Osobu dodavatele /
protistrany, resp. alespoň jeho jméno) a **titulek** odvozený z obsahu,
oba se zobrazují v seznamu i detailu a jsou prohledatelné.

GitHub Issue: shipard/shpd#43.

## Návaznost

- `mail-states-and-classification.md` — vzor AI zápisu zpět do zprávy
  (`primary_type` + `primary_type_source`, „AI nikdy nepřepíše uživatele").
- `mail-isdoc-import.md` — deterministická cesta obcházející AI; musí
  nové sloupce plnit sama.
- `mail-message-centric.md` — kontrakt `/result` v4, `message_classification`.
- Import ze starého Shipardu (návrh, samostatný task) — **doplní partnera
  u zpráv importovaných z historie; tady se backfill neřeší (D6).**

## Před implementací přečti

- `modules/core/mail/tables/core_mail_incoming_messages.jsonc` — sloupce,
  skupiny, indexy.
- `src/Api/Controller/AnalysisController.php` — `result()`,
  `applyMessageClassification()`, `validateAndStoreCanonical()`.
- `modules/core/mail/src/MessageProposalApplier.php` — `applyViaTarget()`,
  `TargetApplyResult`; appliery `DocumentApplier` (docs) a
  `RegistryApplier` (registry, má `resolvePartner()`).
- `modules/core/exchange/src/Resolve/PartyResolver.php` — `resolve($party,
  identifiersOnly: true)`; pořadí shody companyId → vatId → taxId → name.
- `modules/core/mail/src/IsdocImportService.php` — zápis
  `primary_type_source = 'isdoc'` (ř. ~294) a párování dodavatele (ř. ~471).
- `modules/core/mail/src/IncomingMessagesViewer.php` — `selectRows`,
  `renderRow`, `buildDetailHeader`, fulltext.
- `modules/core/mail/profiles/czech_general.jsonc` — `prompt_template`
  a `output_schema` (`additionalProperties: false`!).
- `modules/core/mail/docs/ai-analysis.md` §„Klasifikace typu zprávy".

## Potvrzená designová rozhodnutí (Anna, 2026-09-10)

- **D1 — `subject` se nepřepisuje; přidá se AI-vlastněný sloupec
  `ai_title`.** `subject` je RFC822 hlavička a u ručních zpráv jediná stopa
  vstupu uživatele. `ai_title` přepisuje každý úspěšný běh analýzy,
  uživatel ho ve formuláři needituje.
- **D2 — Zdroj titulku: `message_classification.title`** (≤ 120 znaků,
  jazyk profilu; u dokladu např. „Faktura 2026-0042 — Dodavatel s.r.o.,
  13 105 Kč", u `other` stručný popis obsahu). Prompt i `output_schema`
  se rozšíří, verze promptu v4.2.0 → v4.3.0. Když `title` chybí, server
  složí fallback deterministicky z canonicalu.
- **D3 — `ai_title` se zobrazí místo `subject` jen když je předmět
  generický nebo prázdný, nebo `source_type = 1` (manual).** Generičnost
  určuje config seznam regexů `core.mail.genericSubjectPatterns`.
  U běžných e-mailů uživatel hledá podle předmětu, který zná ze svého
  klienta. Fulltext prohledává `subject` i `ai_title` vždy.
- **D4 — Partner = dva sloupce:** `partner_person` (FK `base_persons_persons`,
  nullable, index) + `partner_name` (varchar 200, denormalizace pro
  hledání a pro partnery, kteří ještě nejsou v Osobách). `sender_person`
  zůstává — má jiný význam (kdo zprávu poslal).
- **D5 — Partner se plní ve dvou vrstvách:**
  1. Při `POST /result`: `partner_name` ze `supplier.name` (docs) /
     `party.name` (registry); `partner_person` best-effort přes
     `PartyResolver::resolve(…, identifiersOnly: true)` — **jen
     deterministická shoda identifikátorem (IČO/DIČ/VAT ID)**, nikdy
     shoda jménem, nikdy create.
  2. Při Použít: přepíše se autoritativně partnerem založeného záznamu
     (`docs_core_heads.partner` / `base_registry_documents.partner`).
  3. ISDOC import plní obě vrstvy sám (obchází AI).
- **D6 — Backfill existujících zpráv se neřeší.** Vyřeší se v návrhu
  importu ze starého Shipardu.
- **D7 — Zobrazení:** t1 = titulek dle D3; t2 = partner (jméno Osoby ??
  `partner_name`) s fallbackem na odesílatele; odesílatel se přesune do t3
  (`[Schránka] od: …`). Detail header analogicky.
- **D8 — Ruční partner má přednost (bez nového `*_source` sloupce):**
  `partner_person` je ve formuláři editovatelný. Vrstva 1 (`/result`,
  ISDOC) zapisuje `partner_person` **jen když je NULL** a zároveň
  `target_row IS NULL`; `partner_name` přepisuje vždy, dokud
  `target_row IS NULL`. Vrstva 2 (Použít) přepisuje `partner_person` vždy —
  založený záznam je autoritativní.

## Scope

**V rozsahu**

- Nové sloupce `partner_person`, `partner_name`, `ai_title` + index.
- Config `core.mail.genericSubjectPatterns`.
- `/result`: zápis partnera (fáze 1) a titulku (fáze 2).
- Použít (docs i registry): autoritativní zápis `partner_person`.
- ISDOC import: partner + titulek.
- Viewer (řádek, detail header, fulltext), formulář (`partner_person`).
- Profil `czech_general`: prompt + schema, v4.3.0.
- Dataset exporter/seeder + fake generator: nové sloupce.
- Testy.

**Mimo rozsah**

- Backfill historických zpráv (D6 → import ze starého Shipardu).
- Sloupec `*_source` pro partnera / titulek (D8 řeší pravidlem).
- Změna `source_type` u skenů (skener posílá e-mail, `source_type = 2`
  je věcně správně; detekce jde přes předmět).
- MCP nástroj `MailListPendingTool` a dashboard `MailSuggestionsSource` —
  přidání partnera/titulku do jejich výstupu je drobný follow-up po fázi 2.
- Generování enumu typů v promptu z `primaryTypes.jsonc` (známé future work).

## Datový model

`core_mail_incoming_messages.jsonc`:

```jsonc
// nová skupina
{ "id": "partner", "name": "Partner", "name:cs": "Partner", "name:en": "Partner" }

// --- partner (protistrana dokumentu, ne odesílatel) ---
{
    // Osoba, která doklad/dokument vystavila. Vrstva 1 (/result, ISDOC)
    // zapisuje jen do NULL a jen dokud target_row IS NULL; vrstva 2
    // (Použít) přepisuje partnerem založeného záznamu. Ručně editovatelné.
    "id": "partner_person", "type": "int", "nullable": true,
    "reference": "base_persons_persons", "group": "partner",
    "name": "Partner (person)", "name:cs": "Partner (osoba)", "name:en": "Partner (person)"
},
{
    // Jméno protistrany z canonicalu (supplier.name / party.name) —
    // denormalizace pro fulltext a pro partnery mimo Osoby.
    "id": "partner_name", "type": "varchar", "length": 200, "nullable": true,
    "group": "partner",
    "name": "Partner name", "name:cs": "Partner (název)", "name:en": "Partner name"
},

// --- ai ---
{
    // Titulek zprávy z AI (message_classification.title) nebo serverový
    // fallback z canonicalu. AI-vlastněný: přepisuje každý úspěšný běh.
    "id": "ai_title", "type": "varchar", "length": 200, "nullable": true,
    "group": "ai",
    "name": "AI title", "name:cs": "Titulek (AI)", "name:en": "AI title"
}

// index
{ "id": "idx_partner_person", "type": "index", "columns": [{"column": "partner_person"}] }
```

Nový config `modules/core/mail/config/genericSubjectPatterns.jsonc`
(cfgItem `core.mail.genericSubjectPatterns`, registrace v `module.jsonc`
podle vzoru ostatních `config/*.jsonc`): pole PCRE vzorů bez delimiterů,
case-insensitive, např. `"^Message from "`, `"^Scan(ned)?( from)?\\b"`,
`"^Skenov"`, `"^Scan_\\d"`, `"^(image|img|doc)[_-]?\\d+"`. Vzory jsou
obecné — žádné konkrétní modely zařízení ani názvy firem.

## Datový tok

```
E-mail / sken / ručně ──► core_mail_incoming_messages (subject, sender_*)
        │
        ▼
AI analyzer ──► POST /result
        │   message_classification.{primary_type, confidence, title}
        │   document.extracted_json.{supplier|party}.{name, companyId, …}
        ▼
AnalysisController::result (jedna transakce)
        ├─ primary_type / primary_type_source='ai'   (stávající)
        ├─ ai_title  ← title ?? MessageTitleComposer::fromCanonical()   [F2]
        ├─ partner_name ← supplier.name | party.name        (target_row IS NULL)
        └─ partner_person ← PartyResolver identifiersOnly   (jen do NULL)   [F1]

ISDOC import (IsdocImportService) — stejné zápisy, zdroj = parser      [F1+F2]

Použít (MessageProposalApplier::applyViaTarget)
        └─ TargetApplyResult.partnerId ──► partner_person (vždy)         [F1]

Viewer / formulář / fulltext
        t1 = IncomingMessageTitle::display(subject, ai_title, source_type, patterns)
        t2 = person.full_name ?? partner_name ?? sender
        t3 = [Schránka] od: sender · první řádek těla
        LIKE přes subject, ai_title, partner_name, sender_*, body_plain
```

## Co je potřeba udělat

### Fáze 1 — partner (D4, D5, D7 část, D8)

1. **Tabulka:** skupina `partner`, sloupce `partner_person`, `partner_name`,
   index `idx_partner_person`. Ověřit `ds-upgrade` migraci (Anna spouští
   ručně na alfě).
2. **`AnalysisController::result`:** nová privátní metoda
   `applyPartnerFromCanonical(\Dibi\Connection $dibi, int $messageNdx,
   ?array $canonical, string $proposedType)` volaná ve stejné transakci
   jako `applyMessageClassification`. Strana: docs → `supplier`
   (selfParty je vždy customer; pro jistotu přepnout na `customer`, pokud
   `selfParty === 'supplier'`), registry → `party`. Neběží, když je
   canonical `null` nebo nese `_validationError`. UPDATE s podmínkami
   `target_row IS NULL` (name) a navíc `partner_person IS NULL` (person).
   `partner_name`: trim, sjednocení whitespace, `mb_substr(…, 0, 200)`.
3. **`TargetApplyResult::ok(int $savedId, ?int $partnerId = null)`;**
   `DocumentApplier` vrací `partnerId` (už ho zná — `$partnerId` v plánu
   hlavičky), `RegistryApplier` vrací `$partner` z `resolvePartner()`.
   `MessageProposalApplier::applyViaTarget` po úspěchu UPDATE
   `partner_person = partnerId` (když není null) — ve stejném místě, kde se
   zapisuje resolution; selhání jen warning, ne rollback (symetrie
   s `writeApplyResolution`).
4. **`IsdocImportService`:** po zápisu `primary_type_source = 'isdoc'`
   zavolat stejnou logiku jako bod 2 (sdílená třída, ne kopie — např.
   `MessagePartnerWriter` v `modules/core/mail/src/`).
5. **Viewer:** `selectRows` LEFT JOIN `base_persons_persons p ON p.id =
   m.partner_person` → `partner_full_name`; t2 dle D7; odesílatel do t3;
   fulltext + `partner_name`. `buildDetailHeader`: subtitle = partner ·
   od: odesílatel · schránka · doručeno. Ověřit skutečný název sloupce
   jména Osoby v `base_persons_persons.jsonc` (PartyResolver používá
   `full_name`).
6. **Formulář `IncomingMessagesForm`:** `partner_person` jako reference
   na Osoby (editovatelné), `partner_name` readOnly info. Labely v `cs.js`
   / form definici — ověřit ve zdroji, ne v docs.
7. **Dataset:** `MailExporter` / `MailSeeder` / `FakeIncomingMessageGenerator`
   znají nové sloupce (u fake dat `partner_name` z fiktivního dodavatele).
8. **Testy (phpunit):** (a) result zapíše `partner_name` + `partner_person`
   při shodě IČO, ne při shodě jen jménem; (b) result nepřepíše ručně
   nastavený `partner_person`; (c) result nepřepíše nic při `target_row`;
   (d) apply přepíše `partner_person` autoritativně; (e) viewer t2/t3.
9. Aktualizovat `modules/core/mail/docs/ai-analysis.md` (nová sekce
   „Partner zprávy") a `tasks/README.md` (řádek v tabulce Došlá pošta).
   Commit, hlavička `**Stav:** částečně — fáze 1 hotovo`.

**Poznámky k implementaci fáze 1 (odchylky schválené 2026-09-10):**

- Bod 3: `TargetApplyResult` ani `ApplyResult` se nerozšiřují. Partner po
  Použít se čte z **cílového záznamu** přes `target_table_id` /
  `target_row` zprávy (`MessageProposalApplier::targetPartnerId`) — jednotně
  pro docs, registry i recovery cestu `completeApplied`. Docs cesta vrací
  sdílený `ApplyResult` z exchange, který partnera nenese, a `_resolve`
  neodráží pravidla hlavičkového partnera u pokladních dokladů.
- Vrstva 1 při canonicalu **bez jména** protistrany `partner_name` nenuluje
  (ponechá předchozí hodnotu); „přepisuje vždy" míní hodnotu.
- Vrátit (unapply) `partner_person` nesahá — partner založeného dokladu byl
  s velkou pravděpodobností správně a re-analýza ho dle D8 nepřepíše.
- Fulltext prohledává i `full_name` Osoby (LEFT JOIN), jinak by ručně
  vybraný partner bez `partner_name` nebyl dohledatelný.
- Sdílená třída se jmenuje `MessagePartnerWriter`; `IsdocImportService` ji
  dostává injektovanou (bez ní partnera nezapisuje — unit testy), wiring
  v `public/index.php` a `PreprocessRunnerFactory`.
- Popisek „od:" v t3 / detailu jde z cfgItem
  `core.mail.viewerDetailLabels.labels.from` (backend labels přes cfgItems).

### Fáze 2 — titulek (D1, D2, D3, D7 část)

1. **Tabulka:** sloupec `ai_title`.
2. **Config** `genericSubjectPatterns.jsonc` + registrace cfgItem.
3. **Profil `czech_general.jsonc`:** prompt — bod TRIAGE doplnit o `title`
   (≤ 120 znaků, česky, „co to je + od koho + částka/číslo, je-li"; u `other`
   stručný popis; nikdy název souboru, nikdy opis předmětu, je-li
   generický); ukázkové JSONy doplnit; `source.promptVersion` → `v4.3.0`
   a odpovídající pole verze profilu. `output_schema.message_classification.
   properties.title` = `{"type": "string", "maxLength": 120}` (ne
   required — starší analyzer/prompt nesmí spadnout na 422).
   Po commitu: Anna `ai-profile-reload` / `ds-upgrade` na alfě.
4. **`MessageTitleComposer`** (čistá třída, `modules/core/mail/src/`):
   `fromCanonical(?array $canonical, string $proposedType, array
   $primaryTypeLabels): ?string` — docs: `{label typu} {docNumber} —
   {supplier.name}, {totals.totalAmount} {currency}` (chybějící části
   vynechat); registry: `title`; bez dokumentu → `null`. Používá ho
   `/result` jako fallback a ISDOC jako primární zdroj.
5. **`AnalysisController::applyMessageClassification`:** `ai_title` ←
   `classification.title` (trim, `mb_substr 200`) ?? composer fallback.
   Zapisuje se vždy (AI-vlastněné), i `null` → tj. re-analýza bez
   dokumentu titulek smaže.
6. **`IncomingMessageTitle::display(string $subject, ?string $aiTitle,
   int $sourceType, array $patterns): string`** (čistá třída) — pravidlo
   D3. Použít ve vieweru (t1, detail title), ve formuláři (`header_info`),
   v `FileFromMessageService` (název registry dokumentu ze zprávy) a všude,
   kde se dnes bere `subject` jako lidský název zprávy — projít
   `grep -rn "'subject'" src modules`. Když se titulek použije místo
   předmětu, původní `subject` ukázat v detailu v „Technické údaje".
7. Fulltext + `ai_title`.
8. **Testy:** composer (docs/registry/null), display pravidlo D3
   (generický vzor / prázdný / manual / normální e-mail), result zapíše
   title i fallback, ISDOC nastaví titulek.
9. Aktualizovat `ai-analysis.md`, `ai-prompts.md` (v4.3.0),
   `tasks/README.md`; hlavička `**Stav:** hotovo`.

## Akceptační kritéria (Hotovo když)

- [ ] Sken s předmětem odpovídajícím `genericSubjectPatterns` má po analýze
      v seznamu t1 = titulek z AI, t2 = dodavatel (Osoba nebo `partner_name`),
      t3 = `[Schránka] od: <technický odesílatel>`.
- [ ] Běžný e-mail s normálním předmětem má t1 = původní předmět; `ai_title`
      je vyplněný a dohledatelný fulltextem.
- [ ] Fulltext najde zprávu podle jména dodavatele i podle textu titulku.
- [ ] `/result` nastaví `partner_person` při shodě IČO; při pouhé shodě
      jménem zůstane NULL a vyplní se jen `partner_name`.
- [ ] Ručně vybraný `partner_person` re-analýza nepřepíše; Použít ho přepíše
      partnerem založeného dokladu.
- [ ] Zpráva s `target_row` po re-analýze nemění `partner_*`.
- [ ] ISDOC import nastaví `partner_*` i `ai_title` bez AI.
- [ ] Starší analyzer posílající `message_classification` bez `title` projde
      (žádná 422), `ai_title` dostane serverový fallback.
- [ ] `php -l`, `phpunit --filter` dotčených tříd, `npm run build` zelené;
      `check-sensitive.py` bez nálezu.

## Pasti

- **P1 — Skener není `source_type = 4`.** Na alfě chodí skeny jako
  `source_type = 2` (e-mail od zařízení). Detekce generického předmětu
  musí jít přes vzory, nikdy přes `source_type` (ten se drží jen pro
  `manual = 1` v D3).
- **P2 — Re-analýza po Použít.** `reanalyze` je možný ve stavech 30/70;
  bez guardu `target_row IS NULL` by `/result` přepsal autoritativního
  partnera. `ai_title` guard nemá (AI-vlastněný) — to je záměr.
- **P3 — `additionalProperties: false` v `output_schema`.** Přidání `title`
  do promptu bez přidání do schématu = odmítnutí celého výstupu u každé
  zprávy. Naopak `title` nesmí být `required` (P8).
- **P4 — Analyzer v jiném repu (`ai_analyzer`).** Ověřit, že
  `message_classification` posílá do `/result` beze změny (pass-through);
  pokud ho skládá sám z vyjmenovaných klíčů, `title` by se ztratil a šel by
  jen fallback.
- **P5 — Shoda jménem je nebezpečná.** `PartyResolver` bez `identifiersOnly`
  matchuje `full_name LIKE %name%`; u tisíců skenů by ukazoval špatné
  partnery. Ve vrstvě 1 výhradně `identifiersOnly: true`.
- **P6 — ISDOC obchází `/result`.** Bez bodu F1.4 / F2.4 zůstanou
  ISDOC zprávy bez partnera i titulku, přestože mají nejkvalitnější data.
- **P7 — `partner_name` vs. jméno Osoby.** Po Použít může `partner_name`
  (z canonicalu) znít jinak než `full_name` Osoby; zobrazení preferuje
  Osobu (D7), `partner_name` se nepřepisuje — je to historický snapshot
  toho, co bylo na dokladu.
- **P8 — Kompatibilita kontraktu.** `title` volitelný; verze promptu se
  bumpuje, ale server musí přijmout i v4.2.0 výstup.
- **P9 — JOIN na Osoby ve `selectRows`.** Smazaná/archivovaná Osoba →
  t2 padá na `partner_name`; JOIN nesmí řádky filtrovat (LEFT JOIN).
- **P10 — Citlivá data.** Do testů, fixtures a do tohoto tasku jen fiktivní
  dodavatelé, IČO, částky; vzory v `genericSubjectPatterns` bez konkrétních
  modelů zařízení.

## Otevřené body

- Má `title` u `other` vůbec vznikat, když zpráva nenese dokument
  (newsletter)? Návrh: ano, stručný popis — právě u `other` skenů (dopis
  bez dokladu) je to jediná použitelná informace. Rozhodnout při ladění
  promptu na alfě.
- Follow-up: partner + titulek do `MailListPendingTool` (MCP) a do
  dashboardových karet `MailSuggestionsSource`.
