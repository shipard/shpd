# Task: Modul `world.vat` — DPH model (CZ)

## Kontext

Připravujeme dokladový systém. DPH model je jeho fundamentem — bez něj nelze
správně vypočítat částky na faktuře, sestavit rekapitulaci DPH ani v budoucnu
podat přiznání. Tato fáze vytváří **samostatný modul `world.vat`**, který bude
poskytovat:

- Klasifikaci sazeb DPH (kategorie: základní, snížená, …)
- Konkrétní **kódy DPH** per stát (`cz-110`, `cz-150`, `cz-203`, …) — to jsou
  položky v roletce na řádku dokladu
- Časově proměnná **procenta** per kód (CZ např. `cz-110`: 20 % do 2012,
  21 % od 2013)
- PHP **resolver** pro lookup procenta podle data, filtrování kódů podle směru
  a místa plnění

Pro MVP jen **CZ**. Ostatní EU státy (`vat-sk.jsonc`, `vat-de.jsonc`, …)
přijdou později jako samostatný úkol s revizí kódů ze starého Shipardu.

Před implementací **přečti**:

- `docs/docs-mvp.md` sekce 4 — kompletní specifikace DPH modelu, struktura
  cfgItem, atributy `vatCodes`, reverse charge mechanismus, časová logika
- `docs/modules.md` — modulový systém, JSONC, cfgItem soubory, kompilace
- `modules/install/country-modules/debs/eu/config/vat-cz.json` v projektu
  `old_shipard` — **zdroj migrace**, obsahuje kompletní seznam CZ kódů
  ze starého Shipardu

Vzorové existující soubory:

- `modules/world/base/` — modul s konfiguračními cfgItem soubory bez tabulek
  (vzor pro strukturu `world.vat`)
- `modules/world/trade/` — pohlédni na `module.jsonc`, jak se registrují
  cfgItem soubory
- `modules/core/system/config/docStatesArchive.jsonc` — vzor pro JSONC
  konfigurace s komentáři

## Cíl

Po dokončení této fáze platí:

- Existuje modul `world.vat` se závislostmi `["world.base", "world.trade"]`
- cfgItem `world.vat.cz` je registrovaný a obsahuje kompletní migraci CZ
  kódů ze starého Shipardu (kategorie + kódy + procenta + poznámky)
- PHP třída `VatRateResolver` umí:
  - Vrátit procento pro `(country, vatCode, date)` s časovou logikou
  - Vyfiltrovat kódy podle `(country, direction, place)` — pro UI roletku
  - Vrátit detail jednoho kódu podle `(country, vatCode)`
  - Vyhodit srozumitelnou výjimku, pokud kód neexistuje nebo nemá platné
    procento k danému datu
- `bin/shpd-ds ds-upgrade` na čistém i existujícím DS projde bez chyb
- `install.base` má `world.vat` v dependencies

Tahle fáze nepřidává **žádné databázové tabulky** — jen konfigurační soubory
a PHP resolver. Modul je čistá knihovna použitelná z budoucího `docs.core`.

## Návaznost

- Závisí na: `world.base` (země, měny — existuje), `world.trade` (unie EU —
  existuje), `core.system` (cfgItem mechanismus — existuje)
- Otevírá: `docs.core` (Fáze 3+) — bez resolveru se nedá vypočítat DPH na
  řádku dokladu

## Scope

### V rozsahu

- Modul `modules/world/vat/`
- cfgItem `world.vat.cz` v `config/vat-cz.jsonc` se sekcemi `vatCategories`,
  `vatCodes`, `vatPercents`, `vatNotes`
- PHP třída `Shipard\Module\World\Vat\VatRateResolver` (viz signatura níže)
- Validační kontrola: každý `code` v `vatPercents` musí existovat ve
  `vatCodes` (sanity check, který odhalí překlepy v migraci)
- README.md modulu
- Aktualizace `install.base/module.jsonc` — `world.vat` do dependencies

### Mimo rozsah (řeší pozdější fáze)

- Per-EU-stát soubory (`vat-sk.jsonc`, `vat-de.jsonc`, …) — samostatný úkol
  s revizí ze starého Shipardu
- UI roletka pro výběr `vat_code` na řádku dokladu — řeší `docs.core` Fáze 5
- Resolver pro místo plnění (země partnera → kategorizace `domestic` /
  `intracom` / `foreign`) — řeší `docs.core` výpočty, využije `world.trade`
- Validace formátu `vatNotes` — texty se zatím nikde nezobrazují

## Adresářová struktura modulu

```
modules/world/vat/
├── module.jsonc
├── README.md
├── config/
│   └── vat-cz.jsonc
└── src/
    └── VatRateResolver.php
```

Namespace: `Shipard\Module\World\Vat\*`.

## Datový model — cfgItem `world.vat.cz`

### Struktura souboru

```jsonc
// world.vat.cz
{
    "vatCategories": { ... },   // klasifikace sazeb (sdíleno mezi kódy)
    "vatCodes":      { ... },   // konkrétní kódy DPH
    "vatPercents":   [ ... ],   // časově proměnná procenta per kód
    "vatNotes":      { ... }    // texty do dokladu (pdp4, pdp5, eu)
}
```

### Sekce `vatCategories`

```jsonc
"vatCategories": {
    "standard": {
        "name": "Standard rate",
        "name:cs": "Základní",
        "name:en": "Standard rate"
    },
    "reduced": {
        "name": "Reduced rate",
        "name:cs": "Snížená",
        "name:en": "Reduced rate"
    },
    "reduced1": {
        "name": "First reduced rate",
        "name:cs": "První snížená",
        "name:en": "First reduced rate"
    },
    "reduced2": {
        "name": "Second reduced rate",
        "name:cs": "Druhá snížená",
        "name:en": "Second reduced rate"
    },
    "zero": {
        "name": "Zero rate",
        "name:cs": "Bez daně",
        "name:en": "Zero rate"
    },
    "exempt": {
        "name": "Exempt",
        "name:cs": "Osvobozeno",
        "name:en": "Exempt"
    }
}
```

### Sekce `vatCodes` — atributy

| Atribut | Typ | Default | Význam |
|---|---|---|---|
| `fullName` | string vícejaz. | — | Plný název pro disambiguation v select |
| `name` | string vícejaz. | — | Krátký název pro UI roletky |
| `print` | string vícejaz. | `name` | Text pro tisk dokladu |
| `category` | string | — | Klíč do `vatCategories` |
| `place` | enum: `domestic` / `intracom` / `foreign` | `domestic` | Místo plnění |
| `direction` | enum: `input` / `output` | — | Vstup (přijatá) / Výstup (vydaná) |
| `noPayTax` | 0/1 | 0 | DPH se neplatí (reverse charge nebo bez daně) |
| `sumBase` | 0/1 | 1 | Započítávat základ do součtu hlavičky |
| `sumTax` | 0/1 | 1 | Započítávat daň do součtu |
| `sumTotal` | 0/1 | 1 | Započítávat celkem do součtu |
| `hidden` | 0/1 | 0 | Skrýt v roletce výběru (jen pro generované odpárované řádky) |
| `reverseVatCode` | string | null | Klíč párového kódu (oddanění) |
| `reverseCharge` | 0/1 | 0 | Jde o reverse charge plnění |
| `reverseChargeCode` | int | null | Kód PDP (4 nebo 5 v ČR) |
| `vatReturnRow` | int | 0 | Řádek v Přiznání DPH (pro budoucí modul) |
| `intracomCode` | int | null | Kód pro Souhrnné hlášení (zboží 0, služby 3) |
| `note` | string | null | Klíč do `vatNotes` |

### Mapování ze starého Shipardu → nové slugy

Slug starého kódu má tvar `EUCZ{NNN}`. Nový slug má tvar `cz-{NNN}` —
odstranit `EU` prefix, lowercase, vložit pomlčku. Příklady:

```
EUCZ110 → cz-110
EUCZ203 → cz-203
EUCZ390 → cz-390
EUCZ000 → cz-000  (nedaňový řádek)
```

### Mapování `rate` (starý) → `category` (nový)

Důležitá pozn: starý systém má atribut `rate` jako numerický klíč do
`taxRates` (0–5). V novém systému používáme `category` jako *sémantickou*
kategorii kódu, **nezávisle na `rate` atributu**. Pro EU vstupní a dovozní
kódy ve starém Shipardu je `rate=2` (Bez daně) — to je formální klasifikace,
ale skutečné procento je 21 %. V novém modelu se kategorie přiděluje podle
**názvu kódu**, ne podle `rate` atributu:

| Starý `rate` | Význam (CZ) | Nová `category` (default) | Výjimky podle názvu |
|---|---|---|---|
| 0 | Základní | `standard` | — |
| 1 | Snížená | `reduced` | — |
| 2 | Bez daně | `zero` | EU/DOVOZ vstup → použij dle názvu (Základní → `standard`, Snížená → `reduced`, …) |
| 3 | Osvobozeno | `exempt` | — |
| 4 | První snížená | `reduced1` | — |
| 5 | Druhá snížená | `reduced2` | — |

**Pravidlo:** Pokud má kód v `fullName` slovo "Základní" → category `standard`.
"Snížená" → `reduced`. "První snížená" → `reduced1`. "Druhá snížená" →
`reduced2`. "Bez daně" → `zero`. "Osvobozeno" → `exempt`. To platí pro VŠECHNY
kódy bez ohledu na starý `rate` atribut.

### Mapování `dir` → `direction`

```
dir: 0 → direction: "input"
dir: 1 → direction: "output"
```

### Mapování `type` → `place`

```
type: 0 → place: "domestic"
type: 1 → place: "intracom"
type: 2 → place: "foreign"
```

Speciální případ: **`cz-000` (nedaňový řádek)** — ve starém Shipardu nemá
`type` ani `dir`. V novém systému dej `direction` jako pole obou hodnot
nebo zaveď konvenci, že chybějící `direction` znamená „obojí" (resolver
to zvládne). Doporučuji **nemít `cz-000` v MVP** — uživatel pro „nedaňový
řádek" zvolí `vat_code = null` na řádku dokladu (textový/nedaňový řádek).
Ušetří se hraniční případ. **Pro MVP `cz-000` vynech.**

### Kompletní seznam kódů k migraci

Migrovat **všechny EUCZ\* kódy ze starého Shipardu kromě `EUCZ000`**:

#### Tuzemsko / Vstup (place=domestic, direction=input)

| Nový slug | Category | Reverse charge | reverseVatCode | vatReturnRow |
|---|---|---|---|---|
| `cz-110` | standard | — | — | 40 |
| `cz-111` | reduced | — | — | 41 |
| `cz-301` | reduced1 | — | — | 41 |
| `cz-302` | reduced2 | — | — | 41 |
| `cz-112` | zero | — | — | 0 |
| `cz-115` | standard | RC4 | `cz-203` | 43 |
| `cz-116` | reduced | RC4 | `cz-204` | 44 |
| `cz-340` | reduced1 | RC4 | `cz-370` | 44 |
| `cz-117` | standard | RC5 | `cz-203` | 43 |
| `cz-118` | standard | — | — | 40 |
| `cz-119` | reduced | — | — | 41 |
| `cz-341` | reduced1 | — | — | 41 |
| `cz-342` | reduced2 | — | — | 41 |

Pro reverse charge kódy:
- `cz-115`: `noPayTax: 1, sumTax: 0, reverseCharge: 1, reverseChargeCode: 4, reverseVatCode: "cz-203"`
- `cz-116`: stejně, `reverseVatCode: "cz-204"`
- `cz-340`: stejně, `reverseVatCode: "cz-370"`
- `cz-117`: stejně, `reverseChargeCode: 5, reverseVatCode: "cz-203"`

#### Tuzemsko / Výstup (place=domestic, direction=output)

| Nový slug | Category | Hidden | sumBase/sumTax/sumTotal | vatReturnRow | note |
|---|---|---|---|---|---|
| `cz-120` | standard | — | def | 1 | — |
| `cz-121` | reduced | — | def | 2 | — |
| `cz-310` | reduced1 | — | def | 2 | — |
| `cz-311` | reduced2 | — | def | 2 | — |
| `cz-122` | zero | — | def | 0 | — |
| `cz-123` | exempt | — | def | 50 | — |
| `cz-150` | standard | — | def + zeroTax | 25 | pdp4 |
| `cz-151` | reduced | — | def + zeroTax | 25 | pdp4 |
| `cz-350` | reduced1 | — | def + zeroTax | 25 | pdp4 |
| `cz-152` | standard | — | def + zeroTax | 25 | pdp5 |
| `cz-203` | standard | 1 | 0/0/0, noPayTax | 10 | — |
| `cz-204` | reduced | 1 | 0/0/0, noPayTax | 11 | — |
| `cz-370` | reduced1 | 1 | 0/0/0, noPayTax | 11 | — |

Pro `cz-150`, `cz-151`, `cz-350`, `cz-152` (PDP výstup): `reverseCharge: 1,
reverseChargeCode: 4` (resp. 5 pro `cz-152`), `note: "pdp4"` (resp. `"pdp5"`),
**bez** `reverseVatCode` (oddanění se generuje na vstupní straně).
Atribut `zeroTax: 1` ze starého Shipardu lze pro MVP **vynechat** — je to
flag pro tisk a nemáme zatím PDF.

#### EU / Výstup (place=intracom, direction=output)

| Nový slug | Category | Hidden | intracomCode | vatReturnRow | note |
|---|---|---|---|---|---|
| `cz-201` | zero | — | 0 | 20 | eu |
| `cz-202` | zero | — | 3 | 21 | eu |
| `cz-205` | standard | 1 | — | 3 | — |
| `cz-206` | reduced | 1 | — | 4 | — |
| `cz-360` | reduced1 | 1 | — | 4 | — |
| `cz-361` | reduced2 | 1 | — | 4 | — |
| `cz-207` | standard | 1 | — | 5 | — |
| `cz-208` | reduced | 1 | — | 6 | — |
| `cz-362` | reduced1 | 1 | — | 6 | — |
| `cz-363` | reduced2 | 1 | — | 6 | — |

Hidden kódy `cz-205` až `cz-363`: `noPayTax: 1, sumBase: 0, sumTax: 0,
sumTotal: 0, hidden: 1`.

#### EU / Vstup (place=intracom, direction=input)

| Nový slug | Category | reverseVatCode | vatReturnRow |
|---|---|---|---|
| `cz-215` | standard | `cz-205` | 43 |
| `cz-216` | reduced | `cz-206` | 44 |
| `cz-390` | reduced1 | `cz-360` | 44 |
| `cz-391` | reduced2 | `cz-361` | 44 |
| `cz-217` | standard | `cz-207` | 43 |
| `cz-218` | reduced | `cz-208` | 44 |
| `cz-392` | reduced1 | `cz-362` | 44 |
| `cz-393` | reduced2 | `cz-363` | 44 |

Všechny: `noPayTax: 1, sumTax: 0` + reverseVatCode.

#### Vývoz / Dovoz (place=foreign)

| Nový slug | Direction | Category | Hidden | reverseVatCode | vatReturnRow |
|---|---|---|---|---|---|
| `cz-401` | output | zero | — | — | 22 |
| `cz-405` | output | standard | 1 | — | 7 |
| `cz-406` | output | reduced | 1 | — | 8 |
| `cz-460` | output | reduced1 | 1 | — | 8 |
| `cz-461` | output | reduced2 | 1 | — | 8 |
| `cz-407` | output | standard | 1 | — | 12 |
| `cz-408` | output | reduced | 1 | — | 13 |
| `cz-462` | output | reduced1 | 1 | — | 13 |
| `cz-463` | output | reduced2 | 1 | — | 13 |
| `cz-415` | input | standard | — | `cz-405` | 43 |
| `cz-416` | input | reduced | — | `cz-406` | 44 |
| `cz-490` | input | reduced1 | — | `cz-460` | 44 |
| `cz-491` | input | reduced2 | — | `cz-461` | 44 |
| `cz-417` | input | standard | — | `cz-407` | 43 |
| `cz-418` | input | reduced | — | `cz-408` | 44 |
| `cz-492` | input | reduced1 | — | `cz-462` | 44 |
| `cz-493` | input | reduced2 | — | `cz-463` | 44 |

Hidden kódy `cz-405..cz-463`: `noPayTax: 1, sumBase: 0, sumTax: 0, sumTotal: 0,
hidden: 1`. Vstupní kódy `cz-415..cz-493`: `noPayTax: 1, sumTax: 0` +
`reverseVatCode`.

### Reprezentativní příklady JSONC

Na ukázku struktury — Claude Code podle těchto vzorů sestaví celou tabulku
podle mapování výše:

```jsonc
"cz-110": {
    "fullName": "Tuzemsko/Vstup/Základní",
    "fullName:cs": "Tuzemsko/Vstup/Základní",
    "name": "Základní",
    "name:cs": "Základní",
    "name:en": "Standard rate",
    "print": "Základní",
    "print:cs": "Základní",
    "category": "standard",
    "place": "domestic",
    "direction": "input",
    "vatReturnRow": 40
},

"cz-115": {
    "fullName": "Tuzemsko/Vstup/Základní - přenesení DP4",
    "fullName:cs": "Tuzemsko/Vstup/Základní - přenesení DP4",
    "name": "Základní - PDP 4",
    "name:cs": "Základní - PDP 4",
    "print": "Základní - přenesení daňové povinnosti 4",
    "print:cs": "Základní - přenesení daňové povinnosti 4",
    "category": "standard",
    "place": "domestic",
    "direction": "input",
    "noPayTax": 1,
    "sumTax": 0,
    "reverseVatCode": "cz-203",
    "reverseCharge": 1,
    "reverseChargeCode": 4,
    "vatReturnRow": 43
},

"cz-203": {
    "fullName": "Tuzemsko/Výstup/Základní - přenesení DP (odběratel)",
    "fullName:cs": "Tuzemsko/Výstup/Základní - přenesení DP (odběratel)",
    "name": "Základní - PDP (ODB)",
    "name:cs": "Základní - PDP (ODB)",
    "print": "Základní - přenesení daňové povinnosti (odběratel)",
    "category": "standard",
    "place": "domestic",
    "direction": "output",
    "noPayTax": 1,
    "hidden": 1,
    "sumTax": 0,
    "sumBase": 0,
    "sumTotal": 0,
    "vatReturnRow": 10
},

"cz-201": {
    "fullName": "EU/Výstup/Zboží",
    "fullName:cs": "EU/Výstup/Zboží",
    "name": "Zboží EU",
    "name:cs": "Zboží EU",
    "print": "EU/Zboží",
    "category": "zero",
    "place": "intracom",
    "direction": "output",
    "intracomCode": 0,
    "vatReturnRow": 20,
    "note": "eu"
},

"cz-215": {
    "fullName": "EU/Vstup/Zboží/Základní",
    "fullName:cs": "EU/Vstup/Zboží/Základní",
    "name": "Základní - zboží EU",
    "name:cs": "Základní - zboží EU",
    "print": "EU/Zboží/Základní",
    "category": "standard",
    "place": "intracom",
    "direction": "input",
    "noPayTax": 1,
    "sumTax": 0,
    "reverseVatCode": "cz-205",
    "vatReturnRow": 43
},

"cz-401": {
    "fullName": "VÝVOZ/Výstup/Zboží",
    "fullName:cs": "VÝVOZ/Výstup/Zboží",
    "name": "Zboží VÝVOZ",
    "name:cs": "Zboží VÝVOZ",
    "print": "VÝVOZ/Zboží",
    "category": "zero",
    "place": "foreign",
    "direction": "output",
    "vatReturnRow": 22
}
```

Tj. všechna `name`/`fullName`/`print` se kopírují ze starého Shipardu
i s jejich `:cs` variantou. `:en` přidávat **jen u `name`** v případech,
kdy existuje smysluplný anglický překlad (`Standard rate`, `Reduced rate`,
…). Pro PDP a hidden kódy `:en` nepřidávej (jsou to české specifické
záležitosti).

### Sekce `vatPercents`

Migruj **všech ~85 záznamů** ze starého `taxPercents` 1:1 — jen přejmenuj
`code` z `EUCZ110` na `cz-110` atd. Struktura zůstává:

```jsonc
"vatPercents": [
    { "code": "cz-110", "from": "2010-01-01", "to": "2011-12-31", "value": 20.0 },
    { "code": "cz-110", "from": "2012-01-01", "to": "2012-12-31", "value": 20.0 },
    { "code": "cz-110", "from": "2013-01-01", "to": "0000-00-00", "value": 21.0 },
    // ... atd. ze starého souboru
]
```

`from = "0000-00-00"` znamená „od začátku platnosti", `to = "0000-00-00"`
znamená „bez konce platnosti". Migrace je čistě mechanická.

**Vynech** záznamy odkazující na `EUCZ113` (kód, který v `taxCodes`
neexistuje — je to artefakt v zdrojovém souboru).

**Vynech** záznam pro `EUCZ000` (nedaňový řádek, pro MVP nemigrujeme).

### Sekce `vatNotes`

```jsonc
"vatNotes": {
    "pdp4": {
        "text": "Daň odvede zákazník",
        "text:cs": "Daň odvede zákazník"
    },
    "pdp5": {
        "text": "Daň odvede zákazník",
        "text:cs": "Daň odvede zákazník"
    },
    "eu": {
        "text": "Daň odvede zákazník",
        "text:cs": "Daň odvede zákazník"
    }
}
```

## PHP třída `VatRateResolver`

Soubor: `modules/world/vat/src/VatRateResolver.php`.

```php
<?php

declare(strict_types=1);

namespace Shipard\Module\World\Vat;

use Shipard\Core\Config\ConfigRuntime;

/**
 * Resolves VAT codes and rates from cfgItem world.vat.{country}.
 *
 * Stateless — uses ConfigRuntime to read cfgItem on each call. Cache by
 * country code is built lazily.
 */
final class VatRateResolver
{
    /** @var array<string, array> per-country cache of full cfgItem data */
    private array $cache = [];

    public function __construct(
        private readonly ConfigRuntime $config,
    ) {}

    /**
     * Resolve VAT percentage for a given code on a given date.
     *
     * @param  string $countryCode  e.g. "cz"
     * @param  string $vatCode      e.g. "cz-110"
     * @param  string $date         "Y-m-d"
     * @return float                e.g. 21.0
     * @throws \LogicException      if code unknown or no rate valid for date
     */
    public function resolveVatPct(string $countryCode, string $vatCode, string $date): float
    {
        $cfg = $this->loadCountryConfig($countryCode);

        if (!isset($cfg['vatCodes'][$vatCode])) {
            throw new \LogicException(
                "Unknown VAT code '{$vatCode}' in country '{$countryCode}'",
            );
        }

        foreach ($cfg['vatPercents'] ?? [] as $entry) {
            if ($entry['code'] !== $vatCode) {
                continue;
            }
            if ($entry['from'] !== '0000-00-00' && $date < $entry['from']) {
                continue;
            }
            if ($entry['to'] !== '0000-00-00' && $date > $entry['to']) {
                continue;
            }
            return (float) $entry['value'];
        }

        throw new \LogicException(
            "No VAT percentage defined for code '{$vatCode}' on date '{$date}' in country '{$countryCode}'",
        );
    }

    /**
     * Get filtered list of VAT codes (for UI dropdown on document row).
     *
     * @param  string      $countryCode  e.g. "cz"
     * @param  string|null $direction    "input" / "output" / null = both
     * @param  string|null $place        "domestic" / "intracom" / "foreign" / null = all
     * @param  bool        $includeHidden  default false (skips hidden codes)
     * @return array<string, array>     keyed by VAT code slug
     */
    public function getVatCodes(
        string $countryCode,
        ?string $direction = null,
        ?string $place = null,
        bool $includeHidden = false,
    ): array {
        $cfg = $this->loadCountryConfig($countryCode);

        $result = [];
        foreach ($cfg['vatCodes'] ?? [] as $key => $code) {
            if (!$includeHidden && !empty($code['hidden'])) {
                continue;
            }
            if ($direction !== null && ($code['direction'] ?? null) !== $direction) {
                continue;
            }
            if ($place !== null && ($code['place'] ?? 'domestic') !== $place) {
                continue;
            }
            $result[$key] = $code;
        }
        return $result;
    }

    /**
     * Get details of a single VAT code.
     *
     * @return array|null  null if not found
     */
    public function getVatCode(string $countryCode, string $vatCode): ?array
    {
        $cfg = $this->loadCountryConfig($countryCode);
        return $cfg['vatCodes'][$vatCode] ?? null;
    }

    /**
     * Get all VAT categories for a country (for reporting / UI labels).
     *
     * @return array<string, array>
     */
    public function getVatCategories(string $countryCode): array
    {
        $cfg = $this->loadCountryConfig($countryCode);
        return $cfg['vatCategories'] ?? [];
    }

    /**
     * Get all VAT notes for a country.
     *
     * @return array<string, array>
     */
    public function getVatNotes(string $countryCode): array
    {
        $cfg = $this->loadCountryConfig($countryCode);
        return $cfg['vatNotes'] ?? [];
    }

    /**
     * Validate cfgItem integrity — every code in vatPercents must exist
     * in vatCodes. Useful for tests and migrations.
     *
     * @return array<string>  list of error messages (empty = OK)
     */
    public function validateCountryConfig(string $countryCode): array
    {
        $cfg = $this->loadCountryConfig($countryCode);
        $errors = [];

        $codeKeys = array_keys($cfg['vatCodes'] ?? []);

        // Check vatPercents references
        foreach ($cfg['vatPercents'] ?? [] as $i => $entry) {
            if (!in_array($entry['code'], $codeKeys, true)) {
                $errors[] = "vatPercents[{$i}]: unknown code '{$entry['code']}'";
            }
        }

        // Check reverseVatCode references and category references
        $categoryKeys = array_keys($cfg['vatCategories'] ?? []);
        foreach ($cfg['vatCodes'] ?? [] as $key => $code) {
            if (isset($code['reverseVatCode'])
                && !in_array($code['reverseVatCode'], $codeKeys, true)) {
                $errors[] = "vatCodes['{$key}']: reverseVatCode '{$code['reverseVatCode']}' not found";
            }
            if (isset($code['category'])
                && !in_array($code['category'], $categoryKeys, true)) {
                $errors[] = "vatCodes['{$key}']: unknown category '{$code['category']}'";
            }
        }

        // Check note references
        $noteKeys = array_keys($cfg['vatNotes'] ?? []);
        foreach ($cfg['vatCodes'] ?? [] as $key => $code) {
            if (isset($code['note'])
                && !in_array($code['note'], $noteKeys, true)) {
                $errors[] = "vatCodes['{$key}']: unknown note '{$code['note']}'";
            }
        }

        return $errors;
    }

    private function loadCountryConfig(string $countryCode): array
    {
        if (isset($this->cache[$countryCode])) {
            return $this->cache[$countryCode];
        }

        $data = $this->config->cfgItem("world.vat.{$countryCode}");
        if (!is_array($data)) {
            throw new \LogicException(
                "VAT configuration for country '{$countryCode}' not found",
            );
        }

        return $this->cache[$countryCode] = $data;
    }
}
```

## `module.jsonc`

```jsonc
{
    "id": "world.vat",
    "name": "VAT rules",
    "name:cs": "Pravidla DPH",
    "name:en": "VAT rules",
    "description": "VAT rates and codes per country",
    "description:cs": "Sazby DPH a kódy DPH per stát (CZ, později další státy EU)",
    "description:en": "VAT rates and codes per country (CZ, later other EU countries)",

    "dependencies": ["world.base", "world.trade"],

    "config": [
        {
            "id": "world.vat.cz",
            "file": "config/vat-cz.jsonc"
        }
    ]
}
```

## `install.base/module.jsonc`

Do `dependencies` přidat `"world.vat"` (na vhodné místo, alfabeticky nebo
za `world.trade`).

## README modulu

`modules/world/vat/README.md`:

```markdown
# Modul: world.vat

Konfigurační modul s pravidly DPH per stát. Bez vlastních databázových
tabulek — pouze cfgItem soubory a PHP resolver.

## Účel

Poskytuje:

- **Klasifikaci** sazeb DPH (`vatCategories`: standard, reduced, …)
- **Konkrétní kódy DPH** per stát (`vatCodes`: cz-110, cz-150, …) — položky
  v roletce na řádku dokladu
- **Časově proměnná procenta** per kód (`vatPercents`: cz-110 → 20 % do 2012,
  21 % od 2013)
- **Texty na doklad** (`vatNotes`: "Daň odvede zákazník" pro PDP a EU)

## Stav

V aktuální verzi je k dispozici **pouze CZ** (`world.vat.cz`). Ostatní
EU státy přijdou v navazujících fázích — viz `vat-{country}.jsonc` šablona.

## Použití

```php
use Shipard\Module\World\Vat\VatRateResolver;

$resolver = new VatRateResolver($config);

// Procento k datu
$pct = $resolver->resolveVatPct('cz', 'cz-110', '2024-06-01');
// → 21.0

// Filtrování pro UI roletku na řádku faktury vydané do tuzemska
$codes = $resolver->getVatCodes('cz', direction: 'output', place: 'domestic');
// → ['cz-120' => [...], 'cz-121' => [...], 'cz-150' => [...], ...]

// Detail kódu
$code = $resolver->getVatCode('cz', 'cz-115');
```

## Struktura cfgItem

Viz `config/vat-cz.jsonc` jako referenční vzor. Detailní popis atributů:

- `vatCategories` — klasifikace (klíč: lowercase slug, value: name vícejaz.)
- `vatCodes` — konkrétní kódy (klíč: `{country}-{nnn}`, value: object s atributy)
- `vatPercents` — pole časových intervalů per kód
- `vatNotes` — texty pro tisk dokladu

Plný popis atributů `vatCodes` viz `docs/docs-mvp.md` sekce 4.

## Vztah k `world.base` a `world.trade`

`world.base` poskytuje seznam zemí a měn. `world.trade` poskytuje obchodní
unie (EU). `world.vat` na obojí navazuje — kód státu v `world.vat.{country}`
musí existovat ve `world.base.countries`. Příslušnost k EU se odvozuje
ze `world.trade.unions`.
```

## Hotovo když

- [ ] `bin/shpd-ds ds-upgrade` projde bez chyb na čistém i existujícím DS
- [ ] cfgItem `world.vat.cz` má všechny sekce (`vatCategories`, `vatCodes`,
      `vatPercents`, `vatNotes`)
- [ ] Migrace kódů ze starého Shipardu je kompletní — všechny EUCZ\* kódy kromě
      `EUCZ000` mají nový slug `cz-{nnn}`
- [ ] Migrace `taxPercents` je kompletní — všechny záznamy přejmenované
      (kromě těch pro `EUCZ000` a `EUCZ113`)
- [ ] PHP třída `VatRateResolver` existuje s metodami `resolveVatPct`,
      `getVatCodes`, `getVatCode`, `getVatCategories`, `getVatNotes`,
      `validateCountryConfig`
- [ ] PHPUnit testy v `tests/Unit/Module/World/Vat/VatRateResolverTest.php`
      pokrývají:
  - resolveVatPct s platným kódem a datem → správné procento
  - resolveVatPct přes přelom roku 2013 (cz-110: 20 → 21) ověří časovou logiku
  - resolveVatPct s neznámým kódem → LogicException
  - getVatCodes s direction=output, place=domestic → očekávaný subset
      (kódy řady 120, 121, 150, ... bez kódů 110/115/203 a EU)
  - getVatCodes default skip hidden → cz-203 ve výsledku není
  - getVatCodes s includeHidden=true → cz-203 přítomen
  - validateCountryConfig na produkčním cfgItem → empty array
- [ ] `install.base` dependencies obsahují `world.vat`
- [ ] `README.md` modulu napsaný

## Konvence

- **Jazyk**: UI texty čeština, kód a komentáře angličtina
- **Vícejazyčnost**: každé `name` v `vatCategories` má `:cs` a `:en`. Pro
  `vatCodes` má `name`/`fullName`/`print` jen `:cs` (převzato 1:1 ze starého
  Shipardu, EN překlady mimo scope tasku); `:en` přidávat **jen u
  `vatCategories`**
- **PHP 8.3** strict_types, readonly properties kde možné
- **Klíče vatCodes**: lowercase, `{country}-{nnn}` (s pomlčkou)
- **JSONC**: komentáře vítány, dělí kódy do logických bloků (vstup tuzemský,
  výstup tuzemský, EU vstup, EU výstup, dovoz, vývoz, hidden páry)
- Po úpravě `module.jsonc` nebo cfgItem volat `bin/shpd-ds ds-upgrade`,
  který kompiluje konfiguraci

## Doporučené pořadí implementace

1. **Modul kostra**: `module.jsonc`, prázdné `vat-cz.jsonc`, `VatRateResolver`
   (jen třída se signaturami) → `ds-upgrade` projde
2. **vatCategories** (šestice klíčů) → další ověření přes `getVatCategories`
3. **vatNotes** (3 záznamy)
4. **vatCodes — Tuzemsko vstup** (13 kódů včetně PDP) — ověř přes
   `validateCountryConfig` (vyhodí chybu na chybějící `cz-203`)
5. **vatCodes — Tuzemsko výstup** (13 kódů včetně 3 hidden páru)
6. **vatCodes — EU** (10 výstup + 8 vstup)
7. **vatCodes — Vývoz/Dovoz** (1 + 8 hidden + 8 vstup)
8. **vatPercents** (~85 řádků)
9. **Plná validace**: `validateCountryConfig('cz')` vrátí prázdné pole
10. **PHPUnit testy** — pokryjí klíčové edge cases
11. **README + install.base aktualizace**

## Otevřené body (ne-blokující)

- `cz-000` (nedaňový řádek) je v MVP vynechán. Pokud později vyplyne potřeba,
  přidá se s konvencí `direction: ["input", "output"]` (pole) a resolver
  to musí umět zpracovat. Pro teď uživatel volí `vat_code = null` na
  textovém řádku dokladu.
- Mapování `:en` názvů kódů (`cz-110` → "Standard rate (domestic input)") je
  nice-to-have. Vyřešit, až bude EN UI relevantní (pravděpodobně až s docs
  MVP fází 5+).
- Per-EU-stát soubory (`vat-sk.jsonc`, `vat-de.jsonc`, …) — samostatný úkol,
  pokud se ukáže, že stejné firmy reálně potřebují vystavovat doklady ve
  více státech najednou.
