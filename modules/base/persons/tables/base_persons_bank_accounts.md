# Tabulka: Bankovní účty (base_persons_bank_accounts)

## Účel

Tabulka eviduje bankovní účty přiřazené k osobám a firmám v tabulce
`base_persons_persons`. Jedna osoba může mít více účtů — typicky
provozní účet v CZK, účet v EUR pro zahraniční platby, sbírkový účet
apod.

Účty se používají při vystavování faktur (výběr účtu dodavatele),
při párování bankovních transakcí v saldokontu a při ověřování
účtů plátců DPH přes registr.

## Struktura

Tabulka nemá skupiny sloupců — všechny sloupce jsou v jedné ploché
struktuře.

| Sloupec | Typ | Nullable | Popis |
|---|---|---|---|
| `id` | int, PK | — | Primární klíč |
| `person` | int | ne | Vazba na osobu/firmu (`base_persons_persons`) |
| `name` | varchar(100) | ano | Popisný název účtu — „Hlavní provozní účet", „EUR účet", „Sbírkový účet". Pomáhá orientaci, pokud má osoba více účtů |
| `account_number` | varchar(50) | ne | Číslo účtu v lokálním formátu — v ČR/SK typicky `předčíslí-číslo/kód_banky` (např. `123456-7890123456/0100`) |
| `iban` | varchar(34) | ano | IBAN — mezinárodní formát čísla účtu (max 34 znaků dle ISO 13616). Používá se na fakturách pro zahraniční platby |
| `bic` | varchar(11) | ano | BIC/SWIFT kód banky (8 nebo 11 znaků). Uvádí se na fakturách společně s IBAN |
| `currency` | varchar(3) | ano | ISO 4217 kód měny účtu — `CZK`, `EUR`, `USD` atd. Slouží k automatickému výběru správného účtu při vystavování faktury v dané měně |
| `source` | enumInt | ne (default 0) | Zdroj záznamu — jak se účet dostal do systému. Viz konfigurace `base.persons.bankAccountSources` |
| `order_pos` | smallint | ne (default 0) | Pořadí zobrazení — nižší hodnota = vyšší priorita |
| `valid_from` | date | ano | Datum začátku platnosti účtu |
| `valid_to` | date | ano | Datum konce platnosti — účet s vyplněným `valid_to` v minulosti se v UI přestane nabízet |
| `docState` | tinyint | ne (default 10) | Stav dokumentu — cfgItem `core.system.docStatesArchive` |
| `docStateMain` | tinyint | ne (default 1) | Stav pro řazení a filtraci viewerů |

### Stavy dokumentů

`valid_to` a `docState` jsou dvě ortogonální osy — viz
[exchange-format-persons.md §4a](../../../../docs/exchange-format-persons.md#4a-stavy-dokumentů-a-valid_to--dvě-ortogonální-osy).
Stručně: `valid_to` značí, že účet zanikl (historie zůstává korektní),
`docState = 90` značí, že záznam je vadný (například překlep v IBAN).

Aktivní záznamy mají `docState IN (10, 40, 80)`. Resolvery výměnného
formátu (modul `core.exchange`) tímto filtrem párují payload se
záznamy v DB.

## Obchodní logika

### Zdroj záznamu (source)

Sloupec `source` eviduje, jakým způsobem se účet do systému dostal:

| Hodnota | Význam |
|---|---|
| 0 — Ruční pořízení | Uživatel účet zadal ručně v detailu osoby |
| 1 — Bankovní transakce | Účet byl automaticky přidán při zpracování bankovního výpisu — systém při párování transakce v saldokontu rozpoznal nové číslo účtu u existující osoby |
| 2 — Registr DPH (API) | Účet byl stažen z veřejného registru plátců DPH přes API. V ČR by plátce DPH měl platit jinému plátci pouze na oficiálně zveřejněný účet — tento zdroj umožňuje automatické ověření |

Zdroj záznamu je informativní — nemá vliv na chování účtu, ale
pomáhá uživateli posoudit důvěryhodnost údaje.

### Platnost účtu

Logika je shodná s kontakty: účet s `valid_to` v minulosti se
nezobrazuje v aktivních výběrech, ale zůstává v historických vazbách
na dokladech.

### Pořadí a výchozí účet

Účet s nejnižší hodnotou `order_pos` se použije jako výchozí
při vystavování dokladu v odpovídající měně.

### Duplicity čísla účtu

Stejné číslo účtu může legitimně patřit více osobám — např.
společný účet manželů, nebo tentýž účet přiřazený k více pobočkám
téhož podniku. Proto index `idx_account_number` není unikátní.
Zároveň umožňuje zpětné vyhledání „komu patří tento účet?" při
párování bankovních transakcí.

## Indexy

| Index | Typ | Sloupce | Účel |
|---|---|---|---|
| `idx_person` | index | `person`, `order_pos` ASC | Hlavní přístupová cesta — účty osoby seřazené podle priority |
| `idx_account_number` | index | `account_number` | Zpětné vyhledání osoby podle čísla účtu (párování transakcí) |
| `idx_iban` | index | `iban` | Vyhledání podle IBAN — např. při zpracování SEPA plateb |
| `idx_doc_state` | index | `docStateMain` ASC | Filtrace aktivních záznamů ve výpisech a resolverech |

## Návaznosti

- **Rodičovská tabulka:** `base_persons_persons` — přes sloupec `person`
- **Konfigurace:** `base.persons.bankAccountSources` — definice hodnot
  enum sloupce `source` ([config/bankAccountSources.jsonc](../config/bankAccountSources.jsonc))
- **Plánované vazby:** Modul ekonomiky (doklady) bude na účet odkazovat
  ze záhlaví faktury — sloupec typu „bankovní účet dodavatele" s referencí
  na tuto tabulku.
