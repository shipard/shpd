# Task: accountChart varianta `"none"` (přeskočení seedu standardní osnovy)

## Kontext

`AccountChartProvisioner` seeduje standardní účtovou osnovu při `ds-upgrade`
podle `DataSourceConfig::getAccountChart()` (`'default'` | `'npo'`,
default `'default'`). Pro zdroje dat, které dostanou osnovu **importem ze
starého Shipardu** (nebo ji nechtějí vůbec), je potřeba seed cíleně vypnout —
globální `skipProvisioning` je na to moc široké (vyplo by i fiscal years,
units, atd.).

Z designu uzavřeno: přidat hodnotu **`accountChart: "none"`** → seed osnovy se
přeskočí, ostatní provisioning běží normálně.

> Pozn.: Migrované DS dnes mají provisioning vypnutý přes `skipProvisioning`,
> takže pro samotný import (`old_shipard:…/tasks/08-accounts.md`) tohle není
> blokující. `none` je pro DS, kde provisioning běží, ale standardní osnova se
> nemá seedovat. Přidáváme to do zásoby na později.

## Před implementací přečti

- `src/Command/DataSource/DsUpgradeCommand.php` — metoda `provisionAccountChart`
  (kolem ř. 432–467).
- `src/Core/Config/DataSourceConfig.php` — `getAccountChart()` (ř. ~119).

## Co implementovat

V `provisionAccountChart()`, hned za řádkem
`$variant = $dsConfig->getAccountChart();` a **před** `$file = match (...)`,
přidat:

```php
if ($variant === 'none') {
    $output->writeln(
        "  <comment>[SKIP] accountChart='none' — standardní osnova se neseeduje.</comment>",
        OutputInterface::VERBOSITY_VERBOSE,
    );
    return;
}
```

Volitelně doplnit komentář u `DataSourceConfig::getAccountChart()`, že platné
hodnoty jsou `'default' | 'npo' | 'none'`.

## Hotovo když

- DS s `"accountChart": "none"` v `config/main.json` projde `ds-upgrade` bez
  naseedování účtů; ostatní provisioning (fiscal years, units, …) běží dál.
- `'default'` / `'npo'` se chovají beze změny.
- Neznámá hodnota stále spadne na `'default'` s warningem (existující chování).
