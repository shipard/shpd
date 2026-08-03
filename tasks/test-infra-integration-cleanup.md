# Test infra — úklid integračních testů (spojení, paměť, skip bez env)

**Stav:** hotovo

## Kontext

Infrastruktura integračních testů (`tests/Integration/IntegrationTestCase.php`)
má tři problémy, které se projevily jako „memory leak v testech" a potíže
dev serveru při plném běhu sady:

1. **Spojení se nikdy nezavírají.** `setUp()` otevírá pro *každou testovací
   metodu* nové mysqli spojení (`DataSourceConnection`), `tearDown()` ho
   nezavírá — `DataSourceConnection` metodu `disconnect()` vůbec nemá.
   Při plném běhu (194 testů) se spojení hromadí podle milosti GC
   cyklických referencí; MariaDB má default `max_connections` 151, takže
   běh sady může vyčerpat spojení i běžící aplikaci nad stejnou DB.
2. **Paměť roste.** Každý test navíc volá plný `TableLoader::load()`
   (všechny definice tabulek) a drží výsledek v `$this->tables`; nic se
   neuvolňuje explicitně.
3. **Skip bez env proměnné neprobíhá čistě.** Bez
   `SHIPARD_INTEGRATION_DS_PATH` skončí **68 testů chybou** `Typed
   property $db must not be accessed before initialization` — `tearDown()`
   podtříd sahá na `$this->db` i po skipu v `setUp()`. Pět novějších
   souborů (Accbal, Bank) má `isset` guard, jedenáct starších ne; vzor je
   nekonzistentní a dá se snadno napsat špatně.

Produkčního kódu se problém týká jedinou drobností (chybějící
`disconnect()`); zbytek je čistě testová infrastruktura.

## Návaznost

- Nezávislé na auth/mail fázích; může běžet kdykoli, ideálně před
  `tasks/mail-outbound.md` (jeho testy přidají další integrační třídy
  a měly by už stavět na opraveném vzoru).
- Žádné schéma, žádný `ds-upgrade`, žádný frontend.

## Před implementací přečti

- `tests/Integration/IntegrationTestCase.php` — celý (krátký).
- `src/Core/Database/DataSourceConnection.php` — wrapper nad
  `Dibi\Connection`.
- `vendor/dibi/dibi/src/Dibi/Connection.php` ř. ~169 —
  `disconnect(): void` (final public; ověřit chování při nikdy
  nenavázaném lazy spojení).
- Vzorek podtříd s vlastním `tearDown()` (16 výskytů):
  `grep -rn "function tearDown" tests/Integration/*/*.php`
  — např. `Settings/SettingsStoreTest.php` (bez guardu, padá),
  `Accbal/BalanceMatcherTest.php` (s `isset` guardem).

## Scope

1. `DataSourceConnection::disconnect()`.
2. Restrukturalizace `IntegrationTestCase`: template-method hook pro
   úklid podtříd, deterministické zavření spojení, uvolnění properties.
3. Migrace všech podtříd s vlastním `tearDown()` na hook.
4. Cache `TableLoader::load()` per DS+jazyk v testové základně.

**Non-goals:** paralelní běh testů; transakční izolace (rollback vzor);
CI pipeline; sdílené fixtures nad rámec cache definic; jakékoli změny
chování produkčního kódu kromě přidané metody `disconnect()`.

## Změny po souborech

### Commit 1 — základna

**`src/Core/Database/DataSourceConnection.php`** — nová metoda
`disconnect(): void` delegující na `Dibi\Connection::disconnect()`.
Bezpečná i při nikdy nenavázaném spojení (Dibi je lazy — ověřit, případně
obalit kontrolou `isConnected()`).

**`tests/Integration/IntegrationTestCase.php`**:

- `tearDown()` označit **`final`** a přestavět na šablonu:

  ```php
  final protected function tearDown(): void
  {
      if (isset($this->db)) {
          $this->onTearDown();          // úklid podtřídy (smí sahat na $db)
          $this->db->disconnect();
      }
      if (isset($this->dsPath) && is_dir($this->dsPath)) {
          $this->rmTree($this->dsPath);
      }
      unset($this->db, $this->tables, $this->dsConfig);
      parent::tearDown();
  }

  /** Úklid podtřídy; volá se JEN když setUp doběhl (db existuje). */
  protected function onTearDown(): void
  {
  }
  ```

- `final` na `tearDown()` je záměr: podtřída *nemůže* vzor obejít a
  vrátit bug zpátky. Kdo potřebuje úklid, přepíše `onTearDown()`.
- `unset()` typed properties je kanonická PHPUnit hygiena — instance
  drží reference do konce běhu procesu dle verze/konfigurace; explicitní
  uvolnění dělá paměťový profil plochý bez ohledu na to.

- **Cache definic tabulek** tamtéž:

  ```php
  /** @var array<string, array<string, TableDefinition>> */
  private static array $tablesCache = [];
  ```

  V `setUp()`: klíč `$path . '|' . $language`; `TableLoader::load()` jen
  při miss. Definice pocházejí ze souborů repa a testy je nemutují
  (ověřit — pokud nějaký test definice upravuje, dostane vlastní kopii,
  ne výjimku z cache). Statická cache se **neuvolňuje** v unset —
  přežívá celý běh záměrně, je to jedna instance dat místo 194.

### Commit 2 — migrace podtříd

Všech 16 souborů s vlastním `tearDown()`:
`tearDown()` → `onTearDown()` (bez volání `parent::tearDown()` — to už
řeší základna; bez `isset($this->db)` guardů — hook běží jen
inicializovaný). U pěti souborů s existujícím guardem (Accbal 2×,
Bank 3×) guard odstranit. Podtřídy, které v `tearDown()` dělaly jen
`parent::tearDown()`, override smazat úplně.

Nic jiného se v testech nemění — žádné úpravy asercí ani fixtures.

## Testy

Task testy opravuje, nové nepíše; ověření je behaviorální:

- **Bez env:** `vendor/bin/phpunit tests/Integration` na stroji bez
  `SHIPARD_INTEGRATION_DS_PATH` → **0 errors, 194 skipped** (dnes:
  68 errors, 126 skipped).
- **S env:** plný běh proti dev DS → zelený, žádná změna počtu
  provedených testů oproti stavu před taskem.
- **Paměť:** `php -d memory_limit=256M vendor/bin/phpunit
  tests/Integration` s env → projde; peak memory zaznamenat do commit
  message (očekávání: desítky MB, ploché).
- **Spojení:** během plného běhu `SHOW STATUS LIKE 'Threads_connected'`
  drží nízkou konstantu (ručně, stačí ověřit jednou a poznamenat).
- Unit sada beze změny zelená (2801 testů) — `disconnect()` nic
  nerozbíjí.

## Commit strategie

1. `test-infra: DataSourceConnection::disconnect, IntegrationTestCase
   teardown hook + tables cache`
2. `test-infra: migrate integration test tearDowns to onTearDown hook`

## Hotovo když

- [ ] Běh bez env proměnné: čistý skip, žádné errors.
- [ ] Plný běh s env: zelený, peak memory plochý (zaznamenáno),
      `Threads_connected` konstantní.
- [ ] `tearDown()` v základně je `final`; žádná podtřída nemá vlastní
      `tearDown()` ani `isset($this->db)` guard.
- [ ] `TableLoader::load()` se za celý běh volá jednou per (DS, jazyk).
- [ ] Unit sada zelená.
