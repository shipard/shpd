# Task: `POST /_setup/parameters` respektuje `skipProvisioning`

**Stav:** hotovo
**Návaznost:** docs/ds-setup.md §7.2 (D9 — import zapisuje parametry vrstvy C sám);
protistrana `old_shipard:modules/imports/newShipard/tasks/25-layer-c-settings.md`
(importér bude endpoint volat).

## Cíl

`SetupController::runProvisioners()` dnes po uložení parametrů vrstvy C spouští
provisionery (osnova, fiskální roky) **bezpodmínečně** — nerespektuje
`skipProvisioning` z `config/main.json`. Na migrovaném DS (import ze starého
Shipardu, `skipProvisioning: true`) by tak zápis parametrů přes API doseedoval
příští fiskální rok vedle importovaných, což je přesně to, co má flag vypínat
(`DataSourceConfig::shouldSkipProvisioning()`: „reference data is supplied by
the import itself").

Oprava je obecná (týká se i panelu dsSetup na jakémkoli DS s vypnutým
provisioningem), ale bezprostředním konzumentem je importér ze starého
Shipardu, který bude `POST /_setup/parameters` používat jako jedinou zápisovou
cestu ke čtyřem klíčům vrstvy C.

## Scope

- Guard na začátku `runProvisioners()` + lokalizované informativní varování.
- Test.
- Poznámka v `docs/ds-setup.md`.

**Mimo scope:** jakákoli změna validace/ukládání parametrů (zůstává
all-or-nothing přes `LayerCParameters::validate()`), chování `ds-upgrade`,
frontend panelu dsSetup (varování zobrazí existující mechanismus `warnings`).

## Změny po souborech

### `src/Api/Controller/SetupController.php`

1. Na začátek `runProvisioners(array $writtenKeys, SettingsStore $settings)`:

   ```php
   if ($this->dsConfig?->shouldSkipProvisioning() === true) {
       // Provisionery by běžely jen pro tyhle klíče — bez nich by varování
       // bylo šum (samotné vatAgenda žádný provisioner nespouští).
       $triggering = ['economy.accountChart', 'economy.fiscalYearStartMonth', 'economy.homeCurrency'];
       if (array_intersect($writtenKeys, $triggering) !== []) {
           return [$this->warnProvisioningDisabled()];
       }
       return [];
   }
   ```

2. Nová privátní metoda vedle `warnProvisionerFailed()` (stejný vzor
   lokalizace přes `$this->language`):

   ```php
   private function warnProvisioningDisabled(): string
   {
       return $this->language === 'cs'
           ? 'Provisioning je na tomto zdroji dat vypnutý (skipProvisioning) — parametry jsou uložené, seed proběhne až po jeho zapnutí přes ds-upgrade.'
           : 'Provisioning is disabled on this data source (skipProvisioning) — the parameters are saved; seeding will run once it is re-enabled via ds-upgrade.';
   }
   ```

Parametry se ukládají beze změny — guard se týká výhradně okamžitého běhu
provisionerů. `$this->dsConfig` je nullable (default `null`) a `null` znamená
dnešní chování (provisionery běží) — žádný existující call site se nemění.

### `tests/Unit/Api/Controller/SetupControllerTest.php`

Nový test podle vzoru existujících testů `saveParameters`:

- DS config se `skipProvisioning: true`, POST s `economy.accountChart =
  'default'` (+ klidně všechny čtyři klíče najednou — tvar, který pošle
  importér).
- Ověřit: klíče jsou v `core_system_settings` uložené; **žádný** řádek
  nepřibyl v `economy_accounting_accounts` ani
  `economy_codebooks_fiscal_years`; `warnings` obsahuje právě jednu zprávu
  (o vypnutém provisioningu).
- Druhý případ: `skipProvisioning: true` + zápis **jen** `economy.vatAgenda`
  → uloženo, `warnings` prázdné.

Spouštět úzkým filtrem, např.
`vendor/bin/phpunit --filter SetupControllerTest tests/Unit/Api/Controller/SetupControllerTest.php`.

### `docs/ds-setup.md`

§7.2 doplnit dvě věty: import zapisuje klíče přes `POST /_setup/parameters`
(jediná validovaná vzdálená cesta) a `runProvisioners` na DS se
`skipProvisioning` provisionery přeskakuje — parametry se uloží, seed dorovná
`ds-upgrade` po zapnutí provisioningu. Případně krátká zmínka i v popisu
endpointu v `docs/rest-api.md`, pokud tam je u `POST /_setup/parameters` víc
než řádek v tabulce.

## Commit strategie

Jeden commit: `setup: POST /_setup/parameters honors skipProvisioning`
(kód + test + docs společně).

## Hotovo když

- [x] `POST /_setup/parameters` na DS se `skipProvisioning: true` uloží
      parametry a nespustí žádný provisioner.
- [x] Odpověď nese informativní varování jen tehdy, když by některý zapsaný
      klíč provisioner spustil.
- [x] DS bez `skipProvisioning` (nebo bez `dsConfig`) se chová jako dosud.
- [x] Nové testy zelené (úzký `--filter`), existující testy SetupControlleru
      nespadly.
- [x] `docs/ds-setup.md` §7.2 zmiňuje endpoint i guard.
