# docs.core: import čísla bez sekvence (sequence_number NULL) pro duplicity migrace

Stav: hotovo (2026-07-22). Moduly: core.exchange, docs.core.

> **Odchylka od scope:** navíc bylo nutné povolit null v JSON schematu
> `shpd.docs.document.v1` (`sequenceNumber: ["integer","null"]`) — apply
> jinak payload odmítne s 400 `schema_invalid` ještě před mapováním.
> Změna propsaná i do inline kopie schématu v AI profilu
> `core/mail/profiles/default_czech_invoices.jsonc` (hlídá
> `ProfileSchemaDriftTest`); `prompt_version` bez bumpu — importní cesta
> čte schema z disku přes `SchemaLoader`, DB kopie profilu slouží jen AI
> extrakci, která `importNumber` neemituje.

> **Kontext:** old_shipard task `modules/imports/newShipard/tasks/`
> `22-wave-d-import-fixes.md`, dodatek **D14-B** — pravé duplicity klíče
> `(řada, rok, sekvence)` ve zdrojových datech (15 msi + 3 lefreal
> klíčů po přečíslování třídy A) se importují s docNumber sufixem
> (`…-2`) a **bez sekvence**: `sequence_number = NULL` v
> `unq_series_seq` nekoliduje (sloupec je nullable, UNIQUE v MariaDB
> bere NULL jako distinct) a číslo mimo formuli se nesmí synchronizovat
> do čítače řady. Migrace už posílá
> `applyOptions.importNumber.sequenceNumber: null` — dnešní kód ale
> null ztratí (viz Scope) a dokladu přidělí čerstvé číslo z čítače.

## Scope

### 1. DocumentApplier — propustit explicitní null

`modules/core/exchange/src/Document/DocumentApplier.php` (~ř. 970–973):
`'sequenceNumber' => (int) ($importNumber['sequenceNumber'] ?? 0)`
přetypuje null na 0. Zachovat explicitní null (pozor, `?? 0` null nikdy
nevrátí — nutné `array_key_exists`):

```php
'sequenceNumber' => (array_key_exists('sequenceNumber', $importNumber)
        && $importNumber['sequenceNumber'] === null)
    ? null
    : (int) ($importNumber['sequenceNumber'] ?? 0),
```

### 2. DocDocument::applyImportNumber — větev pro null sekvenci

`modules/docs/core/src/DocDocument.php` (~ř. 988): dnes
`$sequence = (int)(...)` a guard `$docNumber === '' || $sequence <= 0`
→ fallback na `processStateTransition` (normální přidělení čísla) —
to by sufixovaný docNumber zahodilo. Nově:

- `sequenceNumber === null` (explicitně) a `$docNumber !== ''` →
  `doc_number = $docNumber`, `sequence_number = null`, **přeskočit
  bump čítače** (`docs_core_number_counters` INSERT IGNORE + GREATEST)
  a nevolat fallback — návrat.
- Stávající ochrana pro malformed payload (`docNumber === ''` nebo
  `sequence <= 0` u ne-null hodnoty) → fallback beze změny.

### 3. Test

Dle konvence repa (vzor integračních testů docs na DS `4l3j`,
např. `testCmnbkpFxLossReceivableTwoLinesWithIdentity`): import dvou
dokladů se stejným `(number_series, fiscal_year)`:

1. první s `importNumber {docNumber: 'X', sequenceNumber: N}` →
   uložen se sekvencí N, čítač bumpnut na N,
2. druhý s `importNumber {docNumber: 'X-2', sequenceNumber: null}` →
   uložen, `sequence_number IS NULL`, žádná `unq_series_seq` chyba,
   čítač zůstal N,
3. třetí doklad téže řady s `sequenceNumber: null` → také projde
   (dva NULL v UNIQUE nekolidují).

## Nasazení

Jen kód (žádná změna schématu ani ds-upgrade —
`docs_core_heads.sequence_number` už je nullable). Po nasazení na dev
DS btpg + 4dnh navazuje třetí plný re-import obou DS ze starého
Shipardu (old_shipard task 22).

## Hotovo když

- [x] Explicitní `sequenceNumber: null` v importNumber projde applierem
      i DocDocument až do `sequence_number = NULL`, bez bumpu čítače.
- [x] Malformed payloady (prázdný docNumber, sequence 0/-1 ne-null)
      se chovají jako dosud (fallback na normální přidělení).
- [x] Testy dle bodu 3 zelené — integrační test
      `DocumentImportSeriesStatesTest::testNullSequenceImportsDuplicateKeysWithoutCounterBump`
      (na 4l3j celá třída skipuje — DS nemá invni řady s kódy 1 a 5;
      běhá proti btpg/4dnh).
