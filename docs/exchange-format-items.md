# Shipard — Výměnný formát položek

> Kanonický výměnný formát pro entitu **Položka** (`economy_items`) včetně
> per-partner dodavatelských kódů. Třetí konkrétní formát postavený nad
> infrastrukturou modulu `core.exchange`.
>
> Sourozenci:
> [exchange-format.md](exchange-format.md) (doklady),
> [exchange-format-persons.md](exchange-format-persons.md) (osoby).

## 1. Účel a kontext

`shpd.items.item.v1` je kanonická JSON reprezentace položky (zboží / služba
/ účetní položka / ostatní) určená pro:

1. **Import ze starého Shipardu** — primární use case pro Fázi 1. Importer
   ze starého Shipardu (samostatný projekt, viz `old_shipard:modules/imports/newShipard/`)
   spoléhá na exchange formát pro tři kategorie dat: osoby, položky, doklady.
   Osoby i doklady už formát mají, položky byly poslední slepá kolej.
2. **CSV / katalogový import** od dodavatelů — adaptér přečte vendor formát,
   transformuje na canonical, předá applieru. Per-partner `supplierCodes`
   sub-kolekce nese mapování dodavatelského kódu na lokální položku.
3. **Periodická synchronizace** katalogu dodavatele — re-import s
   `mergeStrategy: fullSync` udrží hlavičku položky v souladu se zdrojem.
   Per-partner supplier code mapování se synchronizací **neuzavírají**
   (viz §9).
4. **Vytvoření položky z dokladu** — když `DocumentApplier` při řádkovém
   `canCreate` potřebuje vytvořit `economy_items` záznam. Dnes (před Fází 1)
   to dělá DocumentApplier inline; Fáze 1 nesahá do této code path — Item
   apply pipeline je samostatná, doc flow zůstává beze změny (viz §15).
5. **Import / export mezi Shipard datasety** — vyexportování položky z
   jednoho DS a naimportování do druhého (Fáze 2, mimo scope).
6. **Manuální zadání přes API** — externí systém pošle Item canonical
   místo mapování na interní strukturu.

### Vztah k `shpd.docs.document.v1` (řádek faktury → Item)

Řádek dokladu je *odkaz* na Item plus snapshot cen — ne projekce Item
v plném rozsahu jako Party vs Person. Z toho plynou rozdíly:

- **Žádné sdílené sub-schéma s dokladem.** Doklad neobsahuje `kind`
  / `supplierCodes` ani jiná Item-specifická pole. Item canonical je
  samostatný formát s vlastní hierarchií.
- **`PartyResolver`** se přepoužívá pro lookup dodavatele v
  `supplierCodes[].supplier` — stejný resolver, jiný kontext (Items
  ho volá s `personType = company` filtrem).
- **`ItemResolver`** (existující, `modules/core/exchange/src/Resolve/`)
  je sdílen mezi doc flow a Item apply pipeline. Doc flow ho volá
  per řádek s `(itemFragment, ?supplierPersonId)`; Item apply ho
  volá s `supplierPersonId = null` (per-partner lookup řeší
  samostatně, viz §7.1).
- **Vytvoření Person z Party** (když supplier není v DB) deleguje
  ItemApplier na `PersonApplier::apply` přes statický helper
  `PartyToPersonCanonical` (Party fragment → Person canonical).
  DocumentApplier tento helper **nepoužívá** — má vlastní zkrácenou
  cestu (`PartyResolver::buildPersonCreatePayload()` → `personsGateway`
  přímý insert, mimo PersonApplier pipeline). Phase 1 ty dva flow
  nesjednocuje (viz §15).

## 2. Pojmosloví (rozšíření)

| Termín | Význam |
|--------|--------|
| **Header** | Hlavičková data položky — vše krom sub-kolekce `supplierCodes`. |
| **Kind** | Druh položky (`economy_items_kinds`) — uživatelský číselník nad systémovým `item_type` enumem. Kanonický payload nese hinty (`code` / `name` / `itemType`), KindResolver mapuje na `economy_items_kinds.id`. |
| **Item type** | Systémový enum `0 = Služba`, `1 = Zásoba`, `2 = Účetní`, `3 = Ostatní` (`economy.items.itemTypes`). Denormalizovaný z `kind.item_type` přes `ItemDocument::beforeSave`. Klient ho v canonical **nepředává** — leží implicitně v `kind`. |
| **Supplier code mapping** | Záznam v `economy_items_supplier_codes` — trojice `(person, item, supplier_code)`. Per-partner alias dodavatelského kódu, který next-time ItemResolver použije v doc flow. |
| **Match-key** | Sada polí, podle kterých applier páruje záznam payloadu s existujícím DB záznamem. Per typ (header vs. supplierCode). |

Zbytek pojmosloví (Canonical, Schema, Resolve, Apply, Lineage) viz
[exchange-format.md sekce 2](exchange-format.md#2-pojmosloví).

## 3. Specifikace `shpd.items.item.v1`

```jsonc
{
  // ── Format meta ──────────────────────────────────────────────────────────
  "format":        "shpd.items.item",
  "formatVersion": "1.0",

  // ── Source (audit / lineage) ─────────────────────────────────────────────
  "source": {
    "kind":        "import.oldShipard",   // viz cfgItem economy.items.sourceKinds
    "fetchedAt":   "2026-05-27T10:00:00Z",
    "registryRef": "12345",               // ndx ve starém Shipardu / vendor ID
    "raw":         { /* opaque */ }       // optional source-specific payload
  },

  // ── Identifikace ─────────────────────────────────────────────────────────
  "code":        "K-001",                 // economy_items.code (unique v DS)
                                          //   null / vynecháno → applier nechá
                                          //   ItemDocument vygenerovat hex
                                          //   vyplněn → applier zachová;
                                          //   kolize s jiným itemem = error
  "name":        "Konzultace IT",         // required, max 200 znaků
  "description": "Hodinová sazba senior konzultanta",
  "sku":         null,                    // optional, varchar(50)
  "ean":         null,                    // optional, varchar(20)

  // ── Klasifikace ─────────────────────────────────────────────────────────
  // Druh — applier mapuje přes KindResolver na economy_items_kinds.id (viz §5).
  // Alespoň jeden ze tří hintů (code / name / itemType) musí být vyplněný.
  "kind": {
    "code":     "service",                // match na economy_items_kinds.system_code
    "name":     "Konzultace IT",          // match na economy_items_kinds.name
    "itemType": 0                         // 0=Služba | 1=Zásoba | 2=Účetní | 3=Ostatní
                                          //   slouží i jako fallback (viz §5)
                                          //   a jako item_type pro canCreate nový kind
  },

  // ── Detaily ─────────────────────────────────────────────────────────────
  "validFrom": null,                      // date | null
  "validTo":   null,                      // date | null

  // ── Cena ────────────────────────────────────────────────────────────────
  "salesPriceNoVat": 1000.00,             // number >= 0 nebo null (necenený)
  "unit":            "h",                 // ISO code, lokální zkratka nebo název;
                                          //   UnitResolver mapuje na core_units.id

  // ── Per-partner dodavatelské kódy ───────────────────────────────────────
  "supplierCodes": [ { /* viz §6 */ } ],

  // ── Stav ────────────────────────────────────────────────────────────────
  "status": {
    "isClosed": false,
    "docState": 10                        // 10 (Koncept) | 40 (V pořádku) |
                                          //   70 (Archív) | 80 (V opravě) |
                                          //   90 (Smazáno)
  },

  // ── Apply options ───────────────────────────────────────────────────────
  // Volitelný blok — řídí chování apply pipeline.
  // Když chybí, applier použije default `mergeStrategy: mergeAdd`,
  // `targetDocState: 10`.
  "applyOptions": {
    "mergeStrategy":  "mergeAdd",         // createOnly | updateHeader |
                                          //   mergeAdd (default) | fullSync
    "targetDocState": 40,                 // 10 | 40
    "rejectOnIssues": ["error"]           // ["error"] | ["error","warning"] | []
  },

  // ── Resolve state (populated by /preview, used by /apply) ───────────────
  "_resolve": { /* viz §8 */ }
}
```

### `code` — kdy generovat, kdy respektovat

`code` je business unique klíč v DS (`economy_items.unq_code`). ItemDocument
v `beforeSave` auto-generuje 6-znakový hex, pokud chybí.

| Vstup `code` | Chování |
|---|---|
| `null` nebo vynecháno | Applier **dropne klíč z payloadu** a nechá `ItemDocument::beforeSave` vygenerovat. Header resolve běží přes `name` (LIKE fuzzy) — ItemResolver fallback. |
| Vyplněn, item neexistuje | Resolver matchne na ostatních klíčích nebo `notFound` / `canCreate`. Applier zachová `code` při insertu. |
| Vyplněn, item existuje | Resolver matchne `(matchedBy: "ourCode")`, header `matched`. |
| Vyplněn, **existuje jiný item se stejným kódem** | Detekováno explicit lookupem v `ItemFlowResolver` před save fází. `code_conflict` issue + reject `409 code_conflict`. |

Důvod explicit lookupu: `ItemDocument::validate` má vlastní uniqueness check
a vrátil by `422 validation_failed: duplicate`. Pro apply flow je structured
error `409 code_conflict` užitečnější — klient ví, že jde o business-key
kolizi, ne o obecnou validační chybu.

### `name` — required

`name` musí být vyplněný v každém Item canonical (i pro draft `targetDocState: 10`).
`economy_items.name` je NOT NULL bez defaultu. Schema reject + PHP validátor
oboje vrací `error severity` issue.

### `sku` / `ean` — secondary keys

V DB mají index, ItemResolver je zkouší jako fallback klíče (po `ourCode`
a per-partner `supplierCode`). Pro Item apply flow (per-partner lookup
neaktivní) zůstává pořadí `ourCode → ean → sku → name`.

### Nemodelovaná pole

`item_type` v DB se denormalizuje z `economy_items_kinds.item_type` přes
`ItemDocument::beforeSave`. V canonical se **nepředává** — leží implicitně
v `kind.itemType` (hint pro KindResolver / pro canCreate nového kindu).
Pokud klient v payloadu pošle top-level `itemType`, schema ho odmítne
(`additionalProperties: false`).

## 4. Životní cyklus

```
Vstup (starý Shipard, CSV vendor katalog, ruční zadání, AI extrakce, …)
  │
  ▼
[Adaptér / parser]                  ← per-zdroj překlad na canonical
  │
  ▼
Canonical JSON  ──────►  /validate     → vrátí jen issues (no DB writes)
  │                       /preview     → vrátí canonical + _resolve (no DB writes)
  │                       /apply       → resolve → save → vrátí enriched canonical
  ▼
DB záznam v economy_items + economy_items_supplier_codes
   (+ aktualizace stávajícího záznamu při mergeStrategy != createOnly)
   (+ side-create v economy_items_kinds, pokud kind canCreate + userAction=create)
```

`validate` a `preview` jsou idempotentní a bez vedlejších efektů. `apply`
podle `mergeStrategy` vytvoří, aktualizuje header a může přidat supplier
code mapování.

## 4a. Stavy dokumentů — hlavička vs. sub-tabulka

`economy_items` má standardní `docState` aparát (`core.system.docStatesArchive`),
přes Fázi 1 přibyde lineage skupina sloupců.

`economy_items_supplier_codes` **nemá `docState` ani `valid_to`** — sub-tabulka
nese pouze trojici `(person, item, supplier_code)` + `supplier_name` (audit)
+ `created`. Vyplývají z toho dvě praktická omezení:

- **`fullSync` u supplierCodes NEMÁ closing semantiku** (viz §9). Existující
  mapování nepřítomná v payloadu zůstávají nedotčená.
- **Mazání mapping** je mimo scope apply pipeline. Uživatel může (v Phase 3
  UI) ručně smazat řádek `economy_items_supplier_codes`; applier se mazání
  nedotýká.

### Důsledky pro applier

- **Resolvery na `economy_items`** filtrují `docState IN (10, 40, 80)`
  (aktivní stavy — Koncept, V pořádku, V opravě). Záznamy v Archívu (`70`)
  a Smazané (`90`) se nepáří.
- **Resolver na `economy_items_kinds`** filtruje stejně — KindResolver
  vidí jen aktivní kindy.
- **Nový item vytvořený applierem** dostane `docState` podle
  `applyOptions.targetDocState` (default `10` = Koncept). Pro `40` = V pořádku
  proběhne state transition přes `ItemDocument::processStateTransition`,
  pokud chybí povinná pole, apply selže.

---

## 5. Kind sub-object + KindResolver

```jsonc
"kind": {
  "code":     "service",       // optional: match na economy_items_kinds.system_code
  "name":     "Konzultace IT", // optional: match na economy_items_kinds.name
  "itemType": 0                // 0=Služba | 1=Zásoba | 2=Účetní | 3=Ostatní
                               //   slouží jako fallback + jako item_type
                               //   pro canCreate nového kindu
}
```

Validace: alespoň jeden ze tří klíčů musí být vyplněn (PHP `ItemValidator`
vrací `error` issue `kind_required`). Schema povoluje všechny tři optional.

### 5.1 KindResolver — pořadí strategií

Všechny SQL probes filtrují `docState IN (10, 40, 80)` (aktivní stavy).
První hit vyhrává.

1. **`kind.code` exact match** v `economy_items_kinds.system_code` (unique).
   → `matched`, `matchedBy: "system_code"`.
2. **`kind.name` exact match** v `economy_items_kinds.name`.
   - 1 match → `matched`, `matchedBy: "name"`.
   - Víc match → `ambiguous` se seznamem kandidátů (klient vyřeší `userAction: "useExisting:<id>"`).
3. **`kind.name` vyplněný, žádný match** → `canCreate` s payloadem pro
   `economy_items_kinds` insert. Nový kind dostane `name` z payloadu
   a `item_type` z `kind.itemType ?? 3` (Ostatní jako safe default).
   **canCreate vyhraje před itemTypeFallback** — explicit `kind.name` je
   silnější signál než systémový pre-seeded druh. Klient pojmenoval
   konkrétní druh; raději ho vytvoříme, než tiše vrátíme generic
   fallback.
4. **Fallback per `kind.itemType`** — pouze pokud `kind.name` chybí.
   Applier vyhledá systémový druh seedovaný `ItemKindsProvisioner` podle
   mapování:

   | `itemType` | `system_code` |
   |---|---|
   | `0` | `service` |
   | `1` | `stock` |
   | `2` | `accounting` |
   | `3` | `other` |

   → `matched`, `matchedBy: "itemTypeFallback"`. Emit warning
   `kind_inferred_from_itemType` do `_resolve.issues` (viz §5.3).
5. **Žádný hint** — `notFound`, applier reject s `kind_unresolved`
   (`422 validation_failed`).

> **Drift hlídání systémových kindů.** Fallback tabulka je PHP konstanta
> v `KindResolver` (`FALLBACK_KIND_BY_ITEM_TYPE`), ne lookup do cfgItem.
> Pokud `ItemKindsProvisioner` v budoucnu změní `system_code` naming,
> KindResolver pojede stejnou tabulku — žádný drift, applier explode.

### 5.2 Side-create kind v apply pipeline

Pokud `_resolve.kind.status == canCreate` a klient v `_resolve.kind.userAction`
nastaví `"create"`:

- ItemApplier vytvoří nový `economy_items_kinds` row přes `ItemKindDocument::saveDocument`
  s `name`, `item_type` (`kind.itemType ?? 3`), `docState = 40`,
  `docStateMain = 2`. Žádný `system_code` (NULL — systémové druhy jsou
  vyhrazeny pro Provisioner).
- Resulting `kindId` se propíše do header insert/update.

Pokud `userAction` zůstane `null`, apply selže s `422 validation_failed`
issue `kind_unresolved`.

Side-create kind probíhá v rámci stejné transakce jako save itemu (viz §10).

### 5.3 Warning `kind_inferred_from_itemType`

Když KindResolver matchne přes itemType fallback, applier emit warning
issue do `_resolve.issues`:

```jsonc
{
  "severity": "warning",
  "path":     "kind",
  "code":     "kind_inferred_from_itemType",
  "message":  "Druh nedohledán podle code/name; použit systémový druh 'service' (itemType=0)."
}
```

Důvod: importer ze starého Shipardu typicky předává jen `kind.itemType`,
bez `code` / `name`. Warning umožní uživateli identifikovat položky,
které mají chudé kind hinty — typicky stojí za to po importu projít
a přiřadit přesnější druh ručně.

`applyOptions.rejectOnIssues: ["error", "warning"]` umožní importeru
striktně odmítnout payloady s tímto warning, pokud chce vynutit precision.

## 6. SupplierCode sub-object + SupplierCodesResolver

```jsonc
"supplierCodes": [
  {
    "supplier": {                       // inline Party (analogie doc spec)
      "name":      "Acme s.r.o.",
      "country":   "cz",
      "companyId": "12345678",
      "taxId":     "CZ12345678",
      "vatId":     "CZ12345678"
    },
    "supplierCode": "KONZ-001",         // required, dodavatelský kód, varchar(50)
    "supplierName": "Konzultace IT"     // optional, audit-only label
                                        //   (název položky v dokladu dodavatele)
  }
]
```

Inline Party fragment je shodný s `Party` v dokladovém formátu — `name`,
`country`, `companyId`, `taxId`, `vatId`, `govEBoxId`. PartyResolver
mapuje na `base_persons_persons.id` s `personType = Company` filtrem.

### 6.1 SupplierCodesResolver — match-key

Match-key na `economy_items_supplier_codes`:

1. `(person, supplier_code)` — unique index `unq_person_supplier_code`.
   Resolver lookuje resolved `supplier.personId` + `supplierCode`.
2. Match → `matched` s `mappingId`; missing → `canCreate`.

Tabulka nemá `docState`, takže žádný state filter (každý záznam je
considered live).

### 6.2 Phase 1 omezení — supplier canCreate = SKIP

Když `supplier.status == canCreate` (partner v DB neexistuje):

- **Default chování** (`_resolve.supplierCodes[i].supplier.userAction = null`)
  — sub-záznam **SKIP**. Item se uloží bez tohoto mappingu, applier emit
  warning issue `supplier_unknown`:

  ```jsonc
  {
    "severity": "warning",
    "path":     "supplierCodes[1]",
    "code":     "supplier_unknown",
    "message":  "Dodavatel 'Beta s.r.o.' (CZ:87654321) nebyl nalezen. Sub-záznam přeskočen."
  }
  ```

- **Explicit autocreate** (`userAction = "create"`) — ItemApplier deleguje
  na `PersonApplier::apply` s payloadem postaveným ze Party fragmentu
  (`PartyToPersonCanonical` helper, sdílený s `DocumentApplier`). Vytvořený
  partner dostane standardní `PersonDocument` validaci. Pak applier
  INSERT IGNORE na `economy_items_supplier_codes`.

Důvod default SKIP: importer ze starého Shipardu nejdřív importuje persons,
pak items — supplier osoby budou v DB. Implicitní side-create partnerů
z items flow by maskoval chybu v pořadí importu a vytvářel duplicity
s nedostatečnou validací.

### 6.3 SupplierCodesResolver — vstup `itemId`

```php
public function resolve(array $supplierCodes, ?int $itemId): array;
```

Pro **create flow** (header `canCreate`) je `itemId = null` — resolver
nemůže lookup `(person, supplier_code)` na `economy_items_supplier_codes`
filtrovat per item (item ještě nemá ID). Vrací per index:

- `supplier.status` (z PartyResolver)
- `status = "canCreate"` automaticky (mapping ještě nemůže existovat)

Po insertu hlavičky applier propíše `itemId` zpět a INSERT IGNORE
provede pro každý `supplier.status == matched` element.

Pro **update flow** (header `matched`, `itemId` známo) resolver lookup
mapování per element a vrací `status: "matched"` (mapping existuje, leave
alone) nebo `status: "canCreate"` (insert nový).

### 6.4 SupplierCodes — žádné closing

Tabulka nemá `valid_to` ani `docState`, mapping je per-partner. `fullSync`
**nezavírá** existující mapování nepřítomná v payloadu. Důvody:

- Mapping je per-partner audit — kdyby supplier A přestal položku dodávat,
  neznamená to, že mapping ze syncu jiného zdroje (např. starého Shipardu)
  je špatný.
- Žádný state column = žádná možnost rozlišit "platilo, už neplatí"
  vs. "neměl být nikdy" sémanticky.
- Closing přes mazání by ztratil per-partner historii.

Pokud uživatel chce explicitně mapping odstranit, dělá to ručně v UI
(Phase 3 feature, mimo scope Fáze 1).

## 7. Resolve — ItemFlowResolver orchestrace

`ItemFlowResolver` orchestruje hlavičku + kind + unit + supplierCodes.
Vstup: kompletní Item canonical. Výstup: `ItemResolveResult` (nested
`_resolve` struktura).

### 7.1 Header resolve

Header lookup deleguje na existující `ItemResolver` (sdílený s doc flow).
Volání:

```php
$itemResolver->resolve($itemPayload, supplierPersonId: null);
```

`supplierPersonId = null` znamená přeskočit strategii 2 (per-partner
supplierCode lookup). Důvod: Item apply flow má `supplierCodes[]` *pole*,
ne jednoho dodavatele jako doc flow. Per-partner lookup se neaplikuje
na header — kdyby payload měl `supplierCodes` s vícero partnery, který
by se použil? Místo arbitrární volby Item apply spoléhá na ostatních
strategiích.

Pořadí strategií pro Item apply (z 6 v ItemResolveru):

1. `ourCode` exact match v `economy_items.code` → `matched`, `matchedBy: "ourCode"`.
2. ~~`(supplierPersonId, supplierCode)` per-partner~~ — **přeskočeno** (null).
3. `ean` exact match → `matched`, `matchedBy: "ean"`.
4. `sku` exact match → `matched`, `matchedBy: "sku"`.
5. `name` LIKE → `matched` (1 hit) / `ambiguous` (n hit).
6. Žádný match → `canCreate`.

### 7.2 Kind resolve

`KindResolver::resolve(kind)` per §5. Vrací `ResolveResult` s
`matchedId = economy_items_kinds.id` nebo `canCreate` s payloadem
pro nový kind. ItemFlowResolver emit `kind_inferred_from_itemType` warning,
pokud `matchedBy: "itemTypeFallback"`.

### 7.3 Unit resolve

`UnitResolver::resolve(unit)` per existující implementace. Vrací
`matched` s `core_units.id` nebo `notFound`. Pořadí lookupů: alias mapa
(včet. českých tvarů „kus/kusy/kusů“) → `system_code` → `shortcut`
(case-insensitive) → `name` (case-insensitive); vstup se normalizuje
(lowercase, trim, odstranění koncových teček, např. „ks.“).

Pokud `notFound`, ItemApplier emit warning issue `unit_unknown` a použije
fallback `system_code: pcs`. Item se uloží, jen s flagem v issues.

### 7.4 SupplierCodes resolve

`SupplierCodesResolver::resolve(supplierCodes, itemId)` per §6. Pro
create flow je `itemId = null`, pro update flow je z header `matchedId`.

### 7.5 Code conflict probe

Po header resolve provede ItemFlowResolver dodatečný lookup:

```sql
SELECT id FROM economy_items
WHERE code = :code AND id <> :matchedId
  AND docState IN (10, 40, 80)
LIMIT 1
```

(Pro `header.status = canCreate` se `id <> :matchedId` přeskočí —
`matchedId` neexistuje, lookup zkontroluje jen existenci jiného itemu
s tím samým `code`.)

Pokud match a `header.status` je `matched` ale na jiný `itemId` než ten,
co má `code` v DB → kolize business klíčů. Vyhodit `code_conflict`
error issue:

```jsonc
{
  "severity": "error",
  "path":     "code",
  "code":     "code_conflict",
  "message":  "Kód 'K-001' je již použit u jiné položky (id=42). Sjednoťte business klíče."
}
```

ItemApplier mapuje na `409 code_conflict` (viz §11).

### 7.6 ResolveResult + statusy

Sdílíme `ResolveResult` / `ResolveStatus` z `core.exchange`. Pro Item flow
v praxi tyto statusy:

| Status | Význam |
|--------|--------|
| `matched` | Jednoznačně napárováno, vrací `matchedId` a `matchedBy`. |
| `ambiguous` | Víc kandidátů (header podle `name`, kind podle `name`), vrací `candidates`. |
| `canCreate` | Žádný match, applier vytvoří záznam (per `mergeStrategy` / `userAction`). |
| `notFound` | Bez kandidátů a bez `canCreate` payloadu (chybí povinný hint pro create). |

## 8. `_resolve` state

```jsonc
{
  "summary": {
    "status":            "needsAttention", // ok | needsAttention | hasErrors | applied
    "headerStatus":      "matched",        // matched | canCreate | ambiguous
    "kindStatus":        "matched",        // matched | canCreate | ambiguous | notFound
    "unitStatus":        "matched",        // matched | notFound
    "supplierCodeCount": {
      "matched":   1,
      "canCreate": 1,
      "skipped":   0
    }
  },

  "header": {
    "status":     "matched",               // matched | ambiguous | canCreate
    "itemId":     42,
    "matchedBy":  "ourCode",
    "candidates": [],                      // jen pro ambiguous
    "userAction": null                     // null | "useExisting:<id>" | "create"
  },

  "kind": {
    "status":     "matched",
    "kindId":     5,
    "matchedBy":  "system_code",           // system_code | name | itemTypeFallback
    "userAction": null                     // pro canCreate: null | "create"
  },

  "unit": {
    "status":     "matched",
    "unitId":     3,
    "matchedBy":  "alias"                  // alias | systemCode | shortcut
  },

  "supplierCodes": [
    {
      "index":  0,
      "supplier": {
        "status":   "matched",
        "personId": 100,
        "matchedBy": "companyId"
      },
      "status":     "matched",             // mapping existuje v DB
      "mappingId":  200
    },
    {
      "index": 1,
      "supplier": {
        "status": "canCreate"
      },
      "status":     "skipped",             // supplier canCreate + userAction=null
      "userAction": null,
      "issue":      "supplier_unknown"
    }
  ],

  "issues": [
    {
      "severity": "warning",
      "path":     "kind",
      "code":     "kind_inferred_from_itemType",
      "message":  "Druh nedohledán podle code/name; použit systémový druh 'service' (itemType=0)."
    },
    {
      "severity": "warning",
      "path":     "supplierCodes[1]",
      "code":     "supplier_unknown",
      "message":  "Dodavatel 'Beta s.r.o.' (CZ:87654321) nebyl nalezen. Sub-záznam přeskočen."
    }
  ]
}
```

### `userAction` slovník

| Hodnota | Význam | Kde platí |
|---------|--------|----------|
| `null` | Default — applier postupuje podle `mergeStrategy`. | Všude. |
| `"useExisting:<id>"` | Použít konkrétního kandidáta z `candidates`. | Ambiguous (header, kind). |
| `"create"` | Vytvořit nový záznam i když existuje match / vyřešit canCreate. | Header, kind, supplier (v supplierCodes[]). |
| `"skip"` | Přeskočit sub-záznam. | supplierCodes[] (pro header neplatí). |

### Issue codes — Items Phase 1

| `code` | Severity | Význam |
|---|---|---|
| `kind_required` | error | Žádný ze tří hintů (`code`/`name`/`itemType`) v `kind` není vyplněn. |
| `kind_unresolved` | error | KindResolver vrátil canCreate a `userAction` zůstal `null`. |
| `kind_inferred_from_itemType` | warning | KindResolver matchne přes itemType fallback (chudé hinty). |
| `kind_ambiguous` | error | KindResolver vrátil ambiguous a `userAction` zůstal `null`. |
| `unit_unknown` | warning | UnitResolver nematchne, applier použije default `pcs`. |
| `code_conflict` | error | Vyplněný `code` v payloadu kolikuje s jiným itemem. |
| `supplier_unknown` | warning | Supplier v supplierCodes[] canCreate + `userAction = null`, sub-záznam SKIP. |
| `header_ambiguous` | error | ItemResolver `name` LIKE vrátí víc kandidátů + `userAction = null`. |

## 9. Merge strategie

Klíčové rozhodnutí — co se stane s **existující položkou**, na kterou
payload matchnul? Strategie je řízena polem `applyOptions.mergeStrategy`.

| Strategie | Hlavička | SupplierCodes |
|---|---|---|
| `createOnly` | Reject pokud existuje (`409 item_exists`) | — (header nezapsán) |
| `updateHeader` | Přepsat hlavičku | Beze změny |
| `mergeAdd` *(default)* | Aktualizovat jen prázdná pole v DB | Matched → nechat; missing v DB → přidat (INSERT IGNORE); existující nepřítomné v payloadu → nechat |
| `fullSync` | Přepsat hlavičku celou | Matched → nechat (INSERT IGNORE pro nové); existující nepřítomné v payloadu → **nechat** (žádný closing — viz §6.4) |

### `mergeAdd` fill rules pro hlavičku

Pole se aktualizuje právě tehdy, když v DB je `NULL` / prázdné a payload
ho má vyplněné:

- `description`, `sku`, `ean`, `salesPriceNoVat`, `validFrom`, `validTo`
  — fill if empty.
- `name`, `code` — **netýká se** (`name` je NOT NULL; `code` je
  business unique klíč, nepřepisuje se ani prázdné → vyplněné).
- `item_kind`, `unit` — fill if empty.

### `fullSync` přepsání

Všechna pole z payloadu se zapíšou do DB (kromě business klíčů `code`,
`id`). `item_type` se přepočte z `item_kind` přes `ItemDocument::beforeSave`.

### `fullSync` a uzavírání záznamů

**Hlavička:** `fullSync` přepíše pole, ale `is_closed`/`status` se v rámci
sync **nemění** automaticky — uzavření položky zůstává explicitním
uživatelským rozhodnutím.

**SupplierCodes:** žádný closing (viz §6.4). Tabulka nemá `valid_to` ani
`docState`, mapping je per-partner audit. Vyhodit ho při sync z jednoho
zdroje by rozbilo per-partner historii.

## 10. Apply pipeline

```
POST /api/v1/_exchange/items/item/apply
  │
  ├─ 1. Schema validation (statická struktura)
  │
  ├─ 2. ItemValidator (dynamická validace)
  │      - name required (error)
  │      - unit required (error)
  │      - salesPriceNoVat >= 0 (error pokud záporné)
  │      - kind alespoň jeden hint (error kind_required)
  │      - code pattern (max 25 znaků, žádné whitespace)
  │
  ├─ 3. ItemFlowResolver (znovu i když /preview proběhl — idempotentní)
  │      - ItemResolver: header
  │      - KindResolver: kind
  │      - UnitResolver: unit
  │      - SupplierCodesResolver per supplierCodes[]
  │      - Code conflict probe (viz §7.5)
  │
  ├─ 4. Reconcile s klientským _resolve
  │      - validate userAction (header, kind, supplierCodes[].supplier)
  │      - sestaví execution plan
  │
  ├─ 5. Validation gate
  │      - severity=error v _resolve.issues → 422 validation_failed
  │      - createOnly + matched header → 409 item_exists
  │      - code_conflict issue → 409 code_conflict
  │      - applyOptions.rejectOnIssues respektován
  │
  ├─ 6. BEGIN TRANSACTION (TransactionlessTableGateway)
  │
  ├─ 7. Side-create kind (pokud _resolve.kind.status == canCreate
  │      a userAction == "create")
  │      - ItemKindDocument::saveDocument([
  │          name => kind.name,
  │          item_type => kind.itemType ?? 3,
  │          docState => 40, docStateMain => 2,
  │        ])
  │      → propíše kindId zpět do _resolve.kind.kindId
  │
  ├─ 8. Header upsert
  │      - create: ItemDocument::saveDocument($payload)
  │           - code: pokud vyplněn v payloadu → zachovat; jinak drop key,
  │             beforeSave vygeneruje hex
  │           - item_kind: z _resolve.kind.kindId
  │           - unit: z _resolve.unit.unitId (nebo default pcs pokud notFound)
  │           - item_type: NEPŘEDÁVÁ se; beforeSave denormalizuje z item_kind
  │           - docState: applyOptions.targetDocState ?? 10
  │      - update (updateHeader / mergeAdd / fullSync):
  │           per merge strategy fill rules (viz §9)
  │
  ├─ 9. SupplierCodes per item
  │      pro každý element v supplierCodes[]:
  │      - supplier.status == matched + sub.status == matched:
  │           → leave alone
  │      - supplier.status == matched + sub.status == canCreate:
  │           → INSERT IGNORE economy_items_supplier_codes
  │             (person, item, supplier_code, supplier_name, created=NOW())
  │      - supplier.status == canCreate + userAction == null:
  │           → SKIP, status="skipped", issue="supplier_unknown"
  │      - supplier.status == canCreate + userAction == "create":
  │           → PartyToPersonCanonical($supplier)
  │           → PersonApplier::apply($personCanonical, mergeStrategy=createOnly)
  │           → INSERT IGNORE mapping s novým personId
  │
  ├─ 10. Lineage update
  │      - economy_items.source_kind        = source.kind
  │      - economy_items.source_ref         = source.registryRef
  │      - economy_items.source_imported_at = now()
  │
  ├─ 11. COMMIT
  │
  └─ 12. Vrátí enriched canonical
        - _resolve.summary.status = "applied"
        - header.status = "matched" (s itemId)
        - kind.status = "matched" (s kindId)
        - supplierCodes mají vyplněné mappingId / status
        - savedItemId v top-level
```

### Apply a doc state

`applyOptions.targetDocState`:

- `10` (default) — záznam zůstává v Konceptu (i u updatu — `docState`
  se nemění).
- `40` — applier provede state transition Koncept → V pořádku
  (`ItemDocument::processStateTransition`). Pokud chybí povinná pole
  podle validace, apply selže.

Pro Fázi 1 je `name` required vždy (i pro draft `targetDocState: 10`),
protože `economy_items.name` je v DB NOT NULL.

## 11. REST API

Všechny endpointy pod `/api/v1/_exchange/items/item/`. Stejný error shape
i HTTP codes jako document a person endpointy.

### POST `/validate`

Statická + dynamická validace bez resolve a bez DB writes.

### POST `/preview`

Validate + plný resolve (header + kind + unit + supplierCodes + code
conflict probe). Bez DB writes.

### POST `/apply`

Validate + resolve + reconcile + uložit (transakční).

### Error shape

```jsonc
{
  "error": {
    "code":    "code_conflict",
    "message": "Kód 'K-001' je již použit u jiné položky (id=42).",
    "details": {
      "canonical": { /* enriched payload s _resolve */ }
    }
  }
}
```

### Error codes — Items Phase 1

| HTTP | `error.code` | Význam |
|---|---|---|
| `400` | `INVALID_JSON` | Payload není validní JSON. |
| `400` | `SCHEMA_VIOLATION` | JSON Schema reject. |
| `422` | `validation_failed` | ItemValidator vrátil error issue (kind_required, kind_unresolved, kind_ambiguous, header_ambiguous, …). |
| `409` | `item_exists` | `mergeStrategy: createOnly` + header `matched`. |
| `409` | `code_conflict` | `code` v payloadu kolikuje s jiným itemem v DB. |
| `500` | `INTERNAL_ERROR` | `ItemApplier` nenavázán (deployment bez economy modulu). |

## 12. Lineage

Nové sloupce v `economy_items` (column group `lineage`):

| Sloupec | Typ | Význam |
|---------|-----|--------|
| `source_kind` | `varchar(40) nullable` | Klíč z cfgItem `economy.items.sourceKinds` (`import.oldShipard`, `import.csv`, `import.supplierCatalog`, `manual`, `aiExtraction`). |
| `source_ref` | `varchar(60) nullable` | Identifikátor v daném zdroji. Pro `import.oldShipard` ndx ve starém Shipardu; pro `import.csv` např. řádkové číslo + jméno souboru. |
| `source_imported_at` | `datetime nullable` | Čas posledního importu/sync. |

Reverse lookup z položky → původ. Manuálně pořízené položky mají
`source_kind = NULL` (nebo `'manual'` podle preference; default `NULL`).

### cfgItem `economy.items.sourceKinds`

```jsonc
{
  "manual":                  { "name:cs": "Ruční zadání",          "name:en": "Manual entry" },
  "aiExtraction":            { "name:cs": "Z AI extrakce",         "name:en": "From AI extraction" },
  "import.oldShipard":       { "name:cs": "Import ze starého Shipardu", "name:en": "Import from legacy Shipard" },
  "import.csv":              { "name:cs": "CSV import",            "name:en": "CSV import" },
  "import.supplierCatalog":  { "name:cs": "Katalog dodavatele",    "name:en": "Supplier catalog" }
}
```

### Sub-tabulka `economy_items_supplier_codes` — lineage NE

Fáze 1 nepřidává lineage do sub-tabulky. Supplier code se vytváří buď
z doc apply pipeline (známý lineage přes parent `docs_core_heads`) nebo
z item apply pipeline (lineage je na parent `economy_items`). Tracking
per-mapping není v Fázi 1 potřeba — kdyby se ukázalo, že troubleshooting
"kde se vzal tento mapping" je častý, přidá se lineage skupina + `source_kind`
/ `source_imported_at` později.

## 13. Verzování

Klíč `formatVersion` v top-level (`"1.0"`). Strategie shodná s
`shpd.docs.document.v1` (sekce 13 [exchange-format.md](exchange-format.md#13-verzování)):

- Drobná rozšíření (nová optional pole, nové enum value) — zachová major.
- Breaking changes — bump na novou major; per-verze applier.

## 14. Budoucí rozšíření (Fáze 2+)

- **`ItemExporter`** (Fáze 2) — DB row → canonical. Potřeba pro export
  mezi DS, registry sync s katalogy dodavatelů, periodickou synchronizaci
  s ERP.
- **Frontend UI** (Fáze 3) — modal náhled, popover pro `userAction`,
  analogie doc Fáze 3b. Přijde, jakmile bude reálný use case (drag-and-drop
  CSV import s preview).
- **Batch apply** (Fáze 4) — víc položek v jednom requestu. Pro CSV import
  od ERP dodavatele bude potřeba.
- **Exchange formát pro `economy_items_kinds`** (`shpd.items.kind.v1`) —
  pokud někdy bude potřeba sdílet katalogy druhů. V Phase 1 řešíme ad-hoc
  přes `canCreate` v rámci Item apply pipeline.
- **Diff API** — `/diff?itemId=X` proti canonical payloadu, vrátí seznam
  rozdílů bez apply. Slouží katalogovému sync UI.
- **Subscription model** pro periodický sync vendor katalogů — cron,
  který pro každý katalog s `source_kind = 'import.supplierCatalog'`
  zavolá adaptér a aplikuje `fullSync`.
- **Country / division filter** pro header `name` LIKE search — Phase 1
  fuzzy match po `full_name` bez restrikce; pokud budou false positives,
  přidat filter (analogicky persons §8.1 limit).
- **Lineage v `economy_items_supplier_codes`** — pokud troubleshooting
  "kde se vzal tento mapping" bude častý.

## 15. Vztah k doc flow — žádný refactor v Phase 1

Doc apply pipeline (`DocumentApplier`) volá `ItemResolver::resolve($itemFragment, $supplierPersonId)`
v rámci řádku faktury. Fáze 1 **nesahá do této code path**:

- `ItemResolver` zůstává beze změny (současný `buildCreatePayload` vrací
  payload bez `item_kind` / `unit` — DocumentApplier si tyto defaulty
  doplňuje sám: `item_kind` = systémový druh `service` přes `system_code`,
  `unit` = resolved `row.unit` nebo `pcs`).
- ItemApplier (Fáze 1) si default `item_kind` a `unit` doplní sám přes
  KindResolver / UnitResolver — žádná code sharing s doc flow ohledně
  defaultů.
- `PartyResolver` se sdílí beze změny (Items volá s `personType = company`
  filtrem, který už existuje).
- `PartyToPersonCanonical` helper (Fáze 1 nová abstrakce v `Common/`) —
  statický mapper Party fragment → Person canonical, používá ho **jen
  ItemApplier**. DocumentApplier má vlastní rychlejší cestu (`PartyResolver::buildPersonCreatePayload()` →
  `personsGateway` přímý insert, mimo PersonApplier pipeline). Sjednocení
  obou cest je mimo scope Phase 1 — refactor doc flow by byl velký,
  riskantní a netýká se Items pipeline. Případný unifikační refactor
  (jeden code path pro vytváření partnerů přes PersonApplier) zůstává
  jako follow-up Phase 2+.

## 16. Reference

- [exchange-format.md](exchange-format.md) — sourozenec spec pro doklady;
  obsahuje obecnou architekturu a pojmosloví.
- [exchange-format-persons.md](exchange-format-persons.md) — sourozenec
  spec pro osoby; vzor merge strategií a lineage.
- [modules/economy/items/](../modules/economy/items/) — modul Položky;
  tabulkové definice + `ItemDocument` + `ItemKindDocument` + `ItemKindsProvisioner`.
- [modules/core/exchange/](../modules/core/exchange/) — implementace
  výměnných formátů (sdílené resolvery, `ApplyResult`, `TransactionlessTableGateway`).
- [modules/base/persons/](../modules/base/persons/) — Person formát (cíl
  delegace pro autocreate supplier).
- [tasks/exchange-format-items-phase1.md](../tasks/exchange-format-items-phase1.md) —
  task spec, konkrétní pořadí implementace, otevřené body.
