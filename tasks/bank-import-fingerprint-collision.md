# Bank import: fingerprint kolize obsahově identických transakcí napříč výpisy

> **Status:** navrženo · **Modul:** economy.bank · **Typ:** oprava chyby
> **Návaznost:** `bank-import-linkable-states.md`, task 19 (old_shipard),
> W4.2 (fingerprint dedup design)

## Kontext

Po re-importu banky (msi-zlin) zůstává výpis **5/2022 ČS** (id 3285)
prázdný — jeho dvě transakce (`old:149248/149249`, poplatky 149 + 15 Kč)
v DB nejsou a výpis je nevyrovnaný o 164 Kč.

Mechanismus (ověřeno): měsíční paušál se opakuje se **stejnou částkou,
textem i datem** (`dateTransaction` z `dateDue` řádku — u obou výpisů
2022-05-31). `fingerprint()` = sha256(účet, datum, směr, částka,
protiúčet, VS, zpráva, `seqInDay`), kde `seqInDay` se počítá **od nuly
per apply** — první transakce dne 31. 5. má v obou výpisech seq 0 →
identický otisk. `findExisting()` s `external_id` fingerprint správně
nekonzultuje → jde se na INSERT → **unikátní index
`unq_fingerprint (bank_account, fingerprint)`** ho zablokuje →
`saveDocument` neuspěje → chyba se posbírá do `$txErrors` a smyčka
pokračuje → transakce tiše chybí.

Rozsah: msi-zlin 1 pár (2 transakce, výpis 3285); lefreal ověřit po
doběhnutí tamního re-importu (1 318 výpisů archivních účtů teprve čeká).
Kolize je deterministická — opakuje se při každém re-importu a nastane
u kohokoli s pravidelnými poplatky, kde se datum transakce sejde napříč
výpisy.

## Řešení

### Varianta A — doporučená: external_id jako součást otisku

Do `fingerprint()` přidat `$tx->externalId ?? ''` mezi parts. Transakce
s externím id (migrace, budoucí API importy se stabilním id banky) tak
mají otisk unikátní z konstrukce; transakce bez id (soubory) beze změny.

Proč je to bezpečné vůči původnímu účelu otisku (dedup přes soubory):
párování migrovaných řádků s budoucím souborovým importem přes fingerprint
je **už dnes fikce** — `dateTransaction` migrace bere z `dateDue` řádku
(≠ reálné datum pohybu v souboru banky, viz dubnový výpis s transakcemi
datovanými 31. 5.) a `seqInDay` závisí na pořadí zpracování. Ochranu proti
překryvu migrace × první soubor řeší external_id/ruční kontrola, ne otisk.

### Varianta B — alternativa: retry s inkrementem seqInDay

Při duplicate-key na `unq_fingerprint` u transakce s `external_id`:
zvýšit `seqInDay[$dateKey]`, přepočítat otisk, opakovat INSERT (bounded).
Sémanticky činí seq „globální pořadí identické transakce v daném dni",
zachovává jednotný formát otisku. Složitější (rozlišení duplicate-key od
jiných selhání `saveDocument`), a pro soubory bez external_id kolizi
napříč výpisy stejně neřeší (findExisting ji spolkne dřív).

Pozn.: obecný design dedupu souborových importů napříč výpisy (W4.2)
zůstává otevřená otázka mimo tento task — tam je „stejný obsah, jiný
výpis" nerozhodnutelné bez id banky.

### Testy

- Dva apply po sobě: dva výpisy, každý s obsahově identickou transakcí
  (stejný den/částka/text), obě s external_id → vzniknou **obě**,
  každá ve svém výpisu.
- Idempotence: opakovaný apply téhož výpisu transakce neduplikuje
  (external_id match → backfill/skip).
- Soubor bez external_id: chování beze změny (fingerprint match → skip).

## Oprava dat (po nasazení)

- msi-zlin: `forget --entity=bank-statement` + `bank-statements`
  (chybějící pár vznikne, výpis 3285 se vyrovná). Ověřím read-only:
  nevyrovnaný zůstane jen old ndx 3477 (zdrojový 1Kč rozdíl) —
  a stav dorovnat v checklistu tasku 19.
- lefreal: první forget+rerun vůbec (archivní účty) — až s tímto fixem,
  ať se kolize nezanesou.
- Mimo scope: výpis old ndx 670 (překlep 02/03 v `dateIssue` zdroje →
  `periodEnd < periodStart` → validace 422) — oprava zdrojového data
  nebo akceptace, samostatné rozhodnutí.

## Hotovo když

- [x] Obsahově identické transakce dvou výpisů se stejným dnem vzniknou
      obě (test; na lefreal ověřeno i na datech — výpis 3285 obdoba).
- [x] Po re-importu msi-zlin: výpis 3285 má 2 transakce a reconciluje;
      nevyrovnaný jen old ndx 3477. Ověřeno read-only 2026-07-20.
- [x] Lefreal: výpisy archivních účtů naimportované (1 318 = přesně zdroj,
      vč. rozpadů slitých párů dle `bank-statement-identity.md`)
      a rekonciliace nevyrovnaná jen u 3 výpisů s nulovými zdrojovými
      zůstatky. Ověřeno read-only 2026-07-20 po importu FIO účtů
      (task 20 old_shipard).
