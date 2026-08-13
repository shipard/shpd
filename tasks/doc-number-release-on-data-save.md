# Oprava: ztráta čísla dokladu při data-save v Potvrzeno (falešný release 20→10)

**Stav:** částečně — kód + testy hotové 2026-08-13; zbývá D2 (reset test DS), ruční proklik a read-only verifikace na alfě

## Status / Cíl

**Status:** Implementováno (commity `fix(core)` + `fix(docs.core)`). Rozhodnutí D1–D3 potvrzena.

**Cíl:** Uložení dat dokladu, jehož payload neobsahuje `docState`, nesmí být
interpretováno jako přechod stavu. Chybějící `docState` v payloadu znamená
„stav se nemění" — efektivní stav je stav z `$originalData`.

## Kontext — příčina chyby

`FormEditor.handleTransition` dělá u existujícího záznamu dva PUTy: nejdřív
uloží data formuláře (bez `docState` — system sloupec), pak pošle state-only
`{docState: N}`. První PUT projde `TableGateway::saveDocument` →
`DocDocument::beforeSave` → `processStateTransition`:

```php
$newState = (int) ($data['docState'] ?? 10);              // payload bez docState → 10!
$oldState = (int) ($originalData['docState'] ?? $newState); // originál → 20
```

Doklad v Potvrzeno (20) se tak tváří jako přechod 20→10 a zavolá se
`releaseDocumentNumber`: dekrement počítadla, `sequence_number = NULL`,
`doc_number = '!{id}'`, smazání snapshotů. Následný state-only PUT přepne
20→40 už s placeholder číslem.

**Empirický důkaz** (DS `4l3j-z0bz-kz39-echj`): doklad id=1 ve 40
s `doc_number='!0000000001'`, `sequence_number=NULL`; doklad id=2 ve 20
dostal recyklovanou sekvenci 1 (`2260001`); počítadlo `last_assigned=1`.

Stejný vadný vzor `$data['docState'] ?? 10` je v `DocDocument` na třech
místech:

| Místo | Řádek (cca) | Dopad |
|---|---|---|
| `processStateTransition` | 966 | falešný release čísla (hlavní bug) |
| `maintainSnapshots` | 1260 | snapshoty se při data-save ve 20/80 neudržují |
| `validate` | 72 | per-stavové validace se při data-save ve 20/80 přeskočí (validuje se „jako Koncept") |

Správný vzor už existuje: `trackStateChange` (ř. 263) používá
`$data['docState'] ?? $originalData['docState'] ?? 10` a výsledný přechod
ukládá do `$this->stateTransition`.

## Návaznost

- `docs/doc-states.md` sekce 9 (TableGateway — persistenční dopočet
  docStateMain) — injektáž efektivního stavu je rozšíření téhož principu
  „jediné místo pravdy v persistenční vrstvě".
- `docs/docs-mvp.md` sekce 5.5 a 9 (životní cyklus čísla dokladu).

## Scope

### D1 — Efektivní docState (systémová oprava v gateway + lokální fallbacky)

**a) `src/Core/Document/TableGateway.php` — `saveDocument()`**

Po načtení `$originalData` a PŘED voláním `$doc->validate($data)` injektovat
efektivní stav:

```php
// Chybějící docState v update payloadu = stav se nemění. Injektáž před
// validate/beforeSave zajistí, že všechny Document hooky vidí efektivní
// stav — payload bez docState se nikdy netváří jako Koncept (10).
if ($this->docStates !== null && $originalData !== null) {
    $stateCol = $this->docStates->stateColumn;
    if (!array_key_exists($stateCol, $data) && isset($originalData[$stateCol])) {
        $data[$stateCol] = (int) $originalData[$stateCol];
    }
}
```

Poznámky:
- Injektáž pouze na update (`$originalData !== null`); insert nechává
  stávající defaulty.
- Existující dopočet `docStateMain` v gateway se díky injektáži nově uplatní
  i na data-saves — to je žádoucí (idempotentní oprava, viz doc-states.md §9)
  a UPDATE zapíše tutéž hodnotu.
- Oprava je generická — chrání všechny tabulky s `docStates`, nejen doklady
  (stejný vzor `?? 10` může být i v jiných Document třídách).

**b) `modules/docs/core/src/DocDocument.php` — obrana do hloubky**

I s injektáží opravit lokální odvozování (Document hooky mohou být volány
i mimo gateway — např. `DocRowsDocument::recomputeHeader`):

- `maintainSnapshots`:
  `$newState = (int) ($data['docState'] ?? $originalData['docState'] ?? 10);`
- `validate`: ponechat `$data['docState'] ?? 10` (validate nemá
  `$originalData`; injektáž v gateway zajistí správnou hodnotu — signatura
  `Document::validate(array &$data)` se NEMĚNÍ, má 42 overridů napříč moduly).

**c) `modules/docs/core/src/DocDocument.php` — `validate()`: kontrola řádků z DB**

Zapnutím per-stavových validací pro data-saves ve 20/80 by kontrola
`no_rows` začala falešně padat na header-only saves (řádky spravuje sub-form,
v payloadu nejsou). Nahradit:

```php
$rows = $data['rows'] ?? null;
if (!is_array($rows) || count($rows) === 0) { ... }
```

za použití existujícího `$this->resolveRowsForCompute($data)` (payload →
fallback DB → prázdné pole pro nový záznam). Ostatní per-stavové kontroly
(partner, vat_registration, exchange_rate, own company) čtou pole, která
data-save payload z formuláře obsahuje — beze změny.

### D3 — Release jen na explicitně detekovaný přechod

`processStateTransition` přestane stav odvozovat sám — použije
`$this->stateTransition` naplněný v `trackStateChange` (běží v `beforeSave`
jako první, se správným fallbackem):

```php
protected function processStateTransition(array &$data, ?array $originalData): void
{
    $t = $this->stateTransition;
    if ($t === null) {
        return; // žádná změna stavu — nikdy nesahat na číslo dokladu
    }
    if ($t['old'] === 10 && $t['new'] === 20) {
        $this->assignDocumentNumber($data);
        return;
    }
    if ($t['old'] === 20 && $t['new'] === 10) {
        $this->releaseDocumentNumber($data, $originalData);
    }
}
```

Jediné místo pravdy pro detekci přechodu = `trackStateChange`. Chování na
insertu se nemění (insert mimo 10 dává `old=0` → žádná větev nematchne,
shodně s dneškem; import cesta `_importNumber` obchází
`processStateTransition` úplně).

### D2 — Oprava dat v testovacím DS

Bez SQL zásahu. Po nasazení opravy David ručně:
- doklad id=1 protáhne cyklem (40 → 80 → 40 znovu nedá číslo — číslo se
  přiděluje jen 10→20; doklad je nutné smazat a pořídit znovu), NEBO
- `ds-reset` testovacího DS (preferováno — DS je čerstvý).

Task nedělá žádnou datovou migraci: chyba je na dev/test DS, alfa doklady
vznikají importem (`_importNumber` cesta, bugem nedotčená) — přesto po
nasazení na alfu provést read-only verifikaci (viz Hotovo když).

## Změny po souborech

| Soubor | Změna |
|---|---|
| `src/Core/Document/TableGateway.php` | injektáž efektivního `docState` do `$data` na update, před `validate()` |
| `modules/docs/core/src/DocDocument.php` | `processStateTransition` čte `$this->stateTransition`; `maintainSnapshots` fallback na `$originalData`; `validate` řádky přes `resolveRowsForCompute` |
| `tests/Unit/Core/Document/TableGatewayTest.php` | nový test: update payload bez stateCol → injektáž z originálu + dopočet docStateMain |
| `tests/Unit/Module/Docs/Core/DocDocumentNumberingTest.php` | regresní testy (viz níže) |
| `tests/Unit/Module/Docs/Core/DocDocumentSnapshotsTest.php` | test fallbacku v `maintainSnapshots` |
| `tests/Unit/Module/Docs/Core/DocDocumentValidateTest.php` | testy per-stavové validace s injektovaným stavem + řádky z DB |

## Testy

Regresní (musí selhat před opravou, projít po):

1. **Falešný release:** `beforeSave` s `$data` bez `docState`,
   `$originalData['docState']=20`, přidělená sekvence → `doc_number`
   a `sequence_number` v `$data` zůstávají nedotčené, počítadlo se nemění,
   `releaseDocumentNumber` se nevolá.
2. **Explicitní release funguje dál:** `$data['docState']=10`, originál 20,
   poslední v řadě → release proběhne (stávající chování).
3. **Assign funguje dál:** `$data['docState']=20`, originál 10 → přidělení
   čísla (stávající chování).
4. **Snapshoty:** data-save ve 20 (stav injektovaný/fallback), změněný
   partner → snapshoty se přestaví.
5. **Validate — řádky z DB:** header-only payload (bez `rows`) ve stavu 20,
   řádky existují v DB → bez chyby `no_rows`; bez řádků v DB → chyba.
6. **Gateway injektáž:** update payload bez `docState` → do UPDATE jde
   původní `docState` + přepočtený `docStateMain`; insert bez `docState`
   beze změny chování.

PHPUnit vždy s úzkým `--filter` (např.
`--filter DocDocumentNumberingTest`), ne broad run.

## Commit strategie

1. `fix(core): TableGateway injects effective docState into update payloads`
   — gateway + TableGatewayTest.
2. `fix(docs.core): state transitions derived from trackStateChange; validate reads rows from DB`
   — DocDocument + zbylé testy.

## Hotovo když

- [x] Všech 6 regresních testů prochází, stávající testy docs.core beze změn
      (úzké filtry: `DocDocumentNumberingTest`, `DocDocumentSnapshotsTest`,
      `DocDocumentValidateTest`, `DocDocumentOrchestrationTest`,
      `DocDocumentTrackStateChangeTest`, `TableGatewayTest`; navíc
      `DocDocumentImportNumberTest` — fallback `applyImportNumber` beze změny
      chování; celá Unit sada zelená)
- [ ] Manuální scénář na test DS: nová faktura přijatá → Potvrdit (číslo
      přiděleno) → V pořádku → číslo dokladu zůstává, snapshoty zůstávají,
      počítadlo nedekrementováno
- [ ] Manuální scénář: doklad ve 20, uložit data (bez přechodu) → číslo
      zůstává; explicitní „Uložit jako koncept" (20→10, poslední v řadě)
      → číslo se korektně uvolní
- [ ] D2: testovací DS `4l3j-z0bz-kz39-echj` resetován / poškozený doklad
      znovu pořízen
- [ ] Po nasazení na alfu read-only verifikace: žádný doklad ve stavu ≥ 20
      s `doc_number LIKE '!%'` nebo `sequence_number IS NULL`
      (`SELECT COUNT(*) FROM docs_core_heads WHERE docState IN (20,40,30) AND (sequence_number IS NULL OR doc_number LIKE '!%')` = 0 na všech DS)
