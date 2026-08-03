# Roletky měny a země napříč aplikací + sjednotný label enum options

**Stav:** hotovo

## Status

Hotovo (2026-06-25). Implementováno na `stable` ve třech commitech
(`refactor(forms)` EnumOptionsHelper → `feat(codebooks)` měna →
`feat(persons)` země). Zbývá `ds-upgrade` v dev DS (změna typů sloupců).
Design schválen Annou (2026-06-25).

## Cíl

Pole „Měna“ ve třech číselnících v Nastavení aplikace dnes nemá roletku —
měna se píše ručně jako volný text (`varchar(3)`, default `czk`). Na faktuře
už roletka funguje (sloupec `doc_currency` je `enumString` + `cfgItem
world.base.currencies`). Cílem je sjednotit chování — přidat roletku na tři
zbývající místa s měnou, přidat roletku země na Adresy, a sjednotit
skládání labelu enum options do jednoho sdíleného helperu (formát
`ALPHA3 — name` jen pro měny, ostatní včetně zemí `name`):

- **Pokladny** (`economy_codebooks_cash_desks`)
- **Bankovní spojení** (`economy_codebooks_bank_accounts`)
- **Fiskální období** (`economy_codebooks_fiscal_years`)

A pro **země**:

- **Adresy** (`base_persons_addresses`) — input `country` → roletka.
- **Registrace DPH** (`economy_codebooks_vat_registrations`) — roletka už
  funguje, jen sjednotíme zdroj options na sdílený helper.

## Kontext / proč to dnes nefunguje

Roletka měny stojí na dvou úrovních:

1. **Sloupec** v TableDefinition musí být `enumString` s `cfgItem:
   world.base.currencies` (ne `varchar`). Pak ho server-side pipeline umí
   vykreslit jako select s automaticky doplněnými options.
2. **Element formuláře** musí být `select` (ne `input`).

Faktury mají obě úrovně správně. Tři číselníky v Nastavení mají sloupec
`currency` jako prostý `varchar(3)` a element `input` — proto volný text.

**Dva různé mechanismy formulářů** (důležité — určuje, jak se mění úroveň 2):

- Pokladny a Bankovní spojení mají formulář deklarativně v **JSONC**
  (`forms/*.jsonc`). U těch stačí změnit element na `select` — options doplní
  `JsoncFormLoader::resolveEnumOptions()` automaticky z `enumString` sloupce.
- Fiskální období má formulář v **PHP** (`FiscalYearsForm.php`) s explicitním
  `->input('currency', ...)`. PHP `TabBuilder::select()` **neauto-resolvuje**
  options — musí se předat ručně přes `options:`.

## Před implementací přečti

- `src/Core/Form/JsoncFormLoader.php` (~ř. 308–352) — `deriveType()` mapuje
  `enumString`/`enumInt` → `select`; `resolveEnumOptions()` skládá options
  z `entry['name']`.
- `src/Core/Form/TabBuilder.php` (~ř. 225) — `select()` bere `options` jako
  hotové pole, nic neresolvuje.
- `src/Core/Database/ColumnDefinition.php` (~ř. 75–80) — `enumString` vyžaduje
  `cfgItem` i `length`.
- `src/Core/Database/SqlGenerator.php` (~ř. 27) — `enumString` → SQL
  `CHAR({length}) CHARACTER SET ascii` (změna typu z `varchar`).
- `modules/docs/core/src/DocsHeadsFormBase.php` (~ř. 623) — referenční
  `resolveCurrencyOptions()` (skládá label jako `ALPHA3 — name`).
- `modules/world/base/config/currencies.jsonc` — cfgItem, entry mají `name`,
  `name:cs`, `name:en`, `alpha3`.
- Vzor hotového `enumString` currency sloupce:
  `modules/economy/bank/tables/economy_bank_statements.jsonc` (~ř. 43).
- Vzor hotového `enumString` **country** sloupce (length 2 + countries):
  `modules/economy/codebooks/tables/economy_codebooks_vat_registrations.jsonc`
  (~ř. 64).
- `modules/economy/codebooks/src/VatRegistrationsForm.php` — vlastní
  `resolveStringOptions` / `resolveIntOptions` (k sjednocení, krok 5).
- `modules/base/persons/tables/base_persons_addresses.jsonc` (~ř. 192) —
  sloupec `country` `varchar(2)`.
- `modules/base/persons/forms/base_persons_addresses.jsonc` (~ř. 76) —
  `country` jako `input` v inline skupině.
- `modules/world/base/config/countries.jsonc` — cfgItem zemí; klíč alpha-2
  (`cz`), entry mají `alpha3` (`cze`), `name`, `name:cs`.

## Scope

**In:**
- Změna sloupce `currency` z `varchar(3)` na `enumString` + `cfgItem
  world.base.currencies` ve třech table definicích.
- Pokladny + Bankovní spojení: element `input` → `select` v JSONC formulářích.
- Fiskální období: `->input('currency')` → `->select('currency', options: ...)`
  v PHP formuláři.
- Sjednocení formátu labelu enum options: kde má entry `alpha3` (currencies,
  countries), label = `ALPHA3 — name`; jinak `name` (beze změny). Společný
  helper sdílený oběma pipeline (`JsoncFormLoader`, `AutoFormBuilder`).
- Faktury (`DocsHeadsFormBase::resolveCurrencyOptions`) sladit na sdílený helper.
- **Země:** Adresy — sloupec `country` `varchar(2)` → `enumString` + countries,
  element `input` → `select`. Registrace DPH — sjednotit options na helper
  (sloupec už je `enumString`).
- Pravidlo M1: prefix `ALPHA3 — name` jen pro `world.base.currencies`; země
  a ostatní enumy `name`.

**Out:**
- Regex validace `currency` v `CashDeskDocument` / `BankAccountDocument`
  — **zůstává beze změny** (rozhodnutí Anny; slouží jako pojistka).
- Faktury (`doc_currency`) — již fungují, nesaháme.
- `home_currency` na faktuře (readOnly) — mimo záběr.
- Datová migrace hodnot — hodnoty zůstávají 3znakové string klíče (`czk`),
  kompatibilní; testovací DS se resetují.
- Lokalizace labelu (`name` vs `name:cs`) — zůstává `name` jako dnes,
  dořeší se s i18n prací (rozhodnutí Anny).
- Změna labelu u **ne-currency** enumů (včetně zemí) — zůstávají `name`
  (M1). Vizuálně se nemění nic kromě měnových selectů.

## Co implementovat

### 1. Table definice — sloupec `currency` → `enumString`

Ve všech třech souborech změnit definici sloupce `currency`. Původně:

```jsonc
{
    "id": "currency",
    "name": "Currency", "name:cs": "Měna", "name:en": "Currency",
    "type": "varchar", "length": 3,
    "nullable": false, "default": "czk",
    "group": "..."
}
```

Nově (vzor dle `economy_bank_statements.jsonc`):

```jsonc
{
    "id": "currency",
    "name": "Currency", "name:cs": "Měna", "name:en": "Currency",
    "type": "enumString", "length": 3, "cfgItem": "world.base.currencies",
    "nullable": false, "default": "czk",
    "group": "..."
}
```

Soubory (pozor na zachování stávající hodnoty `group`):
- `modules/economy/codebooks/tables/economy_codebooks_cash_desks.jsonc`
  (group `settings`)
- `modules/economy/codebooks/tables/economy_codebooks_bank_accounts.jsonc`
  (group `settings`)
- `modules/economy/codebooks/tables/economy_codebooks_fiscal_years.jsonc`
  (group `period`)

> SQL důsledek: `varchar(3)` → `CHAR(3) CHARACTER SET ascii`. Spustí
> `ds-upgrade` (migíruje Anna ručně). Hodnoty kompatibilní.

### 2. JSONC formuláře — Pokladny + Bankovní spojení

Element `currency` změnit z `input` na `select`. Options se doplní
server-side z `enumString` sloupce (`JsoncFormLoader::resolveEnumOptions`).

`modules/economy/codebooks/forms/economy_codebooks_cash_desks.jsonc`:

```jsonc
{"type": "select", "column": "currency", "required": true},
```

`modules/economy/codebooks/forms/economy_codebooks_bank_accounts.jsonc`:

```jsonc
{"type": "select", "column": "currency", "required": true},
```

(jen tento jeden řádek v každém souboru; zbytek formuláře beze změny)

### 3. PHP formulář — Fiskální období

`modules/economy/codebooks/src/FiscalYearsForm.php`. PHP `select()` neresolvuje
options sam, je třeba je dodat. `FiscalYearsForm` rozšiřuje přímo `TableForm`
(ne `DocsHeadsFormBase`), takže `resolveCurrencyOptions()` není zděděný.

Po sjednocení (krok 4) bude formát labelu `ALPHA3 — name` pro currencies.
Helper má použít **stejný sdílený skládač labelu** jako pipeline — viz
`EnumOptionsHelper` v kroku 4. Lokální resolver tedy jen načte cfgItem
a deleguje skládání labelu na sdílený helper:

```php
->input('currency', required: true, placeholder: 'czk')
```

změnit na:

```php
->select('currency', options: $this->resolveCurrencyOptions(), required: true)
```

a přidat metodu:

```php
/** @return list<array{value: string, label: string}> */
private function resolveCurrencyOptions(): array
{
    if ($this->config === null) {
        return [];
    }
    $cfg = $this->config->cfgItem('world.base.currencies');
    if (!is_array($cfg)) {
        return [];
    }
    return EnumOptionsHelper::fromCfgData($cfg, 'enumString');
}
```

`use Shipard\Core\Form\EnumOptionsHelper;` na začátek souboru.
Tím fiskální období sdílí identický formát s JSONC formuláři i fakturami.

### 4. Sjednocení formátu labelu (M1 — prefix jen pro měny)

Dnes se label enum option skládá na **pěti** místech, každé zvlášť:
`JsoncFormLoader::resolveEnumOptions`, `AutoFormBuilder::resolveEnumOptions`
(obě jen `name`), `DocsHeadsFormBase::resolveCurrencyOptions` (`ALPHA3 — name`)
a `VatRegistrationsForm::resolveStringOptions` / `resolveIntOptions` (oboje
`name`, viz krok 5). Sjednotíme do jednoho sdíleného skládače.

**Pravidlo M1:** prefix `ALPHA3 — name` **jen** pro cfgItem
`world.base.currencies`. Všechny ostatní enumy (včetně zemí
`world.base.countries`, které `alpha3` taky mají) → jen `name`. Měnový
kód před názvem dává smysl (CZK lidi znají), alpha-3 kód země (CZE) ne.

**4a. Nový `src/Core/Form/EnumOptionsHelper.php`** — jedno místo pravdy pro
formát labelu. Bere cfgItem ID, aby věděl, kdy prefixovat.

```php
<?php

declare(strict_types=1);

namespace Shipard\Core\Form;

final class EnumOptionsHelper
{
    /** cfgItem ID, jejichž options nesou prefix `ALPHA3 — name`. */
    private const ALPHA3_PREFIXED = ['world.base.currencies'];

    /**
     * Složí options z cfgItem dat. Label: pro cfgItem v ALPHA3_PREFIXED
     * (měny) použije `ALPHA3 — name`; jinak holy `name`. Země záměrně
     * bez prefixu (rozhodnutí M1). Lokalizace (`name` vs `name:cs`)
     * — zatím vždy `name`, dořeší se s i18n.
     *
     * @param array<string|int, mixed> $cfgData
     * @param 'enumInt'|'enumString' $colType
     * @return list<array{value: int|string, label: string}>
     */
    public static function fromCfgData(array $cfgData, string $colType, ?string $cfgItemId = null): array
    {
        $prefix = $cfgItemId !== null && in_array($cfgItemId, self::ALPHA3_PREFIXED, true);
        $options = [];
        foreach ($cfgData as $key => $entry) {
            if (!is_array($entry) || !isset($entry['name'])) {
                continue;
            }
            $name = (string) $entry['name'];
            $alpha3 = isset($entry['alpha3']) ? (string) $entry['alpha3'] : '';
            $label = ($prefix && $alpha3 !== '') ? "{$alpha3} — {$name}" : $name;
            $value = $colType === 'enumInt' ? (int) $key : (string) $key;
            $options[] = ['value' => $value, 'label' => $label];
        }
        return $options;
    }
}
```

> `$cfgItemId` je nullable — kdo ho nezná (teoreticky), dostane `name`.
> V praxi ho všechna volání předají.

**4b. `JsoncFormLoader::resolveEnumOptions`** — nahradit vlastní `foreach`
voláním `EnumOptionsHelper::fromCfgData($cfgData, $col->type, $col->cfgItem)`.
Zachovat současné early-return (`$config === null || $col->cfgItem === null`
→ `null`; `!is_array($cfgData)` → `null`).

**4c. `AutoFormBuilder::resolveEnumOptions`** — totéž
(`...($cfgData, $col->type, $col->cfgItem)`), ale early-return vrací `[]`
(ne `null`) — zachovat stávající návratový kontrakt.

**4d. `DocsHeadsFormBase::resolveCurrencyOptions`** (~ř. 623) — nahradit tělo
voláním sdíleného helperu, aby faktury šly stejnou cestou:

```php
protected function resolveCurrencyOptions(): array
{
    if ($this->config === null) {
        return [];
    }
    $cfg = $this->config->cfgItem('world.base.currencies');
    if (!is_array($cfg)) {
        return [];
    }
    return EnumOptionsHelper::fromCfgData($cfg, 'enumString', 'world.base.currencies');
}
```

(formát zůstane `ALPHA3 — name` — stejný jako dnes; jen už není duplikovaný)

> Pozor: `DocsHeadsFormBase` skládal dříve `alpha3` s fallbackem
> `strtoupper(key)`. Po změně se label řídí přítomností `alpha3` v entry
> a tím, že jde o currency cfgItem. Všechny currency entry `alpha3` mají,
> takže beze změny výstupu.

### 5. Registrace DPH — země na sdílený helper

`modules/economy/codebooks/src/VatRegistrationsForm.php` už má sloupec
`country` jako `enumString` + `world.base.countries` (na úrovni tabulky OK).
Form ale používá **vlastní** `resolveStringOptions` / `resolveIntOptions`
(další duplikace skládání options). Sjednotit na `EnumOptionsHelper`:

- `resolveStringOptions($id, $sortByLabel)` → nahradit voláním
  `EnumOptionsHelper::fromCfgData($cfg, 'enumString', $id)`; řazení podle
  labelu (`$sortByLabel`) zachovat — buď ponechat `usort` po získání options,
  nebo přidat parametr do helperu. **Doporučeno:** `usort` nechat ve formuláři
  (řazení je form-specifické, ne vlastnost skládání labelu).
- `resolveIntOptions($id)` → `EnumOptionsHelper::fromCfgData($cfg, 'enumInt', $id)`.
- Použití: `region` (`world.trade.unions`), `country` (`world.base.countries`,
  `sortByLabel: true`), `taxpayer_kind` / `*_period_kind` (int enumy).
- `use Shipard\Core\Form\EnumOptionsHelper;`.

Výsledek: země zůstanou `name` („Andorra“, „Česko“) jako dnes — M1
nedotkne countries. Žádná vizuální změna, jen odstranění duplikace.

### 6. Adresy — roletka země (`base_persons_addresses`)

**6a. Sloupec `country`** v `modules/base/persons/tables/base_persons_addresses.jsonc`
(~ř. 192) z `varchar(2)` na `enumString` + countries (vzor =
`economy_codebooks_vat_registrations` country sloupec):

```jsonc
{
    "id": "country",
    "name": "Country", "name:cs": "Země", "name:en": "Country",
    "type": "enumString", "length": 2, "cfgItem": "world.base.countries",
    "nullable": true,
    "group": "address"
}
```

> Klíče countries jsou ISO alpha-2 malými písmeny (`cz`), sloupec ukládá
> alpha-2 → hodnoty kompatibilní, `length: 2` sedí. `nullable: true`
> zůstává (země není povinná).

**6b. Element formuláře** v `modules/base/persons/forms/base_persons_addresses.jsonc`
(~ř. 76) z `input` na `select`. Pole je uvnitř `inline` skupiny
(`city / city_part / zip / country`) — `select` je v inline povolený:

```jsonc
{"type": "select", "column": "country"}
```

Options doplní `JsoncFormLoader` z `enumString` sloupce. Label dle M1 =
`name` („Andorra“). Default `cz` se nenastavuje (země není povinná; nechat
prázdné, dokud uživatel nevybere) — pokud chceš předvybrané `cz`, řekni.

## Datový tok

Otevření formuláře (GET /meta) → FormController načte FormDefinition.
Pro JSONC formulář `JsoncFormLoader` u sloupce typu `enumString` (měna
i země) odešle `type: select` + `options`. Pro PHP formuláře (Fiskální
období, Registrace DPH) dodávají options vlastní resolvery. Všechny cesty
skládají label přes společný `EnumOptionsHelper` (M1: prefix jen měny).
Frontend `Select.svelte` vykreslí `<select>`. Uložení → `beforeSave`
(`strtolower` u měny) + regex validace měny (pojistka) beze změny.

## Akceptační kritéria (Hotovo když)

- `php -l` projde na změněném `FiscalYearsForm.php`.
- `vendor/bin/phpunit --filter 'CashDeskDocument|BankAccountDocument|FiscalYear'`
  projde (validace měny dál funguje).
- `cd frontend && timeout 90 npm run build` projde.
- Po `ds-upgrade` (Anna): sloupec `currency` je `CHAR(3) ascii` ve všech třech
  tabulkách.
- Ve formuláři Pokladny, Bankovní spojení i Fiskální období je u pole Měna
  roletka se seznamem měn; default `czk` je předvybraný; uložení funguje.
- Label měny má formát `ALPHA3 — name` (např. „CZK — Czech Koruna“)
  — **stejně ve všech číselnících i na faktuře**.
- Po `ds-upgrade`: sloupec `country` na adresách je `CHAR(2) ascii`.
- Na Adresách (Osoby) je u pole Země roletka; ukládá alpha-2 (`cz`).
- Label země je **jen `name`** („Andorra“, „Česko“) — bez prefixu, jak
  v Adresách, tak v Registraci DPH (M1).
- Ne-currency enum selecty (typ adresy, sazba DPH, region…) mají label
  beze změny (jen `name`).
- Unit test na `EnumOptionsHelper::fromCfgData`: `world.base.currencies`
  → `ALPHA3 — name`; `world.base.countries` → `name` (bez prefixu, přestože
  má `alpha3`); int enum → int value.

## Doporučené pořadí

1. `EnumOptionsHelper` + unit test (krok 4a).
2. Přepojit obě pipeline + faktury + Registraci DPH na helper (kroky 4b–4d, 5)
   — ověřit, že se nic nerozbilo (`--filter 'DocsHeads|VatRegistration'`
   + frontend build). Žádná vizuální změna kromě měnových selectů.
3. Table definice měny (krok 1) + země na adresách (krok 6a) → Anna
   spustí `ds-upgrade`.
4. JSONC formuláře měny (krok 2) + PHP Fiskální období (krok 3) + JSONC
   adresy (krok 6b).
5. Verifikace (`php -l`, phpunit úzký filtr, frontend build).
6. Commity logicky oddělit, např.:
   `refactor(forms): sdílený EnumOptionsHelper, label měny ALPHA3 — name`
   → `feat(codebooks): roletka měny v číselnících Nastavení`
   → `feat(persons): roletka země na adresách`.

## Rozhodnutí ✓

- Sloupec `currency` → `enumString` + `cfgItem world.base.currencies` (ne
  alternativní cesta s ručními options v JSONC) — konzistentní se
  `bank_statements`/`bank_transactions`. (Anna 2026-06-25)
- Regex validace `currency` v Document třídách **zůstává** jako pojistka.
  (Anna 2026-06-25)
- `ds-upgrade` kvůli změně typu sloupce je očekávaný a akceptovaný.
  (Anna 2026-06-25)
- Skládání labelu enum options sjednoceno do společného `EnumOptionsHelper`
  (odstranění pěti duplikací). (Anna 2026-06-25)
- Formát labelu — varianta **M1**: prefix `ALPHA3 — name` **jen** pro
  `world.base.currencies`. Země (a všechny ostatní enumy) zůstávají `name`,
  i když countries `alpha3` mají — alpha-3 kód země uživateli nepomůže tak
  jako měnový. (Anna 2026-06-25)
- Země: Adresy dostanou roletku (sloupec `enumString` + countries); Registrace
  DPH se jen přepojí na helper (sloupec už `enumString`, vzhled beze změny).
  (Anna 2026-06-25)
- Lokalizace labelu zůstává na `name` (anglický základ) jako dnes;
  dořeší se v budoucnu s i18n prací. (Anna 2026-06-25)

## Otevřené body

- Lokalizace labelu: pipeline čte `name` (anglický základ). Až přijde i18n
  práce, ověřit, zda `EnumOptionsHelper` má brát `name:cs` dle aktivního
  jazyka. Změna pak bude na jednom místě (helper).
- `ALPHA3_PREFIXED` je dnes natvrdo `['world.base.currencies']`. Až by přibyl
  další cfgItem, kde prefix dává smysl, přidat do konstanty — nebo přejít na
  deklarativní příznak (varianta M2), pokud jich bude víc.
- Velký počet zemí (~250) v nativním `<select>` — zatím OK (stačí škrtnutím
  klávesnice). Pokud bude vadít, zvážit `lookup` místo `select` — samostatný
  úkol, mimo záběr.
