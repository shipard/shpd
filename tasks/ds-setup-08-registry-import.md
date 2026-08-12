# ds-setup — Task 08: Vlastní Osoba z registru

**Stav:** naplánováno

> PRD pro jednu Claude Code session. Design: `docs/ds-setup.md`,
> rozhodnutí **D4, D15**, kontrakt **§5.4**. Začátek Fáze 4.
> Druhý kus (můstky do číselníků DPH a bankovních účtů) je Task 09.

## Kontext

Panel `dsSetup` z Tasku 06 dnes u položky „Chybí vlastní Osoba" nabídne
otevření prázdného formuláře. Uživatel pak vypisuje ručně to, co je
v ARESu — název, IČO, sídlo, DIČ, zveřejněná bankovní spojení.

Registrová cesta v aplikaci **už celá existuje**:
`RegistryImportWizard.svelte` (search → preview → apply) nad
`personsRegistry.js` → `/persons/registry`, `/_exchange/persons/person/preview`
a `/apply`, s `mergeStrategy: 'createOnly'` a `targetDocState: 40`.
`PersonApplier` navíc mapuje `status.isOwn` → `is_own` (ř. 422), takže
**jeden apply umí vyrobit Osobu, sídlo, bankovní spojení, DIČ i příznak
vlastní osoby**.

Tenhle task tu cestu jen zapojí do panelu v režimu „vlastní osoba".

**Žádný krokový průvodce (D15).** Panel je ta plocha; rozšiřuje se
o akci, ne o druhou obrazovku s kroky.

## Cíl

1. Režim „vlastní osoba" v `RegistryImportWizard`.
2. Akce „Načíst z registru" u položky `missing_own_person` v panelu.
3. Návrh hodnoty `economy.vatAgenda` podle DIČ vlastní Osoby.

## Závislosti

- Závisí na Tasku 06 (panel `dsSetup`, `GET /_setup/checklist`) — hotový.
- Otevírá: Task 09 (můstky vycházejí z dat, která tenhle task naimportuje).

## Potvrzená designová rozhodnutí (Anna)

1. **W2 / D15 — rozšíření panelu, ne samostatný krokový průvodce.**
   Pořadí `SetupChecklist::ORDER` už uživateli říká, co je další na řadě,
   a položky mizí, jak se plní. Krokování by přidávalo dojem, ne informaci —
   a znamenalo by ovládání parametrů podruhé.
2. **Import funguje jen tehdy, když žádná vlastní Osoba není.** Žádný
   merge do existující, žádné doplňování. Výrazně méně stavů — a gate je
   implicitní, protože položka `missing_own_person` svítí právě tehdy,
   když žádná vlastní Osoba neexistuje.
3. **`vatAgenda` se z přítomnosti DIČ předvybere jako default**, ne uloží.
   Rozhodnutí musí zůstat na uživateli (D2: absence klíče = nerozhodnuto).

## Před implementací přečti

- `docs/ds-setup.md` §5.4, rozhodnutí D4/D15
- `frontend/src/components/registry/RegistryImportWizard.svelte` — celý;
  props `open` / `onClose` / `onSaved(personId)`, dvě obrazovky,
  `applyOptions` na ř. 166 a 223, gate `existsInDb`
- `frontend/src/api/personsRegistry.js` — `searchRegistry`,
  `fetchRegistryPerson`, `previewRegistryPerson`, `applyRegistryPerson`
- `modules/core/exchange/src/Person/PersonApplier.php` ~ř. 422 — mapování
  `status.isOwn`
- `modules/core/exchange/schemas/shpd.persons.person.v1.jsonc` — `status`,
  `vatId`, `bankAccounts`
- `frontend/src/components/settings/DsSetup.svelte` — `runAction()`,
  `primaryAction()`, `formModal`, `load()`, `applyState()`
- `src/Api/Controller/SetupController.php` — skládání položek checklistu
- `modules/base/persons/tables/base_persons_persons.jsonc` ~ř. 106 —
  sloupec `vat_id`

## Rozsah

### `RegistryImportWizard.svelte` — režim „vlastní osoba"

Nový prop:

```js
let {
  open = false,
  asOwn = false,
  onClose = () => {},
  onSaved = (_personId) => {},
} = $props();
```

Když `asOwn === true`:

- Před applyem nastav na kanonickém payloadu `status.isOwn = true`
  (`status` může v payloadu chybět → vytvoř objekt). **Nastav to na obou
  místech, kde se `applyOptions` skládá** (ř. 166 a 223) nebo to sjednoť
  do jedné funkce — dvě cesty k applyu jsou v komponentě dnes a je snadné
  upravit jen jednu.
- Titulek modalu a popisek jsou jiné: ne „Přidat firmu z registru", ale
  něco ve smyslu „Načíst vlastní firmu z registru", plus jedna věta, že
  jde o subjekt, pod jehož hlavičkou bude DS fungovat.
- `mergeStrategy: 'createOnly'` a `targetDocState: 40` **nech, jak jsou**.
- `existsInDb` gate nech taky — když je firma v DB, není to případ pro
  vlastní Osobu (ta by pak už existovala a položka checklistu by nesvítila).

Výchozí chování (`asOwn = false`) se nesmí změnit ani o píď — komponenta
se používá i jinde.

### Akce v panelu

Server (`SetupController`): u položky `base.persons.missing_own_person`
přidej **druhou** akci vedle dnešní `open_form`:

```php
[
    'id'      => 'import_own_person_from_registry',
    'label'   => $cs ? 'Načíst z registru' : 'Load from registry',
    'kind'    => 'registry_import_own',
    'target'  => [],
    'primary' => true,
]
```

- Nová akce je **primární**, dnešní `open_form` zůstává jako sekundární
  („Zadat ručně"). Registr je ta správná cesta; ruční zadání je záloha pro
  subjekty, které v registru nejsou.
- Akci **nedávej do `AlertFinding` v checku.** Check je společný pro
  panel i pro tabulku alertů, ale `registry_import_own` umí obsloužit jen
  panel — karta ve feedu ani viewer alertů takový dialog nemají. Přidej ji
  v `SetupController` při serializaci položky, tedy jen pro panel.
  To je důležité: kdyby to šlo do checku, cron by tu akci uložil do
  `core_alerts_alerts` a dashboard by na ni narazil s `console.warn`.

`DsSetup.svelte` — v `runAction()` nová větev:

```js
case 'registry_import_own':
  registryModal = { open: true };
  return;
```

Po `onSaved` zavolej `load()` — checklist se překreslí, `missing_own_person`
zmizí a nastoupí `missing_own_headquarters` nebo další položka. Chování
kopíruj z `handleFormClose()`, kde se to už dělá podle `wasSaved`.

### Návrh `vatAgenda` podle DIČ

Kanonický payload registru nese `vatId`; `PersonApplier` ho ukládá do
`base_persons_persons.vat_id`. Když registr DIČ vrátil, je subjekt
plátcem — a to je použitelný **default**, ne pravda (D5).

Server: položky checklistu dostanou nepovinné pole `suggestion`:

```json
{
  "checkId": "economy.codebooks.undecided_vat_agenda",
  "parameter": "economy.vatAgenda",
  "suggestion": {
    "value": true,
    "reason": "Vlastní Osoba má vyplněné DIČ CZ… — pravděpodobně jste plátce DPH."
  }
}
```

- Zdroj: `vat_id` aktivní vlastní Osoby (`docState IN (10, 40)`,
  `is_own = 1`). Prázdné nebo žádná Osoba → `suggestion` vůbec neposílej.
- `reason` je lokalizovaný text ze serveru. **DIČ v něm ukaž** — uživatel
  má vidět, z čeho návrh vychází.
- Pole je obecné (`{value, reason}`), ne `vatSuggestion` — ať ho může
  použít i budoucí parametr. Ale **teď ho vyplňuj jen u tohohle checku**;
  spekulativní generalizaci ostatních neřeš.

`DsSetup.svelte`:

- `draft[key]` u položky se `suggestion` inicializuj na `suggestion.value`
  místo na `parameters[key]` (což je `null`).
- `reason` zobraz u ovládání jako nápovědu.
- **Neukládej to samo.** Uživatel musí volbu potvrdit — `parameters` dál
  drží `null`, dokud nepotvrdí. Ověř, že se položka z checklistu neztratí
  jen tím, že se předvybralo.

### Dokumentace

- `docs/ds-setup.md` — §5.4 přepiš podle reality (kroky 5–7 řeší panel,
  krok 1 vynechaný, registr nevrací datum registrace k DPH); doplň D15
  a poznámku o `suggestion`.
- `docs/rest-api.md` — `suggestion` v odpovědi `/_setup/checklist`.
- `modules/base/persons/README.md` — pokud popisuje registrový import,
  doplň režim `asOwn`.

## Testy

`SetupControllerTest`:

- položka `missing_own_person` má obě akce, `registry_import_own` je
  primární
- `suggestion` je u `undecided_vat_agenda`, když má vlastní Osoba `vat_id`
- `suggestion` chybí, když je `vat_id` prázdné i když vlastní Osoba není
- `suggestion` se **nepropíše** do `core_alerts_alerts` (regresní test —
  akce a suggestion jsou jen panelová serializace, ne obsah checku)

`RegistryImportWizard` — pokud pro komponentu testy existují, přidej
`asOwn` případ; jinak pokryj E2E níže.

Frontend: `cd frontend && npm run build` (timeout 90–120 s).

PHP: `vendor/bin/phpunit --filter 'SetupController|PersonApplier'`.

## Ověření na dev serveru (součást tasku)

1. Čerstvý DS → panel → u „Chybí vlastní Osoba" jsou dvě akce, primární
   je „Načíst z registru".
2. Vyhledej firmu podle IČO, potvrď → vznikne Osoba s `is_own = 1`, sídlo
   (`address_type = 1`), bankovní spojení a DIČ; ověř v DB.
3. Checklist se překreslí sám, `missing_own_person` zmizí,
   `missing_own_headquarters` **taky** (sídlo přišlo s importem).
4. U položky plátcovství DPH je předvybráno „Plátce" a je vidět důvod
   s DIČ; položka **zůstává v checklistu**, dokud volbu nepotvrdíš.
5. Import u firmy bez DIČ → žádný návrh, položka bez předvolby.
6. Zkus import znovu na DS, kde už vlastní Osoba je → položka nesvítí,
   akce není dostupná (gate je implicitní).
7. Otevři „Přidat firmu z registru" na obvyklém místě (mimo panel) →
   chová se **přesně jako dřív**, importovaná firma nemá `is_own`.

## Hotovo když

- [ ] Jeden import z registru vyrobí vlastní Osobu, sídlo, bankovní
      spojení i DIČ
- [ ] Panel se po importu překreslí a dotčené položky zmizí
- [ ] `vatAgenda` je předvybraná podle DIČ, ale neuložená
- [ ] Běžný registrový import se nezměnil
- [ ] Akce `registry_import_own` se neobjeví v tabulce alertů ani ve feedu
- [ ] `npm run build` prochází, PHP testy zelené
- [ ] `docs/ds-setup.md` §5.4 srovnaná s realitou

## Pasti / na co pozor

- **Akce jen pro panel, ne do checku.** `AlertFinding` z checku putuje
  cronem do `core_alerts_alerts` a odtud do feedu. `registry_import_own`
  umí obsloužit jen panel, takže musí vzniknout při serializaci
  v `SetupController`. Jinak dashboard narazí na neznámý `action.kind`
  (dnes to skončí `console.warn` v `handleCardAction()`).
- **Dvě cesty k applyu ve wizardu.** `applyOptions` se skládá na ř. 166
  a 223. `status.isOwn` musí být na obou — nebo obě sjednoť do jedné
  funkce, což je čistší.
- **`suggestion` není uložená hodnota.** Předvolba v UI nesmí vést
  k zápisu do settings ani k tomu, že položka z checklistu zmizí. Je to
  v ověřovacím scénáři jako bod 4, protože je to přesně ten druh chyby,
  co projde testy a rozbije D2.
- **Neregresuj běžný import.** Komponenta se používá i mimo panel; `asOwn`
  má výchozí `false` a všechny změny musí být pod tou podmínkou. Bod 7
  ověření je na to.
- Registr **nevrací datum registrace k DPH ani příznak plátce** — kanonický
  `shpd.persons.person.v1` má jen `vatId`. Nesnaž se `valid_from`
  registrace odvodit z `bankAccounts[].validFrom`; to je datum zveřejnění
  účtu, ne registrace. Registrace DPH je Task 09 a datum se tam zeptá.
- Firma nemusí být v registru vůbec (nové subjekty, cizí země bez
  konektoru). Ruční cesta proto zůstává dostupná jako sekundární akce —
  neodstraňuj ji.
