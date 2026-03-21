# Modul: Osoby (base.persons)

Modul spravuje fyzické osoby i firmy — dodavatele, odběratele, zaměstnance
a další subjekty, se kterými systém pracuje.

## Závislosti

- `core.system`

## Tabulky

| Tabulka | Popis |
|---|---|
| [base_persons_persons](tables/base_persons_persons.md) | Hlavní evidence osob a firem |

## Zdrojové soubory

| Soubor | Popis |
|---|---|
| [PersonDocument.php](src/PersonDocument.php) | Dokumentová třída — validace a before-save logika pro tabulku Osoby |
| [PersonType.php](src/PersonType.php) | PHP enum `PersonType` (Undefined = 0, Person = 1, Company = 2) |

## Konfigurace

| Klíč | Soubor | Popis |
|---|---|---|
| `base.persons.personTypes` | [config/personTypes.jsonc](config/personTypes.jsonc) | Číselník typů osob pro sloupec `person_type` |
