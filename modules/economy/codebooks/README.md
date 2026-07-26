# Modul: Číselníky (economy.codebooks)

Modul sdružuje ekonomické číselníky používané dokladovým a skladovým
systémem. Klíčovou náplní jsou **fiskální období** (roky a měsíce, na
které se mapují účetní data dokladů) a **registrace DPH s obdobími**
(přiznání DPH, kontrolní hlášení). Modul dále spravuje **pokladny**
a **vlastní bankovní spojení**, které budou referencovány z hlaviček
dokladů (pokladní lístky, bankovní výpisy, faktury s předkontací na
bankovní účet).

Tabulky `economy_codebooks_warehouses` a `economy_codebooks_cost_centers`
jsou v této fázi placeholdery (schémata existují, UI a Document logika
přijde s dokladovým systémem).

## Závislosti

- `core.system`
- `world.base` — cfgItem zemí (`world.base.countries`) pro registrace DPH
  + budoucí currency picker pro fiskální roky
- `world.trade` — cfgItem obchodních unií (`world.trade.unions`) pro
  pole `region` u registrací DPH

## Tabulky

| Tabulka | Popis |
|---|---|
| [economy_codebooks_warehouses](tables/economy_codebooks_warehouses.jsonc) | Sklady (placeholder, fáze 1 neřeší) |
| [economy_codebooks_cost_centers](tables/economy_codebooks_cost_centers.jsonc) | Střediska (placeholder, fáze 1 neřeší) |
| [economy_codebooks_fiscal_years](tables/economy_codebooks_fiscal_years.md) | Fiskální (účetní) roky |
| [economy_codebooks_fiscal_months](tables/economy_codebooks_fiscal_months.md) | Fiskální měsíce navázané na rok |
| [economy_codebooks_vat_registrations](tables/economy_codebooks_vat_registrations.md) | Registrace k DPH (různé země, OSS, diskontinuity) |
| [economy_codebooks_vat_periods](tables/economy_codebooks_vat_periods.md) | Období DPH navázaná na registraci (měsíční / čtvrtletní) |
| [economy_codebooks_cash_desks](tables/economy_codebooks_cash_desks.md) | Pokladny pro hotovostní operace |
| [economy_codebooks_bank_accounts](tables/economy_codebooks_bank_accounts.md) | Vlastní bankovní účty (firma) |

## Zdrojové soubory

| Soubor | Popis |
|---|---|
| [FiscalYearDocument.php](src/FiscalYearDocument.php) | Validace fiskálního roku (povinná pole, rozsah dat, regex měny) |
| [FiscalMonthDocument.php](src/FiscalMonthDocument.php) | Validace měsíce + denormalizace `calendar_year`/`calendar_month` |
| [FiscalYearsForm.php](src/FiscalYearsForm.php) | Formulář roku se sub-tabulkou Měsíce |
| [FiscalYearsViewer.php](src/FiscalYearsViewer.php) | Viewer roků s tabem seznamu měsíců |
| [FiscalYearsProvisioner.php](src/FiscalYearsProvisioner.php) | Idempotentní seed aktuálního a následujícího roku |
| [VatRegistrationDocument.php](src/VatRegistrationDocument.php) | Validace registrace DPH (povinná pole, range platnosti, enum kontroly) |
| [VatPeriodDocument.php](src/VatPeriodDocument.php) | Validace období DPH (povinná pole, range dat) |
| [VatRegistrationsForm.php](src/VatRegistrationsForm.php) | Formulář registrace se sub-tabulkou Období DPH |
| [VatRegistrationsViewer.php](src/VatRegistrationsViewer.php) | Viewer registrací s tabem seznamu období |
| [VatPeriodsProvisioner.php](src/VatPeriodsProvisioner.php) | Idempotentní generátor období pro aktivní registrace |
| [CashDeskDocument.php](src/CashDeskDocument.php) | Validace pokladny (povinná pole, formát měny) + default-per-currency uniqueness v `afterPersist` |
| [BankAccountDocument.php](src/BankAccountDocument.php) | Validace bankovního účtu (account_number nebo iban povinný, regex IBAN/BIC) + default-per-currency uniqueness v `afterPersist` |

## Konfigurace

| Klíč | Soubor | Popis |
|---|---|---|
| `economy.codebooks.fiscalPeriodTypes` | [config/fiscalPeriodTypes.jsonc](config/fiscalPeriodTypes.jsonc) | Typ měsíce — Otevření (0) / Běžné (1) / Uzavření (2) |
| `economy.codebooks.fiscalConfig` | [config/fiscalConfig.jsonc](config/fiscalConfig.jsonc) | `yearStartMonth` — výchozí 1 (leden); per-DS override zatím není |
| `economy.codebooks.vatTaxpayerKinds` | [config/vatTaxpayerKinds.jsonc](config/vatTaxpayerKinds.jsonc) | Druh plátce — Klasický (0) / OSS (1) |
| `economy.codebooks.vatPeriodKinds` | [config/vatPeriodKinds.jsonc](config/vatPeriodKinds.jsonc) | Frekvence DPH přiznání i kontrolního hlášení — Měsíční (1) / Čtvrtletní (2) |

## Auto-generování fiskálních období

Při každém běhu `bin/shpd-ds ds-upgrade` se spustí
`FiscalYearsProvisioner::provision()` s touto logikou:

1. Načte `yearStartMonth` z cfgItem `economy.codebooks.fiscalConfig`
   (default 1).
2. Spočte rozsah aktuálního fiskálního roku podle dnešního data.
3. **Existuje-li v DB rok pokrývající dnešek?**
   - **Ne** → vygeneruje aktuální rok + 14 měsíců. Hotovo.
   - **Ano** → spočte rozsah následujícího roku; pokud neexistuje,
     vygeneruje ho.

Idempotence: lookup před insertem podle vypočítaného `date_begin`.
Druhý běh `ds-upgrade` na DS s aktuálním rokem typicky vygeneruje
rok následující; třetí běh je no-op (`existing: 2`).

Generovaný rok dostává `docState=40, docStateMain=3` (V pořádku).
Manuálně přes UI vznikající rok je `Koncept` (10) — uživatel ho
přepne tlačítkem.

Pro názvy roků a prefixy:

- `yearStartMonth=1`: `name = "YYYY"`, `doc_number_prefix` = poslední
  dvě číslice roku (např. `"26"`)
- jinak: `name = "YYYY-YYYY"` (rok začátku—rok konce, např.
  `"2026-2027"`), prefix = poslední dvě číslice **konce** (`"27"`)

Per-DS override `yearStartMonth` zatím není implementovaný — když
bude potřeba, doplní se mechanismus per-DS cfgItem override.

## Typy fiskálních měsíců

Každý fiskální rok obsahuje právě **14 měsíců**:

| period_type | Význam | Rozsah |
|---|---|---|
| 0 | Otevření | jednodenní = `date_begin == date_end == year.date_begin` |
| 1 | Běžné období | každý kalendářní měsíc roku (12×) |
| 2 | Uzavření | jednodenní = `date_begin == date_end == year.date_end` |

Otevření a Uzavření slouží počátečním a závěrkovým účetním operacím
(počáteční stavy, závěrkové opravy) — fakticky se chovají jako
samostatné jednodenní účetní období na hraně roku, kam doklady
„počátku" a „konce" patří mimo běžné měsíční rytmy.

`calendar_year` a `calendar_month` jsou denormalizované sloupce, do
kterých se v `FiscalMonthDocument::beforeSave` automaticky vyplňuje
rok a měsíc z `date_begin`. Ve formu jsou readOnly.

## Dělení dokladů

Až se začne dělat dokladový systém, každý doklad podle účetního data
„spadne" do konkrétního fiskálního roku a měsíce — proto jsou tyto
tabulky posledním číselníkem před spuštěním dokladové fáze. Samotné
mapování doklad → fiskální období a validace `locked = true` přijde
s dokladovým modulem.

## Registrace DPH a období DPH

Modul modeluje registrace k DPH a navazující období přiznání pro firmy,
které jsou plátci DPH ve více zemích nebo v různých režimech (klasický
plátce, OSS pro EU služby). Firma může mít 0, 1, nebo více aktivních
registrací; modeluje se i diskontinuita (uplynulé období plátcovství).

**Vztah:** každé období patří jedné registraci. Hlavička dokladu (přijde
později) bude obsahovat referenci na konkrétní registraci, a podle data
uskutečnění zdanitelného plnění „spadne" do jejího příslušného období.
Přiznání DPH se sestavují per registrace.

### Auto-generování období DPH

Při každém běhu `bin/shpd-ds ds-upgrade` se po fiskálních rocích spustí
`VatPeriodsProvisioner::provision()`:

1. Načte aktivní registrace (`docState IN (10, 40, 80)`).
2. Pro každou registraci v jedné transakci vygeneruje období v aktuálním
   a následujícím **kalendářním** roce, omezené na rozsah
   `valid_from`/`valid_to` registrace.
3. Frekvence se řídí podle `tax_period_kind`:
   měsíční → 12 období per rok s názvem `"MM/YYYY"`,
   čtvrtletní → 4 období per rok s názvem `"QN/YYYY"`.
4. Vygenerované období dostává `docState=40, docStateMain=3, locked=0`.

**Idempotence**: lookup před insertem je **překryvový** — podle
`vat_registration` + `date_begin <= kandidát.date_end AND date_end >=
kandidát.date_begin` — a **ignoruje docState**. Překryv místo rovnosti
`date_begin` proto, že v tabulce mohou být období importovaná ze starého
Shipardu s jinou frekvencí, než má registrace v `tax_period_kind`
(např. čtvrtletní historie + měsíční registrace); rovnostní lookup by je
nenašel a založil období překrývající se s existujícím. Pokud uživatel
přes UI smaže období (`docState=90`), další `ds-upgrade` ho nikdy
nevygeneruje znovu — smazané období zůstává smazané a blokuje i
generování všech kandidátů, kteří se s ním překrývají.

Generátor počítá podle **kalendářního** (ne fiskálního) roku — období
DPH jsou kalendářní entita; pokud má firma fiskální rok jiný než
kalendář, je to nezávislé.

### Manuální správa

V editačním formuláři registrace v záložce **Období DPH** lze přes
sub-formulář přidat / upravit / smazat jednotlivá období. To pokrývá
speciální případy:

- Mimořádná období (např. „Likvidace 2027" s vlastním rozsahem)
- Změna frekvence (`tax_period_kind`) u existující registrace —
  uživatel manuálně smaže nesedící stará období, další `ds-upgrade`
  doplní chybějící podle aktuální frekvence; provisioner do existujících
  záznamů nesahá. **Úklid musí být úplný**: kvůli překryvovému lookupu
  provisioner nevytvoří kandidáty, kteří se překrývají se zbylými
  (nesmazanými) starými obdobími — zapomenuté čtvrtletí zablokuje
  všechny tři měsíce v něm

Editace `valid_from`/`valid_to` po vygenerování období se neprojeví
zpětně — provisioner nikdy neupravuje existující záznamy. Uživatel si
přebytečné/chybějící období řeší ručně.
