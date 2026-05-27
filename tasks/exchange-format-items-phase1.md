# Task: Exchange Format pro Položky — Fáze 1

## Kontext

Stavíme **kanonický výměnný formát pro Položky** — `shpd.items.item.v1`.
Třetí konkrétní formát postavený nad infrastrukturou modulu `core.exchange`,
sourozenec `shpd.docs.document.v1` (Fáze 3b hotová) a
`shpd.persons.person.v1` (Fáze 1 hotová).

**Motivace.** Format se buduje teď, ne až s další ERP integrací, protože je
**tvrdá prerekvizita pro import dat ze starého Shipardu do nového**
(samostatný projekt, viz `old_shipard:modules/imports/newShipard/`). Import
spoléhá na exchange formát pro tři kategorie dat (osoby, položky, doklady) —
osoby i doklady už mají formát, položky byly poslední slepá kolej.

**Cíl Fáze 1:** Validate / Preview / Apply pipeline pro Item canonical,
včetně sub-kolekce `supplierCodes` (per-partner mapování dodavatelských
kódů) a merge strategií analogických persons (`createOnly`, `updateHeader`,
`mergeAdd`, `fullSync`). Apply musí umět vytvořit i aktualizovat existující
položku, propojit přes druh (item kind) a jednotku (unit).

**Mimo scope Fáze 1:**

- Frontend UI (modal náhled, popover pro `userAction`) — analogie doc Fáze
  3b. Přijde samostatně, jakmile bude potřeba ručně rozhodovat o `canCreate`
  v UI (zatím se používá jen z importu se `safe` / `liberal` autoCreateMode
  a z doc apply pipeline).
- `ItemExporter` (DB → canonical) — Fáze 3 follow-up, pro export mezi DS
  nebo registry sync.
- Batch apply (víc položek v jednom requestu) — Fáze 3 follow-up; pro
  importer ze starého Shipardu Phase 1 stačí per-item HTTP call.
- Exchange formát pro `economy_items_kinds` (Item Kinds) — samostatná
  fáze, pokud někdy bude potřeba. Phase 1 zachází s kindem jako s
  reference, ne entitou pro samostatný import. `ItemApplier` může
  ad-hoc vytvořit nový kind v rámci item apply pipeline (`canCreate`
  semantika přes `KindResolver`) — viz sekce 5.4.

## Před implementací přečti

Kompletně:

- **`docs/exchange-format-persons.md`** — nejbližší příbuzný formát.
  Obecná pojmosloví a architektura platí 1:1 i tady.
- **`docs/exchange-format.md`** — sourozenec spec pro doklady. Definuje
  obecný apply pipeline pattern, ResolveResult statusy, source lineage
  pattern, REST error shape.
- **`modules/core/exchange/README.md`** — stav existujícího modulu.

Klíčové existující soubory (nepřepisuj, modeluj podle nich):

- **`modules/core/exchange/src/Person/PersonApplier.php`** — primární
  vzor pro `ItemApplier`. Stejná pipeline (validate → resolve → reconcile
  → side-creates → save → lineage), stejné merge strategie, stejný
  TransactionlessTableGateway pattern. Item flow je striktně jednodušší
  (jedna sub-kolekce místo tří), takže ItemApplier bude menší.
- **`modules/core/exchange/src/Person/PersonValidator.php`** — vzor pro
  `ItemValidator`. Per-vstup PHP validace navíc proti JSON Schema.
- **`modules/core/exchange/src/Person/PersonResolver.php`** — vzor pro
  `ItemResolveResult` strukturu (header + sub-kolekce + closingExisting).
  Item analogie: header (item samotný) + supplierCodes.
- **`modules/core/exchange/src/Resolve/ItemResolver.php`** — **už existuje**
  a má kompletní logiku resolve s 6 strategiemi (ourCode → supplierCode
  per-partner → ean → sku → name fuzzy → canCreate). ItemApplier ho
  použije beze změny pro header lookup, případně rozšíří `buildCreatePayload`
  o item_kind / unit defaulty (viz 5.3).
- **`modules/core/exchange/src/Resolve/UnitResolver.php`** — existuje,
  použije ho `ItemApplier` při transformu `unit` (string) → `core_units.id`.
- **`modules/core/exchange/src/Resolve/PartyResolver.php`** — existuje,
  použije ho `ItemApplier` pro sub-kolekci `supplierCodes[].supplier`
  (lookup partnera).
- **`modules/core/exchange/src/Common/ApplyResult.php`**,
  **`Common/TransactionlessTableGateway.php`** — sdílené, použij beze
  změny.
- **`modules/core/exchange/src/Schema/SchemaLoader.php`**,
  `SchemaValidator.php` — registrujeme třetí schema.
- **`modules/economy/items/src/ItemDocument.php`** — `validate` + `beforeSave`.
  Pozor: ItemDocument auto-generuje `code`, pokud chybí. ItemApplier
  musí předávat `code` explicitně z payloadu (jinak by se přepsal náhodný).
  Plus denormalizuje `item_type` z `item_kind` v beforeSave — applier to
  nemusí dělat sám.
- **`modules/economy/items/src/ItemKindsProvisioner.php`** — seedovaný
  4 systémové kindy (`service`, `stock`, `accounting`, `other`) přes
  `system_code`. KindResolver má fallback na ně (viz 5.4).
- **`modules/economy/items/tables/economy_items.jsonc`** + `.md`,
  **`economy_items_kinds.jsonc`** + `.md`,
  **`economy_items_supplier_codes.jsonc`** + `.md` — cílové tabulky.
  Pozor: `supplier_codes` má unique index `(person, supplier_code)` —
  applier použije `INSERT IGNORE` nebo `INSERT ... ON DUPLICATE KEY UPDATE`
  pro idempotenci.

Vzorové fixtures pro inspiraci:

- **`tests/Fixtures/Exchange/persons/`** — fixture style.
- **`tests/Integration/Exchange/PersonsApplyE2ETest.php`** — E2E pattern.

## Co implementovat

### 1. DB schema — `economy_items` lineage

Stejný pattern jako u persons. Přidat tři nullable sloupce do
**`modules/economy/items/tables/economy_items.jsonc`** do nové
columnGroup `lineage`:

```jsonc
{
    "id": "lineage",
    "name": "Lineage",
    "name:cs": "Původ záznamu",
    "name:en": "Lineage"
}
```

Sloupce (v této skupině, na konec columns sekce, před systémové
`docState`/`docStateMain`):

| Sloupec | Typ | Nullable | Group |
|---|---|---|---|
| `source_kind` | `varchar`, length 40 | ano | `lineage` |
| `source_ref` | `varchar`, length 60 | ano | `lineage` |
| `source_imported_at` | `datetime` | ano | `lineage` |

`source_kind` má `cfgItem: "economy.items.sourceKinds"` (viz sekce 3).

Aktualizuj **`modules/economy/items/tables/economy_items.md`** — přidej
sekci "Původ záznamu (lineage)" obdobnou té v
`modules/base/persons/tables/base_persons_persons.md`.

### 2. DB schema — `economy_items_supplier_codes` lineage (volitelné)

Tabulka už má sloupec `created`, ale ne `source_kind`. **Phase 1
nepřidává lineage do sub-tabulky** — supplier code se vytváří buď z
doc apply pipeline (známý lineage přes parent `docs_core_heads`) nebo
z item apply pipeline (lineage je na parent `economy_items`). Tracking
per-mapping není v Phase 1 potřeba.

Pokud se ukáže potřeba (např. troubleshooting "kde se vzal tento
mapping"), přidá se to později jako lineage skupina + `source_kind` /
`source_imported_at`.

### 3. cfgItem `economy.items.sourceKinds`

Nový soubor **`modules/economy/items/config/sourceKinds.jsonc`**:

```jsonc
{
    "manual": {
        "name": "Ruční zadání",
        "name:cs": "Ruční zadání",
        "name:en": "Manual entry"
    },
    "aiExtraction": {
        "name": "Z AI extrakce",
        "name:cs": "Z AI extrakce",
        "name:en": "From AI extraction"
    },
    "import.oldShipard": {
        "name": "Import ze starého Shipardu",
        "name:cs": "Import ze starého Shipardu",
        "name:en": "Import from legacy Shipard"
    },
    "import.csv": {
        "name": "CSV import",
        "name:cs": "CSV import",
        "name:en": "CSV import"
    },
    "import.supplierCatalog": {
        "name": "Katalog dodavatele",
        "name:cs": "Katalog dodavatele",
        "name:en": "Supplier catalog"
    }
}
```

Registrovat v **`modules/economy/items/module.jsonc`** v sekci `config`:

```jsonc
{ "id": "economy.items.sourceKinds", "file": "config/sourceKinds.jsonc" }
```

Aktualizovat tabulku v **`modules/economy/items/README.md`** o nový
config klíč.

### 4. JSON Schema — `shpd.items.item.v1`

Dva soubory v **`modules/core/exchange/schemas/`**:

- **`shpd.items.item.v1.jsonc`** — JSONC se zdrojem (komentáře, struktura
  podle sekce 5 níže). Lidsky čitelný.
- **`shpd.items.item.v1.json`** — strict JSON pro validátor. Vyrobeno
  ručně ze .jsonc. Drift hlídá `ItemSchemaDriftTest` (viz 8.4).

Schema klíčové konstrukty:

- `format` — const `"shpd.items.item"`.
- `formatVersion` — pattern `"^1\\.\\d+$"`.
- `code` — `string` nullable. Pokud `null` nebo missing, applier nechá
  ItemDocument vygenerovat. Pokud vyplněn, applier zachová.
- `name` — `string`, required v top-level.
- `kind` — object s `code` / `name` / `itemType` (viz sekce 5.4).
- `unit` — `string`, required (ISO unit code nebo lokalizovaná zkratka;
  UnitResolver mapuje).
- `supplierCodes` — pole objektů, každý s inline `supplier` Party
  fragmenty (analogie Party v doc spec — viz 5.5).
- `applyOptions` — viz sekce 5.7.
- `_resolve` — passthrough.

Sub-schémata Party inline (Phase 1 — analogie persons spec sekce 1,
"Skip $ref/definitions").

### 5. Specifikace canonical formátu `shpd.items.item.v1`

Plnou specifikaci převedeš do `docs/exchange-format-items.md` (viz sekce
12). Tady je referenční payload pro implementaci:

```jsonc
{
    // ── Format meta ─────────────────────────────────────────────────
    "format": "shpd.items.item",
    "formatVersion": "1.0",

    // ── Source (audit / lineage) ────────────────────────────────────
    "source": {
        "kind":        "import.oldShipard",
        "fetchedAt":   "2026-05-27T10:00:00Z",
        "registryRef": "12345",          // ndx ve starém Shipardu
        "raw":         { /* opaque */ }
    },

    // ── Identity ────────────────────────────────────────────────────
    "code":        "K-001",              // economy_items.code (unique)
                                         //   null = applier nechá auto-gen
    "name":        "Konzultace IT",      // required
    "description": "Hodinová sazba senior konzultanta",
    "sku":         null,
    "ean":         null,

    // ── Classification ──────────────────────────────────────────────
    // Druh (item_kind) — viz 5.4 KindResolver
    "kind": {
        "code":     "service",           // optional: match na system_code
        "name":     "Konzultace IT",     // optional: match na name (canCreate)
        "itemType": 0                    // 0=service, 1=stock, 2=accounting,
                                         //   3=other; fallback na systémový
                                         //   druh, pokud code/name neexistuje
    },

    // ── Details ─────────────────────────────────────────────────────
    "validFrom": null,
    "validTo":   null,

    // ── Pricing ─────────────────────────────────────────────────────
    "salesPriceNoVat": 1000.00,
    "unit":            "h",              // ISO unit code, UnitResolver

    // ── Per-partner supplier codes ──────────────────────────────────
    "supplierCodes": [
        {
            "supplier": {                // inline Party (analogie doc)
                "name":      "Acme s.r.o.",
                "country":   "cz",
                "companyId": "12345678",
                "taxId":     "CZ12345678",
                "vatId":     "CZ12345678"
            },
            "supplierCode": "KONZ-001",
            "supplierName": "Konzultace IT"   // optional, audit-only
        }
    ],

    // ── Status ──────────────────────────────────────────────────────
    "status": {
        "isClosed": false,
        "docState": 40                   // 10 (Koncept) | 40 (V pořádku) | 70
                                         //   (Archív) | 80 (V opravě) | 90 (Smaz.)
    },

    // ── Apply options ───────────────────────────────────────────────
    "applyOptions": {
        "mergeStrategy":  "mergeAdd",    // createOnly | updateHeader |
                                         //   mergeAdd (default) | fullSync
        "targetDocState": 40,            // 10 | 40
        "rejectOnIssues": ["error"]
    },

    // ── Resolve state ───────────────────────────────────────────────
    "_resolve": { /* viz sekce 5.6 */ }
}
```

#### 5.1 Identity

`code` je primární klíč v doménovém slova smyslu (unique v
`economy_items.code`). Tři chování:

| Vstup `code` | Chování |
|---|---|
| `null` / vynecháno | Header resolve probíhá podle `name` (LIKE fuzzy) — ItemResolver má fallback. Při create ItemDocument auto-generuje 6-znakový hex. |
| Vyplněn, item neexistuje | Resolver na `code` matchne `notFound`, dál pokračuje. Při create applier zachová `code`. |
| Vyplněn, item existuje | Resolver matchne (`matchedBy: "ourCode"`), header `matched`. |

`name` je required. Pokud chybí v payloadu, schema reject.

`sku` / `ean` mají index v DB, ItemResolver je zkouší jako fallback klíče
(po `ourCode` a per-partner `supplierCode`).

#### 5.2 Unit

`unit` je required string. UnitResolver mapuje:

1. ISO unit code (`"h"`, `"kg"`, `"pcs"`, `"l"`, …) → match v
   `core_units.code`.
2. Lokalizovaná zkratka (`"ks"` → pcs, `"hod"` → h, …) přes alias
   tabulku v UnitResolver.

Pokud `unit` nepárá, applier vyrobí **warning** (`unit_unknown`) a
použije default `pcs`. Item se uloží, ale s flagem v `_resolve.issues`.

#### 5.3 Description, validity, pricing

| Sloupec DB | Canonical |
|---|---|
| `description` | `description` |
| `valid_from` | `validFrom` |
| `valid_to` | `validTo` |
| `sales_price_no_vat` | `salesPriceNoVat` (number, nullable) |

`salesPriceNoVat` musí být `>= 0` (validátor). `null` znamená "necenený".

#### 5.4 Kind — KindResolver

Item musí mít druh (`item_kind` FK NOT NULL). Canonical `kind` object
nese tři hinty, KindResolver je zkouší v pořadí:

1. **`kind.code` exact match** v `economy_items_kinds.system_code` (unique).
   → `matched`, `matchedBy: "system_code"`.
2. **`kind.name` exact match** v `economy_items_kinds.name`.
   1 match → `matched`, `matchedBy: "name"`.
   Víc match → `ambiguous` se seznamem kandidátů.
3. **Fallback per `kind.itemType`** — applier vyhledá systémový druh
   se `system_code IN ('service','stock','accounting','other')` podle
   mapování:
   - `itemType: 0` → `system_code: 'service'`
   - `itemType: 1` → `system_code: 'stock'`
   - `itemType: 2` → `system_code: 'accounting'`
   - `itemType: 3` → `system_code: 'other'`
   → `matched`, `matchedBy: "itemTypeFallback"`.
4. **Žádný hint, žádný fallback** — `notFound`, applier reject s
   `unresolved_required` (kind je povinný).
5. **Pouze `kind.name` vyplněný, žádný match** → `canCreate` s payloadem
   pro `economy_items_kinds` insert. Vyžaduje `itemType` v canonical
   (default 3 = other, pokud chybí) — nový kind dostane `name` z payloadu
   a `item_type` z `kind.itemType ?? 3`.

`KindResolver` je nová třída:

```php
modules/core/exchange/src/Resolve/KindResolver.php
```

Filtruje `docState IN (10, 40, 80)` na `economy_items_kinds` (analogie
ostatních resolverů). Vrací `ResolveResult` (sdílený typ).

**Drift hlídání systémových kindů.** Implementuj jako PHP konstantu v
KindResolver, ne lookup do cfgItem každý query:

```php
private const FALLBACK_KIND_BY_ITEM_TYPE = [
    0 => 'service',
    1 => 'stock',
    2 => 'accounting',
    3 => 'other',
];
```

Pokud `ItemKindsProvisioner` v budoucnu změní system_code naming,
KindResolver pojede stejnou tabulku — žádný drift.

#### 5.5 SupplierCodes — sub-kolekce

```jsonc
"supplierCodes": [
    {
        "supplier": {                    // inline Party
            "name":      "Acme s.r.o.",
            "country":   "cz",
            "companyId": "12345678",
            "vatId":     "CZ12345678",
            "taxId":     "CZ12345678"
        },
        "supplierCode": "KONZ-001",      // dodavatelský kód
        "supplierName": "Konzultace IT"  // audit-only label
    }
]
```

Match-key (na `economy_items_supplier_codes`):

1. `(person, supplier_code)` — unique index. Resolver lookuje
   resolved `supplier.personId` + `supplierCode`.
2. Match → `matched` se záznamem; missing → `canCreate`.

`SupplierCodesResolver` (nová třída):

```php
modules/core/exchange/src/Resolve/SupplierCodesResolver.php
```

Vstup: `supplierCodes[]` array + resolved `itemId` (může být `null` při
create — applier ho propíše po insertu). Volá `PartyResolver` pro každý
prvek (s `personType = Company` filtrem). Vrací nested ResolveResult.

**Phase 1 omezení:** SupplierCodesResolver **nevytváří** nového partnera.
Pokud `supplier` resolvuje na `canCreate`, applier vrátí warning
(`supplier_unknown`) a sub-záznam SKIP. Item se uloží, jen bez tohoto
supplier mapping. Důvod: importer ze starého Shipardu nejdřív importuje
persons, pak items — supplier osob bude v DB. Pro doc apply pipeline se
supplier vytváří přes DocumentApplier vlastní side-create logikou, ne tady.

Pokud uživatel v 3b UI dá `userAction: "create"` na supplier, autocreate
proběhne. Default chování (`userAction: null` + `canCreate`) je SKIP s
warningem.

#### 5.6 `_resolve` state

```jsonc
{
    "summary": {
        "status":           "needsAttention",   // ok | needsAttention | hasErrors | applied
        "headerStatus":     "matched",
        "kindStatus":       "matched",
        "unitStatus":       "matched",
        "supplierCodeCount": {
            "matched":   1,
            "canCreate": 1,
            "skipped":   0
        }
    },
    "header": {
        "status":     "matched",        // matched | canCreate | ambiguous
        "itemId":     42,
        "matchedBy":  "ourCode",
        "candidates": [],
        "userAction": null
    },
    "kind": {
        "status":     "matched",
        "kindId":     5,
        "matchedBy":  "system_code"     // system_code | name | itemTypeFallback
    },
    "unit": {
        "status":     "matched",
        "unitId":     3,
        "matchedBy":  "iso"
    },
    "supplierCodes": [
        {
            "index":  0,
            "supplier": {
                "status":   "matched",
                "personId": 100,
                "matchedBy": "companyId"
            },
            "status":     "matched",    // mapping existuje
            "mappingId":  200
        },
        {
            "index": 1,
            "supplier": {
                "status":   "canCreate"
            },
            "status":     "skipped",    // supplier canCreate → SKIP
            "userAction": null,
            "issue":      "supplier_unknown"
        }
    ],
    "issues": [
        {
            "severity": "warning",
            "path":     "supplierCodes[1]",
            "code":     "supplier_unknown",
            "message":  "Dodavatel 'Beta s.r.o.' (CZ:87654321) nebyl nalezen. Sub-záznam přeskočen."
        }
    ]
}
```

#### 5.7 ApplyOptions

```jsonc
"applyOptions": {
    "mergeStrategy":  "mergeAdd",       // createOnly | updateHeader |
                                        //   mergeAdd (default) | fullSync
    "targetDocState": 40,               // 10 | 40
    "rejectOnIssues": ["error"]         // ["error"] | ["error","warning"] | []
}
```

Merge strategie analogie persons:

| Strategie | Hlavička | SupplierCodes |
|---|---|---|
| `createOnly` | Reject pokud existuje (`409 item_exists`) | — |
| `updateHeader` | Přepsat hlavičku | Beze změny |
| `mergeAdd` *(default)* | Aktualizovat jen prázdná pole | Matched → nechat; missing v DB → přidat; existující missing v payloadu → nechat |
| `fullSync` | Přepsat hlavičku celou | Matched → nechat; missing v DB → přidat; existující missing v payloadu → **nechat** (žádný closing — viz níže) |

**`fullSync` u supplierCodes NEMÁ closing semantiku.** Důvody:

- Tabulka nemá `valid_to`.
- Mapping je per-partner; vyhodit ho při sync z jednoho zdroje by
  rozbilo per-partner historii (kdyby supplier A přestal položku
  dodávat, neznamená to, že přidaný mapping z dřívějšího importu je
  špatný).
- Closing přes `docState = 90` (smazání) by ztratil per-partner audit.

Pokud uživatel chce explicitně vyhodit mapping, dělá to ručně v UI (Phase
3 feature, mimo scope).

### 6. PHP modul — `core.exchange/src/Item/`

Nový adresář **`modules/core/exchange/src/Item/`** s analogickou strukturou
jako `Person/`:

#### 6.1 `ItemApplier.php`

Vstup: canonical array. Výstup: `ApplyResult` (sdílený `Common/ApplyResult`).

Pipeline (analogická PersonApplier):

1. **Schema validation** přes `SchemaLoader`/`SchemaValidator`.
2. **Resolve** — `ItemResolveResult` (header + kind + unit + supplierCodes).
3. **Reconcile** s klientským `_resolve.*.userAction`.
4. **Validation gate** — error severity, `createOnly + matched`,
   `rejectOnIssues` honor.
5. **BEGIN TRANSACTION** (přes `TransactionlessTableGateway`).
6. **Side-create kind** — pokud `_resolve.kind.status == canCreate` a
   `userAction == create`, vytvořit nový `economy_items_kinds` row přes
   ItemKindDocument (pokud existuje) nebo přímo `TableGateway::saveDocument`.
7. **Header upsert** — `ItemDocument::saveDocument`:
   - Create: insert s `code` (pokud vyplněn), `name`, `description`,
     `item_kind`, `unit`, `sales_price_no_vat`, `valid_from`/`valid_to`,
     `sku`, `ean`, `docState = targetDocState ?? 10`.
   - Update (`mergeAdd` / `updateHeader` / `fullSync`): pole per
     merge strategie.
8. **SupplierCodes per item** — per element v payloadu:
   - `supplier.status == matched` + sub-záznam matched → leave alone
     (kromě authoritative refresh, pro items nedefinováno → vždy leave).
   - `supplier.status == matched` + sub-záznam canCreate → INSERT IGNORE
     (idempotence; unique `(person, supplier_code)`).
   - `supplier.status == canCreate` + `userAction == null` → SKIP
     s warning `supplier_unknown`. `_resolve.supplierCodes[i].status = "skipped"`.
   - `supplier.status == canCreate` + `userAction == create` → autocreate
     partner (delegace na `PersonApplier::apply`), pak INSERT IGNORE.
9. **Lineage update** — SQL UPDATE `economy_items` set `source_kind`,
   `source_ref`, `source_imported_at` (pokud payload obsahuje `source.kind`).
10. **COMMIT**.
11. Vrátit enriched canonical.

Klíčové implementační poznámky:

- `mergeStrategy` default `mergeAdd`, override v `applyOptions.mergeStrategy`.
- `createOnly + matched header` → `ApplyResult` se status flag, controller
  vrací `409 item_exists`.
- `code` collision: pokud v payloadu vyplněn a v DB existuje jiný item
  s tím samým kódem (ne matched), to znamená neshoda business klíčů —
  applier vrátí `409 code_conflict`. (Vzácný případ — typicky nastane jen
  při kombinaci jiných match-keys a manuální `code` mismatch.)
- ItemDocument **denormalizuje `item_type`** z `item_kind` v beforeSave —
  applier nemusí dělat sám. Pošli `item_kind`, `item_type` se nastaví
  serverem.
- ItemDocument auto-generuje `code` pokud chybí — pokud canonical má `code`,
  pošli ho explicitně.

#### 6.2 `ItemValidator.php`

PHP-side validace navíc proti JSON Schema:

- `name` required (error).
- `unit` required (error).
- `salesPriceNoVat` `>= 0` (error pokud `< 0`).
- `kind` — alespoň jeden ze tří klíčů (`code` / `name` / `itemType`)
  required (error). Pokud chybí všechny tři, není kam resolvnout.
- `code` pattern: pokud vyplněn, max 25 znaků, žádné whitespace
  (`/^\S{1,25}$/`).
- `sku` / `ean` — pokud vyplněn, validní řetězec (basic length check).
- `targetDocState` v `[10, 40, 70, 80, 90]`; pokud chybí, default 10.

#### 6.3 `ItemResolver` — beze změny + drobná úprava `buildCreatePayload`

Stávající `ItemResolver` má v `buildCreatePayload` TODO komentář:

> `item_kind` + `unit` must be supplied by Applier before save —
> ItemDocument::validate rejects rows without them. The Exchange
> applier picks defaults: item_kind = "service" kind (well-known),
> unit = resolved row.unit if present.

Phase 1: Applier (ne resolver) doplní `item_kind` a `unit` v transform
fázi. ItemResolver beze změny. KindResolver doplní `item_kind`,
UnitResolver doplní `unit`.

Pokud chceš zjednodušit, můžeš v ItemResolver v `buildCreatePayload`
přidat null pole pro `item_kind` a `unit`, ať je payload kompletní
schema-wise. Nepřidávat default hodnoty — to je odpovědnost applieru.

#### 6.4 `KindResolver.php` (nový)

Viz sekce 5.4. Public API:

```php
public function resolve(array $kind): ResolveResult;
```

Vstup: `kind` sub-object z canonical. Logic per sekce 5.4.

#### 6.5 `SupplierCodesResolver.php` (nový)

Viz sekce 5.5. Public API:

```php
/**
 * @param array<int, array<string, mixed>> $supplierCodes
 * @param int|null $itemId  matched item id (null pro create flow)
 */
public function resolve(array $supplierCodes, ?int $itemId): array;
```

Vrací pole `ResolveResult`-like struktur per index. Volá `PartyResolver`
pro každý prvek, pak `economy_items_supplier_codes` lookup.

#### 6.6 `ItemResolveResult.php` (nový)

Datová třída pro výsledek resolve fáze. Obsahuje:

- `header: ResolveResult` (z ItemResolver)
- `kind: ResolveResult` (z KindResolver)
- `unit: ResolveResult` (z UnitResolver)
- `supplierCodes: array<int, array{...}>` (z SupplierCodesResolver)
- `issues: array<int, array{severity, path, code, message}>`

Analogie `PersonResolveResult`.

#### 6.7 `ItemResolver` — orchestrace (volitelně)

Volitelně přidej `ItemFlowResolver` (analogie `PersonResolver`), který
orchestruje všechny resolvery (header item + kind + unit + supplierCodes)
do jednoho volání. Pokud je to čistší, udělej; pokud je dost přímo volat
v ItemApplier, neudělávej. Preferuj čistší.

**Doporučení:** Použít `ItemFlowResolver` jako orchestrátor. ItemApplier
zavolá jeden resolver, vezme `ItemResolveResult`, dál pracuje.

### 7. REST controller

Rozšířit existující **`src/Api/Controller/ExchangeController.php`** o
tři endpointy pod `/api/v1/_exchange/items/item/`:

- `POST /validate` → `validateItem(Request $request): Response`
- `POST /preview` → `previewItem(Request $request): Response`
- `POST /apply` → `applyItem(Request $request): Response`

Konstruktor controlleru přijme `?ItemApplier $itemApplier = null` (analogie
`?PersonApplier`). Bez wired ItemApplier vrátí `500 INTERNAL_ERROR` per
existující `personFlowUnavailable()` pattern.

Stejný error shape, stejné HTTP codes. Přidat error codes:

- `409 item_exists` — `createOnly + matched`.
- `409 code_conflict` — `code` v payloadu kolikuje s jiným item.

Router (`src/Api/Router.php`): nová metoda `resolveItemsExchangeRoute()`
analogie `resolvePersonsExchangeRoute()`. Path prefix `/_exchange/items/item/`,
action prefix `item:` v Route name (jako `person:` pro persons).

Wiring v `public/index.php` (nebo kde se dependencies injection děje —
sleduj jak `PersonApplier` injection probíhá).

### 8. Tests

#### 8.1 Fixtures

**`tests/Fixtures/Exchange/items/`**:

- `service_create_happy.json` — Nová služba s `kind.code: "service"`,
  unit `"h"`, supplier code mapping na existující partner.
  `mergeStrategy: createOnly`.
- `stock_create_with_kind_canCreate.json` — Nová zásobní položka s
  `kind.name: "Hardware notebooky"`, kind neexistuje → canCreate s
  `userAction: "create"`. `kind.itemType: 1`.
- `item_update_mergeAdd.json` — Existující item (matched podle `code`),
  payload má prázdný `description` (nemá přepsat) a vyplněný
  `salesPriceNoVat` (DB má `null` → doplní).
- `item_update_fullSync.json` — Existující item, fullSync — všechny pole
  přepsány. SupplierCodes: jeden matched, jeden nový → INSERT IGNORE,
  jeden existující v DB ale missing v payloadu → **leave alone** (žádné
  closing pro supplier codes).
- `item_supplier_unknown.json` — Nový item, supplier code mapping s
  partnerem který v DB není → SKIP s warning, item se uloží bez mappingu.
- `item_supplier_canCreate.json` — Stejný payload, `_resolve.supplierCodes[0].supplier.userAction: "create"` → partner se vytvoří,
  pak INSERT IGNORE mapping.
- `item_code_conflict.json` — Payload má `code: "K-001"`, ale v DB
  existuje jiný item s tím samým kódem a v matched-by názvu se neshoduje.
  Expect `409 code_conflict`.
- `item_kind_itemTypeFallback.json` — Payload má jen `kind.itemType: 0`,
  bez `code` nebo `name`. KindResolver fallback na `system_code: service`.

#### 8.2 Unit testy

**`tests/Unit/Module/Core/Exchange/Item/`**:

- `KindResolverTest` — všech 4 strategie (system_code → name → itemTypeFallback
  → canCreate). Ambiguous pro multiple name match.
- `SupplierCodesResolverTest` — matched / canCreate per supplier; per-item
  filter; multi-supplier payload.
- `ItemValidatorTest` — required name/unit; salesPriceNoVat range; kind
  required hint; code pattern.
- `ItemApplierTest` — happy path create; updateHeader; mergeAdd;
  fullSync; createOnly + matched = reject; code conflict; kind canCreate;
  supplier canCreate (skip vs create); supplier matched + mapping new.

#### 8.3 Integration / E2E

**`tests/Integration/Exchange/Items/`**:

- `ItemsApplyE2ETest` — full HTTP request → DB state assertion. Mock
  DS, fixture payload, post na endpoint, ověř `savedItemId` + DB rows
  v `economy_items` + `economy_items_supplier_codes` + případně nová
  `economy_items_kinds`. Per fixture jeden test.

#### 8.4 Schema drift test

**`tests/Unit/Module/Core/Exchange/Schema/ItemSchemaDriftTest`** —
analogie existujícího `SchemaDriftTest` pro doklady. Načte
`shpd.items.item.v1.jsonc`, strip comments, porovná se sourozencem
`.json`. Selže pokud se rozcházejí.

### 9. Module dependencies

V **`modules/core/exchange/module.jsonc`** zkontroluj dependencies:

- `economy.items` — musí být v deps.
- `economy.codebooks` — pravděpodobně už je přes tranzitivu (vat, fiscal);
  nepřidávej, pokud není potřeba.
- `core.units` — musí být v deps (UnitResolver).
- `base.persons` — už je v deps (PartyResolver).

### 10. README modulu

Aktualizuj **`modules/core/exchange/README.md`**:

- Nová tabulka "Formáty" — přidat `shpd.items.item.v1` na seznam.
- Sekce "Architektura" — přidat `ItemApplier`, `ItemFlowResolver`,
  `KindResolver`, `SupplierCodesResolver` do schématu.
- Sekce "REST API" — přidat tři items endpointy.
- Sekce "Curl příklady — Item flow" — analogie persons curl příkladů.
- Sekce "Stav (Items Fáze 1)" — výčet hotových komponent.
- Sekce "Limity Items Fáze 1" — co není v Phase 1.

### 11. Vytvoření spec dokumentu

**`docs/exchange-format-items.md`** — kanonická specifikace, analogie
`docs/exchange-format-persons.md`. Sekce:

1. Účel a kontext
2. Pojmosloví (rozšíření)
3. Specifikace `shpd.items.item.v1` (top-level struktura)
4. Životní cyklus
5. Kind sub-object + KindResolver
6. SupplierCode sub-object + SupplierCodesResolver
7. Resolve (ItemFlowResolver orchestrace)
8. `_resolve` state
9. Merge strategie
10. Apply pipeline
11. REST API
12. Lineage
13. Verzování
14. Budoucí rozšíření
15. Reference

Implementuj v stejném detailu jako persons spec. Použij persons spec
jako šablonu — překopíruj strukturu, vyplň items-specifika. Pro části,
kde se Item v ničem neliší od Person (Lineage, Verzování), zkraťuj přes
odkazy na persons spec.

Aktualizuj **`docs/README.md`** — přidej `exchange-format-items.md` do
seznamu.

## Hotovo když

1. **`docs/exchange-format-items.md`** existuje a popisuje plnou
   specifikaci `shpd.items.item.v1`.
2. **`economy_items`** má sloupce `source_kind`, `source_ref`,
   `source_imported_at` ve skupině `lineage`. Aktualizovaný `.md`.
3. **`economy.items.sourceKinds`** cfgItem registrovaný a obsahuje
   5 klíčů (manual, aiExtraction, import.oldShipard, import.csv,
   import.supplierCatalog).
4. **`shpd.items.item.v1.{json,jsonc}`** schema soubory existují
   a `SchemaLoader` je umí načíst přes `getSchema('shpd.items.item.v1')`.
   `ItemSchemaDriftTest` prochází.
5. **`ItemApplier::apply($canonical, $options)`** implementuje pipeline
   z sekce 6.1 — create, updateHeader, mergeAdd, fullSync.
6. **`KindResolver`** vrací správně podle priority strategie (system_code
   → name → itemTypeFallback → canCreate). Filtruje `docState IN (10, 40, 80)`.
7. **`SupplierCodesResolver`** orchestruje PartyResolver per supplier,
   vrací nested ResolveResult. Filtr `docState` na supplier_codes není
   potřeba (sub-tabulka, žádný state column).
8. **`createOnly` + header `matched`** → `409 item_exists`.
9. **`code` collision** (vyplněný code v payloadu, jiný item v DB má ten
   samý code) → `409 code_conflict`.
10. **`supplier.canCreate` + `userAction = null`** → SKIP s warning,
    item se uloží bez tohoto mappingu.
11. **`supplier.canCreate` + `userAction = "create"`** → autocreate
    partner (delegace na PersonApplier), pak INSERT IGNORE mapping.
12. **`kind.canCreate`** s `kind.name` a `kind.itemType` → vytvořit nový
    `economy_items_kinds` row.
13. **REST endpointy** `/api/v1/_exchange/items/item/{validate,preview,apply}`
    odpovídají sekci 7; error shape kompatibilní s document a person
    endpointy.
14. **Lineage** se zapisuje při apply (`source_kind`, `source_ref`,
    `source_imported_at`).
15. **`fullSync` u supplierCodes** existující záznamy nepřítomné v
    payloadu **NE-uzavírá** (žádný `valid_to`, žádný `docState = 90`).
16. **`code` v payloadu** se zachová při create (ItemDocument NE-auto-generuje
    pokud applier předá explicitní hodnotu).
17. **`item_type` se denormalizuje** z `item_kind` přes ItemDocument::beforeSave
    — applier ho nemusí dodávat.
18. **Tests** — všechny unit testy v `tests/Unit/Module/Core/Exchange/Item/`
    + integration testy v `tests/Integration/Exchange/Items/` prochází.
    Pokrytí všech mergeStrategy a všech statusů `_resolve`.
19. **Existující doc a persons testy** v `core.exchange` stále prochází
    (žádný refactor stávajícího kódu nemá způsobit regresi).
20. **`modules/core/exchange/README.md`** aktualizovaný — sekce "Item flow"
    s curl příklady.
21. **`docs/README.md`** odkazuje na `exchange-format-items.md`.

## Doporučené pořadí implementace

1. **`docs/exchange-format-items.md`** spec napřed — všechny otevřené
   body se vyjasní teorií, ne implementací.
2. **DB migration — lineage v `economy_items`** + `sourceKinds` cfgItem.
   Spustit `shpd-ds upgrade`.
3. **JSON Schema** soubory + `SchemaLoader` registrace + drift test.
4. **`ItemValidator`** + unit testy.
5. **`KindResolver`** + unit testy.
6. **`SupplierCodesResolver`** + unit testy.
7. **`ItemFlowResolver`** orchestrace — header (delegace na ItemResolver),
   kind, unit, supplierCodes.
8. **`ItemApplier`** — postupně: create-only happy path, updateHeader,
   mergeAdd, fullSync, kind canCreate, supplier canCreate. Per krok
   unit test.
9. **REST controller + Router** — rozšířit `ExchangeController` + Router
   o tři items endpointy.
10. **Integration testy** — E2E fixtures, HTTP request → DB assertion.
11. **README update** — Curl příklady, sekce Stav.

Po každém kroku spustit relevantní testy + `shpd-ds upgrade` pokud byly
změny v jsonc tabulkách.

## Otevřené body / rozhodnutí

Tyto věci jsem v spec nechal otevřené — vyřeš podle reality kódu.

### 1. `ItemKindDocument` existuje vs neexistuje

V `modules/economy/items/src/` je `ItemKindDocument.php`. Použij ho pro
side-create kind (přes `TableGateway::saveDocument('economy_items_kinds', $data)`),
ne přímý SQL insert. ItemKindDocument má svoji validaci (chrání před
změnou item_type u použitého druhu) a beforeSave logiku.

### 2. `code` collision detection

Aktuální `ItemDocument::validate` kontroluje uniqueness `code` přes
DB lookup. Tj. ItemApplier se na to může spolehnout — pokud applier
předá `code` a item se ve fázi 7 uloží, ItemDocument vrátí validation
error pokud collision. Otázka je, jestli applier předá `code` explicitně
(když ItemResolver na něj nematchnul, ale jiný item v DB ho má).

Doporučená cesta:
- ItemFlowResolver před save fází provede explicit lookup
  `SELECT id FROM economy_items WHERE code = ? AND docState IN (10, 40, 80)`.
- Pokud match a header `_resolve.header.itemId` se liší → vyhodit
  `code_conflict` issue + reject (409).

To je čistší než spoléhat na ItemDocument::validate, který by vyhodil
generic validation error bez kontextu, že jde o code collision z apply
flow.

### 3. ItemDocument denormalizace `item_type`

ItemDocument::beforeSave přepisuje `item_type` podle `item_kind`. To je
důležitá invariant pro DB konzistenci. Applier nemá moc psát do `item_type`
explicitně — pokud se v payloadu objeví, ignoruj ho (ItemDocument override
to stejně).

Ale: payload má `kind.itemType` — to není `item_type` v DB, ale hint pro
KindResolver fallback. Pojmenování v canonical (`kind.itemType`) je dobré,
nemíchá se s DB `item_type`. Implementuj poznámku do spec.

### 4. SupplierCodesResolver — Party deep copy

Kdy `supplierCodes[i].supplier` vede na canCreate a `userAction: "create"`,
applier deleguje na `PersonApplier::apply` s `PersonApplier`-kompatibilním
payloadem. To znamená:

- Konstruovat Person canonical z Party fragmentu (`personType: "company"`,
  `name.fullName: supplier.name`, …).
- Volat `PersonApplier::apply($personCanonical, ['mergeStrategy' => 'createOnly'])`.
- Použít resultující `personId`.

DocumentApplier už tohle dělá (`createPartyAsPerson` nebo podobně —
sleduj jak). Refactoruj do shared helperu v `Common/`, ať není duplicita:

```php
modules/core/exchange/src/Common/PartyToPersonCanonical.php
```

Statický helper, který Party → Person canonical mappuje. Použije i
DocumentApplier i ItemApplier.

### 5. `kind.itemType` jako absolutní fallback vs warning

Pokud payload má jen `kind.itemType: 0` (a žádný `code` ani `name`),
KindResolver fallbackuje na systémový druh `service`. To je tichá akce
bez warning.

Alternativa: vždy emit warning `kind_inferred_from_itemType`, ať user
v importu vidí, že se nedopočetlo z přesných hintů.

**Preference:** warning emit. Pomáhá uživateli identifikovat položky,
které mají chudé kind hinty (typicky importer ze starého Shipardu, kde
se starý "itemType" mapuje na typ, ne na konkrétní druh).

### 6. ItemApplier statusCode pro `applyOptions.targetDocState`

Pokud `targetDocState = 40` a payload nemá `name` (tj. validation
fail), applier vrátí `422 validation_failed`. Pokud `targetDocState = 10`
(draft), validace je permissive, ale pořád musí `name` být — `economy_items.name`
je NOT NULL. Pro Phase 1: validate `name` required vždy (i pro draft).

### 7. JSON Schema strictness pro `_resolve` passthrough

Schema povolí `_resolve` jako `additionalProperties: true` (jako persons).
PHP `ItemFlowResolver` validuje strukturu při reconcile fázi.

### 8. Multi-language fields v sourceKinds

Cfg `economy.items.sourceKinds` má `name:cs` / `name:en`. Existující
`base.persons.sourceKinds` taky. Sleduj stejný pattern.

### 9. Backwards compat s `ItemResolver` z doc flow

DocumentApplier (doc flow) volá `ItemResolver::resolve($itemFragment, $supplierPersonId)`
v rámci řádku faktury. Tato Phase 1 nesmí ItemResolver měnit incompatibly.

Konkrétně: `ItemResolver::buildCreatePayload` v Phase 1 vrátí payload bez
`item_kind` / `unit` (TODO komentář). DocumentApplier to ale dnes řeší
tak, že side-create item v rámci doc apply doplní default kind (`service`
přes system_code) a default unit (`pcs`). Ověř, že to dodnes funguje —
pokud ano, ItemResolver beze změny.

ItemApplier (tento task) si default doplní sám přes KindResolver/UnitResolver,
takže ItemResolver beze změny.

### 10. `economy_items_supplier_codes.created` — kdo vyplní

Sloupec `created` (datetime, NOT NULL). Při insertu přes
`TableGateway::saveDocument` se vyplní automaticky? Pokud ne, applier
musí explicitně předat `NOW()`. Ověř implementaci `TableGateway` (sleduj
analogii s `economy_items.created` / `modified` pokud existují systémové
sloupce).

## Příprava pro Items Fáze 2+

Tato Fáze 1 staví foundation. Future fáze:

- **Fáze 2 (`ItemExporter`)** — DB → canonical, pro export mezi DS, registry
  sync s katalogy dodavatelů, periodickou synchronizaci s ERP.
- **Fáze 3 (Frontend UI)** — modal náhled, popover pro userAction,
  analogie doc Fáze 3b. Přijde, jakmile bude reálný use case (drag-and-drop
  CSV import s preview).
- **Fáze 4 (Batch apply)** — víc položek v jednom requestu. Pro CSV import
  od ERP dodavatele to bude potřeba.
- **Exchange formát pro `economy_items_kinds`** (`shpd.items.kind.v1`) —
  pokud někdy bude potřeba sdílet katalogy druhů. V Phase 1 řešíme přes
  ad-hoc canCreate v rámci Item apply.

Z této Fáze 1 nic dalšího pro Fázi 2+ nepotřebuje — `ItemApplier::apply`
je univerzální vstupní bod, který obstojí ve všech budoucích scénářích.
