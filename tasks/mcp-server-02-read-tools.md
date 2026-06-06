# Task: MCP server — zbývající čtecí nástroje (Fáze 2)

## Kontext

Fáze 1 (`mcp-server-01-skeleton.md`) postavila kostru MCP serveru a první čtecí
nástroj `persons_search`. Tato fáze přidává **tři další čtecí nástroje**, čímž
se zaokrouhlí čtecí vrstva katalogu (čtení = bez brzdy; zápisové/akční nástroje
jsou pozdější fáze):

- `persons_get` — detail osoby (karta + počet napojených dokladů)
- `documents_search` — vyhledávání dokladů (faktury) s filtry
- `mail_list_pending` — došlá pošta čekající na pozornost, se stavem analýzy

Skelet je hotový, takže jde primárně o **tři třídy nástroje** stejného vzoru
(`McpTool`) + jejich registraci. Wire formát, JSON-RPC vrstva, auth gate a
mapování obálky → `content`/`structuredContent` se nemění.

**Uzavřená rozhodnutí (z designové diskuze):**

- `persons_get` vrací **kartu** (identita + adresy + banka + kontakty) a jen
  `documents_count`; seznam dokladů se NEvkládá — od toho je `documents_search`
  s `ref` osoby. (varianta A)
- `documents_search` filtruje přes `partner`, `doc_type`, období na
  **`accounting_date`** (ne issue_date), `state`, `overdue` a volný text.
  `overdue` je **příznak** (ne samostatný nástroj) a v popisu má disclaimer
  „po splatnosti ≠ neuhrazeno — stav úhrady systém bez saldokonta nezná".
  Žádný `paid/unpaid` filtr.
- `mail_list_pending` = **varianta C**: vrací zprávy `docState != 40`
  (nezpracované), každá nese stav analýzy + počet extrahovaných dokladů
  čekajících na akci; volitelně jen ty „akční".

## Návaznost

- Staví na Fázi 1: `McpTool` interface, `McpToolRegistry`,
  `McpInvocationContext`, `McpController` wire-mapping, doménová obálka
  `{summary, items[ref], pagination}`. Nové nástroje **nemění** žádnou z těchto
  tříd.
- `persons_search` (Fáze 1) je **vzorová implementace** — nové nástroje ho
  kopírují strukturou (input schema, dotaz, obálka).

## Před implementací přečti

- **`modules/base/persons/src/Mcp/PersonsSearchTool.php`** — referenční nástroj.
  Stejný tvar `name()`/`description()`/`inputSchema()`/`call()` a obálky drž i u
  nových.
- **`src/Api/Mcp/McpTool.php`, `McpToolRegistry.php`, `McpInvocationContext.php`**
  — kontrakt. `McpInvocationContext` nese `$auth`, `$db`, `$tables`, `$config`.
- **`src/Api/Controller/McpController.php`** — mapování obálky → wire formát
  (nemění se; jen ověř, že `tools/list` a `tools/call` projdou nově
  registrované nástroje).
- **`public/index.php`** — `dispatchMcp()`: sem přibydou tři `register()` (viz
  bod 4).
- **`modules/docs/core/tables/docs_core_heads.jsonc`** + **`config/docStates.jsonc`**
  — sloupce (`partner`, `doc_type`, `issue_date`, `due_date`, `accounting_date`,
  `doc_currency`, `doc_number`, `partner_doc_number`, `total_amount`,
  `total_amount_dom`, `docState`) a stavy: 10 Koncept, 20 Potvrzeno, 80 V opravě,
  40 V pořádku, 30 Storno, 90 Smazáno (`viewGroup: trash`). Indexy
  `idx_partner`, `idx_accounting_date`.
- **`modules/base/persons/tables/base_persons_{addresses,bank_accounts,contacts}.jsonc`**
  — sub-tabulky osoby (`person` FK, `docState`, `valid_to`; adresa má
  `display_line`/`display_block`, banka `account_number`/`iban`/`bic`/`currency`,
  kontakt `name`/`role`/`email`/`phone`).
- **`modules/core/mail/tables/core_mail_incoming_messages.jsonc`** — `docState`
  (`!= 40` = nezpracovaná), `subject`, `sender_name`/`sender_email`,
  `sender_person` (FK), `received_at`, `mailbox`, `idx_doc_state`,
  `idx_received_at`.
- **`modules/core/mail/tables/core_mail_message_analyses.md`** — „current"
  analýza = `MAX(analyzed_at)` per message; `status` 1 pending / 2 success /
  3 failed; `extracted_document_count`.
- **`modules/core/mail/tables/core_mail_extracted_documents.md`** — `status`:
  akční = 10 ready_to_apply / 20 pending_review / 30 low_confidence; vyřešené =
  40 applied / 50 rejected / 60 superseded / 70 ai_failed. Index
  `idx_message_status`.
- **`src/Api/Controller/PersonsRegistryController.php`** — připomínka, že
  `persons_*` nástroje sahají do **lokální** evidence, ne do ARES.

## Scope

**V rozsahu:** tři čtecí nástroje (`persons_get`, `documents_search`,
`mail_list_pending`), jejich registrace v `dispatchMcp`, testy.

**Mimo rozsah:**

- Jakýkoli zápis/akce (apply/reject extrahovaných dokladů, vystavení dokladu) —
  pozdější zápisový tier.
- Stav úhrady / saldokonto — `documents_search` umí jen „po splatnosti" přes
  `due_date`, ne „neuhrazeno".
- Module-driven registrace nástrojů (`module.jsonc` `mcpTools` + `McpToolLoader`)
  — viz Rozhodnutí #5; ve fázi 2 zůstává in-code registrace. Přechod je vhodný
  jako samostatný follow-up.
- `persons_get` nevkládá seznam dokladů (jen count).

## Co implementovat

### 1. `persons_get` (`modules/base/persons/src/Mcp/PersonsGetTool.php`)

**`name()`** → `persons_get`

**`description()`** (LLM-facing):
> „Vrátí detail jedné osoby/firmy z lokální evidence — identitu (jméno, IČO,
> DIČ, kód osoby), adresy, bankovní spojení, kontakty a počet napojených
> dokladů. ID osoby získáš z `persons_search`. Pro seznam jejích dokladů zavolej
> `documents_search` s `partner` = toto ID."

**`inputSchema()`**: `{ type:object, properties:{ id:{type:integer, description:"ID osoby z persons_search"} }, required:["id"] }`

**`call()`**:
1. Načti osobu z `base_persons_persons` WHERE `id` = `id` AND `docState != 90`.
   Nenalezeno → obálka se `summary` „Osoba #id nenalezena." a prázdnými `items`
   (ne chyba; nech LLM reagovat). Pole: `full_name`, `person_id`, `person_type`
   (mapuj na `company`/`person` přes `PersonType`), `company_id` (IČO),
   `vat_id`/`tax_id` (DIČ), `email`, `birth_date`.
2. Sub-tabulky (vždy `docState != 90`, jen aktuální — `valid_to IS NULL`):
   - adresy (`base_persons_addresses`): `address_type`, `display_line` (nebo
     složit z street/city/zip), `city`, `zip`, `country`.
   - banka (`base_persons_bank_accounts`): `account_number`, `iban`, `bic`,
     `currency`, `name`.
   - kontakty (`base_persons_contacts`): `name`, `role`, `email`, `phone`.
3. `documents_count` = `SELECT COUNT(*) FROM docs_core_heads WHERE partner = id
   AND docState != 90` (index `idx_partner`).
4. Obálka — `items` má **jednu** položku (karta osoby) s `ref:{type:'person',id}`:

```php
return [
  'summary' => "{$fullName} — {$typeLabel}" . ($ico ? ", IČO {$ico}" : '') . ", {$docCount} dokladů.",
  'items'   => [[
    'ref'         => ['type' => 'person', 'id' => $id],
    'full_name'   => $fullName,
    'person_type' => $typeLabel,
    'company_id'  => $ico ?: null,
    'vat_id'      => $dic ?: null,
    'email'       => $email ?: null,
    'addresses'   => $addresses,      // list kurátorovaných polí
    'bank_accounts' => $banks,
    'contacts'    => $contacts,
    'documents_count' => $docCount,
  ]],
  'pagination' => null,               // detail, ne seznam
];
```

### 2. `documents_search` (`modules/docs/core/src/Mcp/DocumentsSearchTool.php`)

Namespace `Shipard\Module\Docs\Core\Mcp\`.

**`name()`** → `documents_search`

**`description()`** (LLM-facing, s disclaimerem):
> „Vyhledá doklady (faktury vydané/přijaté) podle partnera, typu, účetního
> období, stavu a po splatnosti. `partner` je ID osoby z `persons_search`.
> Období filtruje **účetní datum**. `overdue=true` vrátí doklady **po
> splatnosti** (datum splatnosti < dnes, nestornované) — POZOR: to NENÍ totéž
> co „neuhrazené"; systém bez saldokonta stav úhrady nezná, nelze říct, jestli
> je faktura zaplacená. Nepoužívej pro osoby (`persons_search`/`persons_get`)
> ani pro došlou poštu (`mail_list_pending`)."

**`inputSchema()`**:
```json
{
  "type": "object",
  "properties": {
    "partner": { "type": "integer", "description": "ID osoby (partnera) z persons_search" },
    "doc_type": { "type": "string", "description": "Typ dokladu, např. 'invno' (faktura vydaná) nebo 'invni' (faktura přijatá)" },
    "accounting_date_from": { "type": "string", "description": "Účetní datum od (YYYY-MM-DD)" },
    "accounting_date_to":   { "type": "string", "description": "Účetní datum do (YYYY-MM-DD)" },
    "overdue": { "type": "boolean", "default": false, "description": "Jen doklady po splatnosti (due_date < dnes, nestornované). NE 'neuhrazené'." },
    "state": { "type": "string", "enum": ["active", "done", "all"], "default": "active", "description": "active = bez smazaných; done = jen V pořádku; all = vč. smazaných" },
    "query": { "type": "string", "description": "Volný text přes doc_number / partner_doc_number" },
    "limit": { "type": "integer", "minimum": 1, "maximum": 50, "default": 20 },
    "offset": { "type": "integer", "minimum": 0, "default": 0 }
  }
}
```

**`call()`**:
- Základ: `SELECT` z `docs_core_heads` s LEFT JOIN na `base_persons_persons`
  (přes `partner`) pro jméno partnera. Sloupce: `id`, `doc_type`, `doc_number`,
  `partner`, partner `full_name`, `accounting_date`, `due_date`, `total_amount`,
  `doc_currency`, `docState`.
- Filtry (WHERE, whitelistované):
  - `state`: `active` → `docState != 90` (default); `done` → `docState = 40`;
    `all` → bez omezení.
  - `partner` → `partner = %i`.
  - `doc_type` → `doc_type = %s`.
  - `accounting_date_from`/`_to` → rozsah na `accounting_date` (index
    `idx_accounting_date`).
  - `overdue=true` → `due_date < CURDATE() AND docState NOT IN (30, 90)`
    (storno ani smazané nejsou „po splatnosti").
  - `query` → `(doc_number LIKE %s OR partner_doc_number LIKE %s)`.
- `ORDER BY accounting_date DESC, id DESC`. Stránkování `LIMIT (limit+1) OFFSET`
  → `has_more`.
- Položka: `ref:{type:'document',id}`, `doc_number`, `doc_type`, `partner`
  (`{id, full_name}` nebo null), `accounting_date`, `due_date`, `total_amount`
  + `doc_currency`, `state_label` (přes `DocStateConfig` z
  `docs.core.docStates`), případně `overdue` bool dopočítaný.
- `summary`: počet + stručně (např. „3 doklady, z toho 1 po splatnosti.").

### 3. `mail_list_pending` (`modules/core/mail/src/Mcp/MailListPendingTool.php`)

Namespace `Shipard\Module\Core\Mail\Mcp\`.

**`name()`** → `mail_list_pending`

**`description()`** (LLM-facing):
> „Vrátí došlou poštu, která ještě čeká na pozornost (není zpracovaná). U každé
> zprávy uvádí stav AI analýzy a počet extrahovaných dokladů čekajících na akci
> (potvrzení/zamítnutí). `only_actionable=true` zúží na zprávy, kde nějaký
> extrahovaný doklad čeká na akci — typicky to, co má agent vyřešit."

**`inputSchema()`**:
```json
{
  "type": "object",
  "properties": {
    "only_actionable": { "type": "boolean", "default": false, "description": "Jen zprávy s extrahovanými doklady čekajícími na akci" },
    "limit": { "type": "integer", "minimum": 1, "maximum": 50, "default": 20 },
    "offset": { "type": "integer", "minimum": 0, "default": 0 }
  }
}
```

**`call()`**:
- Zprávy: `SELECT` z `core_mail_incoming_messages` WHERE `docState != 40`
  (nezpracované), `ORDER BY received_at DESC`. Index `idx_doc_state` /
  `idx_received_at`.
- Pro každou zprávu dopočítej:
  - **stav analýzy** z „current" běhu (`core_mail_message_analyses`,
    `MAX(analyzed_at)` per message): `none` (žádný běh) / `pending` (status 1) /
    `success` (2) / `failed` (3).
  - **`pending_extracted_count`** = `COUNT` z `core_mail_extracted_documents`
    WHERE `message = X AND status IN (10, 20, 30)` (akční stavy; index
    `idx_message_status`).
  - Efektivně: agreguj přes JOIN/subquery, ne N+1 per zpráva.
- `only_actionable=true` → ponech jen zprávy s `pending_extracted_count > 0`.
  (Aplikuj filtr v SQL, ať stránkování sedí.)
- Položka: `ref:{type:'mail_message',id}`, `subject`, `sender`
  (`{name, email, person:{id}|null}`), `received_at`, `mailbox`, `state_label`
  (z `core.mail.docStatesIncoming`), `analysis_status`, `pending_extracted_count`.
- Stránkování `LIMIT (limit+1) OFFSET` → `has_more`.
- `summary`: počet čekajících zpráv + kolik z nich má akční doklady.

### 4. Registrace v `dispatchMcp`

V `public/index.php` (`dispatchMcp`), k existujícímu `persons_search`:

```php
$registry->register(new \Shipard\Module\Base\Persons\Mcp\PersonsSearchTool());
$registry->register(new \Shipard\Module\Base\Persons\Mcp\PersonsGetTool());
$registry->register(new \Shipard\Module\Docs\Core\Mcp\DocumentsSearchTool());
$registry->register(new \Shipard\Module\Core\Mail\Mcp\MailListPendingTool());
```

(In-code, konzistentní s Fází 1 a `dispatchExchange`. Module-driven loading je
follow-up — viz Rozhodnutí #5.)

### 5. Testy

Vzor stávajícího `McpControllerTest`. Seeduj fake data (osoba + sub-tabulky;
doklady s partnerem, různými `docState`/`due_date`; zprávy s/bez analýzy a
akčních extrahovaných dokladů).

- `tools/list` obsahuje všechny **čtyři** nástroje.
- `persons_get`: existující ID → karta s adresami/bankou/kontakty +
  `documents_count`; neexistující ID → prázdné `items` (ne chyba).
- `documents_search`: filtr `partner`; rozsah `accounting_date`;
  `state=done` vs default; `overdue=true` (po splatnosti, vyloučí storno 30 i
  smazané 90); `query` přes doc_number; stránkování `has_more`.
- `mail_list_pending`: vrací jen `docState != 40`; `analysis_status` a
  `pending_extracted_count` správně; `only_actionable=true` zúží správně.
- Každý nástroj vrací MCP `content` (text) + `structuredContent` (obálka).

## Hotovo když

1. `tools/list` vrací `persons_search`, `persons_get`, `documents_search`,
   `mail_list_pending` — každý s `description` a `inputSchema`.
2. `persons_get` vrací kartu osoby (identita + adresy + banka + kontakty +
   `documents_count`), bez vloženého seznamu dokladů.
3. `documents_search` filtruje dle partnera / typu / účetního období / stavu /
   po splatnosti / volného textu; `overdue` korektně vylučuje storno i smazané;
   popis nástroje obsahuje disclaimer o úhradě.
4. `mail_list_pending` vrací nezpracované zprávy se stavem analýzy a počtem
   akčních extrahovaných dokladů; `only_actionable` filtruje.
5. Všechny tři nové nástroje jsou registrované a vrací standardní obálku
   (`content` + `structuredContent`), DS-scoped.
6. Testy procházejí.

## Doporučené pořadí implementace

1. `persons_get` (nejjednodušší, navazuje na `persons_search`) + registrace +
   test.
2. `documents_search` (filtry + partner join + overdue) + test.
3. `mail_list_pending` (agregace analýzy + akčních dokladů) + test.
4. Dotažení edge cases + `tools/list` test na čtyři nástroje.

## Rozhodnutí k designu (potvrzená)

1. ✓ **`persons_get` = karta + `documents_count`**, bez vloženého seznamu
   dokladů (od toho `documents_search` s `partner`). Varianta A.
2. ✓ **`documents_search` filtruje období přes `accounting_date`**, default
   `state=active` (vyloučí smazané 90).
3. ✓ **`overdue` je příznak, ne nástroj**; `due_date < dnes AND docState NOT IN
   (30,90)`. Popis explicitně odlišuje „po splatnosti" od „neuhrazeno"; žádný
   `paid/unpaid` filtr (bez saldokonta).
4. ✓ **`mail_list_pending` = varianta C**: zprávy `docState != 40`, per-zpráva
   stav analýzy (`none/pending/success/failed`) + počet extrahovaných dokladů
   ve stavech 10/20/30; `only_actionable` filtruje na `> 0`.
5. ✓ **In-code registrace** i ve Fázi 2 (čtyři `register()` v `dispatchMcp`).
   Module-driven `mcpTools` v `module.jsonc` + `McpToolLoader` je teď sice už
   namístě (nástroje pokrývají tři moduly), ale řešíme ho **samostatným
   follow-up taskem**, ať Fáze 2 zůstane o nástrojích, ne o infra refaktoru.
6. ✓ **Obálka i wire-mapping beze změny** z Fáze 1.

## Otevřené body (k ověření, neblokující)

- **Kódy `docState` zpráv** v `core.mail.docStatesIncoming` (potvrdit, že
  „nezpracovaná" = `!= 40`; ověřit existenci 30 Analyzovaná). Načti config při
  implementaci.
- **`doc_type` join na partner name** — ověřit přesný název FK sloupce a že
  LEFT JOIN sedí (partner může být null u rozpracovaných).
- **`state_label`** — použít `DocStateConfig` z `docs.core.docStates` resp.
  `core.mail.docStatesIncoming` (vyžaduje `ConfigRuntime` v kontextu; ten už
  `McpInvocationContext` nese).
