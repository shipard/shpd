# Účtování na archivní účty rozvrhu (linkable states v AccountingEngine)

**Stav:** hotovo

> **Status:** implementováno (zbývá re-import DS A) · **Modul:** economy.accounting · **Typ:** oprava
> **Návaznost:** vzor `bank-import-linkable-states.md` (třetí výskyt
> téhož vzoru); nález D15.1a z tasku 22 (old_shipard)

## Kontext

Historické doklady účtované na dnes archivní účty rozvrhu selžou:
`AccountingEngine` dohledává účty (přímý účet řádku i maskový resolver
kategorií) s filtrem `docState IN (10, 40, 80)` — archiv (70) vypadne.
Na DS A tak po D6 selhalo 7 dokladů (1639, 1797, 2707, 3015, 3172, 4405,
5024) s účty **221101–221105, 231001**, které ve zdroji i v novém
rozvrhu existují jako archivní (staré 9000 → nové 70; `AccountsRunner`
je importuje správně, LocalIdMap má všech 839 účtů).

Sémantika stejná jako u bankovních účtů a osob: archivní entita je
**odkazovatelná** pro historická data (zápis na zrušený úvěrový účet
221xxx z roku 2015 je legitimní), jen se nenabízí pro novou aktivitu
(výběr účtu v UI zůstává aktivní-only).

## Nález (korekce scope)

Průzkum ukázal jinou topologii filtrů, než task předpokládal:

- **Skutečné místo selhání D6**: `core.exchange`
  `Resolve/AccountResolver` — import `acc.record` řádků
  (`DocumentApplier`) resolvuje účet **podle čísla** s filtrem
  `[10, 40, 80]`; archivní číslo → null → řádek bez účtu → chybový
  řádek deníku. Pouhý reaccount doklad neopraví — resolution selhala
  při importu, řádky nemají account id → nutný re-import.
- `AccountingEngine::resolveItemAccount`/`resolveRowAccount` (lookup
  podle id) **žádný filtr neměly** — archiv procházel už dřív, ale
  procházel i smazaný (90).
- Maskový filtr žije v `AccountMaskResolver` (sdílený engine dokladů
  i bankovních transakcí).
- Navíc `BankTransactionAccountingEngine::resolveBankAccount` (účet
  banky podle id) měl týž filtr `[10, 40, 80]`.

## Provedené změny

`LINKABLE_STATES = [10, 40, 70, 80]` (vzor `StatementImportService`):

- `core/exchange/src/Resolve/AccountResolver.php` — linkable + při
  duplicitě čísla preferuje nearchivní (`ORDER BY docState = 70, id`).
- `economy/accounting/src/AccountMaskResolver.php` — linkable +
  **aktivní před archivem** (`ORDER BY docState = 70, number`): archiv
  je jen fallback, výsledek masky s existujícím aktivním účtem se nikdy
  nezmění.
- `economy/accounting/src/AccountingEngine.php` — `resolveItemAccount`
  a `resolveRowAccount` nově filtrují linkable → smazaný účet (90)
  je chybový řádek (dřív tiše prošel).
- `economy/bank/src/BankTransactionAccountingEngine.php` —
  `resolveBankAccount` linkable (re-účtování historických transakcí
  na archivní 221xxx).
- UI výběr účtů (`AccountsLookup`) a validační brány nové aktivity
  (`ItemDocument`, `BankAccountDocument`) beze změny — aktivní-only.
- Dokumentace: `docs/accounting.md` §5 (maskový dotaz měl navíc drift
  `docStateMain <= 2` vs. skutečný filtr).

Simulace masek (314/3149/324/3249/563/663/504/518/548/311/321/343)
proběhla na obou dev DS (btpg = DS A Zlín, 4dnh = Lef Real): rozšíření
o stav 70 **nemění výsledek žádné masky**; preference aktivních to
navíc garantuje strukturálně. Na btpg je 7 archivních účtů:
221101–105, 231001, 531901.

## Testy

- [x] Unit: `AccountResolverTest` — linkable stavy + preference
      nearchivního, cache, prázdné číslo.
- [x] Integrace: `AccountMaskResolverTest` — aktivní preferován před
      číselně nižším archivním; archiv jako fallback; smazaný (90)
      nikdy.
- [x] Integrace: `AccountingEngineTest` — `acc.record` na archivní
      účet se zaúčtuje; na smazaný (90) → chybový řádek
      `row_account_missing`.

## Hotovo když

- [ ] 7 DS A dokladů s účty 221xxx/231001 se po re-importu zaúčtuje.
- [x] Simulace masek na obou DS beze změny výsledků pro aktivní účty.
- [x] Testy zelené (úzké filtry).
