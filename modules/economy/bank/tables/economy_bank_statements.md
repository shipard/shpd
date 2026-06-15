# Tabulka: economy_bank_statements

Bankovní výpis — **nepovinná** evidenční a kontrolní vrstva nad bankovními
transakcemi (`economy_bank_transactions`). Transakce na výpis odkazuje přes
`statement`, ale je na něm nezávislá (může dorazit dřív, např. přes API).
Archivní stavová sada `core.system.docStatesArchive`. Vzniká importem (Fáze 2)
nebo migrací (Fáze 4) — viewer nemá akci „nový".

## Sloupce

### Skupina `basic`

| Sloupec | Typ | Popis |
|---|---|---|
| `bank_account` | int → `economy_codebooks_bank_accounts` | Účet, ke kterému výpis patří |
| `statement_number` | varchar(40) NULL | Číslo výpisu od banky |
| `period_start` | date | Začátek období výpisu |
| `period_end` | date | Konec období výpisu |
| `reconciliation_state` | enumInt `economy.bank.reconciliationStates` default 0 | 0 nezkontrolováno / 1 souhlasí / 2 nesouhlasí |

### Skupina `amount`

| Sloupec | Typ | Popis |
|---|---|---|
| `opening_balance` | numeric(15,2) NULL | Počáteční zůstatek |
| `closing_balance` | numeric(15,2) NULL | Koncový zůstatek |
| `currency` | enumString(3) `world.base.currencies` | Měna výpisu |

### Systémové (bez skupiny)

| Sloupec | Typ | Popis |
|---|---|---|
| `docState` | tinyint default 10 | Stav dokumentu |
| `docStateMain` | tinyint default 1 | Sortovací sloupec stavů |

## Indexy

- `idx_account_period` na `(bank_account, period_end)`
- `idx_doc_state` na `(docStateMain, period_end)`

## Pravidla

- `bank_account` a `currency` povinné; `period_start <= period_end` — vynucuje
  `validate()`.
- **Kontrola úplnosti** (Fáze 2): `opening_balance + Σ(příjem) − Σ(výdaj) ==
  closing_balance` nad navázanými transakcemi → naplní `reconciliation_state`.
  Ve Fázi 1 zůstává `reconciliation_state` na 0 a ve formu je read-only.
- PDF výpisu se připojuje přes `core.attachments` (tab Přílohy ve formu,
  `tableId = 415`).

## Související

- [BankStatementDocument](../src/BankStatementDocument.php) — validace
- [forms/economy_bank_statements.jsonc](../forms/economy_bank_statements.jsonc) — edit form + tab příloh
- [economy_bank_transactions](economy_bank_transactions.jsonc) — transakce (prvotřídní záznam)
- [config/reconciliationStates.jsonc](../config/reconciliationStates.jsonc)
