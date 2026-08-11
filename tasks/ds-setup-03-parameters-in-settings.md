# ds-setup — Task 03: Parametry osnovy a fiskálního roku do settings

**Stav:** hotovo

> PRD pro jednu Claude Code session. Design: `docs/ds-setup.md`,
> rozhodnutí **D2, D6, D9**, kontrakt **§5.2**. Tenhle task zavádí
> mechanismus, na kterém stojí celá vrstva C — `vat-payer-01`
> i Task 04 jen přidávají další klíč.

## Kontext

`main.json` dnes nese `accountChart` a `defaultCurrency` a provisionery je
čtou při `ds-upgrade`, tedy dřív, než uživatel DS poprvé otevře. D2 to
obrací: parametry vrstvy C žijí v `core_system_settings` a **absence klíče
znamená nerozhodnuto** — provisioner tedy nic nenaseeduje, dokud rozhodnutí
nepadne (D6).

Tenhle task dělá dva klíče (`economy.accountChart`,
`economy.fiscalYearStartMonth`), `economy.homeCurrency` je Task 04.

**Proč je součástí i CLI.** `SettingsStore` není použitý z jediného
commandu. Bez `ds-setting` by tenhle task nebyl ověřitelný a — horší —
každý DS založený mezi tímhle taskem a průvodcem (Fáze 4) by zůstal bez
osnovy a bez způsobu, jak ji naseedovat.

## Cíl

1. `ds-setting get|set|list` — CLI přístup k `SettingsStore`.
2. `AccountChartProvisioner` a `FiscalYearsProvisioner` řízené settings
   klíčem; chybí klíč → neseedovat.
3. `[TODO]` výpis nerozhodnutých parametrů na konci `ds-upgrade`.
4. Konec `getAccountChart()`.

## Závislosti

- Závisí na: Task 01, Task 02 (hotové).
- Otevírá: `vat-payer-01` (potřebuje mechanismus klíčů), Task 04.

## Potvrzená designová rozhodnutí (Anna)

1. **D2** — absence klíče = nerozhodnuto. Ne prázdný string, ne `null`
   jako uložená hodnota, ne zvláštní sentinel. `SettingsStore::get()`
   vrací `null` u chybějícího klíče a to je ta jediná pravda.
2. **D6** — provisioning se odkládá. Čerstvý DS chvíli nemá osnovu ani
   fiskální roky a to je v pořádku; doklad se stejně nepotvrdí bez
   vlastní Osoby.
3. **D9** — žádný backfill, žádný fallback na `main.json`. Zdroje dat se
   přeimportují a import si klíče zapíše sám.
4. **`ds-setting` do tohohle tasku**, ne samostatně.

## Před implementací přečti

- `docs/ds-setup.md` — §5.2 (klíče a čtenáři), §7 (ds-reset, import)
- `docs/app-settings.md` — §6 pravidlo namespacování klíčů, `SettingsStore`
- `src/Core/System/SettingsStore.php` — `get` / `getMany` / `set` /
  `delete`; **`set($key, null)` klíč maže**
- `src/Command/DataSource/DsUpgradeCommand.php` — `provisionAccountChart()`
  (~ř. 519), `provisionFiscalYears()` (~ř. 623), `logProvisioningResult()`,
  a konec `execute()`, kde se dnes vypisují `[WARN]` o `country`
  a o secrets
- `src/Command/DataSource/UserSetAdminCommand.php` — vzor DS-level
  commandu (konstruktor s nullable `DataSourceConfig`/`DataSourceConnection`,
  `getDataSourceDir()`, kontrola „not a Shipard data source directory")
- `bin/shpd-ds` — registrace commandu (~ř. 31)
- `modules/economy/codebooks/src/FiscalYearsProvisioner.php` — čtení
  `cfgItem('economy.codebooks.fiscalConfig')['yearStartMonth']` s clampem
  na 1–12

## Rozsah

### `src/Command/DataSource/DsSettingCommand.php` (nový)

```
ds-setting list                    vypíše všechny klíče scope `ds` a hodnoty
ds-setting get <key>               vypíše hodnotu, exit 1 když klíč není
ds-setting set <key> <value>       nastaví
ds-setting set <key> --unset       smaže (SettingsStore::set($key, null))
```

- Vzor struktury podle `UserSetAdminCommand` včetně kontroly, že jsme
  v adresáři DS.
- **Whitelist klíčů.** Command smí nastavovat jen klíče, které nějaký
  modul deklaruje — jinak vznikne překlepem `economy.acountChart`, který
  nikdo nikdy nepřečte a bude se hledat hodinu. Zdroj whitelistu:
  posbírej `settingsPages` z resolvovaných modulů (vzor v `DsUpgradeCommand`,
  `isModuleActive()`) **plus** explicitní seznam parametrů vrstvy C, které
  settings stránku ještě nemají (`economy.accountChart`,
  `economy.fiscalYearStartMonth`; `economy.vatPayer` a
  `economy.homeCurrency` přidají navazující tasky). Neznámý klíč →
  chyba s výpisem povolených.
- **Validace hodnot** u parametrů vrstvy C: `accountChart` ∈
  {`default`, `npo`, `none`}, `fiscalYearStartMonth` ∈ 1–12. U ostatních
  klíčů hodnotu neinterpretovat.
- `list` nesmí vypsat hodnoty klíčů, které jsou citlivé — projdi, jestli
  se do `core_system_settings` neukládá něco, co nemá být na stdout
  (dnes myslím ne, ale ověř; secrets mají vlastní úložiště).

Registrace v `bin/shpd-ds`.

### `DsUpgradeCommand::provisionAccountChart()`

Přepsat na settings:

```php
$settings = new SettingsStore($dsConnection);
$variant = $settings->get('economy.accountChart');
if ($variant === null) {
    $output->writeln('  <comment>[SKIP] economy.accountChart není rozhodnuto '
        . '— osnova se neseeduje (docs/ds-setup.md D6).</comment>');
    return;
}
```

- Signatura metody: `DataSourceConfig` z parametrů **vypadne**, přidá se
  `DataSourceConnection` (už tam je).
- Větev `'none'` zůstává, jak je.
- Větev „unknown variant → fallback na default" **zruš** — hodnotu teď
  validuje `ds-setting` při zápisu, takže neznámá varianta je porucha,
  ne stav k tichému dorovnání. Nech `[WARN]` a `return` bez seedu.
- `[SKIP]` u nerozhodnutého klíče vypisuj **bez** `VERBOSITY_VERBOSE` —
  na čerstvém DS je to informace, kterou chce vidět každý.

### `DsUpgradeCommand::provisionFiscalYears()`

Stejný vzor: chybí `economy.fiscalYearStartMonth` → `[SKIP]` a `return`.

Když klíč je, předej hodnotu do `FiscalYearsProvisioner`. Provisioner dnes
čte `cfgItem('economy.codebooks.fiscalConfig')['yearStartMonth']` sám —
**přidej mu volitelný parametr konstruktoru** `?int $yearStartMonth = null`
a cfgItem nech jako fallback, když je `null`. Důvod: provisioner se bude
volat i z průvodce (Fáze 4), kde `ConfigRuntime` k dispozici je, ale
předávat rozhodnutí přes cfgItem nejde.

Clamp 1–12 v provisioneru **nech** — obrana proti nesmyslu z cfgItemu
zůstává, i když `ds-setting` validuje na vstupu.

Komentář v `modules/economy/codebooks/config/fiscalConfig.jsonc`, který
říká, že per-DS override není implementovaný, aktualizuj.

### `DsUpgradeCommand` — `[TODO]` výpis na konci `execute()`

K existujícím `[WARN]` (country, secrets) přidej blok nerozhodnutých
parametrů vrstvy C:

```
[TODO] Nerozhodnuté parametry (docs/ds-setup.md §5.2):
       economy.accountChart          bin/shpd-ds ds-setting set economy.accountChart default
       economy.fiscalYearStartMonth  bin/shpd-ds ds-setting set economy.fiscalYearStartMonth 1
```

- Seznam parametrů drž jako konstantu na jednom místě — navazující tasky
  do ní přidají další dvě položky a nechci to hledat ve třech metodách.
  Tatáž konstanta poslouží jako whitelist pro `ds-setting` (viz výše)
  i jako předloha pro setup checky (Fáze 3).
- Blok se vypíše, jen když něco chybí. Na nastaveném DS ticho.
- `[TODO]`, ne `[WARN]` — není to porucha, je to nedokončené nastavení
  (stejná logika jako `severity: warning` u setup checků v §5.3 specu).

### `src/Core/Config/DataSourceConfig.php`

- `getAccountChart()` **smazat** (D9 — žádný fallback na `main.json`).
- `getDefaultCurrency()` **nechat** — ruší ho Task 04. Nesahat na něj tady.
- `getCountry()` / `hasCountry()` / `getDefaultLanguage()` beze změny.

Prolez celý repo na volání `getAccountChart()` — kromě `DsUpgradeCommand`
by nemělo být žádné, ale ověř to greppem, ne domněnkou.

### Dokumentace

- `docs/cli.md` — sekce `ds-setting` (reference + krátký scénář „nastavení
  čerstvého DS z konzole", který teď nahrazuje průvodce).
- `docs/app-settings.md` — do popisu `SettingsStore` odkaz na `ds-setting`
  jako CLI cestu ke klíčům.
- `docs/ds-setup.md` — pokud se cokoli odchýlí od §5.2, uprav spec; spec
  je nadřazený tomuhle PRD, ne naopak.

## Testy

`tests/Unit/Command/DataSource/DsSettingCommandTest.php` (nový):

- `set` + `get` roundtrip
- `set --unset` klíč smaže a `get` pak skončí exit 1
- neznámý klíč → chyba, nic se nezapíše
- `accountChart` s hodnotou mimo whitelist → chyba
- `fiscalYearStartMonth` = 0 i 13 → chyba

`DsUpgradeCommand` — pokud existuje test provisioningu, přidat případy
„chybí klíč → neseeduje" a „klíč je → seeduje". Pokud ne, pokryj to
ověřením na dev serveru níže.

`FiscalYearsProvisioner` — nový parametr konstruktoru: explicitní hodnota
vyhrává nad cfgItemem, `null` padá na cfgItem.

`tests/Unit/Core/Config/DataSourceConfigTest.php` — odstranit test
`getAccountChart()`.

Spuštění: `vendor/bin/phpunit --filter 'DsSetting|FiscalYears|DataSourceConfig'`.

## Ověření na dev serveru (součást tasku)

1. Nový DS (`ds-create` + `ds-upgrade`) → `economy_accounting_accounts`
   obsahuje **jen** 261200 a 261300 z clearing infrastruktury, žádnou
   osnovu; `economy_codebooks_fiscal_years` je prázdná.
2. `ds-upgrade` na tom DS vypsal `[TODO]` blok s oběma parametry.
3. `ds-setting set economy.accountChart npo` → `ds-upgrade` → osnova
   naseedovaná, účty 261200/261300 vykázané jako `existing`, ne
   duplikované.
4. `ds-setting set economy.fiscalYearStartMonth 4` → `ds-upgrade` →
   fiskální rok začíná v dubnu.
5. Opakovaný `ds-upgrade` → no-op, `[TODO]` blok zmizel.
6. `ds-setting set economy.accountChart --unset` → `ds-upgrade` už osnovu
   nedoplňuje (a existující řádky **nemaže** — provisionery neuklízí).
7. Existující DS s `accountChart` v `main.json` → po tomhle tasku se
   chová jako nerozhodnutý, `[TODO]` to hlásí. **To je správně** (D9),
   ne regrese.

## Hotovo když

- [ ] `ds-setting get|set|list` funguje, whitelist a validace drží
- [ ] Čerstvý DS nemá osnovu ani fiskální roky a `ds-upgrade` to hlásí
      jako `[TODO]` s příkazy k nastavení
- [ ] Po nastavení klíčů `ds-upgrade` naseeduje správnou variantu
      a správný začátek roku
- [ ] `getAccountChart()` je z repa venku, žádné volání nezůstalo
- [ ] `docs/cli.md` a `docs/app-settings.md` aktualizované
- [ ] `vendor/bin/phpunit --filter 'DsSetting|FiscalYears|DataSourceConfig'`
      zelené

## Pasti / na co pozor

- **`ClearingInfrastructureProvisioner` běží bezpodmínečně** a zakládá
  účty 261200/261300 ještě před osnovou. `AccountChartProvisioner` je
  idempotentní podle čísla účtu (`SELECT` → `existing++`), takže pozdější
  seed je přeskočí — ověřeno, ale zkontroluj to v bodě 3 ověření.
  Vedlejší efekt: na čerstvém DS existují dva účty v jinak prázdné
  osnově. Je to správné, `[SKIP]` u osnovy tedy neznamená „nula účtů".
- **Nic dalšího na odložení nezávisí** — ověřeno: `BalancesProvisioner`
  ukládá čísla účtů jako stringy (žádný FK na osnovu),
  `NumberSeriesProvisioner` zapisuje `reset_scope => 'fiscal_year'` jako
  hodnotu (žádný lookup fiskálních roků), `VatPeriodsProvisioner` iteruje
  registrace DPH (na fiskálních rocích nezávisí). Kdyby se při
  implementaci ukázalo něco jiného, **zastav a napiš to** — mohla by to
  být trhlina v D6.
- **`core_system_settings` je v `keepOnReset`.** Po `ds-reset` klíče
  zůstanou, ale osnova a fiskální roky zmizí — a `ds-upgrade` je
  z klíčů znovu naseeduje. Zkontroluj ten průchod, je to hlavní
  argument pro D3.
- `SettingsStore` má request-level cache. V rámci jednoho běhu commandu
  po `set()` nečti hodnotu z jiné instance a nepředpokládej, že
  provisioner v témže procesu uvidí změnu — v `ds-upgrade` se `set`
  neděje, ale v testech na to narazíš.
- Nepřidávej `select`/`checkbox` field typy ani settings stránku pro tyhle
  parametry. To je Fáze 4 (Task 07) a dokud neexistuje, je `ds-setting`
  jediná cesta — vědomě.
