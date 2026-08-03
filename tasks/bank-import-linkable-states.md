# Bank import: archivní účty a osoby jsou odkazovatelné (linkable states)

**Stav:** hotovo

> **Status:** navrženo · **Modul:** economy.bank · **Typ:** oprava chyby
> **Návaznost:** `19-bank-negative-amounts.md` (old_shipard, imports.newShipard),
> konvence linkovatelnosti osob (`entityLinkable()` = `[10, 40, 70, 80]`)

## Kontext

Re-import bankovních výpisů (DS A, `aaaa-bbbb-cccc-dddd`) padá na:

```
HTTP 422: apply_failed — Bankovní účet #2 nenalezen nebo není aktivní.
✗ Failed bank statement (old ndx=6914, #10 2014-12-31)
```

`StatementImportService::ACTIVE_STATES = [10, 40, 80]` vynechává archiv
(70) a používá se na třech místech, která jsou všechna **lookupy
historických referencí**, ne brány pro novou aktivitu:

1. `loadBankAccount(int $id)` (~ř. 285) — dohledání vlastního účtu podle
   id z exchange apply. Archivní účet (starý docState 9000 → nový 70) →
   ImportException → **celý výpis se nenaimportuje**.
2. `matchBankAccount()` ref-matching (~ř. 333) — automatické párování
   účtu podle čísla/IBAN. Stejný problém pro import výpisů souborem.
3. Kontrola existence partnera proti `base_persons_persons` (~ř. 482) —
   transakce odkazující **archivovanou osobu** (starý 9000 → nový 70)
   ztratí přímou vazbu `partner` (spadne na fallback přes protiúčet,
   nebo zůstane NULL).

Sémanticky: výpis z roku 2014 pro účet archivovaný v 2020 je legitimní
historické datum — a platí to i v ostrém provozu (poslední výpis banky
chodí po uzavření účtu). Stejné pravidlo už máme u osob: archivní entita
je odkazovatelná, jen se nenabízí pro novou aktivitu.

**Rozsah (DS A):** 10 archivních účtů se **304 výpisy** ve zdrojových
datech, na nové straně 0 — ztraceno tiše už při prvním importu.
738 bankovních řádků odkazuje archivované osoby (dnes na nové straně
580 transakcí bez partnera). Stejná ztráta bude v datech alfy.

## Scope

### 1. `modules/economy/bank/src/Import/StatementImportService.php`

Zavést `private const LINKABLE_STATES = [10, 40, 70, 80];` (vyloučen jen
smazaný 90) a použít na všech třech místech výše místo `ACTIVE_STATES`.
Pokud tím `ACTIVE_STATES` ztratí poslední použití, smazat. Chybová hláška
u `loadBankAccount`/`matchBankAccount` → „nenalezen nebo smazán".
Docblocky u všech tří míst: jedna věta proč linkable (historická data
archivních entit).

### 2. Testy

Rozšířit existující testy import service (unit/integration dle toho, kde
dnes žijí):
- apply výpisu pro účet v docState 70 projde (výpis + transakce vzniknou),
- docState 90 dál selže,
- partner check: transakce s partnerem v docState 70 si vazbu ponechá.

## Oprava dat (po nasazení, mimo scope kódu)

Selhané výpisy v `LocalIdMap` nejsou (failures se nezapisují), ale kvůli
partner backfillu na už naimportovaných transakcích:
`forget --entity=bank-statement` + fáze `bank-statements`
(`StatementImportService::backfill()` doplní partnera na existujících
transakcích přes external_id dedup, hlavičky výpisů se neduplikují —
find-or-create).

Ověření po doběhnutí (read-only, provedu):
- počty výpisů per účet old ↔ new sedí včetně 10 archivních účtů (+304),
- transakce bez partnera klesnou z 580 na ~úroveň řádků bez osoby
  ve zdroji,
- rekonciliace zůstane 1 nevyrovnaný výpis (zdrojový 1Kč rozdíl,
  old ndx 3477),
- prošetřit anomálii ±1 výpis u účtů 1 a 5 (old 2105/2634 vs.
  new 2104/2635).

**Alfa:** stejná díra v datech (importováno stejnou cestou) — oprava
společně s rozhodnutím o alfě z tasku 19.

## Hotovo když

- [ ] Tři lookupy v `StatementImportService` používají LINKABLE_STATES
      `[10, 40, 70, 80]`.
- [ ] Testy: archivní účet apply OK, smazaný účet 422, archivní partner
      se linkuje.
- [ ] Po re-runu: DS A má výpisy i pro 10 archivních účtů (6 719 +
      304 = 7 023 výpisů), rekonciliace nevyrovnaná jen u old ndx 3477.
- [ ] Transakce bez partnera výrazně pod 580 (zbytek = řádky bez osoby /
      smazané osoby ve zdroji).
