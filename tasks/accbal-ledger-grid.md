# Task: Saldo pohyby — grid layout se skupinami per partner

## Status / cíl

Zapnout **tabulkový (grid) layout** na vieweru `economy.accbal.ledger` —
Saldo pohyby i saldokonta v sidebaru (sdílejí viewer). Naplňuje výhled
`docs/viewer-grid.md` §7.4 („saldokonto grid", rozhodnutí D8): **skupinové
řádky per partner** (D6/D12) + **footer se součty v domácí měně** (D7).

Infrastruktura je z viewer-grid Fáze 1+2 kompletní — práce je čistě
v `LedgerViewer` (grid metody + JOIN na deník kvůli datu). **Frontend se
nemění.** Vzory: `JournalViewer` (footer sdílející WHERE se selectRows),
`BankTransactionsViewer` (částka+měna v jedné buňce, badge sloupec).

## Závislosti

- `accbal-ledger-viewgroup-chips.md` + `accbal-nav-items.md` (hotové).
  Chipy ani `fixedViewGroup` grid nijak neovlivňují — viewGroup je filtr,
  grid je jen jiný render téhož dotazu; taby v grid layoutu zůstávají.

## Potvrzená designová rozhodnutí (Anna)

1. **Skupinové řádky per partner** (D6), ne plochý grid.
2. **Datum pohybu přes LEFT JOIN na deník** (`journal_row` →
   `economy_accounting_journal.accounting_date`) — bez změny schématu.
   Denormalizace data na ledger až bude potřeba jako párovací kritérium.
3. **Footer zapnout** — součty v domácí měně (`amount_hc`).
4. Sloupce dle návrhu níže; Partner jen v hlavičce skupiny (ne sloupec).
5. **Výchozí layout grid**, toggle na list zůstává (D10), mobil list (D2).

### Rozhodnutí Claude (v duchu potvrzených)

- **Bez `sortable` sloupců v MVP.** `buildSortedOrderBy()` neumí prefixovat
  skupinový klíč, a D12 vyžaduje primární řazení podle partnera — sort
  klikem by clustering rozbil (duplicitní group key = pád renderu).
  Skupiny jsou organizační princip; per-sloupcové řazení uvnitř skupin
  případně později (vyžádá si rozšíření helperu o group prefix).
- **Pohyby bez partnera** dostanou skupinu `{key: 'p0', label:
  '(Bez partnera)' / '(No partner)'}` a řadí se **na konec** — vizuálně
  čistší než řádky bez hlavičky, D12 drží triviálně.
- **Footer rozložení** (jedna řádka, mapa columnId → buňka):
  - pod `text` (grow): dva spany `Předpisy <Σ> <HC>` a `Úhrady <Σ> <HC>`
    (labely muted),
  - pod `residual`: `Zůstatek <Σpředpisy − Σúhrady> <HC>` (class amount).
  Vše `amount_hc` — jediná vždy-společná měna. Kód domácí měny v buňkách
  uvádět, aby bylo zřejmé, že jde o HC (sloupec Částka je v měně dokladu).

## Rozsah

### V rozsahu (vše `LedgerViewer.php`)

1. **`selectRows()`**:
   - přidat `LEFT JOIN economy_accounting_journal j ON j.id = l.journal_row`
     a `j.accounting_date` do SELECTu (i pro list layout — neškodí),
   - **ORDER BY pro skupiny (D12)**: primárně partner —
     `ISNULL(p.full_name) ASC, p.full_name ASC, l.partner ASC` (stabilita
     u shodných jmen), sekundárně `l.bal_side ASC, j.accounting_date ASC,
     l.id ASC`. POZOR: mění řazení i pro **list** layout (selectRows je
     sdílené, D1) — to je záměr, list tím taky získá seskupení dle
     partnera; jen to zmínit v commit message.

2. **`getGridColumns()`** (pořadí = návrh Anny; labely cs/en per-viewer
   jako v JournalVieweru):
   | id | label cs | width/align | pozn. |
   |---|---|---|---|
   | `accounting_date` | Datum | 96 | z JOINu na deník |
   | `role` | Role | ~90 | badge: Předpis → `primary`, Úhrada → `success` |
   | `payment_reference` | VS | 110 | |
   | `due_date` | Splatnost | 96 | |
   | `amount` | Částka | 130, right | dva spany: částka + kód měny muted (vzor bank) |
   | `residual` | Zbývá | 120, right | prázdné, když \|residual\| < 0.0001 |
   | `text` | Text | grow | |
   | `balance` | Saldokonto | ~140 | `short_name ?: name`; s chipem redundantní, na „Vše" užitečné |
   Žádný sloupec `sortable` (viz rozhodnutí Claude).

3. **`renderGridRow()`**:
   - `group`: `{key: 'p' . (partner ?? 0), label: partner_name ?: '(Bez
     partnera)'}`,
   - `stateStyle` jako v listu (`primary`/`done` dle bal_side) — levý
     proužek,
   - formátování přes stávající `formatMoney`/`formatDate`.

4. **`renderGridFooter()`** (D7 — agregace přes CELÝ filtrovaný set):
   - samostatný agregační dotaz se **stejným WHERE jako selectRows()** —
     vytáhnout stavbu podmínek do privátní metody sdílené oběma (vzor
     JournalViewer),
   - **past `only_open`**: filtr je řešený přes `HAVING residual <> 0`
     (per-row subdotazy) — footer ho musí replikovat, tj. agregovat přes
     subselect řádků, které HAVING prošly (`SELECT ... FROM (SELECT
     amount_hc, bal_side, (residual_expr) AS residual FROM ... WHERE ...)
     x [WHERE x.residual <> 0]`),
   - hodnoty: `SUM(amount_hc)` per `bal_side`; zůstatek = předpisy −
     úhrady; kód domácí měny vzít z dat (`home_currency` — přes filtrovaný
     set je jednotná, je to domácí měna DS).

5. **`getDefaultLayout()`**: `'grid'`.

6. **`renderRow()` (list) — volitelně**: doplnit `accounting_date` do t2
   (datum dosud v listu chybělo úplně). Drobné, ale ať list z JOINu taky
   něco má.

### Mimo rozsah

- Řazení klikem na hlavičky (vyžaduje group-prefix v
  `buildSortedOrderBy`) — případný samostatný task.
- Denormalizace `accounting_date` na ledger (schéma + generátor).
- Per-měna rozpady footeru, sloupec `amount_hc`.
- Párovací UI (accbal Fáze 4) — tento grid je jeho podklad, ne součást.

## Pasti / na co pozor

- **D12 je tvrdý kontrakt**: nesouvislá skupina = duplicitní `group.key`
  v `{#each}` = pád renderu. Primární ORDER BY podle partnera musí platit
  pro **každou** cestu dotazem (všechny kombinace filtrů).
- `journal_row` je denorm a může být NULL (teoreticky osiřelý pohyb) —
  `accounting_date` pak prázdné, LEFT JOIN to řeší; nesmí vypadnout řádek.
- Footer jen na page 0 (řeší controller) a jen v grid layoutu — viewer
  nic hlídat nemusí, jen vrátit správná čísla.
- `residual` v RESIDUAL_SQL je v **měně dokladu** — pro footer se používá
  `amount_hc`, NE residual výraz (zůstatek = Σ předpisů − Σ úhrad v HC;
  allocations do toho nevstupují). Nemíchat.
- Mobil: grid degraduje na list automaticky — `renderRow()` zůstává plně
  funkční, jen se nemazat.

## Ověření

1. `php -l modules/economy/accbal/src/LedgerViewer.php`;
   `vendor/bin/phpunit --filter 'LedgerViewer'` (existují-li), jinak celý
   unit běh.
2. `cd frontend && timeout 90 npm run build 2>&1 | tail -10` (nemělo by se
   nic měnit — smoke, že se nic nerozbilo).
3. Ručně na dev DS: Saldo pohyby se otevřou v gridu se skupinovými
   hlavičkami partnerů; chipy přepínají saldokonta a skupiny se
   překreslují; footer ukazuje Předpisy/Úhrady/Zůstatek v Kč a mění se
   s chipem i filtrem „Jen otevřené"; toggle na list funguje a list je
   seskupený dle partnera; sidebar Pohledávky/Závazky jedou v gridu bez
   chip lišty; detail v draweru (klik na řádek) funguje vč. akcí Otevřít
   doklad/transakci/deník; mobil (úzké okno) padá na list.
