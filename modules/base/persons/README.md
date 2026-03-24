# Modul: Osoby (base.persons)

Modul spravuje fyzické osoby i firmy — dodavatele, odběratele, zaměstnance
a další subjekty, se kterými systém pracuje. Ke každé osobě lze evidovat
kontaktní osoby/místa a bankovní účty.

## Závislosti

- `core.system`

## Tabulky

| Tabulka | Popis |
|---|---|
| [base_persons_persons](tables/base_persons_persons.md) | Hlavní evidence osob a firem |
| [base_persons_contacts](tables/base_persons_contacts.md) | Kontaktní osoby a kontaktní místa |
| [base_persons_bank_accounts](tables/base_persons_bank_accounts.md) | Bankovní účty |

## Zdrojové soubory

| Soubor | Popis |
|---|---|
| [PersonDocument.php](src/PersonDocument.php) | Dokumentová třída — validace a before-save logika |
| [PersonType.php](src/PersonType.php) | PHP enum `PersonType` |

## Konfigurace

| Klíč | Soubor | Popis |
|---|---|---|
| `base.persons.personTypes` | [config/personTypes.jsonc](config/personTypes.jsonc) | Číselník typů osob (neurčeno / FO / firma) |
| `base.persons.bankAccountSources` | [config/bankAccountSources.jsonc](config/bankAccountSources.jsonc) | Zdroje bankovních účtů (ruční / transakce / registr DPH) |
