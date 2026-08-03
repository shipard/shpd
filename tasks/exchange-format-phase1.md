# Task: Exchange Format — Fáze 1: Core modul a apply pipeline

**Stav:** hotovo

## Kontext

Stavíme **kanonický výměnný formát** pro doklady — `shpd.docs.document.v1`.
Cílem je mít jednu JSON strukturu, kterou lze vizualizovat, validovat,
ukládat do DB, importovat ze cizích zdrojů (AI analyzer, ISDOC, ERP) a
exportovat. Formát používá místo interních ID identifikátory typu země +
IČO, EAN, ISO unit code, atd., a teprve při uložení se přes **resolve**
fázi propojí s lokálními entitami v Shipard DB.

Před implementací **přečti** kompletně:

- `docs/exchange-format.md` — kanonická specifikace formátu, resolve
  pravidla, apply pipeline, REST endpointy. Tento task implementuje
  všechno popsané v té specifikaci (Fáze 1).
- `docs/document-system.md` — Document/TableGateway nad kterým exchange
  formát staví. **Klíčové:** exchange formát NEsestupuje pod Document.
  Veškerá business logika dokladu (snapshoty, čísla, recap, totals) zůstává
  v `DocDocument::beforeSave`. Applier transformuje canonical → interní
  `$data` a volá `TableGateway::saveDocument()`.
- `modules/docs/core/src/DocDocument.php` — kompletně, pochop celou
  `beforeSave` pipeline. Applier nesmí dělat nic, co tahle třída už dělá.
- `modules/world/vat/src/VatRateResolver.php` — VatCodeResolver na něj volá.
- `modules/docs/core/src/OwnCompanyResolver.php` — používá ho PartyResolver
  při `selfParty` resolve.
- `modules/base/persons/src/PersonDocument.php` a její validace — applier
  z ní bude vyrábět nové osoby (canCreate).
- `modules/core/mail/tables/core_mail_extracted_documents.jsonc` — chápej,
  jak je lineage propojená dnes (`target_table_id`, `target_row_ndx`).

Vzorové existující soubory pro implementační styl:

- `modules/core/attachments/src/AttachmentService.php` — vzor pro service
  vrstvu modulu (DI, public API, žádná business logika mimo doménu).
- `modules/docs/core/src/DocsHeadsForm.php` — vzor pro server-side
  orchestraci komplexní operace s validacemi.
- `src/Api/CrudController.php` — vzor pro REST controller (Request/Response,
  validace, error shape).
- `tests/Unit/Module/Docs/Core/DocDocumentTest.php` — vzor PHPUnit testů
  s mockem Dibi connection.

## Cíl Fáze 1

Po dokončení této fáze platí:

- Existuje modul `core.exchange` v `modules/core/exchange/`.
- Existují canonical schema soubory pro `shpd.docs.document.v1`
  (JSONC zdroj + kompilované JSON Schema).
- Existují resolvery: `PartyResolver`, `ItemResolver`, `UnitResolver`,
  `VatCodeResolver`, `BankAccountResolver`.
- Existuje `DocumentApplier`, který orchestruje validate → resolve →
  reconcile → save.
- Existují tři REST endpointy:
  - `POST /api/v1/_exchange/docs/document/validate`
  - `POST /api/v1/_exchange/docs/document/preview`
  - `POST /api/v1/_exchange/docs/document/apply`
- `economy_items` má nové sloupce `sku` a `ean` (oba nullable, indexované).
- Existuje nová tabulka `economy_items_supplier_codes` (per-partner item
  mapping).
- `docs_core_heads` má tři nové sloupce pro lineage:
  `source_kind`, `source_extracted_doc`, `source_extracted_at`.
- `docs_core_heads` má sloupec `partner_doc_number` pro číslo dokladu od
  protistrany (povinné pro `invoiceReceived` ve stavu ≥ 20).
- Existuje cfgItem `docs.core.sourceKinds` (`aiExtraction` | `isdoc`
  | `manual` | `import.flexibee` | …).
- `bin/shpd-ds ds-upgrade` projde bez chyb na čistém DS.
- PHPUnit testy pokrývají: resolvery (každý zvlášť), applier (happy path,
  unresolved error, validation gate, create side-entities), end-to-end
  přes ExchangeController.
- E2E test: ručně sestavený canonical JSON faktury přijaté → `POST /apply`
  → v DB existuje `docs_core_heads` row s rows + vatRecap, partner
  vytvořen (canCreate flow), položka napárována nebo vytvořena.

## Návaznost

- Závisí na: Fáze 6 dokladů (`docs-invoices.md` — hotovo), modul `core.mail`
  Fáze 3a (hotovo).
- **Tento task je Fáze 1 exchange formátu.** Navazující fáze
  (samostatné tasky): Fáze 2 = napojení AI analyzeru (přepsání promptu, aby
  produkoval canonical, automatický `/apply` flow z extracted documents);
  Fáze 3 = frontend náhled canonical dokumentu s resolve interakcí.

## Scope

### V rozsahu

#### Datová příprava
- Rozšíření `modules/economy/items/tables/economy_items.jsonc` o sloupce
  `sku` (varchar 50, nullable, indexed) a `ean` (varchar 20, nullable,
  indexed). Doplnit `.md` dokumentaci.
- Nová tabulka `modules/economy/items/tables/economy_items_supplier_codes.jsonc`
  (tableId — najít volný přes `php bin/shpd-server next-table-id`).
  Schema viz "Implementace" sekce níže.
- Nový sloupec `modules/docs/core/tables/docs_core_heads.jsonc`:
  - `partner_doc_number` (varchar 40, nullable, group "identity")
  - `source_kind` (varchar 40, nullable, system: true, group "status"
    nebo nová skupina "lineage" — preferuju "lineage")
  - `source_extracted_doc` (int, nullable, reference
    `core_mail_extracted_documents`, system: true, group "lineage")
  - `source_extracted_at` (datetime, nullable, system: true, group
    "lineage")
- Aktualizace `.md` dokumentace pro `docs_core_heads.md`.
- Nový cfgItem soubor `modules/docs/core/config/sourceKinds.jsonc`
  + registrace v `modules/docs/core/module.jsonc`. Klíče:
  `aiExtraction`, `isdoc`, `peppolUbl`, `manual`, `import.flexibee`,
  `import.pohoda`. Lze nechat extensible (uživatel může přidat svoje).

#### Schémata
- `modules/core/exchange/schemas/shpd.docs.document.v1.jsonc` — zdrojový
  JSONC s komentáři, kompletní pokrytí struktury popsané v
  `docs/exchange-format.md` sekce 5–7.
- `modules/core/exchange/schemas/shpd.docs.document.v1.json` — kompilovaná
  verze (bez komentářů a trailing čárek). Bude generována/aktualizována
  ručně, není nutné mít na to CLI příkaz pro Fázi 1.

JSON Schema dialect: `https://json-schema.org/draft/2020-12/schema`.
`additionalProperties: false` na top-level, `true` u `source.raw`.
**Polymorfismus podle `docType` se ve schématu neřeší** — všechna
type-specific pole jsou optional. Validaci dělá PHP v
`DocumentExchangeFormat::validate()`.

#### PHP třídy modulu `core.exchange`

```
modules/core/exchange/
├── module.jsonc
├── README.md
├── schemas/
│   ├── shpd.docs.document.v1.jsonc
│   └── shpd.docs.document.v1.json
└── src/
    ├── ExchangeFormat.php              # abstract base
    ├── Schema/
    │   ├── SchemaLoader.php            # load + cache JSON schemas
    │   └── SchemaValidator.php         # JSON Schema validation
    ├── Resolve/
    │   ├── ResolveResult.php           # value object
    │   ├── ResolveStatus.php           # enum: Matched|Ambiguous|NotFound|CanCreate
    │   ├── PartyResolver.php
    │   ├── ItemResolver.php
    │   ├── UnitResolver.php
    │   ├── VatCodeResolver.php
    │   └── BankAccountResolver.php
    └── Document/
        ├── DocumentExchangeFormat.php  # concrete pro shpd.docs.document.v1
        ├── DocumentValidator.php       # validation findings (totals_mismatch,
        │                               #   required fields, …)
        └── DocumentApplier.php         # orchestrátor apply pipeline
```

Namespace: `Shipard\Module\Core\Exchange\`.

#### REST API

- `src/Api/ExchangeController.php` — tři akce (`validate`, `preview`,
  `apply`).
- Úprava `src/Api/Router.php` — přidat routy
  `POST /_exchange/docs/document/{validate|preview|apply}`. Vzor stávajících
  speciálních endpointů (`_auth`, `_meta`).

#### PHPUnit testy

- `tests/Unit/Module/Core/Exchange/Schema/SchemaValidatorTest.php` —
  valid/invalid payloads.
- `tests/Unit/Module/Core/Exchange/Resolve/PartyResolverTest.php` —
  každá cesta (companyId, vatId, taxId, name fuzzy, ambiguous, notFound,
  canCreate), self-party flow.
- `tests/Unit/Module/Core/Exchange/Resolve/ItemResolverTest.php` —
  všechny cesty matchování.
- `tests/Unit/Module/Core/Exchange/Resolve/UnitResolverTest.php`.
- `tests/Unit/Module/Core/Exchange/Resolve/VatCodeResolverTest.php`.
- `tests/Unit/Module/Core/Exchange/Resolve/BankAccountResolverTest.php`.
- `tests/Unit/Module/Core/Exchange/Document/DocumentApplierTest.php` —
  happy path, unresolved error (chybí userAction), validation gate
  (chybí povinné pole), side-create flow (canCreate → INSERT), reconcile
  konflikt (useExisting:42 ale 42 byl smazán).
- `tests/Unit/Api/ExchangeControllerTest.php` — všechny tři endpointy,
  error shape, auth.

### Mimo rozsah

- **Frontend náhled / vizualizace** canonical JSON — samostatný task
  (Fáze 3).
- **Napojení AI analyzeru** — AnalyzerProvisioner dál nastavuje `output_schema`
  v profilu jako dnes. AI dál produkuje legacy ad-hoc JSON, který se ukládá do
  `core_mail_extracted_documents.extracted_json`. Změna promptu, aby AI vracela
  canonical, je separátní task (Fáze 2). Pro testování `/apply` se v této fázi
  použijí ručně sestavené canonical JSONy (curl + fixture).
- **Importy z konkrétních ERP** (Flexibee, Pohoda) — samostatné moduly.
- **ISDOC / Peppol UBL parsery** — samostatné moduly.
- **Další canonical formáty** (`shpd.persons.person.v1`,
  `shpd.items.item.v1`) — budoucí tasky.
- **Fuzzy matching s Levenshtein/trigram score** pro PartyResolver
  step 4 — v Fázi 1 stačí `LIKE '%name%'` s country filterem. Sofistikovanější
  fuzzy je iterace.
- **Update existujícího dokladu přes `/apply`** — Fáze 1 jen `create`.
  Pokud canonical neobsahuje `savedDocId` v top-level, je to vždy nový
  doklad. Update pole pro `/apply` přijde v navazující iteraci.
- **`/apply` jako batch** (více dokladů v jednom requestu) — Fáze 1 jen
  single document per request.

## Architektonická rozhodnutí

### Vrstvení

```
ExchangeController                    (Api)
  └─→ DocumentApplier                 (Module\Core\Exchange\Document)
       ├─→ SchemaValidator            (Module\Core\Exchange\Schema)
       ├─→ PartyResolver, ItemResolver, …  (Module\Core\Exchange\Resolve)
       ├─→ DocumentValidator          (Module\Core\Exchange\Document)
       └─→ TableGateway               (existing Core\Document)
            └─→ DocDocument           (existing Module\Docs\Core)
```

Applier je orchestrátor. Resolvery jsou bezstavové (pure read), Applier
drží stav (execution plan, side-creates) v lokálních proměnných.

### DI a service factory

`ExchangeController` instance v `index.php` dispatch loop. Konstrukce
přes statickou factory:

```php
$applier = DocumentApplier::create($db, $config, $tableGateway);
$controller = new ExchangeController($applier);
```

Resolvery instancuje `DocumentApplier::create` (lazy, jen ty které potřebuje).
**Nedělej** globální service container — jednoduché statické factory metody
podle vzoru ostatních modulů.

### Currency case handling

Canonical používá uppercase ISO 4217 (`"CZK"`, `"EUR"`). Interní DB sloupec
`docs_core_heads.doc_currency` je `enumString` s lowercase klíči (`"czk"`).
**Mapování dělá Applier v transform fázi** — nikde jinde. Resolvery
a validátory pracují s canonical formátem (uppercase).

### Lineage update — atomic s save

Bod 10 v Apply pipeline (`docs/exchange-format.md` sekce 10) — update
`core_mail_extracted_documents.target_*` a `status=40` — **musí být ve
stejné DB transakci** jako save dokladu. Důvod: pokud save uspěje, ale
lineage update selže, máme doklad bez stopy. Vrstvit přes
`$db->begin/commit` v Applier::apply, ne pouštět to mimo TableGateway
transakci.

### Reconcile logika

Mezi `/preview` a `/apply` může uplynout libovolný čas a stav DB se mohl
změnit. Reconcile v Applieru:

1. Spustí resolve znovu (čerstvé čtení DB).
2. Pro každou referenci v canonical:
   - Pokud `_resolve.X.userAction == null` → použij čerstvé resolve.
     Když status != `matched` → chyba `unresolved_required`.
   - Pokud `userAction == "useExisting:<id>"` → ověř, že entita s tím
     id stále existuje a je v aktivním stavu. Nesouhlas → `409 conflict`.
   - Pokud `userAction == "create"` → ověř, že resolve status je
     `canCreate` (nemůžeš "create" když resolve vrátil `matched`).
     Souhlas → naplánuj side-create.
   - Pokud `userAction == "skip"` → naplánuj skip (jen pro řádky).

3. Sestaví execution plan: array `{ partyCreates: [...], itemCreates: [...],
   bankCreates: [...], itemMappings: [...], rowSkips: [...] }`.

Reconcile **neukládá**. Ukládání je krok 5–10 pipeline.

### Side-creates a per-partner item mapping

Když uživatel rozhodne pro `Row.item` `userAction == "useExisting:18"`
a v canonical je `Row.item.supplierCode == "KONZ-001"` a partner je
resolved (`supplier.personId == 42`), applier zaznamená mapping:

```sql
INSERT IGNORE INTO economy_items_supplier_codes
  (person, item, supplier_code, created)
VALUES (42, 18, 'KONZ-001', NOW())
```

Tj. **per-partner mapping se učí z uživatelských rozhodnutí.** Příště se
napaří automaticky v `ItemResolver` kroku 2.

Pozn: `INSERT IGNORE` (nebo `ON DUPLICATE KEY UPDATE`) chrání před
exception při opakovaném pořízení stejné kombinace.

### Co dělá `validate` vs. `preview`

`/validate` je striktně statický:

- Schema validation (struktura)
- `DocumentValidator::validateStructure` — povinná pole per `docType`,
  enum hodnoty, typy
- `DocumentValidator::validateTotals` — totals mismatch warning

`/preview` přidává:

- Všechno z `/validate`
- Plné resolve přes všechny resolvery
- Issues nalezené resolvery (např. neznámý `vatCode` z neznámé země)

Klient může `/validate` použít pro rychlou kontrolu před editací, `/preview`
pro plný náhled před apply.

### Error response shape

Standardní:

```json
{
  "success": false,
  "error": {
    "code": "unresolved_required",
    "message": "Některé reference nelze automaticky propojit.",
    "details": { /* enriched canonical s _resolve */ }
  }
}
```

Codes:

- `schema_invalid` (400)
- `validation_failed` (422) — issues s severity=error v `_resolve.issues`
- `unresolved_required` (422) — chybí userAction pro non-matched ref
- `conflict` (409) — useExisting target zmizel mezi preview a apply
- `internal_error` (500) — neočekávaná chyba

## Implementace

### Tabulka `economy_items_supplier_codes`

```jsonc
{
    "tableId": NNN,  // přidělit přes shpd-server next-table-id
    "name": "Supplier item codes",
    "name:cs": "Dodavatelské kódy položek",
    "name:en": "Supplier item codes",

    "displayPattern": "{supplier_code} → {item}",
    "hideFromNavigation": true,  // spravuje se přes apply pipeline

    "columns": [
        {
            "id": "id",
            "name": "ID",
            "type": "int",
            "autoIncrement": true,
            "primaryKey": true
        },
        {
            "id": "person",
            "name": "Person (supplier)",
            "name:cs": "Osoba (dodavatel)",
            "name:en": "Person (supplier)",
            "type": "int",
            "nullable": false,
            "reference": "base_persons_persons"
        },
        {
            "id": "item",
            "name": "Item",
            "name:cs": "Položka",
            "name:en": "Item",
            "type": "int",
            "nullable": false,
            "reference": "economy_items"
        },
        {
            "id": "supplier_code",
            "name": "Supplier code",
            "name:cs": "Dodavatelský kód",
            "name:en": "Supplier code",
            "type": "varchar",
            "length": 50,
            "nullable": false
        },
        {
            "id": "supplier_name",
            "name": "Supplier name (extracted)",
            "name:cs": "Název v dokladu (extrahovaný)",
            "name:en": "Supplier name (extracted)",
            "type": "varchar",
            "length": 200,
            "nullable": true
            // textový název, pod kterým dodavatel položku uvádí
            // — užitečné pro audit / debug
        },
        {
            "id": "created",
            "name": "Created",
            "name:cs": "Vytvořeno",
            "name:en": "Created",
            "type": "datetime",
            "nullable": false
        }
    ],

    "indexes": [
        {
            "id": "unq_person_supplier_code",
            "type": "unique",
            "columns": [
                {"column": "person"},
                {"column": "supplier_code"}
            ]
        },
        {
            "id": "idx_item",
            "type": "index",
            "columns": [{"column": "item"}]
        }
    ]
}
```

Unique index `(person, supplier_code)` je klíčový — applier dělá `INSERT IGNORE`
proti němu.

### Sloupce na `economy_items`

```jsonc
// Přidat do columns:
{
    "id": "sku",
    "name": "SKU",
    "name:cs": "SKU",
    "name:en": "SKU",
    "type": "varchar",
    "length": 50,
    "nullable": true,
    "group": "identity"
},
{
    "id": "ean",
    "name": "EAN",
    "name:cs": "EAN",
    "name:en": "EAN",
    "type": "varchar",
    "length": 20,
    "nullable": true,
    "group": "identity"
}

// Přidat do indexes:
{
    "id": "idx_sku",
    "type": "index",
    "columns": [{"column": "sku"}]
},
{
    "id": "idx_ean",
    "type": "index",
    "columns": [{"column": "ean"}]
}
```

### Sloupce na `docs_core_heads`

```jsonc
// Přidat do columnGroups (před "snapshots"):
{
    "id": "lineage",
    "name": "Lineage",
    "name:cs": "Návaznosti",
    "name:en": "Lineage"
}

// Přidat do columns (v sekci identity, hned za doc_text):
{
    "id": "partner_doc_number",
    "name": "Partner's document number",
    "name:cs": "Číslo dokladu od partnera",
    "name:en": "Partner's document number",
    "type": "varchar",
    "length": 40,
    "nullable": true,
    "group": "identity"
}

// Přidat do columns (nová sekce lineage, před snapshots):
{
    "id": "source_kind",
    "name": "Source kind",
    "name:cs": "Druh zdroje",
    "name:en": "Source kind",
    "type": "enumString",
    "length": 40,
    "cfgItem": "docs.core.sourceKinds",
    "nullable": true,
    "system": true,
    "group": "lineage"
},
{
    "id": "source_extracted_doc",
    "name": "Source extracted document",
    "name:cs": "Zdrojový extrahovaný dokument",
    "name:en": "Source extracted document",
    "type": "int",
    "nullable": true,
    "reference": "core_mail_extracted_documents",
    "system": true,
    "group": "lineage"
},
{
    "id": "source_extracted_at",
    "name": "Source extraction time",
    "name:cs": "Čas extrakce zdroje",
    "name:en": "Source extraction time",
    "type": "datetime",
    "nullable": true,
    "system": true,
    "group": "lineage"
}
```

**Pozor na pořadí:** `partner_doc_number` patří do groupy `identity` (sousedí
s `doc_text`), zatímco lineage sloupce jsou nová samostatná skupina vedle
snapshots.

Pro per-typ validaci v `ReceivedInvoiceDocument::validate`: při `docState in (20, 40, 80)`
požaduj non-empty `partner_doc_number` — silně doporučená dobrá praxe pro
přijaté faktury. (Optional pro Fázi 1 — uveď v komentáři "TODO Fáze 6
follow-up", pokud chceš nechat čistě v Exchange formátu jako WARNING.)

### `docs.core.sourceKinds` cfgItem

`modules/docs/core/config/sourceKinds.jsonc`:

```jsonc
{
    "aiExtraction": {
        "name": "AI extraction",
        "name:cs": "AI extrakce",
        "name:en": "AI extraction"
    },
    "isdoc": {
        "name": "ISDOC",
        "name:cs": "ISDOC",
        "name:en": "ISDOC"
    },
    "peppolUbl": {
        "name": "Peppol UBL",
        "name:cs": "Peppol UBL",
        "name:en": "Peppol UBL"
    },
    "manual": {
        "name": "Manual entry",
        "name:cs": "Ručně pořízeno",
        "name:en": "Manual entry"
    },
    "import.flexibee": {
        "name": "Import — Flexibee",
        "name:cs": "Import — Flexibee",
        "name:en": "Import — Flexibee"
    },
    "import.pohoda": {
        "name": "Import — Pohoda",
        "name:cs": "Import — Pohoda",
        "name:en": "Import — Pohoda"
    }
}
```

Registrace v `modules/docs/core/module.jsonc` v sekci `config`:

```jsonc
{
    "id": "docs.core.sourceKinds",
    "file": "config/sourceKinds.jsonc"
}
```

### `module.jsonc` modulu `core.exchange`

```jsonc
{
    "id": "core.exchange",
    "name": "Exchange formats",
    "name:cs": "Výměnné formáty",
    "name:en": "Exchange formats",
    "description": "Canonical exchange formats for documents and other entities",
    "description:cs": "Kanonické výměnné formáty pro doklady a další entity",
    "description:en": "Canonical exchange formats for documents and other entities",

    "dependencies": [
        "docs.core",
        "base.persons",
        "economy.items",
        "core.units",
        "world.vat",
        "core.mail",
        "core.attachments"
    ]

    // No tables of its own — purely a logic / API layer.
    // No documentClasses — uses existing Document/TableGateway.
    // No viewers — backend service module.
}
```

Přidat do `modules/install/base/module.jsonc` dependencies.

### `DocumentApplier` — kostra

```php
<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Document;

use Dibi\Connection;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Document\TableGateway;
use Shipard\Module\Core\Exchange\Resolve\PartyResolver;
use Shipard\Module\Core\Exchange\Resolve\ItemResolver;
use Shipard\Module\Core\Exchange\Resolve\UnitResolver;
use Shipard\Module\Core\Exchange\Resolve\VatCodeResolver;
use Shipard\Module\Core\Exchange\Resolve\BankAccountResolver;
use Shipard\Module\Core\Exchange\Schema\SchemaValidator;

final class DocumentApplier
{
    public function __construct(
        private readonly Connection $db,
        private readonly ConfigRuntime $config,
        private readonly TableGateway $headsGateway,
        private readonly SchemaValidator $schemaValidator,
        private readonly DocumentValidator $documentValidator,
        private readonly PartyResolver $partyResolver,
        private readonly ItemResolver $itemResolver,
        private readonly UnitResolver $unitResolver,
        private readonly VatCodeResolver $vatCodeResolver,
        private readonly BankAccountResolver $bankAccountResolver,
    ) {}

    public static function create(
        Connection $db,
        ConfigRuntime $config,
        TableGateway $headsGateway,
    ): self {
        // ... lazy factory
    }

    /**
     * Idempotent: only structural + computed validation, no DB resolve.
     */
    public function validate(array $canonical): ApplyResult { /* ... */ }

    /**
     * Idempotent: validate + resolve. No DB writes.
     */
    public function preview(array $canonical): ApplyResult { /* ... */ }

    /**
     * Transactional: validate + resolve + reconcile + save.
     * Returns enriched canonical with savedDocId, populated _resolve.
     */
    public function apply(array $canonical, array $applyOptions = []): ApplyResult { /* ... */ }

    // ─ private helpers per pipeline step (sections 1–12 in docs/exchange-format.md §10) ─
}
```

`ApplyResult` value object:

```php
final class ApplyResult
{
    public function __construct(
        public readonly bool $success,
        public readonly array $canonical,        // enriched canonical (always)
        public readonly ?int $savedDocId,        // only for apply success
        public readonly ?string $errorCode,      // null when success
        public readonly ?string $errorMessage,
    ) {}

    public static function ok(array $canonical, ?int $savedDocId = null): self { /* ... */ }
    public static function error(string $code, string $message, array $canonical): self { /* ... */ }
}
```

### `ExchangeController` — kostra

```php
<?php

declare(strict_types=1);

namespace Shipard\Api;

use Shipard\Module\Core\Exchange\Document\DocumentApplier;

final class ExchangeController
{
    public function __construct(private readonly DocumentApplier $applier) {}

    public function validate(Request $request, AuthContext $auth): Response
    {
        $payload = json_decode($request->getBody(), true);
        if (!is_array($payload)) {
            return Response::error('schema_invalid', 'Request body must be JSON object', 400);
        }

        $result = $this->applier->validate($payload);
        return $this->mapResultToResponse($result, statusCode: $result->success ? 200 : 422);
    }

    public function preview(Request $request, AuthContext $auth): Response { /* ... */ }
    public function apply(Request $request, AuthContext $auth): Response { /* ... */ }

    private function mapResultToResponse(ApplyResult $result, int $statusCode): Response
    {
        if ($result->success) {
            return Response::success([
                'canonical' => $result->canonical,
                'savedDocId' => $result->savedDocId,
            ], $statusCode);
        }
        return Response::error(
            $result->errorCode ?? 'internal_error',
            $result->errorMessage ?? 'Unknown error',
            $statusCode,
            details: $result->canonical,
        );
    }
}
```

### Router — registrace endpointů

V `src/Api/Router.php` v `route()` metodě, **před** generickým `{table}`
handlerem:

```php
// Exchange API
if ($method === 'POST' && $path === '/api/v1/_exchange/docs/document/validate') {
    return new Route('exchange', 'validate');
}
if ($method === 'POST' && $path === '/api/v1/_exchange/docs/document/preview') {
    return new Route('exchange', 'preview');
}
if ($method === 'POST' && $path === '/api/v1/_exchange/docs/document/apply') {
    return new Route('exchange', 'apply');
}
```

V `public/index.php` dispatch loop:

```php
'exchange' => match ($route->action) {
    'validate' => $exchangeController->validate($request, $auth),
    'preview'  => $exchangeController->preview($request, $auth),
    'apply'    => $exchangeController->apply($request, $auth),
    default    => Response::error('not_found', 'Action not found', 404),
},
```

### Resolvery — společný pattern

Každý resolver má jednu public metodu `resolve(...)` která vrací
`ResolveResult`. Žádný stav v instanci, vše předáno parametry.

`ResolveResult`:

```php
final class ResolveResult
{
    public function __construct(
        public readonly ResolveStatus $status,
        public readonly ?int $matchedId = null,
        public readonly ?string $matchedBy = null,
        public readonly array $candidates = [],       // [{id, name, score?}]
        public readonly array $createPayload = [],    // pre-built payload pro Document::saveDocument
    ) {}

    public static function matched(int $id, string $by): self { /* ... */ }
    public static function ambiguous(array $candidates): self { /* ... */ }
    public static function notFound(): self { /* ... */ }
    public static function canCreate(array $payload): self { /* ... */ }
}

enum ResolveStatus: string
{
    case Matched = 'matched';
    case Ambiguous = 'ambiguous';
    case NotFound = 'notFound';
    case CanCreate = 'canCreate';
}
```

### Schema validator

Pro JSON Schema draft-2020-12 použij knihovnu `opis/json-schema` (přidat
do composer.json). Pokud existují důvody pro lehčí závislost, `justinrainbow/json-schema`
podporuje draft-07. Preferuju `opis/json-schema` kvůli draft-2020-12 (lepší
podpora `$dynamicRef` atd.).

```php
final class SchemaValidator
{
    public function __construct(private readonly SchemaLoader $loader) {}

    public function validate(array $canonical, string $formatId, string $version): array
    {
        $schema = $this->loader->load($formatId, $version);
        // ... opis/json-schema validation, vrací array of issues
    }
}
```

## Hotovo když

- [ ] `bin/shpd-ds ds-upgrade` projde bez chyb na čistém DS (DROP +
      CREATE + naplnit fixtures).
- [ ] V `economy_items` jsou nové sloupce `sku`, `ean` se správnými
      indexy (verifikace přes `SHOW CREATE TABLE`).
- [ ] Tabulka `economy_items_supplier_codes` existuje s unique index
      `(person, supplier_code)`.
- [ ] V `docs_core_heads` jsou nové sloupce `partner_doc_number`,
      `source_kind`, `source_extracted_doc`, `source_extracted_at`.
- [ ] `docs.core.sourceKinds` cfgItem se objeví v `compiled.cs.json`
      a `compiled.en.json` s lokalizovanými názvy.
- [ ] `POST /api/v1/_exchange/docs/document/validate` s validním payloadem
      vrátí 200 a prázdné `issues`.
- [ ] `POST /api/v1/_exchange/docs/document/validate` s payloadem bez
      `dates.issueDate` vrátí 422 s `issues[].code = "required"` a
      `issues[].path = "dates.issueDate"`.
- [ ] `POST /api/v1/_exchange/docs/document/preview` s neznámým supplier
      vrátí 200 (preview je úspěch i s canCreate) a
      `_resolve.supplier.status = "canCreate"` s vyplněným payloadem.
- [ ] `POST /api/v1/_exchange/docs/document/preview` s totals declared
      = 12500.00 a vypočtenými = 12499.50 vrátí 200 a v `_resolve.issues`
      je warning `totals_mismatch`.
- [ ] `POST /api/v1/_exchange/docs/document/apply` s plně resolved
      payloadem (vše `matched`, ne canCreate) uloží doklad → vrátí 200,
      `savedDocId` v response, v DB existuje `docs_core_heads` row + rows
      + vat_recap.
- [ ] `POST /apply` s `_resolve.supplier.userAction = "create"` vytvoří
      novou osobu v `base_persons_persons` a v navazujícím doc je `partner`
      = nově vzniklé id.
- [ ] `POST /apply` s `Row.item.userAction = "useExisting:18"` a
      `Row.item.supplierCode = "KONZ-001"` na resolved supplier 42:
      v DB existuje `economy_items_supplier_codes` row `(42, 18, "KONZ-001")`.
- [ ] Opakované `POST /apply` se stejným supplierCode (mappings learning):
      podruhé je `_resolve.rows[].item.status = "matched"` s
      `matchedBy = "supplierCode"`.
- [ ] `POST /apply` se `selfParty = "customer"` a customer = null v
      payloadu doplní customer z `OwnCompanyResolver`.
- [ ] `POST /apply` s `applyOptions.targetDocState = 10` uloží jako
      Koncept, doc_number = `!0000000NNN` placeholder.
- [ ] `POST /apply` s `applyOptions.targetDocState = 20` přidělí číslo
      ze series (assignDocumentNumber zavolán), v response je `docNumber`
      vyplněn.
- [ ] Pokud canonical obsahuje `source.extractedDoc = 678` a
      `core_mail_extracted_documents` 678 existuje, po apply má row 678
      `target_table_id = 'docs_core_heads'`, `target_row_ndx = $savedDocId`,
      `status = 40`, `applied_at != null`. A `docs_core_heads.source_kind`
      = `'aiExtraction'`, `source_extracted_doc = 678`.
- [ ] `POST /apply` s `_resolve.X.userAction = null` ale resolve
      status = `canCreate` (chybí explicitní rozhodnutí) vrátí 422
      `unresolved_required` s details obsahující enriched canonical.
- [ ] `POST /apply` po preview + zmizení useExisting target mezi
      preview a apply (smazání v jiném tabu): vrátí 409 `conflict`.
- [ ] PHPUnit testy procházejí (`vendor/bin/phpunit`).
- [ ] `tests/Fixtures/Exchange/` obsahuje minimálně 3 fixture canonical
      JSONy (happy invoiceReceived, missing data, full ambiguous).
- [ ] `modules/core/exchange/README.md` obsahuje: účel modulu, příklad
      curl pro každý endpoint, odkaz na `docs/exchange-format.md`.
- [ ] Bez kruhových závislostí modulů (verifikace přes `ds-upgrade`
      module resolver).

## Konvence

- **Jazyk**: UI texty čeština, kód + komentáře angličtina (per CLAUDE.md).
- **Vícejazyčnost**: `name`/`description` v `module.jsonc` s `:cs` a `:en`,
  v JSONC schématu komentáře česky / anglicky (komentáře nejde do JSON
  Schema výstupu).
- **PHP 8.5** strict_types, readonly properties kde možné, enum types.
- **Resolvery jsou stateless** — žádné instance fields, jen DI konstruktoru
  pro `Connection` a `ConfigRuntime`. Žádné cachování v memory (single-shot
  HTTP request).
- **Applier drží transakci** — `BEGIN` v Apply.apply, `COMMIT`/`ROLLBACK`
  podle úspěchu. Resolvery nevolají begin/commit.
- **Žádné magické fallbacky** — když resolver vrátí `notFound` pro povinnou
  ref (vatCode), je to chyba, ne "tichý default". Tiché defaulty (issueDate
  → accountingDate) má `DocDocument::beforeSave` v Document layer.

## Doporučené pořadí implementace

1. **DB schema změny** — `economy_items` sloupce, nová tabulka
   `economy_items_supplier_codes`, sloupce na `docs_core_heads`, cfgItem
   `docs.core.sourceKinds`. `ds-upgrade` projde, `.md` dokumentace doplněna.
2. **Modul `core.exchange` skeleton** — `module.jsonc`, prázdný `src/`,
   `dependencies`. `ds-upgrade` ho zaregistruje (samozřejmě bez efektu).
3. **JSONC schema + JSON Schema** — `shpd.docs.document.v1.jsonc` (zdroj)
   + `.json` (kompilovaná). Spustit přes `opis/json-schema` validátor
   ručně sestaveným fixture, ověřit že schema je samo o sobě validní.
4. **SchemaLoader + SchemaValidator** + PHPUnit testy.
5. **ResolveResult + ResolveStatus** value objects + jednoduché testy.
6. **UnitResolver** + testy. Začni tímto — nejjednodušší (jen ISO lookup
   + alias map).
7. **VatCodeResolver** + testy — wraps existující `VatRateResolver`,
   přidá `notFound` vs `matched` logic.
8. **PartyResolver** + testy — nejdůležitější, nejvíc cest. Postupně:
   companyId → vatId → taxId → name LIKE → canCreate. Self-party flow
   testovat separátně.
9. **ItemResolver** + testy — analogicky, navíc per-partner mapping
   lookup.
10. **BankAccountResolver** + testy.
11. **DocumentValidator** — statická validace (povinná pole per docType,
    totals mismatch).
12. **DocumentApplier** — postupně po sekcích pipeline (`docs/exchange-format.md`
    §10). Začni s `validate()` (jen schema + DocumentValidator). Pak `preview()`
    (přidá resolve). Pak `apply()` (přidá reconcile + transform + transakční
    save + lineage update).
13. **ExchangeController** + úprava Router + dispatch loop v index.php.
    Curl smoke testy.
14. **End-to-end PHPUnit test** — full canonical → apply → assert DB
    state.
15. **README.md modulu** s ukázkami curl pro každý endpoint.
16. **Manuální E2E** — sestavit fixture canonical pro reálnou českou
    fakturu přijatou, `POST /apply`, ověřit v Adminer / DB browser že
    všechny tabulky mají správný state včetně `core_mail_extracted_documents`
    lineage update (pokud `source.extractedDoc` nastavena na existující row).

## Otevřené body

- **`partner_doc_number` validace per `docType`** — `ReceivedInvoiceDocument::validate`
  by mohl požadovat non-empty `partner_doc_number` při Confirm (state ≥ 20).
  Pro Fázi 1 stačí pole + warning v Exchange validatoru; tvrdá per-type
  validace v Document subclass je možné odložit. Rozhodni v rámci tasku
  podle síly závislostí — pokud bez toho nejde rozumně otestovat
  `applyOptions.targetDocState = 20`, doplň tu validaci, jinak nech jako
  follow-up.

- **`opis/json-schema` vs `justinrainbow/json-schema`** — pokud composer
  resolve ukáže konflikty s existujícími závislostmi (dibi, symfony console,
  phpunit), zvol nejlépe se vyrovnávající knihovnu. JSON Schema draft-07
  je dostačující pro náš formát (žádné `$dynamicRef` nepotřebujeme).

- **Schema kompilace JSONC → JSON** — pro Fázi 1 ručně. Pokud bude
  tendence se v tom plést / drift, navrhuju navazující CLI příkaz
  `shpd-server compile-schemas` jako iterace. Tady se to dá zapsat jen
  jako TODO komentář v JSONC souboru.

- **`apply` v rámci `extracted_doc` workflow** — když applier dostává
  canonical odvozený z extracted_doc (`source.extractedDoc` vyplněn), je
  rozumné v `apply` step 9 (Attach attachments) automaticky linkovat
  existující attachments z extracted_doc (resp. ze zdrojové zprávy)
  na nový doc_head. Aktuálně AttachmentService nemá API pro re-link,
  takže jak to udělat: buď SQL update `core_attachments_files.doc_table` /
  `doc_row`, nebo přidat method do AttachmentService. Pro Fázi 1 stačí
  jednoduchý SQL UPDATE v Applieru s komentářem "TODO: extract to
  AttachmentService when used elsewhere".
