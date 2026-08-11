# ds-setup — Task 01: Vrstva A (jazyk a země v `main.json`)

**Stav:** naplánováno

> PRD pro jednu Claude Code session. Design: `docs/ds-setup.md`,
> rozhodnutí **D1**, kontrakt **§5.1**. Tenhle task je vědomě první a
> vědomě malý — otevírá zbytek oblasti a nic z něj nezávisí na vrstvě C.

## Kontext

`ds-create` dnes umí jen `--name`, `--module` a `--ds-id`. Všechno ostatní,
co `main.json` nese (`defaultLanguage`, `defaultCurrency`, `accountChart`),
se edituje ručně po založení — což je jeden z důvodů, proč se na nastavení
zapomíná.

Rozhodnutí D1 říká, že v `main.json` (vrstva A = jednorázové, nezměnitelné)
zůstanou **jen jazyk a země**. Ostatní parametry se stěhují do
`core_system_settings` — to je Task 03/04, tenhle task se jich nedotýká.

**Země je nový pojem.** `DataSourceConfig` ji dnes vůbec nezná. Steeruje
přitom, který registr se dotazuje (ARES / RPO / Handelsregister), jaké jsou
sazby DPH a jaký je formát adres — proto patří do A a ne do C.

## Cíl

1. `DataSourceConfig::getCountry()` + `hasCountry()`.
2. `ds-create --language --country`, oba **povinné**.
3. Předání z dev dashboardu.
4. `[WARN]` v `ds-upgrade` u DS bez `country`.

Předání z hostingu je **Task 02** — tenhle task hosting nechává být.

## Závislosti

- Závisí na: ničem.
- Otevírá: Task 02 (hosting), a nepřímo celou vrstvu C (`country` je vstup
  pro výběr registru v průvodci).

## Potvrzená designová rozhodnutí (Anna)

1. **Vrstva A = jen jazyk a země** (D1). Nic víc do `main.json` nepřidávat,
   nic odtud v tomhle tasku neodebírat.
2. **Přechodný fallback** — `getCountry()` vrací `'cz'`, když klíč chybí.
   Zdroje dat se přeimportují ze starého Shipardu (D9) a do té doby by
   striktní getter rozbil každý existující DS. Po reimportu se fallback
   odstraní; **do tohohle tasku ta odstranění nepatří.**
3. **V `ds-create` fallback není** — oba přepínače jsou povinné. Nový DS
   nesmí vzniknout s odhadnutou zemí; fallback z bodu 2 slouží výhradně
   starým DS. Tohle je ta hranice, o kterou tady jde.

## Před implementací přečti

- `docs/ds-setup.md` — §2 (tři vrstvy), §5.1 (kontrakt vrstvy A)
- `src/Core/Config/DataSourceConfig.php` — vzor ostatních getterů
  (`getDefaultLanguage`, `getDefaultCurrency`) včetně doc komentářů
- `src/Command/Server/DsCreateCommand.php` — `configure()` a skládání
  `$mainConfig`
- `src/Command/DataSource/DsUpgradeCommand.php` — jak se vypisují
  `[WARN]` řádky (vzor `DsSecretCipher::healthCheck()` na konci `execute()`)
- `tests/Unit/Core/Config/DataSourceConfigTest.php` — vzor testu getterů

## Rozsah

### `src/Core/Config/DataSourceConfig.php`

Dva nové gettery, doc komentáře ve stejném stylu jako sousedi:

```php
/**
 * Country of the legal entity this DS runs on behalf of. ISO 3166-1
 * alpha-2 lower-case (e.g. 'cz', 'sk'). Steers which company registry
 * is queried, which VAT rate set applies and address formatting.
 *
 * Transitional: data sources created before ds-setup Task 01 have no
 * `country` key, so 'cz' is returned as a fallback and ds-upgrade emits
 * a warning. Once all data sources are re-imported (ds-setup.md D9) the
 * fallback goes away and a missing value becomes a config error.
 */
public function getCountry(): string
{
    return $this->data['country'] ?? 'cz';
}

/** False for data sources created before the `country` key existed. */
public function hasCountry(): bool
{
    return isset($this->data['country']) && $this->data['country'] !== '';
}
```

`getDefaultLanguage()` **neměnit** — už existuje a chová se správně.

### `src/Command/Server/DsCreateCommand.php`

V `configure()` dva přepínače:

```php
->addOption('language', null, InputOption::VALUE_REQUIRED,
    'Default language, ISO 639-1 (cs|en)')
->addOption('country', null, InputOption::VALUE_REQUIRED,
    'Country of the legal entity, ISO 3166-1 alpha-2 (e.g. cz, sk)')
```

V `execute()` validace **před** jakoukoli mutací (tedy před `mkdir`
a `createDatabase` — nechceme půl založený DS):

- `language` — povinné, whitelist `['cs', 'en']`. Chyba: výpis
  `<e>--language is required (cs|en)</e>` + `Command::FAILURE`.
- `country` — povinné, `preg_match('/^[a-z]{2}$/', $country)`. Chyba
  obdobně.

Sémantickou validaci proti `world.base.countries` tady **nedělat** — cfgItem
v okamžiku `ds-create` ještě není zkompilovaný (compile běží až
v `ds-upgrade`). Tvarová validace stačí, sémantika patří do formuláře
hostingu (Task 02) a dev dashboardu.

Do `$mainConfig` přidat oba klíče. Pořadí klíčů: `id`, `name`, `modules`,
`defaultLanguage`, `country`, `database_*`, `created` — jazyk a země patří
k identitě DS, ne mezi databázové údaje.

Do výstupního souhrnu (`Output summary` na konci) přidat dva řádky
ve stejném formátu jako `Module:` a `Database:`.

### `src/Api/Controller/DevDashboardController.php`

- Do formuláře „ds-create“ dva prvky: `language` (select `cs`/`en`,
  výchozí označení `cs`) a `country` (select `cz`/`sk`, výchozí `cz`).
  Dev dashboard je vývojářský nástroj — přednastavené hodnoty tam
  nevadí, protože je vidět, co se pošle.
- Do `sprintf` volání `ds-create` (~ř. 291) přidat `--language=%s
  --country=%s` + odpovídající `escapeshellarg()`.
- Handler POST `/_dev/api/ds-create` musí ty dvě hodnoty přečíst a
  provalidovat stejně jako command (tvar), aby chyba nepřišla až
  ze streamu.

### `src/Command/DataSource/DsUpgradeCommand.php`

Na konec `execute()`, k existujícím `$secretsWarnings`:

```php
if (!$dsConfig->hasCountry()) {
    $output->writeln('<comment>  [WARN] main.json neobsahuje `country` — '
        . 'používá se přechodný fallback \'cz\'. Doplň hodnotu ručně nebo '
        . 'reimportem (docs/ds-setup.md D9).</comment>');
}
```

Warning, ne chyba — `ds-upgrade` musí na starých DS dál projít.

### `docs/cli.md`

Doplnit oba přepínače do referenční sekce `ds-create` a do workflow
scénářů, kde se `ds-create` volá. Zkontroluj **všechny** výskyty
`ds-create` v souboru — jsou tam jak reference, tak scénáře.

## Testy

`tests/Unit/Core/Config/DataSourceConfigTest.php`:

- `getCountry()` vrací hodnotu z `main.json`
- `getCountry()` vrací `'cz'`, když klíč chybí i když je prázdný string
- `hasCountry()` false pro chybějící i prázdný klíč, true pro vyplněný

`tests/Unit/Command/Server/` — jestli tam pro `ds-create` test existuje,
rozšířit; pokud ne, nezakládat kvůli tomu infrastrukturu (command sahá na
DB a filesystem). Místo toho ověřit ručně na dev serveru (viz níže).

Spuštění: `vendor/bin/phpunit --filter 'DataSourceConfig'`.

## Ověření na dev serveru (součást tasku)

1. `ds-create` **bez** `--country` → selže s jasnou zprávou a **nezaloží
   ani adresář, ani databázi** (to je ta část validace „před mutací“).
2. `ds-create --language=cs --country=cz` → `main.json` má oba klíče,
   mode 0600 zachovaný.
3. `ds-upgrade` na tomhle novém DS → žádný `[WARN]` o `country`.
4. `ds-upgrade` na existujícím DS bez `country` → `[WARN]` se vypíše
   a příkaz skončí úspěchem.
5. Založení DS z dev dashboardu → oba klíče v `main.json`.

## Hotovo když

- [ ] `ds-create` bez jazyka nebo země selže před jakoukoli mutací
- [ ] Nový DS má v `main.json` `defaultLanguage` i `country`
- [ ] `getCountry()` na starém DS vrací `'cz'` a `ds-upgrade` to hlásí
      jako `[WARN]`, ne jako chybu
- [ ] Dev dashboard oba parametry předává
- [ ] `docs/cli.md` aktualizovaná ve všech výskytech `ds-create`
- [ ] `vendor/bin/phpunit --filter 'DataSourceConfig'` zelené

## Pasti / na co pozor

- **Validovat před mutací.** `DsCreateCommand::execute()` dnes zakládá
  adresáře a databázi dřív, než dojde na psaní configu. Validace obou
  přepínačů musí být nad tím — jinak neúplný přepínač nechá po sobě
  osiřelou databázi.
- **Hosting v tomhle tasku neřešit.** `HostingSyncRunner` volá `ds-create`
  bez nových přepínačů, takže po tomhle tasku **provisioning agent
  přestane fungovat**, dokud nepřijde Task 02. To je vědomé a Task 02 jde
  hned za tímhle; jinak by se ta dvojice musela dělat jako jeden velký
  task. Poznamenat do commit message.
- `main.json` má mode 0600 — `file_put_contents` po sobě mode nemění,
  ale při jakémkoli přepisu to zkontroluj.
- Nepřidávat `country` do `/_app/info` — ten endpoint je veřejný a
  `app-settings.md` §4 výslovně říká, že tam nesmí nic dalšího přibývat.
