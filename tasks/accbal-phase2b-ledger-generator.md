# accbal Fáze 2b — generování saldo pohybů z deníku

**Stav:** hotovo

> PRD pro jednu Claude Code session. Design: `docs/accbal.md` (§3.3, §3.4,
> §4.2, §4.3, §4.4). Staví na hotové Fázi 0/1/2a — deník nese symboly +
> splatnost, enginy vyšlou `journalWritten(sourceKind, sourceId)`, settings
> tabulky existují.

## Kontext

Tohle je „motor" saldokonta: handler na událost `journalWritten` re-derivuje
**saldo pohyby** zdroje z aktuálního účetního deníku podle nastavení saldokont.
Žádné párování (to je Fáze 3) — jen ryzí seznam předpisů a úhrad + tabulka
allocations založená prázdná pro Fázi 3.

Vstup: `journalWritten('doc'|'bankTransaction', sourceId)`. Engine accbal načte
řádky deníku zdroje + nastavení, vyrobí kandidátní pohyby a **idempotentně
UPSERTuje** ledger podle stabilního klíče zdroje (§4.3) — `id` pohybu přežije
přeúčtování, takže na něj půjde ve Fázi 3 navázat allocations.

## Cíl

1. Tabulky `economy_accbal_ledger` (418) a `economy_accbal_allocations` (419).
2. Generátor pohybů (`LedgerGenerator` + handler `JournalLedgerHandler`
   registrovaný v `journalEventHandlers`) — UPSERT dle stabilního klíče vč.
   clearing skupiny „Nespárované platby".
3. Úklid při mazání zdroje (`documentEventHandlers` beforeDelete na
   `docs_core_heads` + `economy_bank_transactions`).
4. Read-only viewer ledgeru (filtry: skupina, partner, VS, jen otevřené).

## Návaznost

- **Prerekvizita:** Fáze 0/1/2a hotové.
- **Odemyká:** Fázi 3 (matcher plní `allocations`, spouští reaccount
  clearing → 311/321).
- `allocations` se v této fázi **zakládá, ale neplní** — viewer reziduum počítá
  přes LEFT JOIN (prázdné → vše „otevřené"), aby byl po Fázi 3 rovnou správný.

## Před implementací přečti

- `docs/accbal.md` §3.3 (ledger), §3.4 (allocations), §4.2 (algoritmus), §4.3
  (idempotence + stabilní klíč), §4.4 (clearing varianta B)
- Hotová 2a:
  - `src/Core/Document/JournalEventHandler.php`
    (`onJournalWritten(string $sourceKind, int $sourceId)`)
  - `src/Core/Document/AbstractJournalEventHandler.php` (settery služeb)
  - `src/Core/Module/ModuleDefinition.php` ř. 153–177 (`journalEventHandlers`
    registrace `{class, events}`)
- Deník: `modules/economy/accounting/tables/economy_accounting_journal.jsonc`
  — sloupce `source_kind`, `doc_head`, `bank_transaction`, `account_number`,
  `money_dr`/`money_cr` (domácí) + `money_dr_cur`/`money_cr_cur` (měna dokladu),
  `currency`, `partner`, `fiscal_year`, `accounting_date`, `payment_reference`,
  `specific_symbol`, `constant_symbol`, `due_date`
- Nastavení (Fáze 1): `modules/economy/accbal/tables/economy_accbal_balances.jsonc`
  (`code`, `name`, `sort_order`, `valid_from/to`),
  `economy_accbal_balance_accounts.jsonc` (`balance`, `account_number` =
  prefix, `acc_side` 0 MD/1 DAL, `amounts_sign` 0/1/2, `bal_side` 0 Předpis/
  1 Úhrada, `modify_sign`, `valid_from/to`)
- `modules/economy/accbal/module.jsonc` (přidáš tabulky, viewer,
  journalEventHandlers, documentEventHandlers)
- Vzory: provisioner/engine `AccountChartProvisioner` (idempotence),
  `AccountingEngine::groupLines/writeResult` (UPSERT/transakce styl),
  `JournalViewer` (read-only viewer + filtry + renderDetail), `CashDesksViewer`
  (docStates viewer)
- Úklid: `DocsHeadsEventHandler::onBeforeDelete` (vzor mazání závislých dat)

## Scope

**Uvnitř:** 2 tabulky; `LedgerGenerator` + `JournalLedgerHandler`; beforeDelete
úklid; ledger viewer; registrace v `module.jsonc`; testy.

**Mimo:** párování / plnění `allocations`, reaccount trigger, bucket „kdo kolik
dluží", UI párování (vše Fáze 3); kurzové rozdíly; otevírací doklady období;
zálohy/zápočty.

## Co implementovat

### A. Tabulka `economy_accbal_ledger` (418)

**Bez docStates, bez formu** — čistý derivát deníku (jako účetní deník).
displayPattern `{account_number} {amount}`.

| sloupec | typ | pozn. |
|---|---|---|
| `id` | int PK ai | identita pohybu (na něj se vážou allocations) |
| `balance` | int, FK economy_accbal_balances, not null | |
| `bal_side` | enumInt, cfgItem `economy.accbal.balSides` | 0 Předpis / 1 Úhrada |
| `source_kind` | enumString 20, cfgItem `economy.accounting.journalSources` | `doc` \| `bankTransaction` |
| `doc_head` | int, FK docs_core_heads, nullable | |
| `bank_transaction` | int, FK economy_bank_transactions, nullable | accbal závisí na bank → FK přímo, žádný extension |
| `journal_row` | int, nullable, **bez FK** | denorm na aktuální řádek deníku (volatilní přes reaccount — §4.3) |
| `account_number` | varchar 12, not null | saldo-účet pohybu |
| `fiscal_year` | int, FK economy_codebooks_fiscal_years, nullable | |
| `partner` | int, FK base_persons_persons, nullable | |
| `payment_reference` | varchar 35, nullable | denorm z deníku (signál pro matcher) |
| `specific_symbol` | varchar 20, nullable | denorm |
| `constant_symbol` | varchar 10, nullable | denorm |
| `due_date` | date, nullable | |
| `currency` | enumString 3, nullable | měna dokladu (z deníku) |
| `home_currency` | enumString 3, nullable | domácí měna DS (konstanta z configu) |
| `amount` | numeric 15,2 | částka v měně dokladu (po modify_sign) |
| `amount_hc` | numeric 15,2 | částka v domácí měně (po modify_sign) |
| `text` | varchar 200, nullable | |

Indexy: `(balance, partner, currency, fiscal_year)` (bucket),
`(payment_reference)`, `(doc_head)`, `(bank_transaction)`,
`(account_number, fiscal_year)`. Pro UPSERT stabilní klíč přidat index/unique
`(source_kind, doc_head, bank_transaction, balance, bal_side, account_number)`
(zvaž unique — viz Otevřené body, kolize prefixů).

### B. Tabulka `economy_accbal_allocations` (419)

Zakládá se prázdná (plní Fáze 3). cfgItem `economy.accbal.allocationOrigins`
`{"0":{"name:cs":"Automaticky"},"1":{"name:cs":"Ručně"}}` (do module.jsonc config).

| sloupec | typ | pozn. |
|---|---|---|
| `id` | int PK ai | |
| `payment_entry` | int, FK economy_accbal_ledger, not null | úhrada (bal_side 1) |
| `request_entry` | int, FK economy_accbal_ledger, not null | předpis (bal_side 0) |
| `amount` | numeric 15,2 | rozúčtováno v měně dokladu |
| `amount_hc` | numeric 15,2 | rozúčtováno v domácí měně |
| `created_by` | enumInt, cfgItem `economy.accbal.allocationOrigins` | 0 auto / 1 ručně |
| `note` | varchar 200, nullable | |

Indexy: `(request_entry)`, `(payment_entry)`.

### C. Generátor — `LedgerGenerator` + `JournalLedgerHandler`

`modules/economy/accbal/src/LedgerGenerator.php` (vzor `AccountingEngine` —
plain class, konstruktor `(\Dibi\Connection $db, ?ConfigRuntime $config)`):

```
generate(string $sourceKind, int $sourceId):
  1. Načti nastavení: balances + balance_accounts (aktivní; valid_from/to
     proti účetnímu datu řádků zdroje — viz pozn.). Seřaď dle sort_order.
  2. Načti řádky deníku zdroje:
       WHERE source_kind = :k AND (k='doc' ? doc_head : bank_transaction) = :id
     (prázdné = deník vymazán → desired set prázdný, viz krok 5).
  3. Domácí měna DS: resolve z configu (default dokladů „czk"), jednou.
  4. Pro každý řádek deníku × každý balance_account:
       - account_number řádku začíná na prefix balance_account.account_number?
       - acc_side: 0 MD → money_dr != 0; 1 DAL → money_cr != 0 (jednostranný řádek)
       - active_hc  = acc_side==0 ? money_dr     : money_cr
       - active_cur = acc_side==0 ? money_dr_cur : money_cr_cur
       - amounts_sign: 0 vše; 1 → active_hc > 0; 2 → active_hc < 0
       → kandidát:
           balance, bal_side z nastavení
           amount    = active_cur * (modify_sign ? -1 : 1)
           amount_hc = active_hc  * (modify_sign ? -1 : 1)
           account_number (řádku), partner, fiscal_year, currency (deníku),
           home_currency, payment_reference/specific_symbol/constant_symbol/
           due_date (z deníku), journal_row = řádek.id, text
       stabilní klíč = (source_kind, source_id, balance, bal_side, account_number)
  5. UPSERT v transakci:
       - existuje ledger se stabilním klíčem → UPDATE (zachovej id, refresh
         dat vč. journal_row)
       - nový klíč → INSERT
       - ledger pohyby zdroje s klíčem mimo desired → DELETE
         (+ cascade DELETE allocations, kde figurují jako payment_entry nebo
         request_entry)
```

Pozn.: clearing (261200/261300) je v nastavení skupina „Nespárované platby"
(Fáze 1) → generický algoritmus z bankovní úhrady vyrobí pohyb na téhle
skupině, bez speciálního casu (§4.4).

Pozn. k validitě: ber balance_accounts platné k `accounting_date` řádku deníku
(jako `AccountMaskResolver`); jednoduchost > přesnost — pokud je to drahé, MVP
může brát všechny aktivní (docStateMain <= 3) a validitu řešit později
(Otevřený bod).

Handler `modules/economy/accbal/src/JournalLedgerHandler.php`
(`extends AbstractJournalEventHandler`):

```php
public function onJournalWritten(string $sourceKind, int $sourceId): void
{
    (new LedgerGenerator($this->db, $this->config))->generate($sourceKind, $sourceId);
}
```

Registrace v `module.jsonc`:
```jsonc
"journalEventHandlers": [
    {"class": "Shipard\\Module\\Economy\\Accbal\\JournalLedgerHandler",
     "events": ["journalWritten"]}
]
```

### D. Úklid při mazání zdroje

`modules/economy/accbal/src/AccbalSourceCleanupHandler.php`
(`extends AbstractDocumentEventHandler`), `onBeforeDelete`: smaž allocations a
ledger pohyby zdroje (ledger FK-uje na doc_head/bank_transaction → bez úklidu
by delete zdroje spadl na FK). Registrace v `module.jsonc`:
```jsonc
"documentEventHandlers": [
    {"table": "docs_core_heads", "class": "...\\AccbalSourceCleanupHandler", "events": ["beforeDelete"]},
    {"table": "economy_bank_transactions", "class": "...\\AccbalSourceCleanupHandler", "events": ["beforeDelete"]}
]
```

### E. Viewer ledgeru

`modules/economy/accbal/src/LedgerViewer.php` (vzor `JournalViewer` — read-only,
`$docStatesCfgItem = null`, `getToolbarActions` prázdné) + registrace viewer +
settingsItem/navSection (zvaž: spíš samostatná položka „Saldo pohyby" než
settings — viz Otevřené body).

- **Reziduum** přes LEFT JOIN allocations:
  `request: amount - COALESCE(Σ alloc.amount kde request_entry=id, 0)`;
  `payment: amount - COALESCE(Σ alloc.amount kde payment_entry=id, 0)`.
  V této fázi (prázdné allocations) = vše plně otevřené — to je OK.
- Seznam: skupina + účet, partner, VS, splatnost, částka (obě měny),
  předpis/úhrada badge, reziduum.
- Filtry: `balance` (select skupin, vzor `BalancesLookup`), `partner` (text),
  `payment_reference` (text/rovnost), `only_open` (checkbox → reziduum != 0).
- Detail: properties (pohyb, částky obě měny, zdroj doklad/transakce, symboly,
  splatnost) + akce „Otevřít doklad/transakci" (`open_viewer`) + „Otevřít řádek
  deníku" pokud `journal_row`.

### F. Testy

`tests/Integration/Accbal/LedgerGeneratorTest.php`:
- Faktura vydaná → stav 40 → `journalWritten('doc', id)` → vznikne 1 předpis
  na skupině Pohledávky (311), správné obě měny, symboly z deníku.
- Dobropis (311 záporně) → předpis na skupině Závazky s `modify_sign` (kladně).
- Bankovní příjem (clearing 261200) → úhrada na skupině „Nespárované platby".
- **Idempotence/reaccount:** přechod 40→80→40 (clear + re-write) → `id`
  předpisu se zachová (UPSERT dle stabilního klíče), žádné duplicity.
- Clear (odchod ze 40) → pohyby zdroje zmizí.
- Mazání dokladu → beforeDelete smaže pohyby (žádný FK error).

## Hotovo když

- Po přechodu dokladu/transakce do 40 vzniknou v `economy_accbal_ledger`
  odpovídající pohyby (předpis/úhrada) se správnou skupinou, stranou a oběma
  měnami; clearingová úhrada padne na „Nespárované platby".
- Reaccount (40→80→40) zachová `id` pohybu (ověřeno testem); odchod ze 40 i
  smazání zdroje pohyby uklidí.
- Ledger viewer ukazuje pohyby, filtruje za skupinu/partnera/VS; „jen otevřené"
  funguje (vše otevřené, dokud nejsou allocations).
- `allocations` tabulka existuje, prázdná.
- Suite zelená (accbal + accounting + bank úzce přes `--filter`).

## Doporučené pořadí

1. Obě tabulky + cfgItem `allocationOrigins` → `ds-upgrade`.
2. `LedgerGenerator` + `JournalLedgerHandler` + registrace → test generování
   na dokladu (CZK).
3. Dobropis + clearing + cizí měna v testech.
4. Idempotence/reaccount + clear + beforeDelete úklid.
5. Ledger viewer + filtry + detail.

## Rozhodnutí ✓

1. Identita pohybu = **stabilní klíč zdroje** (source_kind, source_id, balance,
   bal_side, account_number), UPSERT podle něj; `journal_row` jen denorm bez FK
   (volatilní přes reaccount). *(David ✓ — §4.3.)*
2. Pohyby bez `request/payment/residual`; reziduum počítá viewer z allocations
   (oddělení pohyb/párování). *(David ✓ — §5/3.4.)*
3. Clearing = běžná skupina „Nespárované platby", žádný speciální case. *(David ✓ — varianta B.)*
4. `allocations` se zakládá prázdná; plní Fáze 3.
5. accbal závisí na bank → `ledger.bank_transaction` je přímý FK (ne extension
   jako u účetního deníku, kde accounting na bank záviset nesmí).

## Otevřené body

- **Unique na stabilní klíč** — pokud seed nevytvoří kolizní řádky nastavení
  (stejná skupina, stejný prefix, stejná strana, překryv znaménka), je klíč
  unikátní → lze dát unique index a tvrdě tím chránit idempotenci. Ověřit na
  seedu; jinak nechat jen index + dedup v kódu.
- **Validita nastavení vs. accounting_date** — přesné (per řádek) vs. MVP
  (všechna aktivní). Rozhodnout dle výkonu; default přesné jako účty.
- **home_currency zdroj** — DS domácí měna z configu (konstanta). Pokud není
  čistý config zdroj, je to spíš denorm pro reporty (amount_hc je vždy domácí).
- **Umístění ledger vieweru** — samostatná položka navigace „Saldo pohyby"
  (ne settings). Sjednotit s tím, kam půjde bucket pohled z Fáze 3.
- **Cascade delete pohybu s allocations** — ve Fázi 2b prázdné; ve Fázi 3 mít
  na paměti, že reaccount, který pohyb odebere, smaže i jeho párování
  (implikace pro re-pairing řeší matcher).
