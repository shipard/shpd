# Task: Exchange Format pro Osoby — Fáze 1

## Kontext

Stavíme **kanonický výměnný formát pro Osoby** — `shpd.persons.person.v1`.
Sourozenec existujícího `shpd.docs.document.v1` (modul `core.exchange` má
už kompletně udělaný Document flow).

**Cíl Fáze 1:** Validate / Preview / Apply pipeline pro Person canonical,
včetně sub-kolekcí (addresses, bankAccounts, contacts) a merge strategií
(`createOnly`, `updateHeader`, `mergeAdd`, `fullSync`). Apply musí umět
vytvořit i aktualizovat (mergovat) existující osobu.

**Fáze 2 (následuje):** ARES / vlastní Shipard registry adaptér. Nepatří
do tohoto tasku — staví se na výsledku Fáze 1.

**Mimo scope Fáze 1:**

- Frontend UI (modal náhled, popover pro userAction) — analogie Fáze 3
  z `tasks/exchange-format-phase1.md`. Přijde samostatně.
- `PersonExporter` (DB → canonical) — Fáze 3 podle spec.
- Batch apply — Fáze 3.

## Před implementací přečti

Kompletně:

- **`docs/exchange-format-persons.md`** — kanonická specifikace. Tento task
  implementuje vše tam popsané (sekce 3–13). Pokud najdeš nejasnost,
  preferuj specifikaci a doplň otázku do "Otevřené body".
- **`docs/exchange-format.md`** — sourozenec spec pro doklady. Definuje
  obecnou architekturu (3 vrstvy), pojmosloví, ResolveResult statusy,
  apply pipeline pattern, REST error shape, source lineage pattern.
- **`modules/core/exchange/README.md`** — stav existujícího modulu.

Klíčové existující soubory (nepřepisuj, modeluj podle nich):

- **`modules/core/exchange/src/Document/DocumentApplier.php`** — vzor
  pro `PersonApplier`. Outer transakce, validation gate, reconcile,
  side-creates, transform, save, lineage.
- **`modules/core/exchange/src/Document/DocumentValidator.php`** — vzor
  pro `PersonValidator` (PHP validace navíc proti JSON Schema).
- **`modules/core/exchange/src/Resolve/PartyResolver.php`** — existující
  header resolver. Bude **rozšířen** o `personType` filter parametr
  (zpětně kompatibilní; default `null`).
- **`modules/core/exchange/src/Resolve/BankAccountResolver.php`** — bude
  **přepoužit beze změny** pro sub-kolekci `bankAccounts`.
- **`modules/core/exchange/src/Resolve/ResolveResult.php`**,
  `ResolveStatus.php` — sdílené statusy. **Bez změny.**
- **`modules/core/exchange/src/Schema/SchemaLoader.php`**,
  `SchemaValidator.php` — registrujeme druhé schema, jinak nezasahujeme.
- **`modules/core/exchange/src/Document/TransactionlessTableGateway.php`**
  — outer-transaction gateway. Použij i v `PersonApplier`.
- **`modules/base/persons/src/PersonDocument.php`** — `validate` +
  `beforeSave` hooky. PersonApplier nesmí dělat nic, co PersonDocument
  už dělá (auto-fill person_id, complex_name odvození, full_name skládání,
  is_own kontroly). Stejně jako DocumentApplier deleguje na DocDocument.
- **`modules/base/persons/src/PersonType.php`** — PHP enum.
- **`modules/base/persons/tables/*.jsonc`** + **`*.md`** — schémata
  cílových tabulek. Pozor na nullable sloupce — payload nemusí obsahovat
  všechno.
- **`modules/world/divisions/`** — tabulka `world_divisions`. AddressResolver
  potřebuje mapování `divisionCode` → `world_divisions.id` (lookup
  podle `code`).

Vzorové JSON fixtures pro inspiraci stylu testů:

- **`tests/Fixtures/Exchange/`** — existující doc fixtures (invoiceReceived,
  invoiceIssued).
- **`tests/Integration/Exchange/`** — existující E2E testy.

## Co implementovat

### 1. DB schema — sub-tabulky `docState`

**Kontext.** Sub-tabulky modulu `base.persons` (`addresses`, `contacts`,
`bank_accounts`) doposud nemají `docState` ani `docStateMain`. To je
nekonzistence se sémantikou systému (viz `docs/exchange-format-persons.md`
§4a) a zároveň **latentní bug** — existující `BankAccountResolver` z Fáze 1
dokladů má v SQL `[docState] IN (10, 40, 80)` na tabulce, která ten sloupec
nemá. SQL chyba se zjevně nestala, protože resolver dosud nikdo nezavolal
v reálném flow proti DB s touto tabulkou. Tato migrace to opraví.

**Co udělat.** Do všech tří `.jsonc` (`base_persons_addresses`,
`base_persons_contacts`, `base_persons_bank_accounts`) přidat:

1. `docStates` blok na top-level (sourozenec `displayPattern`):

   ```jsonc
   "docStates": {
       "stateColumn": "docState",
       "mainColumn": "docStateMain",
       "cfgItem": "core.system.docStatesArchive"
   }
   ```

   Sdílíme stejný cfgItem jako `base_persons_persons`. Žádný nový cfgItem
   se nevyrábí.

2. Dva systémové sloupce na konec sekce `columns`:

   ```jsonc
   {
       "id": "docState",
       "name": "Document state",
       "name:cs": "Stav dokumentu",
       "name:en": "Document state",
       "type": "tinyint",
       "default": 10,
       "system": true
   },
   {
       "id": "docStateMain",
       "name": "Document state (sort)",
       "name:cs": "Stav dokumentu (řazení)",
       "name:en": "Document state (sort)",
       "type": "tinyint",
       "default": 1,
       "system": true
   }
   ```

3. Index `idx_doc_state` (jednoduchý — jen `docStateMain`):

   ```jsonc
   {
       "id": "idx_doc_state",
       "type": "index",
       "columns": [
           {"column": "docStateMain", "order": "ASC"}
       ]
   }
   ```

**Default `10`** v DB — to je pro ruční UI zadání (Koncept). Applier z importu
zapisuje `docState = 40` (V pořádku) při insertu nových sub-záznamů — viz
sekce 4.1.

**Aktualizovat tabulkové `.md`** dokumenty (`base_persons_addresses.md`,
`base_persons_contacts.md`, `base_persons_bank_accounts.md`) — přidat sekci
"Stavy dokumentů" s odkazem na `docs/exchange-format-persons.md` §4a (`valid_to`
vs `docState` jako dvě ortogonální osy).

### 2. DB schema — `base_persons_persons` lineage

Přidat tři nullable sloupce do `modules/base/persons/tables/base_persons_persons.jsonc`,
do nové skupiny `lineage`:

```jsonc
{
    "id": "lineage",
    "name": "Lineage",
    "name:cs": "Původ záznamu",
    "name:en": "Lineage"
}
```

Sloupce (v této skupině):

| Sloupec | Typ | Nullable | Group |
|---|---|---|---|
| `source_kind` | `varchar`, length 40 | ano | `lineage` |
| `source_ref` | `varchar`, length 60 | ano | `lineage` |
| `source_imported_at` | `datetime` | ano | `lineage` |

`source_kind` má `cfgItem: "base.persons.sourceKinds"` (viz dál).

Aktualizuj **`modules/base/persons/tables/base_persons_persons.md`** —
přidej sekci "Původ záznamu (lineage)" mezi "Stav" a "Obchodní logika":

```markdown
### Původ záznamu (lineage)

| Sloupec | Typ | Popis |
|---|---|---|
| `source_kind` | varchar(40) | Klíč z [base.persons.sourceKinds](../config/sourceKinds.jsonc) |
| `source_ref` | varchar(60) | Identifikátor v zdrojovém registru |
| `source_imported_at` | datetime | Čas posledního importu / sync |
```

A pridaj sekci do "Obchodní logika" o tom, že lineage vyplňuje
`PersonApplier` při apply, manuálně pořízené osoby mají `NULL`.

### 3. cfgItem `base.persons.sourceKinds`

Nový soubor **`modules/base/persons/config/sourceKinds.jsonc`** podle
spec sekce 13:

```jsonc
{
    "manual": {
        "name": "Ruční zadání", "name:cs": "Ruční zadání",
        "name:en": "Manual entry"
    },
    "aiExtraction": {
        "name": "Z AI extrakce", "name:cs": "Z AI extrakce",
        "name:en": "From AI extraction"
    },
    "import.ares": {
        "name": "ARES", "name:cs": "ARES", "name:en": "ARES"
    },
    "import.rpo": {
        "name": "RPO (SK)", "name:cs": "RPO (SK)", "name:en": "RPO (SK)"
    },
    "import.handelsregister": {
        "name": "Handelsregister",
        "name:cs": "Handelsregister",
        "name:en": "Handelsregister"
    },
    "import.shipardRegistry": {
        "name": "Shipard registr",
        "name:cs": "Shipard registr",
        "name:en": "Shipard registry"
    },
    "import.csv": {
        "name": "CSV import", "name:cs": "CSV import",
        "name:en": "CSV import"
    }
}
```

Registrovat v **`modules/base/persons/module.jsonc`** v sekci `config`:

```jsonc
{ "id": "base.persons.sourceKinds", "file": "config/sourceKinds.jsonc" }
```

Aktualizovat tabulku v **`modules/base/persons/README.md`** o nový
config klíč.

### 4. JSON Schema — `shpd.persons.person.v1`

Dva soubory v **`modules/core/exchange/schemas/`**:

- **`shpd.persons.person.v1.jsonc`** — JSONC se zdrojem (komentáře, struktura
  podle spec sekce 3, 5, 6, 7). Lidsky čitelný.
- **`shpd.persons.person.v1.json`** — strict JSON pro validátor. Vyrobeno
  ručně ze .jsonc (drift hlídá `SchemaDriftTest`, viz sekce 5).

Schema klíčové konstrukty:

- `format` — const `"shpd.persons.person"`.
- `formatVersion` — pattern `"^1\\.\\d+$"`.
- `personType` — enum `["company", "person"]`, required.
- `country` — pattern `"^[a-z]{2}$"`, required.
- `name` — object s `fullName` (required for `company`), `firstName`,
  `lastName`, `titleBefore`, `middleName`, `titleAfter`. JSON Schema
  per-personType validation v PHP (PersonValidator), ne v schema —
  schema povolí flexibilní strukturu.
- `addresses`, `bankAccounts`, `contacts` — pole objektů. Sub-schémata
  inline (Phase 1 nevytahujeme do `definitions`/`$ref` — drift management
  je nákladný; refactor až po stabilizaci).
- `applyOptions` — viz sekce 11 spec.
- `_resolve` — povol jako passthrough (`additionalProperties: true`),
  validuje PHP.

Address sub-schema: pole z spec sekce 5. `addressType` enumInt
`[1, 2, 3, 4]`. `isStandardized` boolean. `country` pattern `"^[a-z]{2}$"`.
`placeRegType` enum `[null, "ICP", "ICZ"]`.

BankAccount sub-schema: pole z spec sekce 6. `currency` pattern
`"^[A-Z]{3}$"` v canonical (uppercase), applier transformuje na lowercase.

Contact sub-schema: pole z spec sekce 7.

### 5. PHP modul — `core.exchange/src/Person/`

Nový adresář **`modules/core/exchange/src/Person/`** s analogickou strukturou
jako `Document/`:

#### 5.1 `PersonApplier.php`

Vstup: canonical array. Výstup: `ApplyResult` (sdílíme existující
`Document/ApplyResult` — případně přejmenuj na obecný `Exchange/ApplyResult`).

Pipeline podle spec sekce 11.

Klíčové implementační poznámky `docState`:

- Nové sub-záznamy (insert v kroku 7) mají `docState = 40`, `docStateMain = 2`.
  Default `10` z DB je jen pro ruční UI; import = immediately V pořádku.
- Update sub-záznamů (`mergeAdd` + `authoritativeRefresh`, `fullSync`)
  **nemění** `docState` — zůstává takový, jaký byl.
- `fullSync` closing missing sub-záznamů — jen `valid_to = today`,
  `docState` zůstává `40` (nepovažujeme to za mazání, jen za uzavření platnosti).
- Hlavička při create má `docState = applyOptions.targetDocState ?? 10`
  (existující chování `PersonDocument`).

Pipeline:

1. Schema validation (přes `SchemaLoader`/`SchemaValidator`).
2. Resolve — `PersonResolver::resolve()` (header + sub-kolekce + closingExisting).
3. Reconcile s klientským `_resolve.*.userAction`.
4. Validation gate — error severity v issues, `createOnly + matched`,
   `rejectOnIssues` honor.
5. BEGIN TRANSACTION (`TransactionlessTableGateway` wrap).
6. Header upsert přes `PersonDocument::saveDocument` (insert nebo update).
7. Sub-kolekce per typ — match strategie + authoritativeRefresh + closing.
8. Lineage update (SQL UPDATE base_persons_persons).
9. COMMIT.
10. Vrátit enriched canonical.

Klíčové implementační poznámky:

- `mergeStrategy` default `mergeAdd`, override v `applyOptions.mergeStrategy`.
- `createOnly` + matched header → throw `PersonExistsException` → controller
  vrací `409 person_exists` se shape per existující error pattern.
- `personId` v payloadu: matched header → porovnat, mismatch = warning;
  unmatched canCreate s `personId` → zkusit použít, kolize = `409 person_id_conflict`.
- `fullSync` closing: SQL UPDATE pro každou sub-kolekci, ne smazání. Filter
  pro adresy per `address_type` (viz spec sekce 9). `valid_to = today`.
- `authoritativeRefresh` (provozovna/zařízení matched podle `place_reg_id`):
  vždy overwrite adresních polí z payloadu, nezávisle na `mergeStrategy`.

#### 5.2 `PersonValidator.php`

PHP-side validace polymorfismu (per `personType`):

- `personType: company` — `companyId` required (warning, ne error, pokud
  chybí; v praxi cizí firmy bez IČO existují). `name.fullName` required (error).
  `personal` musí být `null` nebo vynechán (warning).
- `personType: person` — `name.firstName` required (error), `name.lastName`
  required (error). `personal` povolen.

Plus:

- `addresses[].placeRegType` + `placeRegId` — required pro
  `addressType in [3, 4]`, jinak musí být `null` (warning, ne error).
- `bankAccounts[].iban` OR `accountNumber` — alespoň jedno z toho (error).
- `applyOptions.targetDocState` — pokud `40`, validovat povinná pole
  pro `is_own=true` (vlastní firma) — `companyId` required hard error.

#### 5.3 `PersonResolver.php`

Orchestruje header + sub-kolekce. Použij existující `PartyResolver`
pro header (s `personType` filter — viz 4.6).

Public API:

```php
public function resolve(array $canonical, MergeStrategy $strategy): PersonResolveResult;
```

`PersonResolveResult` obsahuje header, addresses[], bankAccounts[],
contacts[], closingExisting (jen pro `fullSync`), issues[].

Pro `fullSync` po header matched: enumerovat existující sub-záznamy
osoby (`docState IN (10, 40, 80)`), porovnat s payload-resolved
matched ID, zbytek dát do `closingExisting`.

#### 5.4 `AddressResolver.php`

Match-key priorita per spec sekce 8.2. Všechny SQL probes filtrují
`AND [docState] IN (10, 40, 80)` na `base_persons_addresses`:

1. `placeRegId` vyplněno → `(person, place_reg_type, place_reg_id)` exact.
   `authoritativeRefresh = true`.
2. `registryCode` vyplněno → `(person, address_type, registry_code)` exact.
   `authoritativeRefresh = false`.
3. `isStandardized = false` → `(person, address_type, displayLine)` exact.
   `authoritativeRefresh = false`.

Žádný match → `canCreate` s payloadem pro insert (nebo `AddressDocument::saveDocument`).

`divisionCode` → `world_divisions.id` lookup (oddělená metoda, vrací `null`
pokud kód neexistuje + emit warning issue).

#### 5.5 `ContactResolver.php`

Match-key priorita. Všechny SQL probes filtrují `AND [docState] IN (10, 40, 80)`
na `base_persons_contacts`:

1. `(person, name, email)` exact — když email vyplněn.
2. `(person, name)` exact — fallback.

Žádný match → `canCreate`.

#### 5.6 BankAccountResolver — reuse + bug fix

Přepoužít existující `BankAccountResolver::resolvePartnerBank` pro
sub-kolekci `bankAccounts[]` beze změny logiky. Resolver už má v SQL
`[docState] IN (10, 40, 80)` filter — ten po migraci v sekci 1 začne
fungovat bez SQL chyby. Doporučení: po migraci spustit existující
`core.exchange` testy znovu — pokud nějaký test dosud náhodou prolézal
mimo bývalou bug-cestu, teď může odhalit, že fixtures `docState` filter
netrefovaly.

#### 5.7 Rozšíření `PartyResolver`

Přidat optional parametr `personType` do `resolve()`:

```php
public function resolve(array $party, ?PersonType $personType = null): ResolveResult;
```

Když `personType` vyplněn, přidat `AND person_type = ?` filter do všech
SQL probes. Default `null` = bez filtru, plně zpětně kompatibilní s
existujícím doc flow.

Také rozšíř `buildCreatePayload` — když resolve volá Person flow,
`person_type` se nesmí hardcodovat na `Company`. Refactor: vyrobit
nový private method `buildPersonCreatePayload(array $party, PersonType $type)`
a nechat existující `buildCreatePayload` jako delegát s `PersonType::Company`.

### 6. REST controller

Rozšíření **`modules/core/exchange/src/Api/Controller/ExchangeController.php`**
(nebo nový `PersonsExchangeController` — preferuj rozšíření, ať máme
jedno místo na config / auth).

Tři endpointy pod `/api/v1/_exchange/persons/person/`:

- `POST /validate` → `validatePerson(Request $request): Response`
- `POST /preview` → `previewPerson(Request $request): Response`
- `POST /apply` → `applyPerson(Request $request): Response`

Stejný error shape, stejné HTTP codes jako document endpointy. Přidat
`409 person_exists` a `409 person_id_conflict` jako nové error codes.

Registrace rout per existující pattern (asi `src/Api/routes.php` nebo
podobné — sleduj jak jsou registrované docs endpointy).

### 7. Tests

#### 7.1 Fixtures

**`tests/Fixtures/Exchange/persons/`**:

- `company_create_happy.json` — Nový subjekt z ARES (firma, dvě adresy:
  Sídlo + Provozovna s IČP, jeden bankovní účet, jeden kontakt).
  `mergeStrategy: createOnly`.
- `company_fullSync.json` — Existující subjekt, simuluje druhý import
  z ARES po roce: změnila se adresa sídla, přibyl účet v EUR, jeden
  starý kontakt v payloadu chybí (má být closed).
  `mergeStrategy: fullSync`.
- `company_mergeAdd.json` — Existující subjekt, payload obsahuje navíc
  nový účet a aktualizovanou provozovnu (`place_reg_id` match →
  authoritativeRefresh). `mergeStrategy: mergeAdd` — overwrite jen
  provozovny, zbytek netknout.
- `person_create.json` — Nová FO s plnou strukturou (datum narození,
  rodné číslo, dvě adresy doručovací).
- `person_id_conflict.json` — Payload s `personId` který už existuje
  u jiné osoby.
- `unstandardized_address.json` — Zahraniční adresa, fallback match-key
  `displayLine`.

#### 7.2 Unit testy

**`tests/Unit/Module/Core/Exchange/Person/`**:

- `PersonResolverTest` — všechny větve resolve (header matched / canCreate /
  ambiguous; per personType filter; closingExisting pro fullSync).
- `AddressResolverTest` — match-key priorita (placeRegId → registryCode →
  displayLine); authoritativeRefresh flag; canCreate s/bez divisionCode.
- `ContactResolverTest` — (name,email) vs (name) fallback.
- `PersonValidatorTest` — per-personType polymorfismus; placeRegType
  required pro type 3/4; iban OR accountNumber.
- `PersonApplierTest` — happy path create; updateHeader; mergeAdd s
  authoritativeRefresh; fullSync s closingExisting; createOnly + matched =
  reject; person_id_conflict.

#### 7.3 Integration / E2E

**`tests/Integration/Exchange/Persons/`**:

- `PersonsApplyE2ETest` — full HTTP request → DB state assertion.
  Mock DS, fixture payload, post na endpoint, ověř `savedPersonId`
  + DB rows v `base_persons_persons` / `_addresses` / `_bank_accounts`
  / `_contacts`. Per fixture jeden test.

#### 7.4 Schema drift test

**`tests/Unit/Module/Core/Exchange/Schema/PersonSchemaDriftTest`** —
analogie existujícího `SchemaDriftTest` pro doklady. Načte
`shpd.persons.person.v1.jsonc`, strip comments, porovná se sourozencem
`.json`. Selže pokud se rozcházejí.

### 8. Module dependencies

Existující `modules/core/exchange/module.jsonc` má `base.persons` v
dependencies — bez změny.

Pokud `AddressResolver` potřebuje `world.divisions`, přidej do
`dependencies` (zkontroluj jestli tam už není přes tranzitivu).

## Hotovo když

1. **`docs/exchange-format-persons.md`** existuje (už ho má spec autor — David;
   tento task ho nevyrábí, jen na něm implementuje). Implementace **musí**
   odpovídat spec; rozdíly buď opraveno v kódu, nebo zaznamenány v "Otevřené body".
2. **Sub-tabulky** `base_persons_addresses`, `base_persons_contacts`,
   `base_persons_bank_accounts` mají `docState` + `docStateMain` + `docStates`
   blok s `cfgItem: core.system.docStatesArchive` + `idx_doc_state` index.
   Aktualizované `.md`.
3. **`base_persons_persons`** má sloupce `source_kind`, `source_ref`,
   `source_imported_at` ve skupině `lineage`. Aktualizovaný `.md`.
4. **Existující `core.exchange` testy procházejí** po sub-table migraci
   — zejména ty, které zahánějí `BankAccountResolver` proti reálné DB
   (latentní `docState` filter začne fungovat).
5. **`base.persons.sourceKinds`** cfgItem registrovaný a obsahuje 7 klíčů
   z spec sekce 13.
6. **`shpd.persons.person.v1.{json,jsonc}`** schema soubory existují
   a `SchemaLoader` je umí načíst přes `getSchema('shpd.persons.person.v1')`.
   `PersonSchemaDriftTest` prochází.
7. **`PersonApplier::apply($canonical, $options)`** implementuje pipeline
   z spec sekce 11 — create, updateHeader, mergeAdd, fullSync.
8. **`PartyResolver::resolve($party, ?PersonType $type)`** přijímá nový
   parametr, default `null` je zpětně kompatibilní (existující doc testy
   prochází bez změny).
9. **`AddressResolver`** vrací `authoritativeRefresh = true` pro match
   podle `placeRegId`. SQL probes mají `docState` filter.
10. **`fullSync`** v `PersonApplier`:
    - Hlavička přepsána celá (krom `is_closed`/`closed_date`/`docState`).
    - Sub-záznamy missing v payloadu mají `valid_to = today` (per address_type
      pro adresy).
    - `_resolve.closingExisting` v response obsahuje seznam uzavřených id.
    - Closing používá jen `valid_to = today`, **NE** `docState = 90`.
11. **`mergeAdd` + Provozovna s `placeRegId` match** → adresní pole
    provozovny přepsána; ostatní sub-kolekce netknuté.
12. **Insert nových sub-záznamů** applierem nastavuje `docState = 40`
    (ne default `10`). Update existujících `docState` nemění.
13. **`createOnly` + header `matched`** → `409 person_exists`.
14. **`personId` collision** → `409 person_id_conflict`.
15. **REST endpointy** `/api/v1/_exchange/persons/person/{validate,preview,apply}`
    odpovídají per spec sekce 12; error shape kompatibilní s document
    endpointy.
16. **Lineage** se zapisuje při apply (`source_kind`, `source_ref`,
    `source_imported_at`).
17. **Tests** — všechny unit testy v `tests/Unit/Module/Core/Exchange/Person/`
    + integration testy v `tests/Integration/Exchange/Persons/` prochází.
    Pokrytí všech mergeStrategy a všech statusů `_resolve`.
18. **Existující doc testy** v `core.exchange` po refactoru `PartyResolver`
    a po sub-table `docState` migraci stále prochází.
19. **`modules/core/exchange/README.md`** aktualizovaný — sekce "Person flow"
    s curl příklady (mirror existing "Curl příklady" pattern).

## Doporučené pořadí implementace

1. **DB migration — sub-tabulky `docState`** — tři `.jsonc` (`addresses`,
   `contacts`, `bank_accounts`) dostanou `docStates` blok, dva systémové
   sloupce a index. Spustit `shpd-ds upgrade`. Spustit existující
   `core.exchange` testy — musí projít (a `BankAccountResolver` se
   z latentního bugu probudí).
2. **DB migration — persons lineage** — sloupce do `base_persons_persons`
   + `sourceKinds` cfgItem + table doc update. Spustit `shpd-ds upgrade`.
3. **`PartyResolver` rozšíření** — `personType` parametr. Spustit existující
   doc testy → musí prošet beze změny.
4. **JSON Schema** soubory + `SchemaLoader` registrace + drift test.
5. **`PersonValidator`** + unit testy pro per-personType polymorfismus.
6. **`AddressResolver`** + unit testy pro match-key priority a
   authoritativeRefresh.
7. **`ContactResolver`** + unit testy.
8. **`PersonResolver`** orchestrace — header (delegace na PartyResolver
   s personType), sub-kolekce volání, closingExisting enumeration.
9. **`PersonApplier`** — postupně: create-only happy path, updateHeader,
   mergeAdd, fullSync (closing), authoritativeRefresh exception, person_id
   handling. Per krok unit test.
10. **REST controller** — rozšířit `ExchangeController` o tři person endpointy.
11. **Integration testy** — E2E fixtures, HTTP request → DB assertion.
12. **README update** — Curl příklady, sekce Stav.

Po každém kroku spustit relevantní testy + `shpd-ds upgrade` pokud byly
změny v jsonc tabulkách.

## Otevřené body / rozhodnutí

Tyto věci jsem v spec nechal otevřené — vyřeš podle reality kódu.

### 1. `PersonExistsException` vs in-band error

`createOnly + matched` lze vyhodit jako PHP exception (cleaner v applieru),
nebo vrátit přes `ApplyResult` se status flag a controller dělá HTTP code
mapping. **Preference:** druhá varianta — konzistentní s tím, jak
`DocumentApplier` řeší `unresolved_required` přes return path. Ale pokud
PHP exception path je v kódu spíš (`OwnCompanyResolver` má jakousi),
sleduj existující pattern.

### 2. Sdílení `ApplyResult` mezi Document a Person flow

Existující `modules/core/exchange/src/Document/ApplyResult.php` může být:

- Přesunut do `Exchange/ApplyResult.php` (sdílený namespace) — refactor,
  doc testy musí prošet.
- Kopírovat strukturu do `Person/PersonApplyResult.php` — bez refactoru,
  drobná duplicita.

**Preference:** refactor (přesun do shared), ale rozhodni podle síly
coupling existujícího `ApplyResult` na document-specifické pole.
Pokud má method/property jen pro doklady, kopie je čistší.

### 3. `AddressDocument` / `BankAccountDocument` / `ContactDocument`

`PersonApplier` při insertu sub-záznamů potřebuje přes nějakou cestu uložit.
Možnosti:

- **Per-table Document třídy** (`AddressDocument extends Document`) —
  cleaner, ale vyžaduje vytvořit tři nové třídy. Aktuálně existují jen
  `PersonDocument`.
- **Přímý `TableGateway::saveDocument(table, $data)`** — funguje bez
  Document třídy, ale obchází eventuální per-table validaci.
- **Insert přímo SQL** — nejjednodušší, ale chybí beforeSave hooky.

**Preference:** prozkoumat, jestli pro sub-tabulky `base_persons_*`
existuje per-table `*Document` třída. Pokud ne, postupuj přes
`TableGateway::saveDocument` s tabulkovým id přímo — to funguje pro
tabulky, které vlastní validaci nepotřebují (contacts, bank_accounts,
addresses pravděpodobně ne). Document subclass přidávat ad-hoc jen
pokud bys jinak duplikoval business logiku.

### 4. `world_divisions` lookup performance

`AddressResolver` mapuje `divisionCode` → `world_divisions.id`. Pokud
payload obsahuje N adres, N round-tripů na DB. Pro Phase 1 to stačí
(typicky 1–5 adres na osobu), pro CSV bulk import to bude bottleneck.

**Preference:** Phase 1 jednoduchý lookup per adresu. Batch lookup
optimization je Phase 3 follow-up. Zapiš jako TODO komentář v kódu.

### 5. `fullSync` closing pro contacts bez `valid_to` sémantiky

`base_persons_contacts` má `valid_to` — uzavírání funguje.
`base_persons_bank_accounts` má `valid_to` — funguje.
`base_persons_addresses` má `valid_to` — funguje.

Všechno je konzistentní, žádné další rozhodnutí.

### 6. `personType` v Party (doc flow) — výhled

Po tomto tasku má `PartyResolver` `personType` filter parametr (default
`null`). V doc flow se zatím nepoužívá. Když AI extrakce z faktur bude
v budoucnu rozpoznávat OSVČ vs firma, `DocumentApplier` zavolá
`PartyResolver::resolve($party, PersonType::Company)` pro hlavičkové
firmy. To je mimo scope tohoto tasku, ale design `personType` parametr
připraví cestu.

### 7. Lineage při manuálním uložení přes UI

Když uživatel ručně edituje osobu přes FormEditor a uloží, `source_kind`
zůstává jak je (typicky `NULL` při prvním uložení; nebo zachovaná hodnota
z dřívějšího importu). PersonApplier source_kind přepisuje **jen když**
payload obsahuje `source.kind` — pokud chybí, hodnota se nemění.
FormEditor `source` neposílá, takže UI editace lineage zachovává.

Implementuj jako: `if (isset($canonical['source']['kind'])) { update lineage }`,
jinak nech sloupce nedotčené v UPDATE SQL.

## Příprava pro Fázi 2 (ARES adaptér)

Tato Fáze 1 staví foundation. Fáze 2 (samostatný task) bude:

1. HTTP klient pro ARES API.
2. **Adapter** — mapování ARES JSON → `shpd.persons.person.v1` canonical.
   Bude žít v `modules/world/registries/ares/` nebo podobně (nový modul).
3. UI tlačítko "Načíst z ARES" v PersonsForm — request s IČO, response
   = canonical preview, uživatel potvrdí → `/apply` s
   `mergeStrategy: fullSync`.

Z tohoto Fáze 1 tasku nic dalšího nepotřebuje — `PersonApplier::apply`
je univerzální vstupní bod.
