# Tabulka: Osoby (base_persons_persons)

Hlavní evidence osob v systému. Tabulka ukládá jak fyzické osoby, tak firmy
do jedné struktury — typ záznamu určuje sloupec `person_type`, který řídí
validaci i chování formuláře.

## Struktura

Sloupce jsou organizovány do skupin:

### Identifikace (identity)

| Sloupec | Typ | Popis |
|---|---|---|
| `person_id` | varchar(10), NOT NULL, UNIQUE | Unikátní kód osoby, slouží jako lidsky čitelný identifikátor |
| `person_type` | enumInt | Typ osoby — viz [personTypes.jsonc](../config/personTypes.jsonc) a PHP enum `PersonType` |
| `company_id` | varchar(30) | IČO — relevantní u firem |
| `tax_id` | varchar(30) | DIČ |
| `vat_id` | varchar(30) | DIČ pro DPH |

### Jméno (name)

| Sloupec | Typ | Popis |
|---|---|---|
| `full_name` | varchar(200), NOT NULL | Celý název — u firmy se zadává přímo, u osoby se skládá automaticky |
| `complex_name` | boolean | Zapne rozšířená pole jména (tituly, prostřední jméno) |
| `title_before` | varchar(50) | Tituly před jménem |
| `first_name` | varchar(100), NOT NULL | Křestní jméno |
| `middle_name` | varchar(100) | Prostřední jméno |
| `last_name` | varchar(100), NOT NULL | Příjmení |
| `title_after` | varchar(50) | Tituly za jménem |

### Osobní údaje (personal)

| Sloupec | Typ | Popis |
|---|---|---|
| `birth_date` | date | Datum narození |
| `national_id` | varchar(30) | Rodné číslo |
| `id_card_number` | varchar(30) | Číslo osobního dokladu |

### Kontaktní údaje (contact)

| Sloupec | Typ | Popis |
|---|---|---|
| `email` | varchar(200) | E-mail |
| `phone` | varchar(30) | Telefon |
| `web` | varchar(200) | Webová stránka |

### Stav (status)

| Sloupec | Typ | Popis |
|---|---|---|
| `is_closed` | boolean | Příznak uzavřeného záznamu |
| `closed_date` | date | Datum uzavření |

## Obchodní logika (PersonDocument)

Dokumentová třída [PersonDocument.php](../src/PersonDocument.php) implementuje
hooky `validate` a `beforeSave`, které řídí chování podle typu osoby.

### Firma (person_type = 2)

- Při zadávání se vyplňuje `full_name` jako název firmy.
- `first_name`, `last_name`, `title_before`, `middle_name`, `title_after` se při uložení automaticky vyprázdní.
- Nastaví se jen `last_name` = `full_name`.
- Validace vyžaduje vyplněný `full_name`.
- Skupina `personal` (datum narození, rodné číslo, číslo dokladu) se v UI
  nezobrazuje — tyto sloupce nemají u firmy smysl.

### Fyzická osoba (person_type = 1)

- Validace vyžaduje vyplněné `first_name` i `last_name`.
- Skupina `personal` se zobrazuje — sloupce jsou nepovinné, vyplňují se
  dle potřeby (např. zaměstnanci, kde je potřeba rodné číslo).
- Chování při uložení závisí na hodnotě `complex_name`:

**complex_name = 0 (výchozí):**
- Zadává se pouze `first_name` a `last_name`.
- `title_before`, `middle_name` a `title_after` se při uložení nastaví
  na prázdný řetězec.
- `full_name` se složí jako `first_name + " " + last_name`.

**complex_name = 1 (rozšířený režim):**
- Zadává se všech pět sloupců: `title_before`, `first_name`, `middle_name`,
  `last_name`, `title_after`.
- `full_name` se při uložení sestaví ze všech vyplněných částí — nevyplněné
  se přeskočí, aby v názvu nevznikaly mezery navíc.

### Společné

- Sloupec `person_type` je vždy povinný — hodnota `Undefined` (0) neprojde
  validací.
- Sloupec `person_id` se generuje automaticky při prvním uložení záznamu —
  krátký alfanumerický hash (písmena + číslice, cca 5 znaků). Slouží
  k jednoznačné identifikaci na tištěných sestavách (faktury, dodací listy),
  kde může dojít k záměně u duplicitních jmen.

## Indexy

| Index | Typ | Sloupce | Poznámka |
|---|---|---|---|
| `unq_person_id` | unique | `person_id` | Unikátní kód osoby |
| `idx_full_name` | index | `full_name` | |
| `idx_last_name` | index | `last_name` ASC, `first_name` ASC | Řazení podle příjmení |
| `idx_company_id` | index | `company_id` | Vyhledávání podle IČO |
| `idx_email` | index | `email` | |
| `ft_full_name` | fulltext | `full_name` | Fulltextové vyhledávání |

## Návaznosti

| Tabulka | Vazba | Popis |
|---|---|---|
| [base_persons_contacts](base_persons_contacts.md) | `contacts.person` → `persons.id` | Kontaktní osoby a kontaktní místa přiřazená k osobě/firmě |
| [base_persons_bank_accounts](base_persons_bank_accounts.md) | `bank_accounts.person` → `persons.id` | Bankovní účty osoby/firmy |
