# Task: Import dokladů — snapshoty partnera z kanonického payloadu

**Stav:** částečně — kód, testy a docs hotové 2026-09-01; zbývá ověření po re-importu qrce (task 29 old_shipard, ops) — poslední dva body checklistu
**Issue:** #55 (M1 — DPH) — nález z verifikace `tasks/taxes-phase01.md`
**Návaznost:** stará strana `modules/imports/newShipard/tasks/29-doc-party-snapshots.md`
(old_shipard) — exportér posílá ve straně partnera dobové DIČ
(`e10doc_core_heads.personVATIN` přebíjí adresářový fallback). Tento task
musí být nasazený dřív, než poběží ostrý re-import.

## Kontext

Kontrolní hlášení čte DIČ partnera ze snapshotů dokladu
(`VatDocumentSelection::vatIdFromSnapshot`). Import snapshoty záměrně
nechává NULL (`DocDocument::$importMode` — nestavět dobové snapshoty
z dnešního adresáře). Na migrovaných DS proto KH nemůže sestavit A4 a B2
řádky jsou bez DIČ (viz issue #55, verifikace fáze 0+1: qrce Q1/2026 —
201 dokladů, 100 % prázdných snapshotů, 14× `vatKh.missingVatId`).

Řešení princip zachovává: dobová data přijdou **v kanonickém payloadu**
(exportér bere DIČ ze staré hlavičky dokladu) a nová strana je persistuje —
nestaví nic z dnešního adresáře. Snapshot importovaného dokladu je tedy
dobový v tom, co dobové být musí (DIČ), a nejlepší dostupná aproximace
ve zbytku; provenienci nese `source_kind = import.oldShipard`.

Před implementací **přečti**:

- `modules/core/exchange/src/Document/DocumentApplier.php` — pipeline
  canonical → `$data` → `TableGateway::saveDocument`; hranice „Applier
  never reaches below the Document layer"
- `modules/docs/core/src/DocDocument.php` — `$importMode` (ř. ~44),
  `maintainSnapshots()` / `buildSnapshots()` (ř. ~1353+): tvar dat,
  `trade_dir` větvení, `encodeSnapshot` (JSON string, ne pole — dibi!)
- `modules/docs/core/src/PersonSnapshotBuilder.php` — závazný tvar
  snapshotu (kompatibilita uložených snapshotů)
- `docs/exchange-format.md` — schema strany (`vatId` už existuje,
  schema se nemění)

## Co udělat

### 1. `DocumentApplier` — kanonická strana → snapshot payload

Z kanonické strany partnera (`supplier` / `customer` dle `selfParty`)
složit pole ve tvaru `PersonSnapshotBuilder`:

| canonical | snapshot |
|---|---|
| `name` | `name` |
| `companyId` | `company_id` |
| `taxId` | `tax_id` |
| `vatId` | `vat_id` |
| `courtRegistration` | `court_registration` |
| `contact.{email,phone}` | `contact.{email,phone}` |
| `address.{…}` | `address.{…}` (klíče dle builderu) |
| `bankAccount.{…}` | `bank_account.{…}` |

Předat do `$data` jako partnerský snapshot (nový interní klíč, např.
`_importPartnerSnapshot` — Applier nerozhoduje o cílovém sloupci,
to je věc Document vrstvy). Jen v import módu (`importNumber` přítomné).

### 2. `DocDocument` — import mód persistuje payload

`maintainSnapshots()`: v import módu místo `return`:

- partnerská strana = `_importPartnerSnapshot` z payloadu (je-li přítomen;
  jinak zůstává NULL — kanonické zdroje bez stran, např. cmnbkp),
- vlastní strana = standardní `buildPersonSnapshot` (vlastní firma,
  `bank_account`, `vat_registration` z hlavičky) — vlastní DIČ nese
  registrace na hlavičce, dnešní adresářová data vlastní firmy jsou OK,
- sloupce dle `trade_dir` — sdílet větvení s `buildSnapshots()`,
- aktualizovat komentář u `$importMode` (rozhodnutí upřesněno: nestavět
  z adresáře ≠ nechat prázdné, když payload nese dobová data).

### 3. Testy

- Unit: mapování canonical → snapshot tvar (včetně chybějících částí:
  bez adresy, bez banky, prázdné `vatId`).
- Unit/integration: import mód s payloadem plní správný sloupec dle
  `trade_dir`; bez payloadu nechává NULL; ne-import chování beze změny.

### 4. Dokumentace

`docs/exchange-format.md` — doplnit sémantiku: strana partnera se
u importu mrazí do snapshotu; exportér odpovídá za dobové `vatId`.

## Mimo scope

- Žádné změny exchange schematu, `PersonSnapshotBuilder` ani KH kalkulátoru.
- Backfill dat — oprava výhradně `ds-reset` + re-import (task 29, ops David).

## Hotovo když

- [x] Testy zelené; ne-importní cesta snapshotů beze změny.
- [ ] Po re-importu qrce (viz task 29): faktury mají oba snapshoty,
      `vat_id` partnera = dobové `personVATIN`; KH A4 obsahuje výstupy
      nad 10 tis. s CZ DIČ; `vatKh.missingVatId` jen u dokladů
      s prázdným zdrojem.
- [ ] DP3 + křížová kontrola beze změny (qrce Q1/2026: ř. 64 = 99 042,86,
      rozdíly 0).
