# Tabulka: economy_vat_deduction_coefficients

Koeficient odpočtu DPH (issue #59, rozhodnutí D13) per **registrace DPH ×
kalendářní rok**. Vypořádací období je podle § 76 ZDPH vždy kalendářní rok
(firma s hospodářským rokem má dvě fiskální období a jeden koeficient) a
firma může mít registrací víc, každá se svým koeficientem — proto ani
fiskální období, ani DS-wide nastavení. Model odpovídá směrnici 2006/112/ES
čl. 173–175 (odpočitatelný podíl, předběžný podíl z minulého roku, roční
vyrovnání) a je společný pro EU; národní je jen mapování na řádky formuláře
(`config/vat-reports-cz.jsonc`, ř. 52).

Záznamy vznikají výhradně ručně (Nastavení → Účetnictví → **Koeficienty
odpočtu DPH**). Import ze starého Shipardu nic nepřenáší — krácený sloupec
tam nikdy nebyl vyplněný, default 1,00 reprodukuje podaná tvrzení.

## Sloupce

| Sloupec | Typ | Popis |
|---|---|---|
| `vat_registration` | int, reference `economy_codebooks_vat_registrations` | Registrace, ke které koeficient patří |
| `year` | smallint | Kalendářní rok (vypořádací období) |
| `coefficient_provisional` | numeric(5,4) NULL | Zálohový koeficient roku (§ 76 odst. 6). `NULL` = odvodit: vypořádací koeficient roku N−1, jinak 1,0000 |
| `coefficient_settled` | numeric(5,4) NULL | Vypořádací koeficient roku (§ 76 odst. 7–9). `NULL` = rok zatím nevypořádán. Implicitní zálohový roku N+1 |
| `note` | varchar(200) NULL | Odkud hodnota je (rozhodnutí správce daně, výpočet, odhad) |

### Systémové (bez skupiny)

| Sloupec | Typ | Popis |
|---|---|---|
| `docState` | tinyint default 10 | Stav dokumentu (`core.system.docStatesArchive`) |
| `docStateMain` | tinyint default 1 | Sortovací sloupec stavů |

## Indexy

- `idx_registration_year` **unique** na `vat_registration, year`
- `idx_doc_state` na `docStateMain ASC, year DESC`

## Pravidla

- Hodnota koeficientu leží v intervalu ⟨0; 1⟩ (`invalid_range`) a má
  přesnost na **setiny** — ZDPH koeficient zaokrouhluje na celé procento
  nahoru (§ 76 odst. 3), takže `0.8000` ano, `0.8050` ne
  (`coefficient_precision`). Ukládá se jako desetinné číslo, formulář
  zadává rovněž desetinné číslo (0,80 = 80 %).
- Jedna registrace má za rok nejvýš jeden živý záznam (`docState != 90`) —
  duplicitu hlásí validace (`duplicate`) ještě před unique indexem.
- Report čte **jen záznamy ve stavu V pořádku (40)**; koncept se do výpočtu
  nedostane. Řeší `DeductionCoefficientResolver`.
- Rok bez záznamu je legitimní stav (default 1,00), ne chyba — živé
  přiznání to ale řekne ve zprávách (D13d).
- Roční vypořádání (ř. 53) a úprava odpočtu (ř. 60) jsou mimo scope;
  `coefficient_settled` je na ně připravený.

## Související

- [economy_codebooks_vat_registrations](../../codebooks/tables/economy_codebooks_vat_registrations.md) — rodičovská registrace
- [DeductionCoefficientDocument](../src/DeductionCoefficientDocument.php) — validace
- [DeductionCoefficientResolver](../src/DeductionCoefficientResolver.php) — jediná autorita „jaký koeficient platí pro rok N“
- [docs/README.md](../docs/README.md) §Koeficient odpočtu — ř. 52 v živém přiznání
