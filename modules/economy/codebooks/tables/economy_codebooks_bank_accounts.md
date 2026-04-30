# Tabulka: economy_codebooks_bank_accounts

Vlastní bankovní účty firmy se stavy dokumentů
(`core.system.docStatesArchive`). Záznamy vznikají ručně přes UI jako
`Koncept` (10), uživatel je manuálně přepne do `V pořádku` (40).
Dokladový modul (přijde později) bude na hlavičce bankovního dokladu
i na faktuře s předkontací na bankovní úhradu držet referenci na
konkrétní účet.

> Tabulka modeluje účty firmy. Bankovní účty kontaktů (dodavatelé,
> odběratelé) jsou v modulu `base.persons` — tabulka
> `base_persons_bank_accounts`.

## Sloupce

### Skupina `identity`

| Sloupec | Typ | Popis |
|---|---|---|
| `code` | varchar(10), UNIQUE | Krátký kód pro selecty (`CSOB1`, `EUR`) |
| `name` | varchar(150) | Název účtu |
| `notice` | varchar(250) NULL | Poznámka (číslo smlouvy s bankou apod.) |

### Skupina `account`

| Sloupec | Typ | Popis |
|---|---|---|
| `bank_name` | varchar(150) NULL | Název banky (lidsky čitelný; nedopočítává se) |
| `account_number` | varchar(40) NULL | Domácí formát (např. `19-2000145399/0800`) |
| `iban` | varchar(34) NULL | IBAN — uppercase v DB |
| `bic` | varchar(11) NULL | BIC/SWIFT — uppercase v DB |

### Skupina `settings`

| Sloupec | Typ | Popis |
|---|---|---|
| `currency` | varchar(3) default `'czk'` | ISO 4217 lowercase |
| `is_default` | boolean default 0 | Výchozí účet pro danou měnu — vynucené unikátně per měna v `afterPersist` |
| `valid_from` | date NULL | Platnost od |
| `valid_to` | date NULL | Platnost do |
| `sort_order` | smallint default 0 | Pořadí pro řazení ve výpisu |

### Systémové (bez skupiny)

| Sloupec | Typ | Popis |
|---|---|---|
| `docState` | tinyint default 10 | Stav dokumentu |
| `docStateMain` | tinyint default 1 | Sortovací sloupec stavů |

## Indexy

- `unq_code` UNIQUE na `code`
- `idx_sort_order` na `sort_order ASC, name ASC`
- `idx_doc_state` na `docStateMain ASC, sort_order ASC`
- `idx_iban` na `iban` — připravený lookup pro budoucí SEPA modul
  (rozpoznání účtu podle IBANu z příchozí platby)

## Pravidla

- `code` je UNIQUE.
- Musí být vyplněn alespoň jeden z údajů: `account_number` NEBO `iban`
  (oba zároveň prázdné = error `required_one_of` na `account_number`).
  Pokrývá to CZ-only účty (jen `account_number`) i čistě zahraniční
  (jen `iban`).
- `iban` (pokud vyplněný) musí matchovat regex
  `^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$` — basic shape, **bez** mod-97
  checksum kontroly. Pokročilejší validace lze dodat později (např.
  IBAN.com lib).
- `bic` (pokud vyplněný) musí matchovat regex
  `^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$` (8 nebo 11 znaků v bankovním formátu).
- `iban` i `bic` se v `beforeSave` automaticky převádějí na uppercase
  (uživatel může zadat lowercase). Validace probíhá v `validate()`
  proti uppercase verzi (volá `strtoupper(trim(...))` jen pro porovnání,
  data se přepíší až v `beforeSave`, který běží AŽ PO `validate`).
- `currency` lowercase ISO 4217 (regex `^[a-z]{3}$`).
- `is_default = 1` je unikátní **per měna** — při uložení záznamu jako
  default `BankAccountDocument::afterPersist` automaticky odznačí
  ostatní defaulty se stejnou měnou. Logika běží v transakci se save,
  takže je atomická.
- `valid_from <= valid_to`.

### Pojmenování `bic` (ne `swift`)

Sloupec se jmenuje `bic` pro konzistenci s
`base_persons_bank_accounts`, který stejnou konvenci zavedl jako první.
V UI se zobrazuje jako "BIC/SWIFT" — uživatelsky srozumitelný popisek
nese form a TableDefinition. SWIFT a BIC jsou de facto synonymní:
ISO 9362 BIC kód je tentýž identifikátor, který spravuje SWIFT.

## Související

- [BankAccountDocument](../src/BankAccountDocument.php) — validace + default-per-currency uniqueness
- [forms/economy_codebooks_bank_accounts.jsonc](../forms/economy_codebooks_bank_accounts.jsonc) — deklarativní edit form
- [base_persons_bank_accounts](../../../base/persons/tables/base_persons_bank_accounts.jsonc) — bankovní účty kontaktů (jiná entita, stejná konvence pojmenování polí)
- Hlavička bankovního dokladu i faktury s bankovní úhradou (přijdou
  s dokladovým modulem) budou obsahovat FK na tuto tabulku.
