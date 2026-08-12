# ds-setup — Task 09: Můstky do číselníků DPH a bankovních účtů

**Stav:** hotovo

> PRD pro jednu Claude Code session. Design: `docs/ds-setup.md`,
> rozhodnutí **D5, D15, D17**, kontrakt **§5.4** body 2 a 3.
> Dokončení Fáze 4.

## Kontext

Task 08 zapojil registrový import: jeden apply vyrobí vlastní Osobu,
sídlo, DIČ a bankovní spojení v `base_persons_bank_accounts`.

Dvě věci, které z toho neplynou automaticky, protože leží v jiných
tabulkách:

- **Registrace DPH** (`economy_codebooks_vat_registrations`) — doklad
  vyžaduje registraci, ne DIČ na Osobě.
- **Vlastní bankovní účet** (`economy_codebooks_bank_accounts`) — na
  vydanou fakturu jde **číselníková** tabulka, ne bankovní spojení Osoby.
  Jsou to dvě různé tabulky a tenhle rozdíl je snadné přehlédnout.

Tenhle task oba překlopy dodá jako akce v panelu (D15 — žádná nová plocha).

## Cíl

1. Předvyplněná Registrace DPH z DIČ vlastní Osoby.
2. Můstek bankovních spojení do číselníku, se zaškrtávacím seznamem.

## Závislosti

- Závisí na Tasku 08 (data z registru) a `vat-payer-01`
  (`VatRegistrationDocument` s hookem na `VatPeriodsProvisioner`) —
  oba hotové.
- Otevírá: Fázi 5 (nabídky).

## Potvrzená designová rozhodnutí (Anna)

1. **D15** — rozšíření panelu, ne krokový průvodce.
2. **Zaškrtávací seznam u bankovních účtů** — uživatel může překlopit
   víc účtů naráz. Ne „navrhni jeden a potvrď".
3. **Registr nevrací datum registrace k DPH ani příznak plátce** —
   kanonický formát má jen `vatId`. `valid_from`, `tax_period_kind`
   a `report_period_kind` se musí zeptat.

## Před implementací přečti

- `docs/ds-setup.md` §5.4 body 2 a 3
- `modules/economy/codebooks/src/VatRegistrationDocument.php` ~ř. 90 —
  hook na `VatPeriodsProvisioner` po uložení (z `vat-payer-01`)
- `modules/economy/codebooks/tables/economy_codebooks_vat_registrations.jsonc`
- `modules/economy/codebooks/config/vatPeriodKinds.jsonc` — `1` Měsíční,
  `2` Čtvrtletní; **`0` se nepoužívá**
- `modules/base/persons/tables/base_persons_bank_accounts.jsonc` — sloupce
  `person`, `name`, `account_number`, `iban`, `bic`, `currency`, `source`,
  `order_pos`, `valid_from`, `valid_to`
- `modules/economy/codebooks/tables/economy_codebooks_bank_accounts.jsonc`
  — `code` (varchar 10, **not null, unikátní** `unq_code`), `name`
  (varchar 150, not null), `bank_name` (nullable), `currency`
  (enumString, not null, default `czk`), `is_default`, `valid_from`,
  `valid_to`, `sort_order`
- `modules/base/persons/config/bankAccountSources.jsonc` — `0` ruční,
  `1` bankovní transakce, `2` Registr DPH (API)
- `src/Api/Controller/SetupController.php` — serializace položek, akce
  `registry_import_own` z Tasku 08 jako vzor akce jen pro panel
- `frontend/src/components/settings/DsSetup.svelte` — `runAction()`,
  modaly, `load()` po dokončení

## Rozsah

### Registrace DPH — předvyplnění

Položka `economy.codebooks.missing_vat_registration` dostane akci
`prefill_vat_registration` (jen v `SetupController`, **ne v checku** —
stejný důvod jako u Tasku 08: check putuje cronem do feedu, který takový
dialog nemá).

Server: `GET /_setup/vat-registration-prefill` vrátí navrhované hodnoty:

| Pole | Zdroj |
|---|---|
| `vat_id` | `base_persons_persons.vat_id` aktivní vlastní Osoby |
| `country` | vrstva A — `DataSourceConfig::getCountry()` |
| `region` | z konfigurace / default `eu` podle dnešního chování sloupce |
| `name` | název vlastní Osoby |
| `taxpayer_kind` | `0` (Klasický plátce) |
| `valid_from` | **prázdné** — registr datum nevrací |
| `tax_period_kind` | **prázdné** — zeptat se |
| `report_period_kind` | **prázdné** — zeptat se |

Frontend: dialog se třemi vstupy, které se musí doplnit (`valid_from`,
`tax_period_kind`, `report_period_kind`) a předvyplněným zbytkem
k nahlédnutí. Uložení jde **přes `VatRegistrationDocument`**, ne přímým
INSERTem — hook na `VatPeriodsProvisioner` z `vat-payer-01` pak vygeneruje
období DPH sám. Kdyby se to obešlo přímým zápisem, uživateli vznikne
registrace bez období a nikdo si toho nevšimne.

Frekvence: select nad `vatPeriodKinds` (`1` / `2`). **Hodnotu `0`
nenabízej** — cfgItem ji výslovně rezervuje.

`valid_from` je povinné a nemá rozumný default. Nabídni jako nápovědu, že
jde o datum na rozhodnutí o registraci, ne o datum, kdy uživatel dialog
vyplňuje — lidé sem jinak dají „dnes".

### Můstek bankovních účtů

Položka `economy.codebooks.missing_own_bank_account` dostane akci
`bridge_bank_accounts`.

Server: `GET /_setup/bank-account-candidates` — bankovní spojení aktivní
vlastní Osoby, každé s příznakem, jestli už v číselníku je (podle `iban`,
a když chybí, podle `account_number`), a s `source`:

```json
{
  "candidates": [
    {"id": 12, "name": "…", "accountNumber": "…", "iban": "…",
     "bic": "…", "currency": "czk", "source": 2,
     "validFrom": null, "validTo": null, "existsInCodebook": false}
  ]
}
```

`POST /_setup/bank-accounts` s `{"personBankAccountIds": [12, 13],
"defaultId": 12}` je překlopí.

Mapování:

| Číselník | Zdroj |
|---|---|
| `account_number`, `iban`, `bic`, `currency` | 1:1 |
| `valid_from`, `valid_to` | 1:1 |
| `name` | z `name` bankovního spojení; když je prázdné, dogeneruj (banka + poslední čtyřčíslí) |
| `bank_name` | **není v `base_persons_bank_accounts`** — nechej `null` (sloupec je nullable) |
| `code` | dogeneruj, viz níže |
| `is_default` | podle `defaultId` |
| `sort_order` | podle `order_pos`, mezery doplň |

**Generování `code`.** Varchar 10, not null, unikátní. Navrhuju krátký
sekvenční kód (`BU1`, `BU2`, …) s kontrolou proti existujícím řádkům —
`code` je uživatelsky viditelný a měl by být krátký, ne hash. Kolizi řeš
posunem sekvence, ne selháním. Pokud v číselníku už nějaká konvence kódů
je, **použij ji** místo mojí; podívej se, co generuje `NumberSeriesProvisioner`
a co používají ostatní číselníky.

**`currency`.** Číselník má `enumString` s cfgItem `world.base.currencies`,
not null, default `czk`. Kanonický formát registru má měnu **velkými
písmeny** (`^[A-Z]{3}$`). Ověř, co je v `base_persons_bank_accounts`
reálně uložené, a normalizuj na malá — jinak vznikne řádek s měnou, kterou
cfgItem nezná.

Frontend: dialog se **zaškrtávacím seznamem**:

- Účty se `source = 2` (Registr DPH API) **předvybrané** — jsou oficiálně
  zveřejněné, tedy ty, na které mají partneři platit.
- Účty, které už v číselníku jsou (`existsInCodebook`), zobraz zašedle
  a nezaškrtnutelné, s poznámkou proč.
- Právě jeden vybraný účet označ jako výchozí (radio ve vybraných řádcích).
  Když je vybraný jediný, nastav `is_default` automaticky.
- Prázdný výběr → tlačítko neaktivní.
- Po uložení `load()` panelu.

**`is_default` musí být v číselníku právě jeden.** Ověř, jak to řeší
`BankAccountDocument` — pokud tam už logika „nový default zruší starý"
je, použij ji; pokud ne, udělej to v překlopu a **napiš to do commit
message**, protože je to změna chování číselníku, ne jen setupu.

### Dokumentace

- `docs/ds-setup.md` — §5.4 body 2 a 3 srovnat s realitou; doplň D17
  (zaškrtávací seznam, předvýběr podle `source = 2`).
- `docs/rest-api.md` — tři nové routy.
- `modules/economy/codebooks/README.md` — pokud popisuje číselník
  bankovních účtů, doplň, že se dá naplnit z bankovního spojení vlastní
  Osoby, a **zdůrazni rozdíl mezi oběma tabulkami**.

## Testy

`SetupControllerTest`:

- prefill registrace vrací `vat_id` a `country` z vrstvy A, `valid_from`
  a frekvence prázdné
- prefill bez vlastní Osoby → 409 nebo prázdná odpověď (rozhodni a otestuj;
  akce by se v panelu neměla ani nabídnout)
- kandidáti bankovních účtů nesou `existsInCodebook` správně (podle `iban`
  i podle `account_number`, když IBAN chybí)
- překlop dvou účtů vytvoří dva řádky s unikátními `code`
- překlop účtu, který už v číselníku je → odmítnut, nic se nezduplikuje
- `defaultId` nastaví `is_default` právě u jednoho řádku
- `currency` se normalizuje na malá písmena

`VatRegistrationDocument` — regresní test, že uložení přes dialog
vygeneruje období DPH (hook z `vat-payer-01` se skutečně chytí).

Frontend: `cd frontend && npm run build` (timeout 90–120 s).

PHP: `vendor/bin/phpunit --filter 'SetupController|VatRegistration|BankAccount'`.

## Ověření na dev serveru (součást tasku)

1. Čerstvý DS → import vlastní Osoby z registru (Task 08) → v panelu jsou
   u registrace DPH i u bankovního účtu nové akce.
2. Otevři prefill registrace → `vat_id` a země předvyplněné, datum
   a frekvence prázdné; ulož → registrace vznikne **a období DPH taky**
   (ověř v `economy_codebooks_vat_periods`).
3. Otevři můstek bankovních účtů → účty se `source = 2` předvybrané,
   ostatní ne.
4. Vyber dva, jeden označ jako výchozí, ulož → dva řádky v číselníku,
   `is_default` u jednoho, `code` unikátní.
5. Otevři můstek znovu → oba účty jsou zašedlé jako už existující.
6. Potvrď vydanou fakturu → projde (bankovní účet už je).
7. Firma bez zveřejněných účtů (žádný `source = 2`) → seznam se zobrazí
   bez předvýběru, ruční zaškrtnutí funguje.

## Hotovo když

- [ ] Registrace DPH se dá založit z předvyplněných dat a období DPH
      vzniknou spolu s ní
- [ ] Bankovní účty se překlápějí zaškrtávacím seznamem, víc naráz
- [ ] Už překlopené účty se nenabízejí znovu
- [ ] `is_default` je v číselníku právě jeden
- [ ] Vydaná faktura se po dokončení dá potvrdit
- [ ] `npm run build` prochází, PHP testy zelené
- [ ] `docs/ds-setup.md` a `docs/rest-api.md` doplněné

## Pasti / na co pozor

- **Registraci ukládej přes `VatRegistrationDocument`.** Přímý INSERT by
  obešel hook na `VatPeriodsProvisioner` z `vat-payer-01` a uživatel by
  skončil s registrací bez období DPH — což se projeví až u prvního
  přiznání, tedy pozdě.
- **Dvě různé tabulky bankovních účtů.** `base_persons_bank_accounts`
  patří Osobě (i partnerské), `economy_codebooks_bank_accounts` je náš
  číselník a jen ten jde na vydanou fakturu. Nezkoušej to sjednotit ani
  odkazovat FK — překlop je kopie a je to zamýšlené.
- **`bank_name` v číselníku není z čeho vzít.** V bankovních spojeních
  Osoby ten sloupec neexistuje. Nechej `null`; **nedopočítávej** název
  banky z kódu banky, to je samostatné téma (číselník bank neexistuje).
- **Měna velkými písmeny.** Kanonický registrový formát má
  `^[A-Z]{3}$`, číselník `enumString` s cfgItem malými. Nenormalizovaná
  hodnota projde INSERTem a rozbije se až při renderu.
- **`valid_from` registrace nemá default.** Nedávej tam `today` ani datum
  ze `bankAccounts[].validFrom` — to je datum zveřejnění účtu, ne
  registrace k DPH.
- **Akce jen do `SetupController`, ne do checků.** Stejná past jako
  v Tasku 08: check se cronem reconciluje do feedu, kde `prefill_vat_registration`
  ani `bridge_bank_accounts` nemá kdo obsloužit.
- `sort_order` v číselníku doplň tak, aby nevznikly duplicity — `order_pos`
  z bankovních spojení může být `null` u ručně pořízených.
