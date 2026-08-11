# ds-setup — Task 02: Přenos jazyka a země z hostingu

**Stav:** naplánováno

> PRD pro jednu Claude Code session. Design: `docs/ds-setup.md`,
> rozhodnutí **D1, D7**, kontrakt **§5.6**. Navazuje bezprostředně na
> Task 01 — po Tasku 01 je provisioning agent rozbitý a tenhle task ho
> spravuje.

## Kontext

Task 01 udělal `--language` a `--country` povinnými přepínači `ds-create`.
`HostingSyncRunner` je nepředává, takže provisioning z portálu selže na
prvním kroku. Tenhle task doplňuje celou cestu: admin formulář → řádek
`hosting_core_data_sources` → queue payload → agent → `ds-create`.

Rozhodnutí D7 drží rozsah úmyslně malý: **hosting přispívá výhradně
vrstvou A.** Žádné volání registru z agenta, žádné business řádky, žádná
změna v `confirm`. Firemní identitu (vlastní Osoba, registrace DPH,
bankovní spojení) nastavuje průvodce až v cílovém DS.

## Cíl

1. Dva sloupce na `hosting_core_data_sources`.
2. Pole ve formuláři + řádky v detailu vieweru.
3. Povinnost při přechodu do `request` (validace dokumentu).
4. Položky v queue payloadu.
5. Předání v agentovi do `ds-create`.

## Závislosti

- **Závisí na Tasku 01** — bez něj `ds-create` přepínače nezná.
- Otevírá: nic. Vrstvou A to končí.

## Potvrzená designová rozhodnutí (Anna)

1. **D7** — hosting se vrstvy C nedotýká. Kdyby při implementaci vznikla
   chuť poslat do payloadu IČO nebo cokoli o firmě: ne. Bylo to vědomě
   zamítnuto (nesprávný actor, ARES v agentovi jako nová chybová plocha).
2. Země a jazyk jsou **povinné pro založení**, ne jen doporučené — jinak
   by se povinnost z `ds-create` obcházela přes hosting.

## Před implementací přečti

- `docs/ds-setup.md` §5.6 — kontrakt
- `docs/hosting.md` §5.2 — popis queue payloadu (bude se aktualizovat)
- `modules/hosting/core/tables/hosting_core_data_sources.jsonc` — skupiny
  sloupců (`identity`, `placement`, `status`, `oidc`, `mail`)
- `modules/hosting/core/src/HostingDataSourceDocument.php` — `beforeSave`
  a validační smyčka nad `web_id` / `install_module` (~ř. 51)
- `modules/hosting/core/src/DataSourcesForm.php`,
  `DataSourcesViewer.php` — vzory polí a detailových řádků
- `src/Api/Controller/HostingServerController.php` — `buildQueueItem()`
  (~ř. 528) a `peek` varianta (~ř. 180)
- `src/Core/Server/HostingSyncRunner.php` — krok a. (`ds-create`, ~ř. 283)

## Rozsah

### `modules/hosting/core/tables/hosting_core_data_sources.jsonc`

Dva sloupce do skupiny **`identity`**, za `web_id`:

```jsonc
{
    "id": "language",
    "name": "Language",
    "name:cs": "Jazyk",
    "name:en": "Language",
    "type": "enumString",
    "length": 2,
    "nullable": false,
    "default": "cs",
    "cfgItem": "world.base.languages",
    "group": "identity"
},
{
    "id": "country",
    "name": "Country",
    "name:cs": "Země",
    "name:en": "Country",
    "type": "enumString",
    "length": 2,
    "nullable": false,
    "default": "cz",
    "cfgItem": "world.base.countries",
    "group": "identity"
}
```

Vzor `enumString` + `cfgItem` nad zemí je
`economy_codebooks_vat_registrations.country`. Sloupcové defaulty jsou tu
kvůli `ALTER TABLE` na existujících řádcích — **nejsou** náhradou validace
(bod níže), stejně jako u `region`/`country` v registracích DPH.

`world.base.languages` je plný ISO 639-1 seznam. Pokud se v selectu ukáže
jako nepoužitelně dlouhý, **neřeš to filtrací cfgItemu** (ten je světový
a používají ho i jiné moduly) — omez options ve formuláři.

Po změně JSONC: `vendor/bin/shpd-ds ds-upgrade` na dev hosting DS, ověř
`[ALTER]` řádek.

### `modules/hosting/core/src/HostingDataSourceDocument.php`

Do existující validační smyčky nad povinnými poli (dnes `web_id`,
`install_module`) přidat `language` a `country`. Zachovej stejný tvar
labelů a error codes, jaký tam je — nezavádět nový vzor kvůli dvěma polím.

Validovat při přechodu do lifecycle `request` (tedy tam, kde se dnes
validuje `install_module`), ne při každém uložení — admin si může řádek
rozpracovat.

### `modules/hosting/core/src/DataSourcesForm.php`

Dva inputy do sekce, kde je dnes `web_id` a `install_module`. Hinty:

- Jazyk: „Výchozí jazyk aplikace nového zdroje dat. Uživatel si ho
  může přebít ve svém účtu.“
- Země: „Zem právního subjektu. Určuje registr firem, sazby DPH a formát
  adres. **Po založení se nemění.**“

Ten poslední tvrdý dovětek u země tam chci mít — je to jediné pole
formuláře s nevratným dopadem.

### `modules/hosting/core/src/DataSourcesViewer.php`

Do detailu (skupina `identity`, kde je dnes `Web ID`) přidat dva řádky se
**lokalizovanými názvy** z cfgItemu, ne s kódy — v detailu má být
„Čeština“ / „Česko“, ne `cs` / `cz`. Vzor rozpadu enumString na label
najdi v jiném vieweru, který cfgItem zobrazuje.

### `src/Api/Controller/HostingServerController.php`

Do `buildQueueItem()` přidat `'language'` a `'country'` (obojí `(string)`
cast jako sousední pole). Varianta `peek` (~ř. 180) je informativní —
přidat je tam **taky**, ať `--dry-run` agenta ukazuje, co reálně poletí.

### `src/Core/Server/HostingSyncRunner.php`

V kroku a. rozšířit argumenty:

```php
[$this->shpdServerPath, 'ds-create', '--ds-id', $dsId, '--name', $name,
 '--module', $module, '--language', $language, '--country', $country],
```

Hodnoty vytáhnout z payloadu ve stejném místě, kde se dnes vytahují
`$name` / `$module`. **Chybějící hodnota v payloadu = chyba požadavku**
(vzor existující kontroly nad `issuer`/`client_secret`, která vrací
`'Queue payload is missing oidc issuer/client_secret'`) — ne dopočítaný
default. Hosting starší verze než DS server je legitimní stav a musí
skončit hlasitě, ne založením DS s odhadnutou zemí.

Pozor na idempotenci: krok a. se přeskakuje, když adresář existuje — to
zůstává, jazyk a zemi na už založeném DS tenhle krok nedorovnává.

### `docs/hosting.md`

V §5.2 doplnit `language` a `country` do výčtu položek queue payloadu
(`{request_id, ds_id, name, install_module, web_id, host, owner, oidc}`).
Zpětný odkaz na `ds-setup.md` už v dokumentu je, ten neměnit.

## Testy

`tests/Unit/Api/Controller/HostingServerControllerTest.php`:

- queue payload obsahuje `language` a `country` z řádku
- `peek` varianta je obsahuje taky

`HostingSyncRunner` — pokud pro něj test existuje, přidat případ
„payload bez `country` → chyba, `ds-create` se nevolá“. Pokud ne,
pokryj to E2E níže.

`HostingDataSourceDocument` — přechod do `request` bez země selže.

Spuštění: `vendor/bin/phpunit --filter 'Hosting'`.

## E2E na dev serveru (součást tasku)

1. Admin formulář: nový DS bez země → validace ho nepustí do `request`.
2. S vyplněnou zemí → `shpd-server hosting-sync --dry-run` ukáže oba
   parametry v payloadu.
3. Reálný běh `hosting-sync` → DS vznikne a jeho `main.json` má
   `defaultLanguage` i `country` shodné s tím, co bylo ve formuláři.
4. `ds-upgrade` na tom novém DS → žádný `[WARN]` o chybějící zemi
   (tj. cesta Task 01 + Task 02 je uzavřená).
5. Opakovaný běh agenta → idempotentní, žádný druhý DS.

## Hotovo když

- [ ] Provisioning z portálu opět funguje end-to-end (regrese z Tasku 01
      je zavřená)
- [ ] `main.json` nového DS nese jazyk a zemi z admin formuláře
- [ ] Požadavek bez země se nedostane do `request`
- [ ] Payload bez země zastaví agenta s jasnou chybou, nezaloží DS
- [ ] Detail vieweru ukazuje lokalizované názvy, ne kódy
- [ ] `docs/hosting.md` §5.2 aktualizovaná
- [ ] `vendor/bin/phpunit --filter 'Hosting'` zelené

## Pasti / na co pozor

- **`ds-upgrade` na hosting DS** je potřeba po změně JSONC — bez něj
  formulář spadne na chybějícím sloupci.
- `hosting_core_data_sources` má `"adminOnly": true` (hosting D9) — nové
  sloupce na tom nic nemění, ale při ručním testování se přihlas jako
  admin hostingu, jinak dostaneš 403 a budeš to hledat jinde.
- Payload je jediné místo, kde z hostingu odchází `client_secret` — když
  budeš přidávat pole do `buildQueueItem()`, nepřehleď, že se ta metoda
  volá i z `confirm` cesty; přidávej jen do sestavení payloadu.
- Neřeš, co se stane při **změně** země na existujícím řádku evidence.
  Lifecycle už je `active`, agent krok a. přeskakuje, takže se změna
  nikam nepropíše. Zamykání toho pole po založení je samostatná drobnost
  (kandidát na follow-up), ne součást tohoto tasku.
