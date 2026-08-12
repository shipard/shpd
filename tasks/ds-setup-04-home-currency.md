# ds-setup — Task 04: Domácí měna do settings

**Stav:** hotovo

> PRD pro jednu Claude Code session. Design: `docs/ds-setup.md`,
> rozhodnutí **D2, D9**, kontrakt **§5.2**. Poslední parametr vrstvy C —
> po tomhle tasku je `main.json` čistě vrstva A (jazyk a země).

## Kontext

Task 03 přestěhoval osnovu a fiskální rok do `core_system_settings`.
`economy.homeCurrency` je poslední klíč, který zbývá; `getDefaultCurrency()`
je poslední getter `DataSourceConfig`, který nepatří do vrstvy A.

**Zjištění z průzkumu:** hardcoded `'czk'` je v repu na dvanácti místech,
ale jen na **čtyřech** jde o rozhodnutí. Zbytek jsou defenzivní fallbacky
při čtení `$data`, které v praxi nikdy nevystřelí, protože `applyDefaults()`
hodnoty vždycky vyplní. Rozlišení je v tomhle tasku to hlavní — viz
past č. 1.

## Cíl

1. Klíč `economy.homeCurrency` v `LayerCParameters`.
2. Čtyři místa, která měnu **rozhodují**, ji berou ze settings.
3. `getDefaultCurrency()` z repa venku, `defaultCurrency` v `main.json`
   mrtvý.

## Závislosti

- Závisí na Tasku 03 (`LayerCParameters`, `ds-setting`) — hotový.
- Otevírá: Fázi 3 (setup checky). Po tomhle tasku je vrstva C
  parametricky kompletní.

## Potvrzená designová rozhodnutí (Anna)

1. **D2** — absence klíče = nerozhodnuto.
2. **D9** — žádný fallback na `main.json`, žádný backfill. Import ze
   starého Shipardu si klíč zapisuje sám.

## Před implementací přečti

- `docs/ds-setup.md` §5.2 (tabulka klíčů a čtenářů)
- `src/Core/Settings/LayerCParameters.php` — `SPECS`, `validate()`
- `src/Core/Config/DataSourceConfig.php` — `getDefaultCurrency()` (~ř. 102)
  a doc komentář nad ním
- `modules/docs/core/src/DocsHeadsFormBase.php` — `applyDefaults()`
  ~ř. 320–330
- `modules/docs/core/src/DocDocument.php` ~ř. 404
- `modules/economy/accbal/src/LedgerGenerator.php` ~ř. 36 a 43
- `modules/economy/codebooks/src/FiscalYearsProvisioner.php` ~ř. 126
  a `FiscalYearsForm.php` ~ř. 16
- jak Task 03 řeší `[SKIP]` u nerozhodnutého klíče
  v `DsUpgradeCommand::provisionFiscalYears()` — tenhle task na to
  navazuje

## Rozsah

### `src/Core/Settings/LayerCParameters.php`

```php
'economy.homeCurrency' => [
    'module'  => 'economy.codebooks',
    'example' => 'czk',
],
```

`validate()`: tříznakový kód malými písmeny (`/^[a-z]{3}$/`). Nevaliduj
proti seznamu měn — kód proti `world` číselníku ověřovat nemá `ds-setting`,
který běží před kompilací configu; tvar stačí, stejně jako u `country`
v Tasku 01.

### Čtyři místa, která měnu rozhodují

| Soubor | Dnes | Po tasku |
|---|---|---|
| `DocsHeadsFormBase::applyDefaults()` ~ř. 326 | `$data['home_currency'] = 'czk'` | ze settings |
| `DocsHeadsFormBase::applyDefaults()` ~ř. 323 | `$data['doc_currency'] = 'czk'` | z **home_currency** — domácí doklad je v domácí měně |
| `DocDocument` ~ř. 404 | `getDefaultCurrency() ?? 'czk'` | ze settings |
| `LedgerGenerator` ~ř. 43 | `getDefaultCurrency() ?? 'czk'` | ze settings |

Nerozhodnutý klíč → `'czk'`, tedy dnešní chování. Stejná logika jako
u `vatAgenda` v `vat-payer-01`: nerozhodnuto nesmí měnit sémantiku
existujících dokladů.

### `FiscalYearsProvisioner` a `FiscalYearsForm`

`FiscalYearsProvisioner` ~ř. 126 zakládá fiskální roky s `'currency' => 'czk'`.

**Rozšiř gate v `DsUpgradeCommand::provisionFiscalYears()` na oba klíče** —
fiskální roky se naseedují, až je rozhodnutý `economy.fiscalYearStartMonth`
**i** `economy.homeCurrency`. Důvod: měna je součástí zakládaného záznamu,
takže vytvořit ho s odhadnutou měnou je přesně to, co D2 zakazuje. `[SKIP]`
zpráva ať říká, který klíč chybí.

`FiscalYearsForm` ~ř. 16 (`$data['currency'] = 'czk'`) → ze settings,
s fallbackem `'czk'` jako u ostatních formulářů.

### `src/Core/Config/DataSourceConfig.php`

- `getDefaultCurrency()` **smazat** včetně doc komentáře.
- `getCountry()`, `hasCountry()`, `getDefaultLanguage()` beze změny.

Pak prolez konstruktory, ze kterých `DataSourceConfig` zbyl jen kvůli měně:

- `LedgerGenerator::__construct()` má `?DataSourceConfig $dsConfig = null`
  a používá ho **jen** na ř. 43. Po přepojení je parametr mrtvý → odstranit
  a upravit `JournalLedgerHandler` ~ř. 23, který ho předává.
- `DocDocument` — zkontroluj stejně; pokud `$dsConfig` slouží i jinde,
  nech ho.

Nezapomeň na grep celého repa na `getDefaultCurrency` a `defaultCurrency`
včetně `docs/`.

### Dokumentace

- `docs/ds-setup.md` — §5.2 tabulka klíčů (čtenáře uveď podle reality
  po tasku, ne podle dnešního textu), a v §5.1 poznámku, že po tomhle
  tasku je `main.json` čistě vrstva A.
- `docs/cli.md` — do scénáře „nastavení čerstvého DS z konzole" přidat
  `ds-setting set economy.homeCurrency czk`.

## Testy

`tests/Unit/Core/Settings/LayerCParametersTest.php` — validace
`economy.homeCurrency`: `czk`/`eur` projde, `CZK`, `cz`, `czks`, prázdný
string ne.

`DocsHeadsFormTest` (existuje z `vat-payer-01`):

- rozhodnutá měna `eur` → nový doklad má `home_currency = 'eur'`
  i `doc_currency = 'eur'`
- nerozhodnutý klíč → `'czk'` (dnešní chování)
- explicitně zadané `doc_currency` v datech se **nepřebíjí**

`DsUpgradeCommandTest` (existuje z Tasku 03) — fiskální roky se
neseedují, když chybí kterýkoli z obou klíčů; naseedují se se správnou
měnou, když jsou oba.

`LedgerGenerator` — domácí měna ze settings; pokud test existuje,
uprav konstruktor v setupu.

`tests/Unit/Core/Config/DataSourceConfigTest.php` — odstranit test
`getDefaultCurrency()`.

Spuštění:
`vendor/bin/phpunit --filter 'LayerCParameters|DocsHeadsForm|DsUpgradeCommand|DataSourceConfig|Ledger'`.

## Ověření na dev serveru (součást tasku)

1. Čerstvý DS: `[TODO]` výpis `ds-upgrade` hlásí tři parametry včetně
   `economy.homeCurrency`.
2. `ds-setting set economy.fiscalYearStartMonth 1` bez měny →
   `ds-upgrade` fiskální roky **neseeduje** a `[SKIP]` říká, který klíč
   chybí.
3. Po `ds-setting set economy.homeCurrency eur` → `ds-upgrade` naseeduje
   fiskální roky s měnou `eur`.
4. Nový doklad má `home_currency` i `doc_currency` = `eur`; hlavička
   nezobrazuje kurzová pole (`$hasForeignCurrency` je false, protože obě
   měny jsou shodné).
5. Doklad v `czk` na tom DS → kurzová pole se objeví, přepočet funguje.

## Hotovo když

- [ ] `economy.homeCurrency` je v `LayerCParameters` a v `[TODO]` výpisu
- [ ] Čtyři rozhodovací místa čtou settings, defenzivní fallbacky
      zůstaly beze změny
- [ ] Fiskální roky se neseedují bez rozhodnuté měny
- [ ] `getDefaultCurrency()` je z repa venku, `main.json` je čistě
      vrstva A
- [ ] `LedgerGenerator` nedostává `DataSourceConfig`, pokud ho už
      nepotřebuje
- [ ] Testy zelené

## Pasti / na co pozor

- **Nepřepisuj defenzivní fallbacky na čtení settings.** Řádky jako
  `DocsHeadsFormBase` 340/341 a 713/714, `ReceivedInvoiceForm` 58/59/178,
  `IssuedInvoiceForm` 62/63 čtou `$data['home_currency'] ?? 'czk'` —
  hodnota tam už je z `applyDefaults()`, `?? 'czk'` je jen obrana.
  Kdyby se z každého stal dotaz do settings, přidají se dotazy do
  renderovací cesty formuláře za nulový přínos. **Osm míst nech, jak
  jsou; měň čtyři.**
- **Neinstancuj `SettingsStore` per doklad.** `DocDocument` se konstruuje
  pro každý doklad; při dávkovém přeúčtování by to byl jeden dotaz na
  doklad, protože cache je per instance. Předej hodnotu nebo store zvenčí,
  stejným způsobem, jakým to řeší `vat-payer-01` u `vatAgenda` —
  podívej se, jak to tam vyšlo, a drž jednotný vzor.
- **Změna měny na běžícím DS je mimo rozsah.** Klíč se dá `ds-setting`
  přepsat, ale existující doklady a fiskální roky mají měnu uloženou
  a nikdo je nepřepočítá. Nedělej k tomu migraci ani varování v UI —
  je to samostatné téma; jen to nezakrývej (do doc komentáře
  `LayerCParameters` u klíče větu, že mění jen nové záznamy).
- `FiscalYearDocument`, `BankAccountDocument` a `CashDeskDocument` mají
  `'czk'` jen v textu validační zprávy jako příklad. Nesahat.
- Rozšíření gate fiskálních roků na dva klíče znamená, že DS, kde je
  rozhodnutý jen měsíc, po tomhle tasku fiskální roky ztratí z výhledu
  (existující řádky zůstanou, nové se nedogenerují). Na dev serveru to
  ověř bodem 2 — je to zamýšlené, ne regrese.
