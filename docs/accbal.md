# Shipard — Saldokonto (modul `economy.accbal`)

**Designový dokument.** Saldokonto = párování úhrad proti předpisům
(pohledávky, závazky, zálohy, …) postavené **čistě nad účetním deníkem**.
Vychází z myšlenek rozpracovaného „Saldo2" ze starého Shipardu
(`e10doc/accBal`), ale párování řeší jinak a opírá se o regenerovatelný
deník a clearing šev nového systému.

> **Stav:** návrh. Implementace po fázích (§9). Vlastní párovací algoritmus
> (matcher) je samostatná pozdější fáze s vlastním designem.

---

## 1. Motivace a princip

Saldokonto odpovídá na otázku „kdo komu kolik dluží a je to uhrazené?".
Technicky: vybrané řádky účetního deníku (na saldokontních účtech —
311/321/314/324/…) jsou buď **předpisy** (vznik pohledávky/závazku), nebo
**úhrady**. Saldokontní případ je uzavřený, když se předpisy a úhrady
v rámci jednoho párovacího kontextu vyrovnají na nulu.

### 1.1 Co se přebírá ze starého Saldo2 a co ne

Přebírá se **datový princip**: nastavení saldokont = seznam skupin + seznam
účtů s konfigurací (strana MD/DAL, znaménko, předpis/úhrada), a generování
saldo pohybu z řádku deníku, pokud řádek vyhoví nastavení (`AccBalanceCreator`).
Duální měna (měna dokladu + domácí) zůstává — řeší obchodní vs. účetní
saldokonto (§6).

**Nepřebírá se** párování zašité do journal řádku. Starý `AccBalanceCreator`
vázal úhradu na předpis přes `fetch()` podle klíče `(balance, person, symbol1,
symbol2)` a sečetl. Když mělo víc faktur stejný variabilní symbol (typicky
opakované platby služeb), `fetch()` vzal první předpis a zbytek se rozsypal —
matematicky to sedělo, ale rozpad „která faktura je uhrazená" byl náhodný.
Ruční obcházení (`specifický symbol = rok+měsíc`) většina uživatelů nezvládla.

Nový model párování od symbolového klíče odděluje: pohyby jsou ryzí seznam,
**párování je samostatná vrstva** (allocations) s alokačním algoritmem
v rámci bucketu `(partner, balance, currency)`. Variabilní symbol je pro
matcher silný **signál**, ne tvrdý párovací klíč (§5).

### 1.2 Saldo pracuje výhradně s deníkem

Klíčové architektonické rozhodnutí: saldo čte **jen** `economy_accounting_journal`.
Nesahá na doklady ani transakce při generování pohybů. Důsledek: každý budoucí
zdroj účtování (pokladna, zápočty, otevírací sekvence období, ruční zápis)
nakrmí saldo **bez jediné změny v saldo kódu** — stačí, aby do deníku zapsal
řádky se symboly a splatností.

Aby to platilo doslova, deník musí symboly a splatnost nést (dnes je nemá —
viz prerekvizita §3.5). Jediná zbývající vazba saldo↔účtovací engine je
**přeúčtovací trigger** (matcher řekne bance „přegeneruj clearing → 311"),
což je vrstva **párování**, ne generování.

---

## 2. Architektura

```
┌──────────────────────────────────────────────────────────────────┐
│  Účetní deník (economy_accounting_journal)                        │
│  - jednostranné řádky, obě měny, partner                          │
│  - NOVĚ: payment_reference / specific_symbol / constant_symbol /  │
│          due_date (plní účtovací enginy — prerekvizita §3.5)      │
│  - po (pře)zápisu deníku zdroje vyšle událost journalWritten      │
├──────────────────────────────────────────────────────────────────┤
│  Generátor pohybů (economy.accbal, handler journalWritten)        │
│  - načte nastavení saldokont (balances + balance_accounts)        │
│  - řádek deníku na saldo-účtu → saldo pohyb (předpis | úhrada)    │
│  - idempotentní UPSERT podle stabilního klíče zdroje (§4.3)       │
├──────────────────────────────────────────────────────────────────┤
│  economy_accbal_ledger   (pohyby)                                 │
│  - balance, bal_side, partner, symboly, splatnost, obě měny      │
│  - source_kind + zdroj (doc_head | bank_transaction)             │
├──────────────────────────────────────────────────────────────────┤
│  Matcher (samostatná fáze) → economy_accbal_allocations           │
│  - vazby úhrada↔předpis s rozúčtovanou částkou (obě měny)        │
│  - default FIFO dle splatnosti + ruční úprava                    │
│  - výsledek řekne bance „přegeneruj clearing → 311/321"          │
└──────────────────────────────────────────────────────────────────┘
```

Modul `economy.accbal`, závislosti: `core.system`, `economy.accounting`,
`economy.bank`, `docs.core`, `economy.codebooks`.

Saldo zůstává oddělené od `economy.accounting` (ten je „čistě deník");
accbal na něj jen závisí a čte jeho tabulku.

---

## 3. Datový model

### 3.1 `economy_accbal_balances` — saldokonta (skupiny)

tableId **416**. docStates: archivní sada (`core.system.docStatesArchive`).

| sloupec | typ | popis |
|---|---|---|
| `id` | int PK | |
| `code` | varchar 25 | stabilní identifikátor pro seed/exchange (nahrazuje starý `globalId`) |
| `name` / `short_name` | varchar 140 / 80 | |
| `order` | int | pořadí v UI |
| `valid_from` / `valid_to` | date, nullable | platnost skupiny |
| `docState` / `docStateMain` | tinyint, system | |

Seed (dle screenshotu starého systému): Pohledávky, Poskytnuté půjčky,
Závazky, Úvěry, Přijaté půjčky, Poskytnuté zálohy, Přijaté zálohy,
**Nespárované platby** (clearing — §4.4), Náklady příštích období.

### 3.2 `economy_accbal_balance_accounts` — účty saldokont

tableId **417**. Řádek per účet ve skupině.

| sloupec | typ | popis |
|---|---|---|
| `id` | int PK | |
| `balance` | int, FK balances, not null | |
| `account_number` | varchar 12, not null | **prefix** účtu (`311` chytí `311100`), `str_starts_with` jako ve starém |
| `acc_side` | enumInt | strana řádku deníku: 0 = MD, 1 = DAL |
| `amounts_sign` | enumInt | 0 = Všechny, 1 = Kladné, 2 = Záporné |
| `bal_side` | enumInt | 0 = Předpis, 1 = Úhrada |
| `modify_sign` | bool, default 0 | obrátit znaménko částky (dobropisy) |
| `note` | varchar 80, nullable | |
| `system_order` | int | |
| `valid_from` / `valid_to` | date, nullable | |
| `docState` / `docStateMain` | tinyint, system | |

**Proč MD/DAL + znaménko + filtr částky:** dělá to sémantické přesměrování,
které účtovací engine sám nedělá. Dobropis vydané faktury se zaúčtuje na **311
záporně** (engine ho nepřesměruje na 321). Záporná pohledávka je ale ekonomicky
**závazek**, takže nastavení „Závazky" obsahuje řádky pro 311 se zápornou
částkou a `modify_sign` (přesně řádky 13–16 na screenshotu): `311 MD Záporné →
Předpis *−1`, `311 DAL Záporné → Úhrada *−1`. Bez této vrstvy by dobropisy
v saldu seděly na špatné straně.

Příklad seedu pro „Závazky" (zkráceně):

```
321 DAL Kladné  Předpis        (běžný závazek vzniká)
321 MD  Kladné  Úhrada         (závazek se platí)
311 MD  Záporné Předpis  *−1   (dobropis pohledávky = závazek)
311 DAL Záporné Úhrada   *−1
325/331/336/341/342/345/379 …  (ostatní závazkové účty)
```

### 3.3 `economy_accbal_ledger` — saldo pohyby

tableId **418**. **Bez docStates, bez formu** — čistý derivát deníku, generuje
a maže ho jen handler (jako účetní deník sám).

| sloupec | typ | popis |
|---|---|---|
| `id` | int PK | identita pohybu — na něj se vážou allocations (stabilní, §4.3) |
| `balance` | int, FK balances, not null | skupina saldokonta |
| `bal_side` | enumInt, not null | 0 = Předpis, 1 = Úhrada |
| `source_kind` | enumString 20 | `doc` \| `bankTransaction` (denorm z deníku) |
| `doc_head` | int, FK docs_core_heads, nullable | zdroj (dle source_kind) |
| `bank_transaction` | int, FK economy_bank_transactions, nullable | zdroj — FK přidává `economy.bank` jako extension (správný směr závislosti) |
| `journal_row` | int, nullable | **denorm** odkaz na aktuální řádek deníku (pro „otevřít deník"); **není** stabilní identita — viz §4.3 |
| `account_number` | varchar 12, not null | saldo-účet pohybu (311100…) |
| `fiscal_year` | int, FK fiscal_years | saldokonto je period-scoped (§7) |
| `partner` | int, FK base_persons_persons, nullable | |
| `payment_reference` | varchar 35, nullable | denorm z deníku (signál pro matcher) |
| `specific_symbol` | varchar 20, nullable | denorm |
| `constant_symbol` | varchar 10, nullable | denorm (jen informační, ne párovací) |
| `due_date` | date, nullable | splatnost (předpis); u úhrady typicky NULL |
| `currency` | enumString 3 | měna dokladu |
| `home_currency` | enumString 3 | domácí měna |
| `amount` | numeric 15,2 | částka v měně dokladu (po `modify_sign`) |
| `amount_hc` | numeric 15,2 | částka v domácí měně |
| `text` | varchar 200, nullable | |

Indexy: bucket `(balance, partner, currency, fiscal_year)`, `(payment_reference)`,
`(doc_head)`, `(bank_transaction)`, `(account_number, fiscal_year)`.

Poznámky:

- **Žádné `request`/`payment`/`residual` na pohybu** (na rozdíl od starého
  Saldo2). Reziduum a stav případu se počítají z allocations (§5).
- Symboly + splatnost se denormalizují z deníku jen kvůli indexovaným
  bucket-dotazům matcheru a UI; zdroj pravdy je deník.

### 3.4 `economy_accbal_allocations` — párovací vazby

tableId **419**.

| sloupec | typ | popis |
|---|---|---|
| `id` | int PK | |
| `payment_entry` | int, FK ledger, not null | úhradový pohyb (`bal_side = 1`) |
| `request_entry` | int, FK ledger, not null | předpisový pohyb (`bal_side = 0`) |
| `amount` | numeric 15,2 | rozúčtovaná částka v měně dokladu |
| `amount_hc` | numeric 15,2 | rozúčtovaná částka v domácí měně |
| `created_by` | enumInt | 0 = auto (matcher), 1 = ruční |
| `note` | varchar 200, nullable | |

Indexy: `(request_entry)`, `(payment_entry)`.

Many-to-many: jedna úhrada → více předpisů (platba 600 na tři faktury) i jeden
předpis → více úhrad (faktura placená na splátky). Reziduum předpisu =
`request.amount − Σ allocations.amount`; předpis je obchodně uzavřený, když je
to nula (analogicky v domácí měně, §6).

**Případ (case)** se v Fázi 1–2 **nemodeluje jako entita** — je odvozený:
bucket `(partner, balance, currency)`, otevřené předpisy = ty s nenulovým
reziduem. Explicitní tabulka případů (kvůli ručnímu seskupení faktur
s různým VS nebo poznámce k případu) je možné rozšíření, ne nutnost (§8).

### 3.5 Prerekvizita: symboly + splatnost do účetního deníku

Deník dnes symboly ani splatnost nenese (`accounting.md` rozhodnutí #10).
Pro §1.2 je potřeba je doplnit. **Additivní, bezpečné** (`ds-upgrade` ADD
COLUMN; deník je derivát):

Sloupce do `economy_accounting_journal` (vše nullable, system):

| sloupec | typ | zdroj |
|---|---|---|
| `payment_reference` | varchar 35 | hlavička `payment_reference` / transakce |
| `specific_symbol` | varchar 20 | hlavička / transakce |
| `constant_symbol` | varchar 10 | hlavička / transakce |
| `due_date` | date | hlavička `due_date`; u transakce NULL |

- Hodnoty jsou konstantní přes celý doklad (z hlavičky), takže se jen
  orazítkují na každý vkládaný řádek v `AccountingEngine::writeResult`
  (vedle stávajících `doc_type`/`doc_number`/`currency`). **Grouping se
  nemění** (klíč `(side, account_number, partner, operation)` je nedotčený) —
  riziko nula.
- `BankTransactionAccountingEngine` razítkuje symboly transakce, `due_date`
  NULL.
- `JournalViewer`: přidat `payment_reference` do filtrů a fulltextu — to je
  rovnou ta lidská hodnota („najdi v deníku všechno na VS 12345"), kvůli které
  to do deníku patří i nezávisle na saldu.
- Index `(payment_reference)` na deníku.

### 3.6 Prerekvizita: sjednocení symbolů na bankovních transakcích

`economy_bank_transactions` má dnes `symbol1/2/3` (varchar **10**), starým
jménem. Hlavička dokladu má `payment_reference` (varchar **35**, RF/EndToEndId)
+ `specific_symbol` + `constant_symbol`. Párovací klíč musí být porovnatelný
napříč doklad↔transakce — proto lockstep přejmenování na transakcích:

```
symbol1 → payment_reference (varchar 35)
symbol2 → specific_symbol   (varchar 20)
symbol3 → constant_symbol   (varchar 10)
```

Doplnit do parserů (CAMT `EndToEndId` ⇒ `payment_reference`), exchange schématu
`shpd.bank.statement.v1` a applieru. Bez toho RF reference z faktury nikdy
nesedne na osekaný 10znakový VS z banky.

---

## 4. Generování pohybů z deníku

### 4.1 Trigger — událost `journalWritten`

Saldo se nezahákuje na změnu stavu dokladu (to dělá účtování), ale na **změnu
deníku**. Účtovací enginy (`AccountingEngine`, `BankTransactionAccountingEngine`)
po každém (pře)zápisu deníku zdroje — i po jeho vymazání — vyšlou událost:

```
journalWritten(sourceKind, sourceId)   // deník zdroje se změnil / vymazal
```

Generický mechanismus (obdoba `documentEventHandlers` z `accounting.md` §7.1):
modul `economy.accbal` zaregistruje handler na `journalWritten`. Handler
**re-derivuje** saldo pohyby daného zdroje z aktuálního deníku.

Proč událost a ne `stateChanged`: drží §1.2 (saldo zná jen deník) a **samo
řeší clearing → 311 přechod** — když matcher spustí přeúčtování transakce,
bankovní engine přepíše deník a vyšle `journalWritten`; saldo re-derivaci
provede automaticky (clearing pohyb zmizí, 311 úhrada vznikne). Žádná zvláštní
cesta pro „po spárování".

> Vyžaduje malé doplnění do core (událost, kterou enginy vyšlou) — analogické
> `documentEventHandlers`. Detail viz prerekvizity v PRD.

### 4.2 Algoritmus generátoru (per zdroj)

```
1. Načti nastavení saldokont platné k účetnímu datu (balances +
   balance_accounts), seřazené.
2. Načti aktuální řádky deníku zdroje (source_kind + source_id).
3. Pro každý řádek deníku:
   pro každý řádek nastavení (balance_account):
     - account_number řádku začíná na prefix nastavení?  (str_starts_with)
     - acc_side nastavení == strana řádku (MD ⇔ money_dr, DAL ⇔ money_cr)?
     - amounts_sign vyhovuje znaménku částky?
     → ano: vznikne kandidát na saldo pohyb:
        balance   = nastavení.balance
        bal_side  = nastavení.bal_side
        amount    = částka řádku (×−1 dle modify_sign), obě měny
        partner, symboly, due_date, fiscal_year, account_number, journal_row
4. UPSERT pohybů zdroje podle stabilního klíče (§4.3); chybějící smaž.
```

Jeden řádek deníku může vyhovět **víc** řádkům nastavení (vznikne víc pohybů) —
to je validní (např. týž účet ve dvou skupinách). Pohyb dědí měny z deníku
přímo, žádný přepočet (deník je už vede v obou měnách).

### 4.3 Idempotence a stabilní identita pohybu

**Problém:** deník je DELETE+INSERT, takže `economy_accounting_journal.id`
**není stabilní** přes přeúčtování. Kdyby pohyb FK-oval na `journal_row.id`,
po každém přechodu dokladu 40→80→40 by `id` přeskákalo a allocations by
dangly.

**Řešení:** identita pohybu = stabilní klíč odvozený ze **zdroje**, ne z řádku
deníku:

```
(source_kind, source_id, balance, bal_side, account_number)
```

Tenhle klíč je stabilní přes přeúčtování (zdroj se nemění, saldo-účet se
nemění). Generátor pohyby **UPSERTuje** podle něj (vzor starého
`saveBalanceJournalRequests` + memo `claimAccountForNewId`):

- existuje pohyb s klíčem → UPDATE částek/symbolů, `id` se zachová → allocations
  drží
- nový klíč → INSERT
- pohyb zdroje, který už v novém deníku není → DELETE (cascade allocations)

`journal_row` je jen **denorm** odkaz na aktuální řádek (refreshuje se při
každé re-derivaci), pro akci „otevřít řádek deníku". Není load-bearing.

> Jednoznačnost klíče: grouping deníku slučuje řádky per `(side, account_number,
> partner, operation)`; partner je konstantní per zdroj, takže na daném
> saldo-účtu a straně je per zdroj **právě jeden** řádek → klíč je unikátní.

### 4.4 Clearing šev (varianta B)

Nespárovaná bankovní úhrada má protistranu na clearingu (261200/261300, viz
`bank.md` §6.3). Clearing účty se zařazují do nastavení saldokont jako skupina
**„Nespárované platby"**:

```
261200 DAL Kladné Úhrada   (nespárovaný příjem)
261300 MD  Kladné Úhrada   (nespárovaný výdaj)
```

Důsledky:

- Nespárovaná úhrada je **normální saldo pohyb** na skupině „Nespárované platby"
  (bal_side = Úhrada, bez allocation). Matcher má tím **jediný zdroj kandidátů**
  — ledger.
- Po spárování bankovní engine přeúčtuje transakci clearing → 311/321, vyšle
  `journalWritten`, saldo re-derivuje: clearing pohyb (na „Nespárovaných")
  **zmizí**, vznikne 311/321 úhrada na skupině Pohledávky/Závazky, a matcher na
  ni naváže allocation.
- Invariant zůstává čistý: **nenulový obrat skupiny „Nespárované platby" =
  existují nespárované úhrady** (signál k akci, ne chyba).
- Pravidlo matcheru: skupina „Nespárované platby" se **nepáruje sama proti
  sobě** (nemá předpisy).

---

## 5. Párování (matcher) — samostatná fáze

> Vlastní algoritmus je velký a navrhne se zvlášť (Fáze 3, samostatné sezení).
> Zde jen tvar řešení a hranice, aby na něj schéma sedělo.

Matcher pracuje v rámci **bucketu** `(partner, balance, currency)`:

- kandidáti: nealokované úhrady + otevřené předpisy (nenulové reziduum) bucketu
- variabilní symbol je **silný signál** (shoda VS → preferuj), ne tvrdý klíč
- default strategie: **FIFO dle `due_date`** předpisů; částka úhrady se
  rozúčtuje na nejstarší otevřené předpisy
- výsledek = zápis allocations; možnost ruční úpravy (`created_by = 1`)

**Kontrolní příklad — „600 Kč na 3×250":** zákazník má tři vydané faktury po
250 Kč (tři předpisy na 311, různé VS), dluží 750, pošle 600.

```
Předpisy (ledger, bal_side=0, balance=Pohledávky):
  inv1  250   due 1.3.
  inv2  250   due 1.4.
  inv3  250   due 1.5.

Úhrada (ledger, bal_side=1): příjem 600 → nejdřív clearing „Nespárované",
matcher v bucketu (partner, Pohledávky, CZK):

  allocation: 600 → inv1 250  (inv1 uzavřen)
              → inv2 250  (inv2 uzavřen)
              → inv3 100  (inv3 reziduum 150, otevřen)

Matcher označí transakci jako spárovanou → bankovní engine ji přeúčtuje
clearing → 311 (jeden řádek 600 na partnera) → journalWritten → saldo
re-derivuje: clearing pohyb zmizí, vznikne 311 úhrada 600, allocations
(250/250/100) se navážou na ni.
```

Granularita 3-cestného rozúčtování žije **jen v allocations** — deník nese
jeden 311 řádek 600 Kč (bankovní engine nepotřebuje znát rozpad). Tím zůstává
saldo deník čistý a starý „chaos stejných VS" mizí: párujeme částkou a stářím,
ne přesnou shodou symbolu.

**Přegenerovat případ** = smaž auto allocations bucketu, spusť matcher znovu;
ruční allocations (`created_by=1`) se zachovají.

**Kontrakt matcher → bankovní engine:** matcher označí transakci jako
spárovanou (operation / příznak) → reaccount → 311/321. Detail (jak banka
pozná „matched") se doladí v Fázi 3.

Mimo Fázi 3 (další pozdější témata): zálohy (přijaté/poskytnuté, odpočty,
zdanění — účty 314/324/…900 jsou v osnově), zápočty, kurzové rozdíly (§6),
přeplatky/platby bez předpisu (zůstanou na clearingu nebo se stanou zálohou).

---

## 6. Měny a uzavření případu

Politika „každá částka v obou měnách" (z `accounting.md` §8) se propisuje do
salda: pohyb i allocation vedou `amount` (měna dokladu) i `amount_hc` (domácí).

Dvojí uzavření předpisu:

- **Obchodní saldokonto:** `Σ allocations.amount == request.amount` (měna
  dokladu) → faktura je **uhrazená** (zákazník zaplatil dohodnutou částku).
- **Účetní saldokonto:** `Σ allocations.amount_hc == request.amount_hc`
  (domácí) → účetně vyrovnáno. U cizoměnové faktury domácí strana dosedne až
  po zaúčtování **kurzového rozdílu**.

Reziduum v domácí měně po obchodním uzavření = kurzový rozdíl. **Generátor
kurzových rozdílů je mimo scope** (vlastní pozdější engine, vzor starého
`ExchDiffsEngine`); saldo ho jen vykáže jako otevřené účetní reziduum.

---

## 7. Účetní období a otevírací sekvence

Saldokonto funguje v rámci **účetního období** (`fiscal_year` na pohybu). Na
začátku období se neuhrazené případy „otevřou" sekvencí otevíracích účetních
dokladů — univerzální účetní princip (umožní změnu metodiky apod.).

**Generátor otevíracích dokladů je mimo scope tohoto návrhu.** Pro saldo z toho
plyne jen: otevírací předpisy přijdou jako **normální saldo pohyby** (zdroj =
otevírací doklad, prochází stejným generátorem z deníku). Tabulky nic
speciálního nepotřebují; `fiscal_year`-scoping ano.

---

## 8. Mimo scope

- **Matcher (alokační algoritmus)** — §5; samostatná Fáze 3 s vlastním designem.
- **Zálohy** — přijaté/poskytnuté, odpočet zálohy na fakturu, zdaněné zálohy
  (314900/324900). Účty jsou v osnově, skupiny v seedu; logika odpočtu je
  součást matcheru/pozdější fáze.
- **Zápočty** (vzájemné pohledávky/závazky), **kurzové rozdíly** (vlastní engine),
  **přeplatky / platby bez předpisu**.
- **Generátor otevíracích dokladů období** — §7.
- **Explicitní entita „případ"** — ruční seskupení faktur s různým VS do jednoho
  případu, poznámka k případu. Fáze 1–2 jede na odvozeném case (bucket); entita
  je možné rozšíření.
- **Příkazy k úhradě / upomínky / penalizace** — navazují na saldo, ale samostatně.

---

## 9. Fáze implementace

**Fáze 0 — prerekvizity** (mimo vlastní accbal) ✓ hotovo:

- symboly + splatnost do `economy_accounting_journal` + razítkování v obou
  enginech + `payment_reference` do `JournalViewer` filtrů/fulltextu (§3.5)
- přejmenování `symbol1/2/3 → payment_reference/specific_symbol/constant_symbol`
  na `economy_bank_transactions` + parsery + exchange + applier (§3.6)

> Událost `journalWritten` (§4.1) přesunuta z Fáze 0 do **Fáze 2a** — staví se
> až s prvním konzumentem (generátorem), ne spekulativně bez odběratele.

**Fáze 1 — nastavení saldokont** ✓ hotovo:

- modul `economy.accbal`, tabulky `economy_accbal_balances` (416),
  `economy_accbal_balance_accounts` (417) + formuláře + viewer
- seed skupin + účtů (vč. clearingu jako „Nespárované platby") + provisioner
- import/export nastavení (vzor starého `AccBalances{Import,Export}Wizard`)

**Fáze 2a — událost `journalWritten` (core)** ✓ hotovo:

- mechanismus `journalWritten` (interface + dispatcher + loader + registrace
  `journalEventHandlers`), zrcadlo `documentEventHandlers` (§4.1)
- emise z obou účtovacích enginů (po (pře)zápisu i vymazání deníku),
  proplumbování dispatcheru do míst konstrukce enginu

**Fáze 2b — generování pohybů z deníku** ✓ hotovo:

- tabulky `economy_accbal_ledger` (418), `economy_accbal_allocations` (419)
- handler na `journalWritten` → generátor pohybů (UPSERT dle stabilního klíče,
  §4.3) vč. clearing skupiny + beforeDelete úklid ledger/allocations
- viewer ledgeru (read-only, filtry: skupina, partner, VS, jen otevřené)

**Fáze 3 — matcher (samostatné sezení):**

- alokační algoritmus (FIFO/částka/signál VS), ruční úprava, přegenerace případu
- kontrakt s bankovním enginem (reaccount clearing → 311/321)
- UI párování + bucket pohled (kdo kolik dluží)

Pozdější: zálohy, zápočty, kurzové rozdíly, otevírací doklady období, explicitní
case entita.

---

## 10. Log rozhodnutí

1. Saldokonto = **vlastní modul `economy.accbal`** (závisí na accounting + bank),
   ne rozšíření accounting — ten zůstává „čistě deník".
2. Saldo čte **výhradně účetní deník** (§1.2). Žádný přímý read dokladů/transakcí
   při generování pohybů. Každý budoucí zdroj účtování krmí saldo zadarmo.
3. **Symboly + splatnost se doplní do deníku** (prerekvizita §3.5) — obrací část
   rozhodnutí #10 v `accounting.md` („saldo bez předpřípravy ve schématu").
   Důvod: drží §1.2, zlevňuje generátor (žádný join na zdroj) a dává cennou
   lidskou hodnotu (filtr deníku za VS). Levné a bezrizikové (hodnoty
   z hlavičky, grouping nedotčen).
4. Nastavení saldokont (skupiny + účty s MD/DAL + znaménko + filtr částky)
   přebráno ze starého Saldo2 — nutné kvůli sémantickému přesměrování
   (dobropis 311 záporně → Závazky, §3.2).
5. **Pohyb vs. párování odděleno**: `ledger` = ryzí pohyby (bez request/payment/
   residual), `allocations` = vazby úhrada↔předpis s rozúčtovanou částkou.
   Ruší starý párovací klíč zašitý do journal řádku → řeší „chaos stejných VS".
6. **Identita pohybu = stabilní klíč zdroje** `(source_kind, source_id, balance,
   bal_side, account_number)`, ne `journal_row.id` (ten je nestabilní přes
   DELETE+INSERT deníku). UPSERT podle něj drží allocations přes přeúčtování.
7. **Clearing varianta B**: clearing účty (261200/261300) jsou saldo-skupina
   „Nespárované platby"; matcher má jediný zdroj kandidátů (ledger), přechod
   clearing → 311 řeší re-derivace po `journalWritten`.
8. **Trigger = událost `journalWritten`** z účtovacích enginů, ne `stateChanged`.
   Drží „saldo zná jen deník" a automaticky pokrývá přeúčtování po spárování.
9. **Case je odvozený** (bucket `partner+balance+currency`), ne entita.
   Explicitní case entita je možné pozdější rozšíření.
10. Duální měna → dvojí uzavření (obchodní / účetní); generátor kurzových
    rozdílů mimo scope.
11. **Bankovní symboly přejmenovat** na `payment_reference/specific_symbol/
    constant_symbol` (varchar 35) — párovací klíč musí být porovnatelný
    napříč doklad↔transakce.
12. Matcher (alokační algoritmus) = samostatná Fáze 3 s vlastním designem.

---

## 11. Otevřené body

- **Mechanika `journalWritten`** — přesný tvar core události a registrace
  (synchronně v téže transakci jako zápis deníku, nebo po commitu?).
  Synchronně je konzistentnější; doladit v PRD Fáze 0.
- **Výkon hromadné re-derivace** — při migraci/účtování tisíců zdrojů běží
  generátor per zdroj. Zvážit dávkový režim (analogie `bank.md` §11
  „account all").
- **Skupina „Nespárované platby" a obousměrný clearing** — 261200 (příjmy) jen
  Pohledávkám, 261300 (výdaje) Závazkům; ověřit, že amounts_sign + acc_side
  pokryjí oba směry bez kolize.
- **Zaokrouhlení rozúčtování** — při rozpadu úhrady na více předpisů hlídat,
  že `Σ allocations == payment.amount` v obou měnách (haléřové dorovnání na
  poslední alokaci, vzor `accounting.md` §8).
