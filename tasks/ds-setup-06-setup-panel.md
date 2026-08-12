# ds-setup — Task 06: Panel „Co ještě chybí nastavit"

**Stav:** naplánováno

> PRD pro jednu Claude Code session. Design: `docs/ds-setup.md`,
> rozhodnutí **D3, D12, D14**, kontrakt **§4** a **§5.2**. Druhý kus
> Fáze 3. Karta ve feedu je Task 07 a míří akcí `open_panel` sem.

## Kontext

Task 05 dodal osm setup checků a službu `SetupChecklist`, která je
spouští naživo a vrací nálezy v pořadí `SetupChecklist::ORDER`. Zatím to
ale nikdo nevolá — uživatel vidí setup položky jen ve vieweru alertů
(z cronu) a parametry vrstvy C se dají nastavit jen přes `ds-setting`
v konzoli.

Tenhle task dodává panel, který obojí spojí: **vyjmenuje, co chybí,
a u parametrů to rovnou umí nastavit.**

**Proč panel a ne settings stránka (D14).** Panel je ručně psaná Svelte
komponenta mapovaná v `ContentArea.svelte` přes `panelComponents` (vzor
`accountSecurity`), takže si ovládání vyrenderuje sám a field typy
`select`/`checkbox` nejsou potřeba — ty zůstávají jako obecná mezera
settings stránek v `tasks/TODO.md`. Parametry navíc potřebují
vysvětlující UI, které deklarativní pole neunese, a `vatAgenda` je
tříhodnotový.

## Cíl

1. `GET /_setup/checklist` — položky z `SetupChecklist` + hodnoty parametrů.
2. `POST /_setup/parameters` — zápis parametrů vrstvy C.
3. Panel `dsSetup` v `panels` + `settingsItems`.
4. Svelte komponenta `DsSetup.svelte`.

## Závislosti

- Závisí na Tasku 05 (`SetupChecklist`) — hotový.
- Otevírá: Task 07 (karta ve feedu potřebuje panel jako cíl akce
  `open_panel`).

## Potvrzená designová rozhodnutí (Anna)

1. **D14 — panel, ne settings stránka.** Field typy `select`/`checkbox`
   do tohohle tasku **nepatří**; jsou v `TODO.md` mimo oblast.
2. **D12 — panel spouští checky naživo**, ne z tabulky alertů. Uživatel
   něco doplní, znovu načte panel a položka je hned pryč.
3. **D3 — žádný stav se nepamatuje.** Panel nemá „označit jako
   vyřízené", nemá progress uložený v settings. Prázdný checklist =
   hotovo, a to je vše.
4. **Vrstva A (jazyk, země) do panelu nepatří** — změnit se nedá
   a rekapitulaci řeší až průvodce ve Fázi 4 (§5.4 krok 1).

## Před implementací přečti

- `docs/ds-setup.md` — §4 (architektura, tři pohledy na jednu kolekci),
  §5.2, rozhodnutí D3/D12/D14
- `src/Core/Settings/SetupChecklist.php` — `collect()` vrací
  `list<array{checkId: string, name: string, finding: AlertFinding}>`,
  konstanta `ORDER`
- `src/Core/Settings/LayerCParameters.php` — `SPECS`, `keys()`,
  `validate(string $key, string $raw): string|int|bool`
- `src/Api/Router.php` ~ř. 740 `resolveAlertsRoute()` — **vzor, jak se
  přidává skupina rout** (`_alerts` → `resolveAlertsRoute`); udělej
  `_setup` → `resolveSetupRoute` stejným způsobem
- `src/Api/Controller/AlertsController.php` — jak si controller obstarává
  `AlertCheckRegistry`, `ConfigRuntime`, `$language` a `DataSourceConnection`
- `frontend/src/components/account/AccountSecurity.svelte` — **vzor
  panelu**: struktura, načítání, chybové stavy, styling
- `frontend/src/components/layout/ContentArea.svelte` ~ř. 14 —
  `panelComponents` mapa
- `modules/core/system/module.jsonc` ~ř. 139 — deklarace `panels`
  a `settingsItems` (`{ "panel": "accountSecurity", "section": "basic" }`)
- `src/Api/Controller/SettingsController.php` — `savePage()` jako vzor
  validace a hromadného zápisu; **nepoužívej ho**, parametry mají vlastní
  endpoint

## Rozsah

### `GET /_setup/checklist`

Nový `SetupController`. Odpověď:

```json
{
  "items": [
    {
      "checkId": "economy.accounting.undecided_account_chart",
      "name": "Účtová osnova není vybraná",
      "title": "...",
      "message": "...",
      "severity": "warning",
      "actions": [ ... ],
      "parameter": "economy.accountChart"
    }
  ],
  "parameters": {
    "economy.accountChart": null,
    "economy.fiscalYearStartMonth": 1,
    "economy.vatAgenda": null,
    "economy.homeCurrency": "czk"
  }
}
```

- `items` = `SetupChecklist::collect()`, serializované ploše. Pořadí
  **neměň** — je to kontrakt `ORDER`.
- `parameter` je nové pole: u položek nad nerozhodnutým parametrem uveď,
  o který klíč jde, aby komponenta věděla, jaké ovládání vykreslit.
  Mapování check → klíč drž **na serveru**, ne ve Svelte. Nejlepší místo
  je konstanta v `SetupChecklist` vedle `ORDER` (`PARAMETER_BY_CHECK`),
  protože oba seznamy se budou měnit spolu.
- `parameters` = aktuální hodnoty **všech** klíčů z
  `LayerCParameters::keys()`, včetně `null` u nerozhodnutých. Panel je
  potřebuje i pro rozhodnuté parametry, aby je šlo změnit, ne jen doplnit.

Auth: přihlášený uživatel, stejná úroveň jako `/_settings/page`. Bez
`adminOnly` — v jednouživatelských DS by to jinak zablokovalo majitele.

### `POST /_setup/parameters`

Body `{"values": {"economy.accountChart": "npo", "economy.vatAgenda": null}}`.

- Klíče validuj proti `LayerCParameters::keys()`; neznámý → 422
  `VALIDATION_ERROR` s `field`, `code`, `message` (stejný tvar jako
  `savePage`).
- Hodnoty přes `LayerCParameters::validate()` — **žádná druhá validační
  logika ve controlleru ani ve Svelte.** Ta funkce už existuje a je
  jediné místo pravdy.
- `null` → `SettingsStore::set($key, null)`, tedy smazání klíče = vrácení
  do nerozhodnutého stavu. Podporuj to; je to legální akce (uživatel si
  uvědomí, že vybral špatně, a chce to nechat na průvodci).
- **Po zápisu spusť dotčené provisionery.** `economy.accountChart` →
  `AccountChartProvisioner`, `economy.fiscalYearStartMonth`
  i `economy.homeCurrency` → `FiscalYearsProvisioner` (ten je gatovaný na
  oba klíče, viz Task 04). Bez toho by uživatel parametr rozhodl a nic by
  se nestalo až do dalšího `ds-upgrade` — a to je přesně ta prodleva,
  kterou D12 řeší u čtení.
  - Provisioner selže → **parametr zůstane uložený**, vrať 200
    s polem `warnings`, ať to panel ukáže. Neuloženo-a-neprovisionováno
    je horší stav než uloženo-a-neprovisionováno; druhé dorovná
    `ds-upgrade`.
  - Zaloguj přes `ErrorLogger`.
- Odpověď: stejný tvar jako `GET /_setup/checklist` (nové `items`
  a `parameters`) + případné `warnings`. Panel tak po uložení nemusí
  dělat druhý request.

### Panel `dsSetup`

`modules/core/system/module.jsonc`:

```jsonc
"panels": [
    ...,
    { "id": "dsSetup", "name": "Setup", "name:cs": "Nastavení zdroje dat",
      "name:en": "Data source setup", "icon": "..." }
],
"settingsItems": [
    ...,
    { "panel": "dsSetup", "section": "app", "order": 5 }
]
```

`order: 5` = nad `appSettings` (které má 10). Na čerstvém DS je to první
věc, kterou má uživatel v Nastavení vidět.

Ikonu vyber z existující sady (podívej se, co používají ostatní položky
sekce `app`) — nezaváděj novou.

### `frontend/src/components/settings/DsSetup.svelte`

Registrace v `panelComponents` v `ContentArea.svelte`.

Obsah:

1. **Úvodní odstavec** — co panel je: seznam nastavení, které ještě chybí,
   aby se v DS dalo účtovat. Ne „průvodce" — ten přijde ve Fázi 4 a bude
   to jiná věc.
2. **Prázdný stav** — když `items` je prázdné: krátké potvrzení, že je
   vše nastavené. Žádné konfety, žádný uložený příznak (D3).
3. **Seznam položek** v pořadí ze serveru. U každé `title`, `message`
   a podle typu:
   - **položka nad chybějícím řádkem** (`actions` s `open_form`) →
     tlačítko, které akci provede; použij **stejnou cestu, jakou už
     zpracovává karta feedu / viewer alertů** — najdi ji a zavolej,
     neduplikuj navigaci na formulář.
   - **položka nad parametrem** (`parameter` je vyplněný) → ovládání
     přímo v řádku, viz níže.
4. **Rozhodnuté parametry** pod seznamem, sbalené („Už rozhodnuto"), aby
   se daly změnit, ale nepletly se do checklistu.

Ovládání parametrů:

| Klíč | Ovládání | Poznámka pro uživatele |
|---|---|---|
| `economy.accountChart` | select: Podnikatelská / Nezisková / Žádná | **Po naseedování se varianta nepřepíná** — účty už budou v DS |
| `economy.vatAgenda` | tři explicitní stavy: Nerozhodnuto / Plátce / Neplátce | Řídí jen výchozí režim nových dokladů a viditelnost agendy, ne plátcovství samo |
| `economy.fiscalYearStartMonth` | select 1–12, lokalizované názvy měsíců | Mění se jen pro nově generované roky |
| `economy.homeCurrency` | select nad `world.base.currencies` | Platí jen pro nové záznamy; existující doklady mají měnu uloženou |

Ty poznámky ve třetím sloupci **napiš do UI**, ne jen do kódu. Jsou hlavní
důvod, proč D14 zvolilo ručně psaný panel místo generického selectu.

`vatAgenda` musí mít **tři viditelné stavy**, ne checkbox — nerozhodnuto
je legitimní hodnota a nesmí vypadat jako „neplátce".

Chování:

- Změna ovládání → `POST /_setup/parameters`, optimisticky nic
  nepředbíhej; po odpovědi překresli z vrácených dat.
- `warnings` v odpovědi zobraz nad seznamem, ne jako toast — uživatel si
  je má přečíst.
- Chyba requestu → hodnota se vrátí na původní, chyba se ukáže u pole.

### Dokumentace

- `docs/ds-setup.md` — §4 a §5.2 srovnat s realitou; pokud se cokoli
  odchýlí, uprav spec.
- `docs/rest-api.md` — obě nové routy.
- `docs/app-settings.md` — poznámka, že parametry vrstvy C mají vlastní
  panel a nejdou přes `settingsPages` (odkaz na D14), ať to příště nikdo
  nehledá mezi field typy.

## Testy

`tests/Unit/Api/Controller/SetupControllerTest.php`:

- `GET` vrací položky v pořadí `ORDER` a `parameters` se všemi klíči
  včetně `null`
- `POST` s neznámým klíčem → 422, nic se nezapíše
- `POST` s neplatnou hodnotou → 422 (a ověř, že validaci dělá
  `LayerCParameters`, ne controller)
- `POST` s `null` → klíč se smaže
- `POST` `economy.accountChart` → provisioner proběhl (osnova naseedovaná)
- provisioner vyhodí výjimku → 200, parametr uložený, `warnings` neprázdné

`SetupChecklist` — `PARAMETER_BY_CHECK` pokrývá všechny `undecided_*`
checky z `ORDER` (regresní test, aby nový parametrový check nezůstal bez
ovládání).

Frontend: `cd frontend && npm run build` (timeout 90–120 s).

Spuštění PHP: `vendor/bin/phpunit --filter 'SetupController|SetupChecklist'`.

## Ověření na dev serveru (součást tasku)

1. Čerstvý DS → Nastavení → panel je první položkou sekce a vypisuje osm
   položek v pořadí `ORDER`.
2. Rozhodni účtovou osnovu v panelu → položka **hned** zmizí (bez čekání
   na cron) a osnova je v DS naseedovaná.
3. Rozhodni fiskální rok i měnu → fiskální roky vzniknou (gate na oba
   klíče z Tasku 04).
4. Přepni `vatAgenda` na Neplátce → položka registrace DPH se nerozsvítí;
   přepni na Plátce → rozsvítí se.
5. Vrať parametr na Nerozhodnuto → položka se vrátí do checklistu.
6. Klikni akci u „chybí vlastní Osoba" → otevře se formulář s presetem
   (`is_own`, `person_type`).
7. Nastav vše → panel ukáže prázdný stav a sbalený seznam rozhodnutých
   parametrů.

## Hotovo když

- [ ] Panel je v Nastavení a vypisuje živý checklist v pořadí `ORDER`
- [ ] Všechny čtyři parametry se dají v panelu rozhodnout i vrátit na
      nerozhodnuto
- [ ] Rozhodnutí parametru spustí dotčený provisioner okamžitě
- [ ] Položky mizí bez čekání na cron
- [ ] Poznámky o nevratnosti a dopadu jsou vidět v UI
- [ ] `npm run build` prochází, PHP testy zelené
- [ ] `docs/rest-api.md` a `docs/app-settings.md` doplněné

## Pasti / na co pozor

- **Nevaliduj hodnoty ve Svelte ani v controlleru.**
  `LayerCParameters::validate()` je jediné místo pravdy; duplikát se
  rozejde při prvním přidaném parametru. Ve frontendu smí být jen
  omezení nabídky selectu.
- **Mapování check → parametr patří na server.** Ve Svelte by to byl
  druhý seznam, který nikdo neudržuje synchronně s `ORDER`. Regresní test
  na pokrytí je proto v zadání.
- **Neukládej progress.** Panel nemá „hotovo" checkboxy ani stav
  v settings (D3). Je to pokušení, protože seznam vypadá jako to-do list —
  ale stav se dopočítává a po `ds-reset` musí odpovídat realitě.
- **`accountChart` je prakticky nevratný.** Panel to musí říct **před**
  volbou, ne po ní. Vrácení klíče na `null` osnovu **nemaže** (provisionery
  neuklízí), takže „vrátit zpět" znamená jen „nerozhodnuto", ne „čistý
  stav" — a uživatel to musí vědět.
- **Provisioner voláš z HTTP requestu.** `AccountChartProvisioner` bere
  `$dsConnection` + seed soubor, `FiscalYearsProvisioner` `$dsConnection`
  + `ConfigRuntime` (+ nepovinný `yearStartMonth` z Tasku 04) — obojí je
  z requestu dostupné. Ověř, jak si to obstarává `ds-upgrade`, a použij
  tentýž způsob, ne vlastní.
- **Panel ≠ průvodce.** Fáze 4 přinese `dsSetup` průvodce s ARESem
  a krokováním. Nepředbíhej ho: žádné „další krok", žádné volání
  registru, žádné zakládání vlastní Osoby přímo v panelu. Panel odkazuje
  na formulář, průvodce to bude umět sám.
- Panel je jediné místo, kde uživatel uvidí, co má dodělat — když spadne
  jeden check, `SetupChecklist` je fail-open a vrátí ostatní. Ověř, že
  komponenta tenhle částečný výsledek zobrazí a nespadne na chybějícím
  poli.
