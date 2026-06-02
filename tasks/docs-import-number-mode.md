# Task: Import-mód čísla dokladu + oprava validace bankovního spojení

## Kontext

Migrace ze starého Shipardu přivádí historické doklady přes exchange formát
(`POST /api/v1/_exchange/docs/document/apply`). Po migraci následuje **ostrý
provoz** — nové doklady musí navazovat na importovaná čísla. Dnešní chování
to neumí:

1. **Číslo dokladu se negeneruje při importu.** `DocumentApplier` mapuje
   `canonical.docNumber → partner_doc_number`; vlastní `doc_number` přiděluje
   `DocDocument::assignDocumentNumber` **jen při přechodu stavu 10→20**.
   - Doklad vložený rovnou na stav 20 má `oldState = newState = 20`, takže
     `assignDocumentNumber` se nespustí → `doc_number` zůstane prázdný →
     `afterPersist` zapíše placeholder `!{id}`.
   - Importér to dnes obchází post-apply PATChem (vloží koncept 10, povýší
     10→20, pak přepíše `doc_number`). Tím se ale **counter
     `docs_core_number_counters` posune podle pořadí importu**, ne podle
     původních čísel → první nová faktura po migraci naváže špatně (kolize
     nebo díra v řadě).

2. **Counter se po importu rozejde se skutečnými čísly.** I kdyby čísla
   seděla, counter `last_assigned` odpovídá počtu importovaných dokladů, ne
   nejvyššímu původnímu pořadí.

**Cíl:** zavést **import-mód**, ve kterém klient dodá hotové `doc_number`
+ `sequence_number` a `DocDocument`:
- nevolá `assignDocumentNumber` (nepřiděluje z counteru),
- nezapisuje placeholder,
- synchronizuje counter na nejvyšší použité pořadí (`GREATEST`).

Tím importované doklady (přijaté i vydané faktury) nesou **původní čísla** a
counter je nastaven tak, že **další nová faktura naváže správně**.

Součástí tasku je i drobná oprava nesouvisející s číslováním (bod B níže):
**validace bankovního spojení dodavatele u přijatých faktur** se má vázat na
způsob úhrady.

## Rozsah

Dvě nezávislé části, obě v novém Shipardu:

- **A) Import-mód čísla dokladu** — `shpd.docs.document.v1` schema +
  `DocumentApplier` + `DocDocument`.
- **B) Oprava validace bankovního spojení** — `ReceivedInvoiceDocument`.

Importér (starý Shipard, `DocsRunner`) se mění v samostatném tasku — tady jen
definujeme kontrakt (co import-mód očekává v payloadu).

**Mimo rozsah:**
- Středisko/sklad na dokladech (nový Shipard je nemá — known limitation).
- Parsování pořadového čísla z původního `docNumber` (řeší importér).
- Změny chování UI flow (ruční vystavování dokladů) — import-mód je aktivní
  jen když klient pošle `applyOptions.importNumber`.

## Část A — Import-mód čísla dokladu

### A.1 Schema `shpd.docs.document.v1`

Soubor: `modules/core/exchange/schemas/shpd.docs.document.v1.jsonc`.

Do `applyOptions` přidat volitelný objekt `importNumber`:

```jsonc
"applyOptions": {
    "type": "object",
    "properties": {
        // … stávající (targetDocState, autoCreateMode, …) …

        "importNumber": {
            "type": "object",
            "description": "Import mode: force the document's own number + sequence instead of generating from the series counter. Used by legacy migration. When present, DocDocument skips assignDocumentNumber and syncs the counter to GREATEST(last_assigned, sequenceNumber).",
            "properties": {
                "docNumber": {
                    "type": "string",
                    "minLength": 1,
                    "description": "The document's own number to store verbatim in doc_number."
                },
                "sequenceNumber": {
                    "type": "integer",
                    "minimum": 1,
                    "description": "Sequence-in-series, used for counter sync and variable_symbol default."
                }
            },
            "required": ["docNumber", "sequenceNumber"],
            "additionalProperties": false
        },

        "importOwnBankAccount": {
            "type": ["integer", "null"],
            "minimum": 1,
            "description": "Import mode: id of our own bank account (economy_codebooks_bank_accounts) to set on the document. For issued invoices confirmed at state 20+, bank_account is required and cannot be carried by the standard self-party flow; the importer resolves it and passes it here."
        }
    }
}
```

**Pozn.:** `importNumber` i `importOwnBankAccount` jsou čistě opt-in. Bez nich
se chování `apply` nemění (UI flow, AI extrakce, …).

### A.2 `DocumentApplier::transform()`

Soubor: `modules/core/exchange/src/Document/DocumentApplier.php`.

V `transform()` (kde se staví `$data`) přidat předání import-mód polí do
`$data` jako **virtuální pole** (s podtržítkem — `DocDocument::beforeSave` je
zkonzumuje a odstraní, viz A.3). Čti je z `applyOptions`:

```php
$importNumber = $canonical['applyOptions']['importNumber'] ?? null;
$importOwnBank = $canonical['applyOptions']['importOwnBankAccount'] ?? null;
```

Do pole `$data` (před `array_filter`) přidat:

```php
// Import mode: virtual field consumed by DocDocument::beforeSave.
// Must NOT reach SQL — beforeSave unsets it before insert.
'_importNumber' => is_array($importNumber) ? [
    'docNumber'      => (string) ($importNumber['docNumber'] ?? ''),
    'sequenceNumber' => (int) ($importNumber['sequenceNumber'] ?? 0),
] : null,

// Import mode: our own bank account (issued invoices need it at state 20+).
'bank_account' => $importOwnBank !== null ? (int) $importOwnBank : ($data['bank_account'] ?? null),
```

**Pozor na `array_filter`:** funkce na konci `transform()` odstraňuje
`null` hodnoty. `_importNumber = null` se tím odstraní (správně — bez import
módu pole neexistuje). Když je vyplněné, projde. Ověř, že `array_filter`
predikát nechá `_importNumber` projít, když není null (je to běžná hodnota,
projde standardně).

`bank_account` přes `array_filter`: když `importOwnBank` je null a v `$data`
už `bank_account` není, zůstane null → `array_filter` ho odstraní (OK,
sloupec zůstane prázdný / default).

### A.3 `DocDocument::beforeSave()` — konzumace import-mód pole

Soubor: `modules/docs/core/src/DocDocument.php`.

Na začátku `beforeSave()` vyzvedni a **odstraň** virtuální pole (jinak by
`TableGateway::insertRow` poslal `_importNumber` do SQL → chyba "unknown
column"; gateway sloupce nefiltruje):

```php
public function beforeSave(array &$data, ?array $originalData = null): void
{
    // Import mode marker — virtual field, must be consumed + removed before SQL.
    $importNumber = $data['_importNumber'] ?? null;
    unset($data['_importNumber']);

    $this->trackStateChange($data, $originalData);
    $this->denormalizeDocType($data);
    $this->applyDateDefaults($data);
    $this->applyHomeCurrency($data);
    $this->resolveAccountingPeriods($data);   // sets fiscal_year/month from accounting_date

    $vatMode = (int) ($data['vat_mode'] ?? 1);
    $rowsForCompute = $this->resolveRowsForCompute($data);
    foreach ($rowsForCompute as &$row) {
        $this->calculateRowPrice($row);
        $this->calculateRowVat($row, $vatMode);
    }
    unset($row);

    $recap = $this->buildVatRecapitulation($data, $rowsForCompute);
    $data['vatRecap'] = $recap;
    $this->sumTotals($data, $recap);
    $this->applyTotalRounding($data);
    $this->applyExchangeRate($data);

    // Number assignment: import mode forces the number; otherwise normal
    // state-transition assignment.
    if (is_array($importNumber)) {
        $this->applyImportNumber($data, $importNumber);
    } else {
        $this->processStateTransition($data, $originalData);
    }

    $this->maintainSnapshots($data, $originalData);
    $this->applyVariableSymbolDefault($data);
}
```

### A.4 `DocDocument::applyImportNumber()` — nová metoda

```php
/**
 * Import mode: store the document's own number + sequence verbatim and sync
 * the series counter to the highest used sequence. Replaces
 * assignDocumentNumber for migrated documents.
 *
 * Counter sync uses GREATEST so it is:
 *   - idempotent (re-importing the same doc never lowers the counter),
 *   - order-independent (importing 7, then 3, leaves counter at 7),
 *   - hole-tolerant (deleted source docs leave gaps; counter still ends at
 *     the true maximum so the next new doc continues correctly).
 *
 * @param array<string, mixed> $importNumber {docNumber: string, sequenceNumber: int}
 */
private function applyImportNumber(array &$data, array $importNumber): void
{
    $docNumber = (string) ($importNumber['docNumber'] ?? '');
    $sequence  = (int) ($importNumber['sequenceNumber'] ?? 0);

    if ($docNumber === '' || $sequence <= 0) {
        // Defensive: malformed import payload — fall back to normal assignment
        // rather than persisting an empty/placeholder number silently.
        $this->processStateTransition($data, null);
        return;
    }

    $data['doc_number']      = $docNumber;
    $data['sequence_number'] = $sequence;

    $seriesId = (int) ($data['number_series'] ?? 0);
    if ($seriesId === 0 || $this->db === null) {
        return;
    }

    // fiscal_year already resolved by resolveAccountingPeriods() above; the
    // counter is keyed per (number_series, fiscal_year) using NULL-safe match,
    // matching assignDocumentNumber's reset_scope = fiscal_year default.
    $fyId = $data['fiscal_year'] ?? null;

    $this->executeSql(
        'INSERT INTO [docs_core_number_counters]
            ([number_series], [fiscal_year], [last_assigned])
         VALUES (%i, %iN, %i)
         ON DUPLICATE KEY UPDATE [last_assigned] = GREATEST([last_assigned], %i)',
        $seriesId, $fyId, $sequence, $sequence,
    );
}
```

**Pozor — reset_scope:** `assignDocumentNumber` čte `reset_scope` z number
series (default `fiscal_year`); pro `reset_scope = 'never'` je counter keyed
s `fiscal_year = NULL`. `applyImportNumber` výše předpokládá `fiscal_year`
scope (běžný případ faktur). Pokud řada používá `reset_scope = 'never'`,
counter klíč musí mít `fiscal_year = NULL` — zrcadli logiku
`assignDocumentNumber`:

```php
$resetScope = $this->numberSeriesResetScope($seriesId);   // helper: SELECT reset_scope
$fyId = ($resetScope === 'fiscal_year') ? ($data['fiscal_year'] ?? null) : null;
```

Přidej drobný helper `numberSeriesResetScope(int): string` (SELECT
`reset_scope` z `docs_core_number_series`), nebo načti scope ze stejného
místa jako `assignDocumentNumber`. Cílem je, aby import-mód counter klíč byl
**identický** s tím, který později použije `assignDocumentNumber` pro nové
doklady — jinak by se counter sync minul účinkem.

### A.5 `afterPersist` — placeholder

`afterPersist` zapisuje placeholder `!{id}` jen když je `doc_number`
prázdný. V import módu `applyImportNumber` `doc_number` vyplnil → placeholder
se nepřidá. **Žádná změna není potřeba** — jen ověř, že to platí (doc_number
je v `$data` před insertem, takže po persistu je v DB neprázdný).

### A.6 `variable_symbol`

`applyVariableSymbolDefault` nastaví VS na `sequence_number`, pokud VS prázdný.
V import módu je `sequence_number` vyplněný → VS bude původní pořadí. To je
konzistentní s tím, jak to dělá normální flow. Importér ale posílá i původní
`payment.variableSymbol` (symbol1) — ten má přednost (default se aplikuje jen
když je VS prázdný). Žádná změna.

## Část B — Oprava validace bankovního spojení (přijaté faktury)

Soubor: `modules/docs/invoicesIn/src/ReceivedInvoiceDocument.php`.

Dnešní `validate()` vyžaduje `partner_bank` / `partner_bank_account` /
`partner_bank_iban` **bezpodmínečně** při stavu 20/40/80. To je nesprávné:
bankovní spojení dodavatele potřebujeme jen, když mu budeme platit
**převodem**. U hotovosti, karty, dobírky, zápočtu je spojení irelevantní.

Payment methods (`docs.core.paymentMethods`): `0` hotovost, `1` převodem,
`2` kartou, `3` dobírkou, `4` zápočtem.

**Oprava:** validaci podmínit `payment_method === 1`.

```php
public function validate(array &$data): ValidationResult
{
    $result = parent::validate($data);

    $newState = (int) ($data['docState'] ?? 10);
    $paymentMethod = (int) ($data['payment_method'] ?? 1);

    // Supplier bank info is only needed when we will pay by bank transfer.
    // Cash / card / cash-on-delivery / set-off don't need it.
    if (in_array($newState, [20, 40, 80], true) && $paymentMethod === 1) {
        $hasBank = !empty($data['partner_bank'])
            || !empty($data['partner_bank_account'])
            || !empty($data['partner_bank_iban']);
        if (!$hasBank) {
            $result->addError(
                'partner_bank',
                'Bankovní spojení dodavatele je povinné — vyberte jeho účet '
                . 'nebo vyplňte ručně číslo účtu / IBAN.',
                'partner_bank_required',
            );
        }
    }

    return $result;
}
```

**Dopad mimo import:** opravuje i běžný UI flow — přijatou fakturu placenou
hotově/kartou půjde potvrdit bez vyplnění účtu dodavatele. To je správné
chování.

## Hotovo když

1. **Schema** `shpd.docs.document.v1.jsonc` má `applyOptions.importNumber`
   (`docNumber` + `sequenceNumber`, oba required) a `applyOptions.importOwnBankAccount`.
2. **`DocumentApplier::transform()`** předává `_importNumber` (virtuální pole)
   a `bank_account` (z `importOwnBankAccount`) do `$data`.
3. **`DocDocument::beforeSave()`** vyzvedne a `unset` `_importNumber` před
   jakýmkoli zápisem; v import módu volá `applyImportNumber` místo
   `processStateTransition`.
4. **`applyImportNumber()`**:
   - zapíše `doc_number` + `sequence_number` z payloadu,
   - synchronizuje counter `INSERT … ON DUPLICATE KEY UPDATE last_assigned =
     GREATEST(last_assigned, sequence)`,
   - counter klíč `(number_series, fiscal_year)` je identický s tím, který
     používá `assignDocumentNumber` (respektuje `reset_scope`).
5. **Placeholder** `!{id}` se v import módu nezapíše (doc_number je vyplněný).
6. **Bez `importNumber`** se chování `apply` nezmění (UI / AI flow beze změny).
7. **`ReceivedInvoiceDocument::validate()`** vyžaduje bankovní spojení jen
   při `payment_method === 1`.
8. **Virtuální pole nikdy nedoteče do SQL** — ověřeno (insert přijaté i
   vydané faktury v import módu nehodí "unknown column `_importNumber`").

### Testy

PHPUnit (`modules/docs/...` test suite):

- **applyImportNumber zapíše číslo + sekvenci:** doklad s `importNumber:
  {docNumber: "2024-0042", sequenceNumber: 42}` na stav 20 → `doc_number =
  "2024-0042"`, `sequence_number = 42`, žádný placeholder.
- **counter sync GREATEST:** counter na 10; import doklad sequence 42 →
  counter 42. Import doklad sequence 7 → counter zůstane 42 (GREATEST).
  Re-import sequence 42 → counter zůstane 42 (idempotence).
- **navázání:** po importu (counter 42) normální nový doklad (10→20) →
  `assignDocumentNumber` přidělí sequence 43.
- **bez import módu beze změny:** doklad bez `importNumber` na 10→20 →
  `assignDocumentNumber` přidělí číslo z counteru (regrese).
- **bank validace (B):** přijatá faktura, `payment_method = 1`, stav 20, bez
  účtu → error `partner_bank_required`. Stejná s `payment_method = 0` (hotově)
  → projde bez chyby.
- **virtuální pole pryč:** mock/spy na insert payload — `_importNumber` není
  mezi sloupci.

## Doporučené pořadí implementace

1. **B (bank validace)** — izolovaná, malá, hned otestovatelná. Odbav první.
2. **Schema `importNumber` + `importOwnBankAccount`** — rozšíření JSONC.
3. **`applyImportNumber` + helper `numberSeriesResetScope`** v `DocDocument`,
   napojení v `beforeSave` (konzumace `_importNumber`).
4. **`DocumentApplier::transform()`** — předání virtuálních polí + bank_account.
5. **PHPUnit testy** dle výše.
6. **Smoke** z importéru (až bude hotový jeho task): pár faktur obou směrů,
   ověřit čísla + counter + navázání nového dokladu.

## Otevřené body / rozhodnutí

### 1. Counter klíč musí přesně zrcadlit `assignDocumentNumber`

Nejdůležitější riziko: pokud `applyImportNumber` použije jiný counter klíč
(jiný `fiscal_year` scope) než `assignDocumentNumber`, sync se mine a nová
faktura nenaváže. Proto helper `numberSeriesResetScope` a **stejná NULL-safe
logika** (`fiscal_year <=> %iN`). Při review porovnej oba kódy vedle sebe.

### 2. Import-mód nezávisí na `targetDocState`

`applyImportNumber` zapíše číslo bez ohledu na to, jestli je doklad vkládán
na 10 nebo 20 — protože importér vkládá faktury rovnou na cílový stav (20).
Pokud by někdo poslal `importNumber` s `targetDocState = 10` (koncept),
číslo se zapíše taky (nestandardní, ale neškodné — koncept s číslem). Importér
to nedělá; není potřeba bránit.

### 3. `importOwnBankAccount` jen pro vydané faktury

Vlastní bank účet potřebují vydané faktury (invno) při stavu 20+
(`IssuedInvoiceDocument::validate`). Importér ho dohledá a pošle. Pokud je
null a faktura jde na 20 → validate selže; importér to ošetří (vloží jako
koncept + warning) na své straně. Nový Shipard tu validaci nemění (na rozdíl
od přijatých faktur v části B) — vydaná faktura na 20 bez vlastního účtu je
oprávněně chyba.

### 4. Proč virtuální pole, ne sloupec

`sequence_number` a `doc_number` jsou skutečné sloupce, ty zapisujeme přímo.
Marker `_importNumber` je ale řídicí příznak, ne data — proto virtuální pole,
které `beforeSave` zkonzumuje. Alternativa (detekovat import mód podle
přítomnosti neprázdného `sequence_number` v `$data`) je křehčí: kolidovala by
s případným budoucím flow, kde klient pošle sequence_number z jiného důvodu.
Explicitní marker je jednoznačný.

### 5. Vztah k importéru

Tento task definuje kontrakt. Importér (`old_shipard:.../DocsRunner`) v
navazujícím tasku:
- parsuje `sequenceNumber` z původního `docNumber` (formula-based parser),
- posílá `applyOptions.importNumber` + (pro invno) `importOwnBankAccount`,
- vkládá obě směry faktur rovnou na cílový stav, odstraní dosavadní
  post-apply PATCH `doc_number` a povýšení 10→20.
