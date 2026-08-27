# Task: Panel „O zdroji dat" (About) v Nastavení

**Stav:** hotovo

**GitHub Issue:** [#41 Informace o zdroji dat](https://github.com/shipard/shpd/issues/41)

## Kontext

Chceme do aplikace dát základní informace o zdroji dat na jednom místě —
po vzoru webových prohlížečů jako poslední položka v Nastavení. Panel je
čistě informační (read-only), do budoucna rozšiřitelný (billing, expirace
zkušební doby).

Všechna data jsou dostupná z živého DS — žádná mocková vrstva:

- **Název DS** — setting `app.name` (`SettingsStore`, tabulka
  `core_system_settings`)
- **Vlastní osoba** — `base_persons_persons` s `is_own = 1` a
  `docState IN (10, 40)` (vzor: `SetupController::ownPerson()`,
  `src/Api/Controller/SetupController.php:621`)
- **E-mail DS** — `core_mail_mailboxes`, výchozí schránka
  (`is_default = 1`, sloupec `email_address`)
- **Plátce DPH** — aktivní záznam v `economy_codebooks_vat_registrations`
  (`valid_from <= dnes`, `valid_to IS NULL OR >= dnes`, aktivní `docState`)
- **Firma/NPO** — setting `economy.accountChart`
  (`default` = podnikatel, `npo` = nezisková organizace, `none` = bez osnovy)
- **Velikost DB** — `information_schema.tables` pro aktuální databázi
- **Velikost příloh** — rekurzivní součet `<dsPath>/att/`
  (cesta: `DataSourceConfig::getDataSourceDir()` + `/att`)
- **Počty** — `COUNT(*)` nad `docs_core_heads`,
  `core_mail_incoming_messages`, `core_attachments_files`

Mechanismus zobrazení: **panel** — deklarace v `module.jsonc`
(`panels[]` + `settingsItems[]`), Svelte komponenta zaregistrovaná v mapě
`panelComponents` v `ContentArea.svelte`. Stejný vzor jako `dsSetup`.

Panel je jen pro **běžný účetní DS** (`install.base`). Dedikovaný hosting
DS (`install.hosting`, D11) nemá nainstalované moduly `base.persons`,
`core.mail`, `economy.*`, `docs.core` ani `core.attachments` — About by
tam neměl co ukázat, sekci tam proto nezavádíme (D6).

## Cíl

Po dokončení platí:

- V Nastavení existuje nová sekce „O zdroji dat" jako **poslední** položka
  sidebaru (jen na běžném DS, ne na hosting profilu — D6).
- Kliknutí otevře panel se třemi bloky (seřazeno podle důležitosti):
  1. **Identita** — název DS, vlastní osoba (název, IČO, DIČ),
     e-mailová adresa DS
  2. **Charakteristika** — plátce/neplátce DPH, typ osnovy
     (podnikatel/NPO/bez osnovy), ID zdroje dat, datum vytvoření
  3. **Velikosti a počty** — velikost databáze, velikost příloh,
     počet dokladů / příchozí pošty / příloh
- Chybějící údaj (žádná vlastní osoba, žádná schránka…) se zobrazí jako
  decentní „—" nebo lokalizované „zatím nenastaveno", nikdy chyba.
- Vše read-only; žádná tlačítka Smazat/Reset (D3).
- `npm run build` projde, PHPUnit projde.

## Potvrzená designová rozhodnutí (Anna, 2026-08-27)

- **D1 — Umístění:** nová sekce `about` s `order: 200` (za sekcí `other`)
  v profilu `install/base`. Jediná položka sekce = panel `dsAbout`.
- **D2 — Viditelnost:** panel vidí všichni uživatelé DS (žádný
  `adminOnly` gate). Pozdější zúžení na adminy je otevřená otázka,
  neřešíme teď.
- **D3 — Nebezpečná tlačítka:** „Smazat zdroj dat" a „Reset zdroje dat"
  se v této fázi NEIMPLEMENTUJÍ. Zapsáno do Issue #41.
- **D4 — Výpočty velikostí:** velikost DB a počty záznamů naživo (levné
  dotazy). Sken `att/` s cache v `SettingsStore` — klíč
  `about.attachmentsSize`, JSON `{bytes, files, computedAt}`, TTL 3600 s.
  Po expiraci se přepočítá synchronně v rámci requestu.
- **D5 — Řazení bloků v panelu:** od nejdůležitějšího po nejméně důležité:
  identita → charakteristika → velikosti a počty.
- **D6 — Hosting profil bez About:** sekce `about` se do
  `install/hosting/config/settingsSections.jsonc` NEPŘIDÁVÁ. Hosting DS
  nemá moduly s daty, které panel zobrazuje. Mechanismus: `core.system`
  sice deklaruje settingsItem všude, ale položka v sekci, která v profilu
  neexistuje, se v navigaci tiše zahodí — žádný gate není potřeba.
  Endpoint ale musí chybějící tabulky tolerovat (viz Rozsah bod 1).

## Ověření zadání proti kódu (2026-08-27)

Před implementací ověřeno; kde se zadání rozcházelo s kódem, je to
opraveno přímo v textu Rozsahu, tady je souhrn:

- `DataSourceConnection::tableExists()` je **private** — existence tabulek
  se ověřuje výhradně přes registry `$tables` (`TableLoader::load`, klíč =
  název tabulky, vzor `SetupController` ř. 548). Žádné `SHOW TABLES`.
- Osoby mají tři identifikátory: `company_id` = IČO, `tax_id` = DIČ,
  `vat_id` = „DIČ pro DPH" (`SetupController::ownPerson()` čte `vat_id`).
  Panel zobrazuje IČO + DIČ (`tax_id`).
- `taxpayer_kind` je enumInt s cfgItem `economy.codebooks.vatTaxpayerKinds`
  (0 Klasický plátce, 1 OSS) — server posílá i lokalizovaný
  `taxpayerKindLabel`, frontend enum neduplikuje.
- Formátovač bajtů existuje: `formatFileSize()` v
  `frontend/src/api/attachments.js` (B / KB / MB,1 / GB,1) — použít,
  nepřesouvat.
- Routy `/_ui/*` jsou v `Router.php` pohromadě u `/_ui/section-badges`
  (ř. ~68), ne u `/_setup` (ř. 317) — nová routa patří tam.
- `global.settingsSections` je kompilovaná cfgItem — po přidání sekce je
  na dev DS nutný `vendor/bin/shpd-ds ds-upgrade`, jinak se sekce neukáže.
- Vzor panelu dsSetup (commit `a101e80`) aktualizoval i `docs/rest-api.md`
  a `docs/app-settings.md`; CLAUDE.md navíc vyžaduje `help/` ve stejném
  commitu → přidány body 10 a 11 Rozsahu.
- Unit test controlleru je levný — `SetupControllerTest::makeDb()` má
  hotový vzor (`fetchSingle` callback podle substringu SQL, temp dir pro
  `DataSourceConfig` jako `AppControllerTest`).

## Před implementací přečti

- `docs/ds-setup.md` — panel dsSetup jako vzor (D12/D14), vrstvy A/C
- `src/Api/Controller/SetupController.php` — vzor malého controlleru
  s vlastními routami: `ownPerson()` (ř. 621), konstruktor, Response tvary
- `src/Api/Router.php:866` (`resolveSetupRoute`) — vzor registrace rout
- `public/index.php:690` (`dispatchSetup`) — vzor dispatch funkce
- `frontend/src/components/settings/DsSetup.svelte` — vzor panelu
  (loading/error stavy, i18n, struktura)
- `frontend/src/api/setup.js` — vzor API helperu (`get` z `client.js`)
- `modules/core/system/module.jsonc:161` — deklarace `panels[]`
  a `settingsItems[]` (ř. 189)
- `frontend/src/components/layout/ContentArea.svelte:18` — mapa
  `panelComponents`

## Rozsah

### 1. Backend — `src/Api/Controller/DsAboutController.php` (nový)

Jedna akce `about(AuthContext $auth): Response` → `GET /_ui/ds-about`.

Vrací (tvar odpovědi):

```json
{
  "identity": {
    "dsName": "…",                    // setting app.name, fallback DataSourceConfig::getName()
    "ownPerson": {                    // null pokud neexistuje
      "fullName": "…",
      "companyId": "…",               // IČO, může být null
      "taxId": "…"                    // DIČ, může být null
    },
    "mailAddress": "…"                // default mailbox, null pokud žádný
  },
  "profile": {
    "vatPayer": true,                 // aktivní registrace DPH existuje
    "taxpayerKind": 0,                // enumInt z registrace, null pokud neplátce
    "taxpayerKindLabel": "…",         // lokalizovaný název z cfgItem vatTaxpayerKinds, null pokud neplátce
    "accountChart": "default",        // default | npo | none | null (nerozhodnuto)
    "dsId": "…",                      // DataSourceConfig::getId()
    "created": "2026-…"               // DataSourceConfig::getCreated()
  },
  "storage": {
    "databaseBytes": 123,
    "attachments": { "bytes": 123, "files": 45, "computedAt": "…" },
    "counts": { "documents": 12, "incomingMail": 34, "attachmentFiles": 45 }
  }
}
```

Poznámky k implementaci:

- Velikost DB: `SELECT SUM(data_length + index_length) FROM
  information_schema.tables WHERE table_schema = DATABASE()`.
- Sken `att/`: `RecursiveDirectoryIterator` se `SKIP_DOTS`; počítej bytes
  i počet souborů. Výsledek ulož přes `SettingsStore::set()` do klíče
  `about.attachmentsSize` (JSON). Při čtení: pokud `computedAt` mladší
  než 3600 s, vrať cache bez skenu.
- Neexistující `att/` adresář = `{bytes: 0, files: 0}` (žádná výjimka).
- Vlastní osoba: dotaz podle vzoru `SetupController::ownPerson()`,
  navíc sloupce `company_id` (IČO) a `tax_id` (DIČ). Pozor: `ownPerson()`
  čte `vat_id` = „DIČ pro DPH" — to je třetí, jiný sloupec.
- **Tolerance chybějících tabulek (D6):** na hosting profilu neexistují
  `base_persons_persons`, `core_mail_mailboxes`,
  `economy_codebooks_vat_registrations`, `docs_core_heads`,
  `core_mail_incoming_messages` ani `core_attachments_files`. Před
  dotazem ověř existenci tabulky **jen** přes registry `$tables`
  (`DataSourceConnection::tableExists()` je private) a chybějící blok
  vrať jako null/nuly — přímé zavolání endpointu na hostingu nesmí
  skončit 500.
- Žádné mutace kromě zápisu cache klíče.

### 2. Routa — `src/Api/Router.php`

- Do bloku `/_ui/*` rout (za `GET /_ui/section-badges`, okolí ř. 68 —
  ne k `/_setup` na ř. 317) přidej větev pro `/_ui/ds-about` →
  `new Route('dsAbout', 'about')`, jiná metoda → 405.
  Routa je záměrně `/_ui/ds-about`, ne `/_ui/settings/about`, aby se
  nezaplétala do settings page mechanismu (`/_ui/settings/…`).

### 3. Dispatch — `public/index.php`

- Nová funkce `dispatchDsAbout(...)` podle vzoru `dispatchSetup`
  (ř. 680): sestaví `DsAboutController($db, $configRuntime, $dsConfig,
  $language, $tables)` a zavolá akci; `$tables` je registry
  z `TableLoader::load` (guard chybějících tabulek). Zapoj do hlavního
  `match` na `$route->controller === 'dsAbout'`.

### 4. Deklarace panelu — `modules/core/system/module.jsonc`

- Do `panels[]` (ř. 161) přidej:
  ```jsonc
  {
      "id": "dsAbout",
      "name": "About data source",
      "name:cs": "O zdroji dat",
      "name:en": "About data source",
      "icon": "info"
  }
  ```
- Do `settingsItems[]` (ř. 189) přidej:
  `{ "panel": "dsAbout", "section": "about", "order": 10 }`

### 5. Sekce — jen `install/base`

- `modules/install/base/config/settingsSections.jsonc` — na konec
  `sections[]`:
  ```jsonc
  {
      "id": "about",
      "name": "About data source",
      "name:cs": "O zdroji dat",
      "name:en": "About data source",
      "icon": "info",
      "order": 200
  }
  ```
- `modules/install/hosting/config/settingsSections.jsonc` se
  **záměrně NEMĚNÍ** (D6).

### 6. Frontend — `frontend/src/api/dsAbout.js` (nový)

- `export function fetchDsAbout() { return get('/_ui/ds-about'); }`
  (import `get` z `./client.js`).

### 7. Frontend — `frontend/src/components/settings/DsAbout.svelte` (nový)

- Vzor `DsSetup.svelte`: `onMount` → fetch, `loading` / `error` stavy,
  `t()` z i18n.
- Tři bloky dle D5. Definiční seznam (label + hodnota), žádné formuláře.
- Formátování velikostí: `formatFileSize()` z
  `frontend/src/api/attachments.js` (B / KB / MB a GB s jedním desetinným
  místem) — existující helper, nepřesouvat ani neduplikovat.
- Typ osnovy a „nerozhodnuto" přes existující klíče `setup.chart.*`
  a `setup.undecided`; druh plátce z `taxpayerKindLabel` ze serveru.
- Chybějící hodnoty: „—" (u vlastní osoby lokalizovaný text
  „zatím nenastaveno").

### 8. Frontend — registrace panelu

- `frontend/src/components/layout/ContentArea.svelte`: import
  `DsAbout` + záznam `dsAbout: DsAbout` do `panelComponents` (ř. 18–24).

### 9. i18n — `frontend/src/i18n/cs.js` + `en.js`

- Nové klíče pro labely panelu (názvy bloků, popisky polí,
  plátce/neplátce, podnikatel/NPO/bez osnovy, „zatím nenastaveno").
  Jmenný prostor `dsAbout.*`.

### 10. Dokumentace — `docs/`

- `docs/rest-api.md`: odstavec `GET /_ui/ds-about` za blokem `/_setup`
  endpointů (tvar odpovědi, tolerance chybějících tabulek, cache).
- `docs/app-settings.md`, sekce *Panel*: doplnit `dsAbout` mezi konzumenty.

### 11. Uživatelská dokumentace — `help/o-zdroji-dat.md` (nová)

- Krátká úlohová stránka (root `help/` → sekce Základy): kde panel najdu
  a co v něm je, popisky přesně dle `cs.js`. Poté
  `python3 scripts/help-index.py`. Formát: `docs/help-authoring.md`.

## Testy

- `tests/Unit/Api/RouterTest.php` — routa `GET /_ui/ds-about` →
  controller `dsAbout` / akce `about`; jiná metoda → 405.
- `tests/Unit/Api/Controller/DsAboutControllerTest.php` — mock DB podle
  vzoru `SetupControllerTest::makeDb()`: (a) registry bez tabulek
  (hosting) → null/nuly a **žádný** SQL na chybějící tabulky;
  (b) cache mladší než hodina → sken se nespustí; (c) sken temp `att/`
  → správné bytes/files + jeden zápis cache; (d) chybějící `att/` →
  nuly bez zápisu; (e) anonym → 401.

## Ověření na dev serveru (součást tasku)

1. `cd frontend && timeout 90 npm run build 2>&1 | tail -4` — bez chyb.
2. PHPUnit: `vendor/bin/phpunit --filter Router` — zeleně.
3. Na dev DS `vendor/bin/shpd-ds ds-upgrade` — rekompilace
   `global.settingsSections` (bez toho se nová sekce neukáže).
4. V prohlížeči (běžný DS): Nastavení → sekce „O zdroji dat" je
   **poslední** v sidebaru; panel zobrazí všechny tři bloky s reálnými
   daty.
5. Na DS bez vlastní osoby / bez schránky (čerstvý DS): panel se zobrazí
   bez chyb, chybějící údaje jako „—".
6. Druhé načtení panelu do hodiny: sken `att/` se nespouští
   (ověř logem nebo časem odpovědi).
7. Hosting DS (pokud je k dispozici): sekce „O zdroji dat" se
   v Nastavení NEZOBRAZUJE; ruční `GET /_ui/ds-about` vrátí odpověď
   s null/nulami, ne 500.

## Hotovo když

- Sekce „O zdroji dat" je poslední položkou Nastavení na base profilu;
  na hosting profilu není (D6).
- Panel zobrazuje identitu, charakteristiku a velikosti/počty
  z živých dat; chybějící údaje neshodí render.
- Endpoint toleruje chybějící tabulky (hosting) — žádná 500.
- Cache velikosti příloh funguje (TTL 1 h).
- Žádná nebezpečná tlačítka (D3).
- Build + testy zelené.

## Pasti / na co pozor

- **Hosting profil se záměrně nemění** (D6) — settingsItem z `core.system`
  se na hostingu sbírá taky, ale bez sekce `about` v hosting
  `settingsSections.jsonc` se položka v navigaci tiše zahodí. To je
  zamýšlený mechanismus, ne bug. NEPŘIDÁVEJ sekci do hosting profilu
  „pro jistotu".
- **Endpoint na hostingu** — tabulky `base_persons_persons`,
  `core_mail_mailboxes`, `economy_*`, `docs_core_heads`,
  `core_attachments_files` tam NEEXISTUJÍ. Bez guardu na existenci
  tabulek spadne přímé volání na SQL chybu.
- **`DataSourceConnection::tableExists()` je private** — nezveřejňovat ho
  kvůli tomuhle panelu; registry `$tables` stačí a je to tentýž zdroj
  pravdy jako u `TableAccessGuard`.
- **Prázdná sekce se v navigaci vynechá**
  (`SettingsController::navigation()`, ř. ~97) — sekce `about` se ukáže
  až s naregistrovaným panelem v `settingsItems[]`. Pokud sekci nevidíš,
  chybí položka, ne sekce.
- **JSONC s diakritikou** — soubory edituj Python heredocem s Unicode
  escapes (`\u010d` apod.) a `assert s.count(old) == 1`, ne shell
  heredocem ani `patch_file`.
- **`valid_to` u registrace DPH** může být NULL (registrace bez konce) —
  podmínka musí být `(valid_to IS NULL OR valid_to >= CURDATE())`.
- **`att/` u čerstvého DS nemusí existovat** — vrať nuly, nezakládej
  adresář (žádné mutace FS mimo cache klíč v DB).
- **Ikona `info`** existuje v mapě `frontend/src/icons.js:240` — nezaváděj
  novou.
- **Žádná citlivá data do commitů** — v task filu ani testech nesmí být
  reálné názvy firem, e-maily ani částky z alfy.
- **`information_schema` dotaz** vrací bajty za celou databázi aktuálního
  připojení (`DATABASE()`) — neber `table_schema` z konfigurace, ať to
  funguje i na dev DS.
