# Modul: docs.cashDocs

Modul pro **Pokladní doklady** (`doc_type = 'cash'`). Polymorfní subclass nad
`docs.core`, issue #59.

## Účel

Příjmový a výdajový pokladní doklad jedné pokladny. Směr obchodu je **per
doklad**: `cash_dir` 1 = příjem (my jsme dodavatel, DPH na výstupu), 2 = výdej
(my jsme odběratel, DPH na vstupu) — viz `docs.core.docTypes` (`trade_dir: 0`,
`trade_dir_column: cash_dir`) a `DocDocument::resolveTradeDir()`.

Číselná řada je **vázaná na pokladnu** (`series_binding: cash_desk`): pro každou
aktivní pokladnu vznikne řada automaticky (`BoundNumberSeriesProvisioner`),
záložky vieweru = pokladny, „Přidat" zařadí doklad do řady aktivní záložky
a pokladna se na dokladu nezadává (denormalizuje se z řady do `cash_desk`).
Číslo má tvar `31{kód pokladny}{rok}{pořadí}`, např. `31HP12600001`.

## Co modul přidává

- **Document třída** `CashDocument extends CashDeskDocumentBase` (docs.core) —
  partner nepovinný, způsob úhrady jen Hotovost / Kartou, měna dokladu = měna
  pokladny, splatnost = datum vystavení. Pohyby řádku závisejí na směru
  (`rowOperations[].docTypes.cash.cashDir`): příjem `sale.services`,
  `sale.goods`, `payment.receivable`, `acc.entry`; výdej `purchase.goods`,
  `purchase.services`, `purchase.other`, `payment.payable`, `acc.entry`.
  `payment.*` = úhrada faktury hotově/kartou (partner + VS na řádku, accbal
  ji páruje jako bankovní úhradu).
- **Editační formulář** `CashDocForm extends CashDeskFormBase` — směr
  (`cash_dir`, po vzniku řádků už jen pro čtení), způsob úhrady, nepovinný
  partner, datumy, DPH, readOnly sekce „Pokladna"; titulek hlavičky
  „Příjmový / Výdajový pokladní doklad".
- **Viewer** `CashDocsViewer extends DocsHeadsViewer` — Účtárna → Pokladní
  doklady, `scopedDocType = 'cash'`, v řádku směr (Příjem / Výdej), datum
  a způsob úhrady; částka vždy kladná (směr nese štítek, vratky jsou záporné
  řádky).
- **Polymorfní registrace** v `documentClasses` / `forms` (typeColumn
  `doc_type`), merge s `docs.core` a sesterskými moduly.

## Co modul NEpřidává

- Žádné nové tabulky ani cfgItems — typy, směry (`docs.core.cashDirections`)
  a pohyby žijí v `docs.core`, účtovací předpis `cash` v `economy.accounting`
  (`accountingRules.cz.jsonc`: protiúčet = účet pokladny přes
  `accountSrc: cashDesk`, karta → `card.transit` 261).
- Pokladní knihu, otevírací doklady ani inventuru (fáze 2, #59 D10).
- Zálohy na pokladních dokladech, platební terminály per analytika, tisk.

## Vztah k `docs.cashRegister`

Sesterský modul pro **Prodejky** (`doc_type = 'cashreg'`, pevně výstup) sdílí
tutéž bázi `CashDeskDocumentBase` / `CashDeskFormBase` v `docs.core`; moduly na
sobě nezávisejí.
