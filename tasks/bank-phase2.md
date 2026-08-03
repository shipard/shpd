# Banka — Fáze 2: import výpisu ze souboru + deduplikace

**Stav:** hotovo

> **Stav: ✅ Hotovo.** Commity: `7f06ebf` (W1–W3 DTO + detekce formátu +
> parsery CAMT/GPC/FIO), `9cb28e4` (W4–W6 import service — dedup, fingerprint,
> reconciliace, partner), `33eb811` (W5.2 alert reconciliace),
> `083c3ae` (W7 CLI `bank-import-statement`), `1a673b3` (W8 backend endpoint),
> `92cc98a` (W8 UI akce Importovat výpis).
>
> Pozn.: vznik transakce byl ve Fázi 2 raw insertem (stav 10, bez účtování);
> Fáze 4 (`fd1f72a`) ho převedla na dokumentovou vrstvu — chování souborového
> importu beze změny.

## Kontext

Fáze 1 položila datový model (`economy_bank_transactions` tableId 414,
`economy_bank_statements` tableId 415, generalizovaný deník, číselníky stavů
a pohybů, clearing účty). Fáze 2 přivádí **data**: import bankovního výpisu ze
souboru, rozpad na jednotlivé transakce, deduplikaci a kontrolu úplnosti
zůstatkovým můstkem. Žádné účtování (to je Fáze 3) ani API konektory (pozdější
fáze) — transakce vznikají ve stavu **Nová (10)**.

Architektura starého Shipardu (`e10doc/bank/ebanking`) je vzorem pro
**parsery** (detekce formátu, charset, per-formát parsing), ale **orchestrace
se zásadně mění**: starý systém vytvářel doklad `bank` s řádky, pároval osoby
proti saldu (`e10doc_balance_journal`) a re-import nahrazoval celý doklad
(`replaceDocumentNdx`). My místo toho deduplikujeme na úrovni **jednotlivé
transakce** a saldo párování **neděláme** (odložené).

Osm pracovních balíčků:

- **W1** — neutrální mezistruktura (DTO) + rozhraní parseru
- **W2** — detekce formátu + charset + registr parserů
- **W3** — port parserů: CAMT (`cba-xml`), GPC, FIO JSON
- **W4** — import service: dedup + vznik transakcí + výpis
- **W5** — zůstatkový můstek (`reconciliation_state`) + alert
- **W6** — dohledání partnera dle protiúčtu
- **W7** — CLI příkaz `bank-import-statement`
- **W8** — REST endpoint + upload z UI

## Návaznost

- Návrhový dokument: `docs/bank.md` §4 (ingestion, dedup, kontrolní vrstva,
  dohledání partnera) — **závazný**.
- Staví na schématu Fáze 1 (skutečné názvy sloupců ověřené:
  `direction` 1/2, `amount` kladná, `amount_dom`, `exchange_rate`,
  `external_id`/`fingerprint` nullable, unique `(bank_account, external_id)`
  a `(bank_account, fingerprint)`, `operation` nullable, stav 10 Nová).
- **Fáze 3** (mikroengine) zaúčtuje transakce při přechodu do stavu 40 —
  Fáze 2 jim jen nastaví `operation` default dle směru (viz W4) a nechá je
  ve stavu 10.
- Saldo párování (VS proti otevřeným fakturám) je odložené — Fáze 2 dělá
  **pouze** dohledání partnera dle čísla protiúčtu (saldo-nezávislé).

## Před implementací přečti

**Starý Shipard (projekt `old_shipard`) — vzor parserů a orchestrace:**

- `modules/e10doc/bank/bank.php` — třída `ebankingImportDoc` + funkce
  `createImportObject` (detekce formátu z `formats.json` + charset přes
  `iconv`). Metody k **portu**: `setHeadInfo`/`setRowInfo`/`appendRow`
  (akumulace; `setRowInfo` pro `memo` přeskakuje duplicitní po sobě jdoucí
  řádky — zachovat), `substr` (mb-aware trim), `parseNumber` (CZ formát:
  mezera tisíce, čárka desetinná), `parseDate`. Metody k **náhradě**:
  `checkBankAccount` (zachovat koncept — viz W4), `checkRowPerson` /
  `checkRowValues` (SALDO matching — **NEPORTOVAT**, jen poslední blok
  protiúčet→osoba viz W6), `createDocHead`/`createDocRow`/`saveDoc`
  (doklad+řádky → nahradit výpis+transakce, viz W4).
- `modules/e10doc/bank/ebanking/formats.json` — detekční regexpy + `srcCharset`
- `modules/e10doc/bank/ebanking/cz/cba-xml/import.php` — CAMT/ISO 20022
  (multi-statement `Stmt[]`, `CdtDbtInd` DBIT/CRDT pro znaménko, VS/SS/KS
  z `EndToEndId`/`PmtInfId`/`RmtInf/Strd`, `RmtInf/Ustrd`/`AddtlTxInf` memo)
- `modules/e10doc/bank/ebanking/cz/gpc/import.php` — fixní-šířkový (074 head /
  075 row / 078,079 memo continuation), `mod11` + `getAccountNumber` dekódování
- `modules/e10doc/bank/ebanking/cz/fio-json/import.php` — `column22` =
  `bankTransId` (stabilní ID, starý zakomentoval — **my použijeme** jako
  `external_id`)

**Nový Shipard (projekt `nov_shipard`) — vzory a cíle:**

- `modules/economy/bank/tables/economy_bank_transactions.jsonc` + `.md`,
  `economy_bank_statements.jsonc` + `.md` — cílové tabulky
- `modules/economy/bank/src/BankTransactionDocument.php`,
  `BankStatementDocument.php` — validace; insert přes document layer
- `modules/economy/codebooks/src/BankAccountDocument.php` +
  `tables/economy_codebooks_bank_accounts.jsonc` (sloupce `account_number`,
  `iban`, `ebanking_id` z Fáze 1) — match našeho účtu (W4)
- `modules/base/persons/tables/base_persons_bank_accounts.jsonc` — protiúčty
  kontaktů (W6 dohledání partnera)
- `src/Api/Router.php` (řádek ~176, `/_accounting/reaccount`) — vzor
  registrace endpointu (W8)
- `modules/economy/accounting/src/AccountingController.php` — vzor controlleru
  (`Request`/`Response`, `Response::success`/`error`)
- `modules/core/attachments/src/AttachmentService.php` — `upload(tableId,
  recordId, originalName, tmpPath, userId)` (W8 uložení zdrojového souboru
  k výpisu + provenience)
- `modules/economy/accounting/src/Checks/AccountingErrorsCheck.php` — vzor
  `AlertCheck` (W5 reconciliation alert)
- `src/Command/DataSource/AlertsRunCommand.php` (a okolní) — vzor Symfony
  Console příkazu; `bin/shpd-ds` registrace; Testable* subclass pro testy
- `docs/bank.md`, `docs/attachments.md`, `docs/alerts.md`

## Scope

### V scope

- neutrální DTO + rozhraní parseru + registr + detekce formátu + charset
- port parserů CAMT (`cba-xml`), GPC, FIO JSON
- import service: idempotentní dedup (`external_id` + `fingerprint`), vznik
  transakcí ve stavu 10, vznik/propojení výpisu, uložení zdrojového souboru
- zůstatkový můstek + `reconciliation_state` + alert check
- dohledání partnera dle čísla protiúčtu
- CLI příkaz + REST endpoint + minimální upload z UI
- integrační testy s fixture soubory per formát

### Mimo scope

- **účtování** transakcí (mikroengine, deník) — Fáze 3
- ostatní formáty (MT940, ČSOB, ČS, KB, RB) — následný balíček po ověření
  vzoru na CAMT/GPC/FIO; rozhraní parseru je ale musí pojmout
- **API konektory** (FIO token, ČS/Erste OAuth2) — pozdější fáze
- **saldo** párování (VS → otevřené faktury) — pozdější fáze; W6 dělá jen
  protiúčet→osoba
- kurzové rozdíly cizoměnových účtů — viz Otevřené body (FX)
- migrace ze starého Shipardu — Fáze 4

---

## Co implementovat

Vše v modulu `economy.bank`, namespace `Shipard\Module\Economy\Bank\`.
Doporučený podadresář `src/Import/`.

### W1 — Neutrální mezistruktura + rozhraní parseru

**W1.1** DTO (immutable value objects, `src/Import/`):

```
ParsedStatement
  bankAccountRef: string      // identifikátor NAŠEHO účtu z výpisu (IBAN / domácí / accountId)
  statementNumber: ?string    // docOrderNumber / LglSeqNb / idList
  periodStart: \DateTimeImmutable
  periodEnd: \DateTimeImmutable
  openingBalance: float
  closingBalance: float
  currency: ?string           // CAMT má; GPC/FIO → null (doplní se z účtu)
  transactions: ParsedTransaction[]

ParsedTransaction
  externalId: ?string         // stabilní ID od banky (FIO column22, CAMT AcctSvcrRef); null = není
  amount: float               // ZNAMÉNKOVÁ (−  = výdaj); import ji rozloží na amount+direction
  dateTransaction: \DateTimeImmutable   // datum zaúčtování (CAMT BookgDt; GPC/FIO jediné datum)
  dateValue: ?\DateTimeImmutable        // datum valuty (CAMT ValDt)
  counterpartyAccount: ?string
  counterpartyName: ?string
  symbol1/symbol2/symbol3: ?string      // VS/SS/KS, normalizované (bez leading nul, prázdné místo "0"/"0000")
  message: ?string            // memo (sloučené řádky, bez duplicit)
  raw: array                  // surový parser payload — pro fingerprint a debug
```

**W1.2** Rozhraní `StatementParser`:

```php
interface StatementParser {
    /** @return ParsedStatement[]  (CAMT/GPC mají více výpisů v souboru) */
    public function parse(string $text): array;
}
```

Parser pracuje nad **už dekódovaným UTF-8** textem (charset řeší registr, W2).
Vrací pole `ParsedStatement` (jeden soubor = N výpisů).

### W2 — Detekce formátu + charset + registr

**W2.1** Config `config/statementFormats.jsonc` → cfgItem
`economy.bank.statementFormats` — port `formats.json` (id, name, `checkRegExp`,
volitelně `checkRegExp2`, `srcCharset`). Pro Fázi 2 stačí záznamy pro
`cz.cba-xml`, `cz.gpc`, `cz.fio-json`; ostatní doplnit s parsery později.

**W2.2** `src/Import/StatementFormatDetector.php` — port `createImportObject`:
projde formáty, matchne regexp nad surovým textem (POZOR: regexp se hodnotí nad
**surovým** bytestreamem, ne dekódovaným — viz starý kód), vrátí `{formatId,
srcCharset}`. Charset konverze `iconv($srcCharset, 'UTF-8', $raw)` jen když je
`srcCharset` uvedený (GPC/RB/ČS/ČSOB-SLK = CP1250; CAMT/FIO/MT940 = UTF-8).
Nerozpoznaný formát → vyhodit doménovou výjimku s jasnou hláškou.

**W2.3** `src/Import/StatementParserRegistry.php` — mapuje `formatId` →
instanci parseru (`cz.cba-xml` → `Parsers\CbaXmlParser`, atd.).

### W3 — Port parserů

Podadresář `src/Import/Parsers/`. Každý parser je čistá transformace
text → `ParsedStatement[]` (žádný přístup k DB, žádné účty/osoby — to je
import service ve W4). Sdílené helpery (`parseNumberCz`, `mbSubstrTrim`,
mod11/getAccountNumber pro GPC) do traitu nebo `ParserUtils`.

**W3.1 `CbaXmlParser`** (CAMT/ISO 20022) — port `cba-xml/import.php` s úpravami:
- multi-statement (`Stmt[]`), `Acct/Id/IBAN` → `bankAccountRef`,
  `FrToDt` → perioda, `LglSeqNb` → `statementNumber`, `Bal[0/1]/Amt` →
  opening/closing, měna z `Acct/Ccy` nebo `Amt/@Ccy` → `currency`
- znaménko z `CdtDbtInd` (DBIT → záporná)
- **`dateTransaction` z `BookgDt`** (ne jen `ValDt`!), `dateValue` z `ValDt`
- **`externalId` z `Ntry/NtryRef` nebo `AcctSvcrRef`** (starý parser je
  ignoroval — doplnit; fallback `EndToEndId`, pokud unikátní)
- VS/SS/KS z `EndToEndId`/`PmtInfId`/`RmtInf/Strd` (port logiky prefixů),
  memo z `RmtInf/Ustrd` + `AddtlTxInf`
- protiúčet z `RltdPties/DbtrAcct` + `RltdAgts/DbtrAgt`, `counterpartyName`
  z `RltdPties/Dbtr/Nm` / `Cdtr/Nm` (doplnit — starý nebral)

**W3.2 `GpcParser`** — port `gpc/import.php`: 074 head / 075 row / 078,079
memo continuation; `mod11` + `getAccountNumber` (permutace ABO formátu)
zachovat 1:1; znaménko z typu pohybu (1,5 = výdaj); `externalId = null`
(GPC stabilní ID nemá → spoléhá na fingerprint); jediné datum → `dateTransaction`.

**W3.3 `FioJsonParser`** — port `fio-json/import.php`: `accountStatement/info`
→ hlavička, `transactionList/transaction[]` → řádky; **`column22` →
`externalId`** (odkomentovat to, co starý systém zahodil); `column0` → datum,
`column1` → částka, `column2`+`column3` → protiúčet, `column5/6/4` → VS/SS/KS,
`column10`/`column7` název protistrany, memo z `column16/7/8/10`.

### W4 — Import service

**W4.1** `src/Import/StatementImportService.php` — orchestrace:

1. **Detekce + parse** (W2/W3) → `ParsedStatement[]`.
2. Pro každý `ParsedStatement`:
   - **Náš účet** (port `checkBankAccount`): match `bankAccountRef` proti
     `economy_codebooks_bank_accounts` přes `account_number` OR `iban` OR
     `ebanking_id` (normalizovat — strip mezery/pomlčky pro porovnání čísla).
     Nenalezeno → chyba importu s jasnou hláškou (číslo musí být v některém
     vlastním bank. spojení), tento výpis přeskočit. Volitelný explicitní
     `bankAccountId` (z CLI/endpointu) override.
   - **Výpis**: najít existující `economy_bank_statements` dle
     `(bank_account, statement_number)` nebo `(bank_account, period_start,
     period_end)`; když není, vytvořit (stav archiv-concept). Currency
     z účtu, pokud parser nedal.
   - **Transakce**: pro každou `ParsedTransaction`:
     - `direction` = `amount < 0 ? 2 : 1`, `amount = abs(amount)`
     - `currency` = měna účtu; `exchange_rate` = 1 (domácí) / lookup (FX,
       viz Otevřené body); `amount_dom = round(amount × rate, 2)`
     - `external_id` z DTO; **`fingerprint`** = W4.2
     - `operation` default dle směru: `payment.in` / `payment.out`
       (Fáze 3 dle něj zaúčtuje na clearing; uživatel může přepsat)
     - **dedup**: existuje-li transakce dle `(bank_account, external_id)`
       (když external_id) nebo `(bank_account, fingerprint)` → **přeskočit**
       (volitelně doplnit chybějící `statement`/`external_id`); jinak insert
       ve stavu **10 (Nová)**
   - **Partner** (W6) pro nově vložené transakce.
   - **Reconciliation** (W5) výpisu.
   - **Zdrojový soubor** uložit jako přílohu výpisu přes `AttachmentService`
     (provenience — vzor `docs/attachments.md`).
3. Vrátit souhrn: `{statements: [...], created, skipped, unmatchedPartner,
   reconciliation}`.

Vše per-výpis v transakci (rollback při chybě jednoho výpisu neshodí ostatní —
nebo per soubor, rozhodnout; preferuj per-výpis).

**W4.2 Fingerprint** — `sha256` z normalizovaných polí spojených oddělovačem:
`bank_account | date_transaction(Y-m-d) | direction | amount(2 des.) |
counterparty_account | symbol1 | symbol2 | message | seqInDay`.
`seqInDay` = pořadové číslo transakce v rámci `(bank_account,
date_transaction)` v aktuální dávce (řeší dvě identické transakce v jednom dni).
Idempotence: opětovný import téhož souboru → všechny transakce `skipped`.

### W5 — Zůstatkový můstek + alert

**W5.1** Po importu výpisu spočítat:
`opening_balance + Σ(amount kde direction=1) − Σ(amount kde direction=2)`
nad transakcemi navázanými na výpis; porovnat s `closing_balance` (tolerance
na haléře). Nastavit `reconciliation_state`: 1 souhlasí / 2 nesouhlasí
(0 zůstává, dokud výpis nemá zůstatky). Config `reconciliationStates.jsonc`
už z Fáze 1 existuje.

**W5.2** Alert check `src/Checks/StatementReconciliationCheck.php` (vzor
`AccountingErrorsCheck`): výpisy s `reconciliation_state = 2`, jeden finding
per výpis (`finding_key = id`, subject tableId **415**), severity `warning`.
Registrovat v `module.jsonc` `alertChecks` (interval např. `1h`, tag `bank`).
Reconciler auto-resolvuje, jakmile výpis přestane vyhovovat (doplnění chybějící
transakce → můstek sedne).

### W6 — Dohledání partnera dle protiúčtu

**W6.1** `src/Import/PartnerResolver.php` — port **jen posledního bloku**
`checkRowPerson`: pro nově vloženou transakci s `counterparty_account` najít
v `base_persons_bank_accounts` osobu dle čísla účtu / IBANu (normalizované
porovnání). Právě jedna shoda → nastavit `partner`. Žádná / víc shod →
nechat prázdné (do souhrnu `unmatchedPartner`). **Žádné saldo** (VS proti
fakturám) — to je pozdější fáze.

### W7 — CLI příkaz

**W7.1** `src/Command/DataSource/BankImportStatementCommand.php` (vzor
okolních DS příkazů), registrace v `bin/shpd-ds`:

```
shpd-ds bank-import-statement <ds> <file> [--account=<code|id>]
```

Načte soubor, zavolá `StatementImportService`, vypíše souhrn (výpisy, počet
vytvořených/přeskočených transakcí, nespárovaný partner, výsledek
reconciliace). Pro testy `Testable`-subclass (vzor z memory: testování přes
podtřídu, ne mock).

### W8 — REST endpoint + upload z UI

**W8.1** Endpoint `POST /_bank/import-statement` (multipart): zaregistrovat
v `src/Api/Router.php` (vzor `/_accounting/reaccount` → `Route('bank',
'importStatement')`). `src/BankController.php` (vzor `AccountingController`):
přijmout nahraný soubor (tmp path), zavolat `StatementImportService`, vrátit
`Response::success` se souhrnem. Validace: typ/velikost souboru; multipart
parsing jako u příloh (`docs/attachments.md`, `api/attachments.js` — přímý
`fetch` s Bearer, ne JSON `apiRequest`).

**W8.2** Minimální UI: v `BankStatementsViewer` toolbar akce „Importovat
výpis" → upload souboru → volání endpointu → refresh + souhrn (kolik transakcí
přibylo, stav reconciliace). Polishovaný náhled/preview (validate→preview→apply
jako exchange) je **mimo scope** — stačí přímý import se souhrnem.

---

## Hotovo když

> Všechny body splněny ✅

1. `bank-import-statement` naimportuje vzorový **CAMT**, **GPC** i **FIO**
   soubor: vznikne výpis + N transakcí ve stavu Nová, `direction`/`amount`
   správně dle znaménka, symboly normalizované, memo sloučené.
2. **Idempotence**: druhý import téhož souboru → 0 nových transakcí
   (vše `skipped`), žádné duplicity. FIO využije `external_id` (`column22`),
   GPC `fingerprint`.
3. **Zůstatkový můstek**: u souboru se sedícími zůstatky `reconciliation_state
   = 1`; u uměle porušeného (smazaná transakce) `= 2` a vznikne alert
   (subject 415); po doplnění transakce se alert auto-resolvuje.
4. **Partner**: transakce s protiúčtem odpovídajícím bankovnímu spojení
   kontaktu (právě jedna shoda) dostane `partner`; víc/žádná shoda → prázdné.
5. **Náš účet**: výpis pro neznámý účet → srozumitelná chyba, transakce
   nevzniknou; účet se matchuje přes `account_number`/`iban`/`ebanking_id`.
6. **Charset**: GPC s diakritikou v memo (CP1250) se naimportuje bez
   poškození textu.
7. Zdrojový soubor je uložen jako příloha výpisu.
8. REST endpoint `POST /_bank/import-statement` i UI akce v seznamu výpisů
   importují a vrátí souhrn.
9. Integrační testy (fixtures CAMT/GPC/FIO) zelené: parsing, dedup idempotence,
   reconciliation pass/fail, partner match, charset. Existující testy
   neporušené.

## Doporučené pořadí

1. W1 (DTO + rozhraní) — čistá doména, bez DB
2. W3 parsery + jejich unit testy nad fixtures (čistá transformace — testuje se
   nejsnáz samostatně) — souběžně W2 (detekce/charset/registr)
3. W4 import service + W4.2 fingerprint + dedup + integrační test idempotence
4. W6 partner + W5 reconciliation + alert
5. W7 CLI (zpřístupní ruční ověření na reálných souborech)
6. W8 endpoint + UI
7. Commit per balíček (DTO+parsery / service+dedup / reconcile+partner /
   CLI / endpoint+UI)

## Rozhodnutí ✓

- Parsery jsou čistá transformace text → `ParsedStatement[]` (bez DB);
  orchestrace (účty, dedup, partner, výpis) je v import service.
- Dedup na úrovni transakce: `(bank_account, external_id)` primárně,
  `fingerprint` (sha256 + seqInDay) fallback. Re-import je idempotentní.
- `dateTransaction` = datum zaúčtování (CAMT `BookgDt`), `dateValue` = valuta
  (`ValDt`); kde má formát jen jedno datum, jde do `dateTransaction`.
- `externalId`: FIO `column22`, CAMT `AcctSvcrRef`/`Ntry/NtryRef` (oba starý
  systém ignoroval — doplňujeme); GPC nemá → fingerprint.
- Transakce vznikají ve stavu **10 (Nová)**, `operation` default dle směru
  (`payment.in`/`payment.out`); účtování je Fáze 3.
- Partner jen dle protiúčtu (saldo-nezávislé); saldo VS→faktura odložené.
- Náš účet se matchuje přes `account_number`/`iban`/`ebanking_id` (port
  konceptu `checkBankAccount`).
- Začínáme třemi formáty (CAMT/GPC/FIO); rozhraní pojme zbytek později.
- Import přes CLI i REST; preview pipeline (jako exchange) mimo scope.

## Otevřené body

- **FX cizoměnové účty** — `exchange_rate`/`amount_dom` pro účet v cizí měně:
  má nový systém službu kurzů (jako starý `e10utils::exchangeRate`)? Pokud ne,
  pro Fázi 2 `rate = 1` + `amount_dom = amount` a poznámka do backlogu (FX
  rozdíly jsou stejně z větší části odložené); pokud ano, lookup dle
  `date_transaction`. Ověřit při W4.
- **Fixture soubory** — potřebujeme 1–2 reálné (anonymizované) vzorky per
  formát od Davida; bez nich se parsery testují jen syntetickými daty
  odvozenými ze struktury.
- **Měna výpisu vs. účtu** — když CAMT nese jinou měnu než má účet
  v číselníku: chyba, nebo přebít z výpisu? (Spíš varovat + použít měnu účtu.)
- **statement_number kolize** — když banka pošle stejné číslo výpisu pro
  jinou periodu / re-export: klíč výpisu `(bank_account, statement_number)`
  vs. `(bank_account, period_start, period_end)` — potvrdit, který je vodící.
- **Více výpisů v jednom souboru** (CAMT/GPC) — transakce/rollback per výpis
  vs. per soubor; doporučení per výpis (jeden vadný neshodí ostatní).
