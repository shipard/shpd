# Tabulka: economy_bank_transactions

Bankovní transakce — **prvotřídní záznam** o jednom pohybu na bankovním účtu
firmy. Vzniká importem výpisu (Fáze 2) nebo migrací (Fáze 4), ne ručním
zakládáním (viewer nemá akci „nový"). Vlastní stavovou sadu
`economy.bank.txStates` (Nová 10 → Zaúčtováno 40 → V opravě 80 → Smazáno 90);
přechod do 40 spustí bankovní mikroengine (Fáze 3).

> Výpis (`economy_bank_statements`) je nepovinná evidenční/kontrolní vrstva —
> transakce na něj odkazuje přes `statement`, ale může dorazit dřív (např. přes
> API) a být nenavázaná.

## Sloupce

### Skupina `basic`

| Sloupec | Typ | Popis |
|---|---|---|
| `bank_account` | int → `economy_codebooks_bank_accounts` | Náš účet, na kterém pohyb nastal |
| `statement` | int NULL → `economy_bank_statements` | Výpis, do kterého transakce patří |
| `direction` | enumInt `economy.bank.txDirections` | 1 = Příjem (na účet), 2 = Výdaj (z účtu) |
| `date_transaction` | date | Datum zaúčtování bankou |
| `date_value` | date NULL | Datum valuty |

### Skupina `amount`

| Sloupec | Typ | Popis |
|---|---|---|
| `amount` | numeric(15,2) | Částka v měně účtu — **vždy kladná**; směr drží `direction` |
| `currency` | enumString(3) `world.base.currencies` | Měna transakce (= měna účtu) |
| `amount_dom` | numeric(15,2) | Částka v domácí měně; `beforeSave` ji dopočítá z `amount × exchange_rate` |
| `exchange_rate` | numeric(15,6) default 1 | Kurz měna → domácí (u domácí měny 1) |

### Skupina `counterparty`

| Sloupec | Typ | Popis |
|---|---|---|
| `counterparty_account` | varchar(40) NULL | Protiúčet (číslo/IBAN) dle banky |
| `counterparty_name` | varchar(150) NULL | Název protistrany dle banky |
| `partner` | int NULL → `base_persons_persons` | Dohledaná protistrana (dle protiúčtu) |

### Skupina `payment`

| Sloupec | Typ | Popis |
|---|---|---|
| `payment_reference` | varchar(35) NULL | Variabilní symbol (konvence dokladů; délka 35 pro RF/EndToEndId) |
| `specific_symbol` | varchar(20) NULL | Specifický symbol |
| `constant_symbol` | varchar(10) NULL | Konstantní symbol |
| `message` | varchar(250) NULL | Zpráva pro příjemce / poznámka |

### Skupina `accounting`

| Sloupec | Typ | Popis |
|---|---|---|
| `operation` | enumString(40) NULL `economy.bank.txOperations` | Co transakce znamená — řídí účtování (Fáze 3) |
| `accounting_state` | enumInt, system, `economy.accounting.accountingStates` | 0 neúčtováno / 1 zaúčtováno / 2 chyba (sdíleno s hlavičkou dokladu) |
| `accounting_messages` | json NULL, system | Chyby bankovního mikroenginu (Fáze 3) |

### Skupina `dedup`

| Sloupec | Typ | Popis |
|---|---|---|
| `external_id` | varchar(80) NULL | Stabilní ID transakce od banky (FIO `column22`, CAMT `AcctSvcrRef`/`EndToEndId`) |
| `fingerprint` | varchar(64) NULL | Hash pro dedup, když chybí `external_id` |

### Systémové (bez skupiny)

| Sloupec | Typ | Popis |
|---|---|---|
| `docState` | tinyint default 10 | Stav dokumentu (`economy.bank.txStates`) |
| `docStateMain` | tinyint default 1 | Sortovací sloupec stavů |

## Indexy

- `unq_external` UNIQUE na `(bank_account, external_id)`
- `unq_fingerprint` UNIQUE na `(bank_account, fingerprint)`
- `idx_account_date` na `(bank_account, date_transaction)`
- `idx_partner` na `(partner)`
- `idx_statement` na `(statement)`
- `idx_doc_state` na `(docStateMain, date_transaction)`

## Pravidla

- **Deduplikace** (Fáze 2): primárně `(bank_account, external_id)`; když banka
  stabilní ID nedává, fallback `(bank_account, fingerprint)`. Ve **Fázi 1** jsou
  `external_id` i `fingerprint` **nullable** a prázdné — plní je až ingestion.
  MariaDB povoluje víc NULL v unikátním indexu, takže testovací inserty bez nich
  nekolidují.
- `fingerprint` se **NEPOČÍTÁ** v `BankTransactionDocument` — je to ingestion
  concern (Fáze 2), hash z `(bank_account, date_transaction, amount, direction,
  counterparty_account, payment_reference, specific_symbol, message, pořadí v rámci dne)`.
- `direction` ∈ {1, 2} (povinné), `amount > 0`, `bank_account` / `currency` /
  `date_transaction` povinné — vynucuje `validate()`.
- `amount_dom` je derivované: `beforeSave` dopočítá `round(amount × exchange_rate, 2)`,
  pokud chybí (kurz default 1). `validate()` odmítá jen explicitně zápornou hodnotu.
- Invariant účtování: transakce má řádky v deníku (`economy_accounting_journal`,
  `source_kind = 'bankTransaction'`) právě tehdy, když je `accounting_state = 1`
  (zařídí mikroengine ve Fázi 3).

## Související

- [BankTransactionDocument](../src/BankTransactionDocument.php) — validace + dopočet `amount_dom`
- [forms/economy_bank_transactions.jsonc](../forms/economy_bank_transactions.jsonc) — edit form (operation/partner/message)
- [config/txStates.jsonc](../config/txStates.jsonc), [config/txOperations.jsonc](../config/txOperations.jsonc), [config/txDirections.jsonc](../config/txDirections.jsonc)
- [economy_bank_statements](economy_bank_statements.jsonc) — výpis (nepovinná evidence)
- [economy_accounting_journal](../../accounting/tables/economy_accounting_journal.jsonc) — deník (polymorfní zdroj přes `source_kind`/`bank_transaction`)
