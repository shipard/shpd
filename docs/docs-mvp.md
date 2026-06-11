# Shipard — Doklady MVP

> **Stav:** Designový dokument pro MVP dokladového systému. Slouží jako vodítko
> pro implementaci. Po dokončení MVP jeho obsah přechází do standardní per-modul
> dokumentace (`README.md` a `tables/{id}.md`).

## 1. Úvod a cíle MVP

Doklady jsou klíčová evidence systému — všechny účetní a ekonomické dokumenty
firmy. Tento dokument popisuje **MVP** dokladového systému, jehož cílem je
**umožnit pořizování, výpočet a evidenci faktur (vydaných i přijatých)** se
správnou obsluhou DPH napříč režimy (tuzemsko, EU intrakomunitární plnění,
zahraničí, reverse charge) a s životním cyklem dokladu řízeným stavovým
modelem.

### Co MVP dělá

- **Dva typy dokladů:** Faktura vydaná (`invno`), Faktura přijatá (`invni`)
- **Hlavička, řádky, rekapitulace DPH** — kompletní třístupňová struktura
- **DPH model pro Českou republiku** — všechny kódy DPH ze starého Shipardu
  (tuzemsko vstup/výstup ve všech sazbách, EU plnění, dovoz/vývoz, tuzemský
  PDP), časově proměnná procenta, párové kódy pro reverse charge
- **Číselné řady** — atomicky generované číslo dokladu při Potvrzení
- **Snapshoty** dodavatele a odběratele do hlavičky při Potvrzení
- **Stavový model** s 6 stavy (Koncept, Potvrzeno, V opravě, V pořádku, Storno,
  Smazáno), specifický pro doklady (cfgItem `docs.core.docStates`)
- **Polymorfní zpracování** — společné tabulky `docs_core_*` pro všechny
  budoucí typy, samostatné Document třídy per typ v modulech
  `docs.invoicesOut` / `docs.invoicesIn`

### Co MVP NEdělá

Záměrně nepokrývá (a očekává se, že přijde v dalších fázích):

- **Přiznání DPH** a **Kontrolní hlášení** — sloupce a kódy jsou připravené,
  ale samotný report a podání daňové správě je samostatný modul
- **Souhrnné hlášení** k EU plnění
- **OSS režim**
- **Účtování** — žádný účetní deník, žádné předkontace
- **Saldokonto** — žádné párování úhrad, žádné otevřené pohledávky/závazky
- **PDF výstupy a tisk** dokladů
- **Bankovní výpisy a pokladní doklady** (typy `bank`, `cash`)
- **Zálohové faktury** (typy `prfmin`, `invpo`)
- **Objednávky, dodací listy, nabídky**
- **DPH per EU stát** — pouze CZ; ostatní státy přijdou jako samostatný úkol
  s revizí kódů ze starého Shipardu
- **Skladová evidence** — položky se používají jen jako katalog, žádné pohyby
- **Per-form JavaScript** pro pokročilou dynamiku — pouze server-side
  recalculate

### Vztah k existujícím modulům

MVP staví na již implementovaných modulech:

- `core.system` — uživatelé, doc states, settings
- `core.units` — měrné jednotky (FK z řádků)
- `core.attachments` — přílohy k dokladu (tab v editačním formuláři)
- `base.persons` — partneři + adresy + bankovní účty (FK z hlavičky)
- `world.base` — země, měny, jazyky
- `world.trade` — obchodní unie (EU)
- `economy.codebooks` — fiskální období, registrace DPH, vlastní bank účty
- `economy.items` — katalog položek (FK z řádků)

Vzniká několik nových modulů (modulová struktura sekce 2) a jedno **menší
rozšíření** modulu `base.persons` (sekce 10) — sloupce `is_own`
a `court_registration` na osobě.

---

## 2. Modulová struktura

### Nová skupina modulů `docs`

Doklady tvoří samostatnou top-level skupinu kvůli očekávanému rozsahu (~20
typů dokladů v plné implementaci). Společné jádro je v `docs.core`,
specifické typy v samostatných modulech.

```
modules/docs/
├── core/                  ← polymorfní jádro: tabulky heads/rows/vat_recap,
│   ├── module.jsonc           číselné řady, doc states, výpočty
│   ├── tables/
│   ├── config/
│   ├── forms/
│   └── src/
├── invoicesOut/           ← Faktura vydaná (Document třída + viewer)
│   ├── module.jsonc
│   └── src/
└── invoicesIn/            ← Faktura přijatá (Document třída + viewer)
    ├── module.jsonc
    └── src/
```

### Nový modul `world.vat`

Daňová pravidla a sazby DPH per stát. Bez vlastních tabulek, čistě
konfigurační soubory.

```
modules/world/vat/
├── module.jsonc
└── config/
    └── vat-cz.jsonc       ← cfgItem world.vat.cz (jediný v MVP)
```

### Závislosti

```
docs.core         → core.system, core.units, core.attachments,
                    base.persons, world.base, world.vat,
                    economy.codebooks, economy.items
docs.invoicesOut  → docs.core
docs.invoicesIn   → docs.core

world.vat         → world.base, world.trade
```

### Tabulky a tableId

| Tabulka | tableId | Modul |
|---|---|---|
| `docs_core_heads` | 401 | `docs.core` |
| `docs_core_rows` | 402 | `docs.core` |
| `docs_core_vat_recap` | 403 | `docs.core` |
| `docs_core_number_series` | 404 | `docs.core` |
| `docs_core_number_counters` | 405 | `docs.core` |

Moduly `docs.invoicesIn` a `docs.invoicesOut` nemají vlastní tabulky.

### Aktualizace `install.base`

V `modules/install/base/module.jsonc` se do `dependencies` přidají moduly
`world.vat`, `docs.core`, `docs.invoicesIn`, `docs.invoicesOut` (transitively
přitáhnou `economy.items` a další).

### Prerekvizita: rozšíření `base.persons`

Tabulka `base_persons_persons` dostane dva nové sloupce: `is_own` (flag
vlastní firmy) a `court_registration` (zápis v obchodním rejstříku). Detail
v sekci 10. Tato změna je nezávislá na docs MVP a může se nasadit dřív.

---

## 3. Stavový model dokladu

### cfgItem `docs.core.docStates`

Doklady mají vlastní rozšířenou sadu stavů — oproti standardnímu
`core.system.docStatesArchive` přibývají **Potvrzeno** (20) a **Storno** (30),
chybí **V archívu** (70).

| docState | stateName | mainState | viewGroup | readOnly | enablePrint | closeForm | goto |
|---|---|---|---|---|---|---|---|
| 10 | Koncept | 1 | active | — | — | 0 | 20, 90 |
| 20 | Potvrzeno | 2 | active | — | — | 0 | 10*, 40, 90 |
| 80 | V opravě | 3 | active | — | — | 0 | 40, 30, 90 |
| 40 | V pořádku | **4** | active | 1 | 1 | 1 | 80, 30, 90 |
| 30 | Storno | **4** | active | 1 | — | 1 | 80 |
| 90 | Smazáno | 5 | trash | 1 | — | 1 | 80 |

\* Přechod 20 → 10 je povolen **jen pokud je doklad poslední v řadě** (kontrola
v `processDocState` + filtr v `getAvailableTransitions`). Důvod: uvolnění
sequence_number jen pokud nevznikne díra v sekvenci.

**Stav 40 a 30 sdílejí `mainState=4`** záměrně — v prohlížeči se Storno
prolíná s aktivními doklady a řadí se podle čísla dokladu (oranžovo-červená
varianta, vidíš ji v seznamu jako „škrtlou" položku, ale zachová si svou
pozici v sekvenci).

### Přechody — sémantika

| Z → Do | Co se děje | Důvod |
|---|---|---|
| 10 → 20 | Přidělí se sequence_number, sestaví doc_number, vyplní snapshoty | Doklad získává identitu, je viditelný „venku" |
| 20 → 40 | Žádná změna dat, jen stav | Účetně uzavřeno, doklad „v pořádku" |
| 20 → 10 | Uvolní sequence_number (jen pokud poslední), doc_number → `!...` | Kompletní vrácení do rozpracování |
| 40 → 80 | Žádná změna dat | Otevření pro opravu |
| 80 → 40 | Aktualizace snapshotů (pokud změněn partner) | Uzavření po opravě |
| 80 → 30 | Žádná změna dat, jen stav | Doklad je nesmyslný / chybný, ale evidovaný |
| 30 → 80 | Otevření pro úpravu (přechod na cestu zpět) | Storno se má vrátit do života |
| 40 → 30 | Žádná změna dat, jen stav | Stornování validního dokladu |
| → 90 | Žádná změna dat, jen stav | Smazání (Koncept/Potvrzeno/V opravě → Smazáno) |
| 90 → 80 | Žádná změna dat | Obnovení smazaného (přes V opravě) |

**Klíčový invariant:** `sequence_number` jednou přidělené nelze ztratit (kromě
přechodu 20 → 10 u posledního dokladu). Jakmile je `sequence_number != NULL`,
zůstává na dokladu i v Storno, V archívu (kdyby existoval) i ve Smazáno.

### CSS stateStyles

Použije se stávající paleta z `docs/design-system.md`:

| docState | stateStyle | Barva |
|---|---|---|
| 10 Koncept | `concept` | žlutá |
| 20 Potvrzeno | `confirmed` | (bez pruhu) |
| 80 V opravě | `edit` | fialová |
| 40 V pořádku | `done` | (bez pruhu, badge zelený) |
| 30 Storno | `cancelled` | červená |
| 90 Smazáno | `trash` | tmavě šedá + line-through |

### Kompletní cfgItem soubor

`modules/docs/core/config/docStates.jsonc`:

```jsonc
{
    // docs.core.docStates
    //
    // Stavy dokladů. Oproti core.system.docStatesArchive:
    // + 20 Potvrzeno (přidělené číslo, ale stále editovatelné)
    // + 30 Storno (zachovává číslo, sdílí mainState s 40 V pořádku)
    // - 70 V archívu (u dokladů nadbytečné)

    "10": {
        "stateName": "Koncept",
        "stateName:cs": "Koncept",
        "stateName:en": "Draft",
        "actionName": "Uložit jako koncept",
        "actionName:cs": "Uložit jako koncept",
        "actionName:en": "Save as draft",
        "stateStyle": "concept",
        "mainState": 1,
        "viewGroup": "active",
        "closeForm": 0,
        "goto": [20, 90]
    },

    "20": {
        "stateName": "Potvrzeno",
        "stateName:cs": "Potvrzeno",
        "stateName:en": "Confirmed",
        "actionName": "Potvrdit",
        "actionName:cs": "Potvrdit",
        "actionName:en": "Confirm",
        "stateStyle": "confirmed",
        "mainState": 2,
        "viewGroup": "active",
        "closeForm": 0,
        "goto": [10, 40, 90]
    },

    "80": {
        "stateName": "V opravě",
        "stateName:cs": "V opravě",
        "stateName:en": "Being edited",
        "actionName": "Opravit",
        "actionName:cs": "Opravit",
        "actionName:en": "Edit",
        "stateStyle": "edit",
        "mainState": 3,
        "viewGroup": "active",
        "closeForm": 0,
        "goto": [40, 30, 90]
    },

    "40": {
        "stateName": "V pořádku",
        "stateName:cs": "V pořádku",
        "stateName:en": "Done",
        "actionName": "V pořádku",
        "actionName:cs": "V pořádku",
        "actionName:en": "Mark as done",
        "stateStyle": "done",
        "mainState": 4,
        "viewGroup": "active",
        "readOnly": 1,
        "enablePrint": 1,
        "closeForm": 1,
        "goto": [80, 30, 90]
    },

    "30": {
        "stateName": "Storno",
        "stateName:cs": "Storno",
        "stateName:en": "Cancelled",
        "actionName": "Stornovat",
        "actionName:cs": "Stornovat",
        "actionName:en": "Cancel",
        "stateStyle": "cancelled",
        "mainState": 4,
        "viewGroup": "active",
        "readOnly": 1,
        "closeForm": 1,
        "goto": [80]
    },

    "90": {
        "stateName": "Smazáno",
        "stateName:cs": "Smazáno",
        "stateName:en": "Deleted",
        "actionName": "Smazat",
        "actionName:cs": "Smazat",
        "actionName:en": "Delete",
        "stateStyle": "trash",
        "mainState": 5,
        "viewGroup": "trash",
        "readOnly": 1,
        "closeForm": 1,
        "goto": [80]
    }
}
```

### Backend — kontextové filtrování přechodů

Stávající `DocStateConfig::getAvailableTransitions(int $currentState)` musí
být rozšířený o **kontext dokladu** — minimálně předaný `array $data`.
Důvod: filtrování přechodu 20 → 10 podle pravidla „poslední v řadě".

Návrh signatury:

```php
public function getAvailableTransitions(int $currentState, array $context = []): array
{
    $transitions = $this->doStandardLookup($currentState);

    // Hook pro business-specific filtrování
    foreach ($transitions as $i => $transition) {
        if (!$this->isTransitionContextuallyAllowed($currentState, $transition['state'], $context)) {
            unset($transitions[$i]);
        }
    }

    return array_values($transitions);
}
```

Implementace `isTransitionContextuallyAllowed` může být v subclass `DocStateConfig`
specifické pro `docs.core` — kontrola přes DB, zda je `sequence_number`
maximální v dané řadě+roce.

Volání z `FormController.meta()` předá `$data` z aktuálně načteného dokladu.

---

## 4. Modul `world.vat` — DPH model

### 4.1 Přehled

DPH je v Shipardu modelováno **třemi vrstvami**, převzato 1:1 ze starého
Shipardu, jen s přejmenovanou terminologií:

| Vrstva | Účel | Příklady |
|---|---|---|
| **VAT kategorie** (`vatCategories`) | Abstraktní třída sazby (klasifikace) | Základní, Snížená, Bez daně, Osvobozeno |
| **VAT kódy** (`vatCodes`) | Konkrétní receptura: směr, místo plnění, kategorie + flagy chování (sumTax, hidden, reverseTaxCode, …) | `cz-110` Tuzemsko/Vstup/Základní, `cz-203` PDP/Výstup/Základní (odběratel) |
| **VAT procenta** (`vatPercents`) | Časově proměnná procenta per kód | `cz-110`: 20.0 do 2012, 21.0 od 2013 |

Na **řádku dokladu** je sloupec `vat_code` (konkrétní kód) a `vat_pct`
(resolvované procento, editovatelné). Kategorie se *neukládá* — odvozuje
se z kódu při potřebě (zobrazení, agregace).

### 4.2 Struktura cfgItem `world.vat.{country}`

Per stát existuje jeden cfgItem soubor. Pro MVP jen **CZ**.

```jsonc
// world.vat.cz
{
    "vatCategories": { ... },
    "vatCodes":      { ... },
    "vatPercents":   [ ... ],
    "vatNotes":      { ... }
}
```

### 4.3 vatCategories

Klasifikace sazby. Sdílí se mezi vstupními a výstupními kódy.

```jsonc
"vatCategories": {
    "standard":    { "name": "Standard rate",       "name:cs": "Základní" },
    "reduced":     { "name": "Reduced rate",        "name:cs": "Snížená" },
    "reduced1":    { "name": "First reduced rate",  "name:cs": "První snížená" },
    "reduced2":    { "name": "Second reduced rate", "name:cs": "Druhá snížená" },
    "zero":        { "name": "Zero rate",           "name:cs": "Bez daně" },
    "exempt":      { "name": "Exempt",              "name:cs": "Osvobozeno" }
}
```

| Klíč | CZ aktuálně | Poznámka |
|---|---|---|
| `standard` | 21 % | Současná základní |
| `reduced` | 12 % | Aktuální (od 2024) |
| `reduced1` | 15 % do 2023 | Historické (2015–2023) |
| `reduced2` | 10 % do 2023 | Historické (2015–2023) |
| `zero` | 0 % | Bez DPH (pro vstupní kódy bez DPH) |
| `exempt` | 0 % | Osvobozeno (pro výstupní kódy s vykazováním) |

Klíč kategorie se v `vatCodes` referencuje pod polem `category`.

### 4.4 vatCodes

Centrální entita. Klíčem je **slug ve tvaru `{country}-{num}`** (s pomlčkou).
Tj. `cz-110`, `cz-203`, atd. Ten samý kód pro různé státy bude mít prefix
státu (např. `sk-110` na Slovensku).

```jsonc
"vatCodes": {
    "cz-110": {
        "fullName":  "Tuzemsko/Vstup/Základní",
        "name":      "Základní",
        "name:cs":   "Základní",
        "print":     "Základní",
        "category":  "standard",
        "place":     "domestic",       // domestic | intracom | foreign
        "direction": "input",          // input | output
        "vatReturnRow": 40
    },
    "cz-115": {
        "fullName":  "Tuzemsko/Vstup/Základní - přenesení DP4",
        "name":      "Základní - PDP 4",
        "category":  "standard",
        "place":     "domestic",
        "direction": "input",
        "noPayTax":       1,
        "sumTax":         0,
        "reverseVatCode": "cz-203",
        "reverseCharge":      1,
        "reverseChargeCode":  4,
        "vatReturnRow":   43
    },
    "cz-203": {
        "fullName":  "Tuzemsko/Výstup/Základní - přenesení DP (odběratel)",
        "name":      "Základní - PDP (ODB)",
        "category":  "standard",
        "place":     "domestic",
        "direction": "output",
        "noPayTax": 1,
        "hidden":   1,
        "sumTax":   0,
        "sumBase":  0,
        "sumTotal": 0,
        "vatReturnRow": 10
    }
    // ... další kódy
}
```

#### Atributy vatCode — kompletní přehled

| Atribut | Typ | Default | Význam |
|---|---|---|---|
| `fullName` | string vícejaz. | — | Plný název pro select / disambiguation |
| `name` | string vícejaz. | — | Krátký název pro UI roletky |
| `print` | string vícejaz. | `name` | Text pro tisk dokladu |
| `category` | string | — | Klíč do `vatCategories` |
| `place` | enum: `domestic` / `intracom` / `foreign` | `domestic` | Místo plnění |
| `direction` | enum: `input` / `output` | — | Vstup (přijatá faktura) / Výstup (vydaná) |
| `noPayTax` | 0/1 | 0 | DPH se neplatí (reverse charge nebo bez daně) |
| `sumBase` | 0/1 | 1 | Započítávat základ do součtu |
| `sumTax` | 0/1 | 1 | Započítávat daň do součtu |
| `sumTotal` | 0/1 | 1 | Započítávat celkem do součtu |
| `hidden` | 0/1 | 0 | Skrýt v roletce výběru (jen pro odpárované generování) |
| `reverseVatCode` | string | null | Klíč párového kódu (oddanění) |
| `reverseCharge` | 0/1 | 0 | Jde o reverse charge plnění |
| `reverseChargeCode` | int | null | Kód PDP (4 nebo 5 v ČR) |
| `vatReturnRow` | int | 0 | Řádek v Přiznání DPH (0 = nezahrnuto) |
| `intracomCode` | int | null | Kód pro Souhrnné hlášení (zboží 0, služby 3) |
| `note` | string | null | Klíč do `vatNotes` (text na doklad pro uživatele) |

Některé atributy jsou v MVP připravené, ale aktivně se nepoužijí
(`vatReturnRow`, `intracomCode`) — slouží budoucímu modulu Přiznání DPH /
Souhrnné hlášení. Schema pro ně je ale již nyní validní.

#### Filtrování v roletce na řádku

Při editaci řádku dokladu se nabídne jen **podmnožina kódů** podle:

1. **Stát** registrace DPH na hlavičce → vybírá se cfgItem `world.vat.{country}`
2. **Direction** — z typu dokladu (FVB → output, FPB → input)
3. **Place** — z hlavičky dokladu (`vat_place`):
   - `domestic` → kódy s `place=domestic` (tuzemsko, vč. PDP)
   - `intracom` → kódy s `place=intracom`
   - `foreign` → kódy s `place=foreign`
4. **Vyřadit `hidden=1`** — tyhle kódy jsou jen pro generování oddaňující
   strany v rekapitulaci, uživatel je nikdy ručně nevybírá

### 4.5 vatPercents

Časově proměnná procenta. Pole objektů (ne mapa, kvůli časovým overlapům).

```jsonc
"vatPercents": [
    { "code": "cz-110", "from": "0000-00-00", "to": "2011-12-31", "value": 20.0 },
    { "code": "cz-110", "from": "2012-01-01", "to": "2012-12-31", "value": 20.0 },
    { "code": "cz-110", "from": "2013-01-01", "to": "0000-00-00", "value": 21.0 },
    { "code": "cz-111", "from": "0000-00-00", "to": "2011-12-31", "value": 10.0 },
    { "code": "cz-111", "from": "2012-01-01", "to": "2012-12-31", "value": 14.0 },
    { "code": "cz-111", "from": "2013-01-01", "to": "2014-12-31", "value": 15.0 },
    { "code": "cz-111", "from": "2024-01-01", "to": "0000-00-00", "value": 12.0 }
    // ... další kódy a období
]
```

`from = "0000-00-00"` znamená „od začátku platnosti kódu", `to = "0000-00-00"`
znamená „bez konce platnosti".

#### Resolver

```php
// world.vat.{country}
function resolveVatPct(string $countryCode, string $vatCode, ?string $date = null): float
{
    $date = $date ?? date('Y-m-d');
    $cfg = $config->cfgItem("world.vat.{$countryCode}");
    
    foreach ($cfg['vatPercents'] as $entry) {
        if ($entry['code'] !== $vatCode) continue;
        if ($entry['from'] !== '0000-00-00' && $date < $entry['from']) continue;
        if ($entry['to']   !== '0000-00-00' && $date > $entry['to'])   continue;
        return (float) $entry['value'];
    }
    
    throw new \LogicException("No VAT rate for code {$vatCode} on {$date}");
}
```

V dokladech se jako referenční datum používá **`vat_duzp`** z hlavičky.
Nový řádek dostává `vat_pct` resolved z DUZP. Při změně DUZP se procenta
**nepřepočítávají automaticky** (mohl by uživatel přijít o ručně přepsanou
hodnotu) — uživatel musí přepočet provést explicitně tlačítkem nebo změnou
kódu.

### 4.6 Reverse charge — párové kódy

Reverse charge (přenesení daňové povinnosti) se v rekapitulaci řeší **dvěma
řádky**: dodanění a oddanění. Výsledná daň = 0, ale obě strany jsou v evidenci
pro Přiznání DPH a budoucí účtování.

Mechanismus:

1. Uživatel vybere na řádku dokladu kód s `reverseVatCode != null`
   (např. `cz-115` Tuzemsko/Vstup/Základní – PDP 4)
2. Při sestavení rekapitulace v `Document::beforeSave`:
   - **Dodanění** = řádek pro `cz-115` se základem ze vstupních dat,
     `tax = 0` (`noPayTax: 1`, `sumTax: 0` znamená nezapočítávat do součtu)
   - **Oddanění** = řádek pro `cz-203` (z `reverseVatCode`) se stejným
     základem, ale s `sumBase: 0, sumTax: 0, sumTotal: 0` — všechno
     vyšednuté, slouží jen pro Přiznání DPH

Stejný princip platí i pro EU vstupní plnění (`cz-215` → `cz-205`) a dovoz
(`cz-415` → `cz-405`).

V rekapitulaci se uchová `is_reverse_pair` flag k identifikaci, která strana
páru řádek je. UI rendering pak může grayed-out hodnoty zobrazit jako
v existujícím Shipardu (viz screenshot rekapitulace v kole #5 designu).

### 4.7 Klíče vatCodes — konvence

- Lowercase, oddělené pomlčkou: `cz-110`, `cz-203`, `sk-110`
- Číselná část navazuje na konvenci starého Shipardu (110 = tuzemský vstup
  základní sazba, 120 = tuzemský výstup základní, 200 řada = EU, 400 řada
  = vývoz/dovoz)
- Slug se ukládá jako string do `docs_core_rows.vat_code`

Při migraci ze starého Shipardu (kódy `EUCZ110`, `EUCZ203`):

```
EUCZ110 → cz-110
EUCZ203 → cz-203
EUSK110 → sk-110
```

Tj. odstranit `EU` prefix, lowercase, vložit pomlčku mezi `cz` a číslo.

### 4.8 vatNotes

Texty, které se mohou objevit na dokladu (podle použitých kódů).

```jsonc
"vatNotes": {
    "pdp4": { "text": "Daň odvede zákazník" },
    "pdp5": { "text": "Daň odvede zákazník" },
    "eu":   { "text": "Daň odvede zákazník" }
}
```

vatCode na sebe odkazuje přes `note` atribut. Použije se při generování PDF
(až přijde) nebo i v UI náhledu rekapitulace.

### 4.9 Modul `world.vat` — module.jsonc

```jsonc
{
    "id": "world.vat",
    "name": "VAT rules",
    "name:cs": "Pravidla DPH",
    "name:en": "VAT rules",
    "description:cs": "Sazby DPH a kódy DPH per stát (CZ, později další státy EU)",
    "description:en": "VAT rates and codes per country (CZ, later other EU countries)",

    "dependencies": ["world.base", "world.trade"],

    "config": [
        {
            "id":   "world.vat.cz",
            "file": "config/vat-cz.jsonc"
        }
        // future: vat-sk.jsonc, vat-de.jsonc, ... (mimo MVP)
    ]
}
```

---

## 5. Číselné řady

### 5.1 Tabulka `docs_core_number_series`

Číselná řada drží konfiguraci pro generování čísel dokladů určitého typu.
Jeden typ dokladu může mít víc řad (FVB tuzemsko / FVB EUR / …); řada je
**vázaná pevně na jeden typ dokladu**.

```jsonc
{
    "tableId": 404,
    "name": "Document number series",
    "name:cs": "Číselné řady dokladů",
    "name:en": "Document number series",

    "displayPattern": "{name}",

    "docStates": {
        "stateColumn": "docState",
        "mainColumn": "docStateMain",
        "cfgItem": "core.system.docStatesArchive"
    },

    "columnGroups": [
        {"id": "identity",  "name:cs": "Identifikace"},
        {"id": "numbering", "name:cs": "Číslování"},
        {"id": "validity",  "name:cs": "Platnost"}
    ],

    "columns": [
        {"id": "id", "type": "int", "autoIncrement": true, "primaryKey": true},

        // identity
        {
            "id": "doc_type",
            "name:cs": "Typ dokladu",
            "type": "enumString", "length": 20,
            "cfgItem": "docs.core.docTypes",
            "nullable": false,
            "group": "identity"
        },
        {
            "id": "name",
            "name:cs": "Název řady",
            "type": "varchar", "length": 100,
            "nullable": false,
            "group": "identity"
        },
        {
            "id": "notice",
            "name:cs": "Poznámka",
            "type": "varchar", "length": 250,
            "nullable": true,
            "group": "identity"
        },

        // numbering
        {
            "id": "doc_number_code",
            "name:cs": "Kód řady (%C)",
            "type": "varchar", "length": 10,
            "nullable": true,
            "group": "numbering"
        },
        {
            "id": "doc_number_pattern",
            "name:cs": "Vzorec čísla dokladu",
            "type": "varchar", "length": 50,
            "nullable": false,
            "group": "numbering"
        },
        {
            "id": "reset_scope",
            "name:cs": "Restart počítadla",
            "type": "enumString", "length": 15,
            "cfgItem": "docs.core.resetScopes",
            "default": "fiscal_year",
            "group": "numbering"
        },

        // validity
        {"id": "valid_from", "name:cs": "Platnost od", "type": "date", "nullable": true, "group": "validity"},
        {"id": "valid_to",   "name:cs": "Platnost do", "type": "date", "nullable": true, "group": "validity"},

        // system
        {"id": "docState",     "type": "tinyint", "default": 10, "system": true},
        {"id": "docStateMain", "type": "tinyint", "default": 1,  "system": true}
    ],

    "indexes": [
        {"id": "idx_doc_type", "type": "index",   "columns": [{"column": "doc_type"}]},
        {"id": "idx_doc_state", "type": "index",
         "columns": [{"column": "docStateMain"}, {"column": "name"}]}
    ]
}
```

cfgItem `docs.core.resetScopes`:

```jsonc
{
    "none":        { "name:cs": "Bez restartu (průběžné)" },
    "fiscal_year": { "name:cs": "Restart každý fiskální rok" }
}
```

### 5.2 Tabulka `docs_core_number_counters`

Atomický counter. Není doc-state model — je to čistě technický záznam.

```jsonc
{
    "tableId": 405,
    "name": "Document number counters",
    "name:cs": "Počítadla čísel dokladů",

    "columns": [
        {"id": "id", "type": "int", "autoIncrement": true, "primaryKey": true},
        {
            "id": "number_series",
            "type": "int",
            "nullable": false,
            "reference": "docs_core_number_series"
        },
        {
            "id": "fiscal_year",
            "type": "int",
            "nullable": true,
            "reference": "economy_codebooks_fiscal_years"
        },
        {
            "id": "last_assigned",
            "type": "int",
            "nullable": false,
            "default": 0
        }
    ],

    "indexes": [
        {
            "id": "unq_series_year",
            "type": "unique",
            "columns": [
                {"column": "number_series"},
                {"column": "fiscal_year"}
            ]
        }
    ]
}
```

UNIQUE klíč zajišťuje právě jeden záznam per (řada, fiskální rok). Pro
řady s `reset_scope = 'none'` je `fiscal_year` vždy NULL — UNIQUE klíč se
v MariaDB chová tak, že NULL hodnoty se s NULL nekonfliktují, takže
takových záznamů by mohlo být víc. Aplikační logika (ten samý FOR UPDATE
SELECT) zajistí jediný záznam přes `WHERE fiscal_year IS NULL`. **Pojistka**
je tedy primárně na úrovni transakce, ne UNIQUE.

### 5.3 Algoritmus přidělení čísla

Volá se v `Document::beforeSave` při přechodu Koncept (10) → Potvrzeno (20).

```php
public function assignDocumentNumber(array &$data): void
{
    $seriesId    = (int) $data['number_series'];
    $accDate     = $data['accounting_date'];

    // 1. Načti řadu a zjisti reset_scope
    $series = $db->fetchRow(
        "SELECT * FROM docs_core_number_series WHERE id = ?", $seriesId
    );

    // 2. Zjisti fiscal_year_id (NULL pro reset_scope='none')
    $fyId = ($series['reset_scope'] === 'fiscal_year')
        ? $this->resolveFiscalYearId($accDate)
        : null;

    $db->begin();
    try {
        // 3. Zajisti existenci counteru (idempotentní)
        $db->query(
            "INSERT IGNORE INTO docs_core_number_counters
             (number_series, fiscal_year, last_assigned)
             VALUES (?, ?, 0)",
            $seriesId, $fyId
        );

        // 4. Lock + read counter
        $current = $db->fetchSingle(
            "SELECT last_assigned FROM docs_core_number_counters
             WHERE number_series = ? AND fiscal_year <=> ?
             FOR UPDATE",
            $seriesId, $fyId
        );
        $newSeq = $current + 1;

        // 5. Increment
        $db->query(
            "UPDATE docs_core_number_counters
             SET last_assigned = ?
             WHERE number_series = ? AND fiscal_year <=> ?",
            $newSeq, $seriesId, $fyId
        );

        // 6. Doplň do data
        $data['sequence_number'] = $newSeq;
        $data['fiscal_year']     = $fyId;  // může být null pro reset_scope=none
        $data['doc_number']      = $this->resolvePattern(
            $series['doc_number_pattern'], $data, $series
        );

        $db->commit();
    } catch (\Throwable $e) {
        $db->rollback();
        throw $e;
    }
}
```

Pojistka: na `docs_core_heads` je **UNIQUE constraint** na trojici
`(number_series, fiscal_year, sequence_number)`. I kdyby logika selhala,
INSERT/UPDATE nikdy nezpůsobí duplicitu — místo toho se transakce zhroutí
na duplicate key error.

### 5.4 Vzorec a placeholders

Vzorec je string s `%X` placeholdery. Resolver:

```php
public function resolvePattern(string $pattern, array $data, array $series): string
{
    return preg_replace_callback('/%(D|C|y|Y|3|4|5|6)/', function ($m) use ($data, $series) {
        return match ($m[1]) {
            'D' => $this->getDocIdCode($data['doc_type']),         // z cfgItem docTypes
            'C' => $series['doc_number_code'] ?? '',
            'y' => substr($this->getFiscalYearLabel($data), -2),   // posledních 2
            'Y' => $this->getFiscalYearLabel($data),
            '3' => str_pad((string) $data['sequence_number'], 3, '0', STR_PAD_LEFT),
            '4' => str_pad((string) $data['sequence_number'], 4, '0', STR_PAD_LEFT),
            '5' => str_pad((string) $data['sequence_number'], 5, '0', STR_PAD_LEFT),
            '6' => str_pad((string) $data['sequence_number'], 6, '0', STR_PAD_LEFT),
        };
    }, $pattern);
}
```

| Placeholder | Zdroj | Příklad |
|---|---|---|
| `%D` | `doc_id_code` z cfgItem `docs.core.docTypes` | `1` (FVB), `2` (FPB) |
| `%C` | `doc_number_code` z `number_series` | `A`, `EUR`, `tuzem` |
| `%y` | Rok (2 místa) z `fiscal_years.doc_number_prefix` | `26` |
| `%Y` | Rok (4 místa) | `2026` |
| `%3..%6` | `sequence_number` doplněný nulami | `0001`, `00001` |

**Mimo MVP** (neznámý placeholder = výjimka, gracefulně přidáme později):

- `%W` — kód skladu
- `%B` — kód pokladny

#### Příklady vzorců

| Vzorec | Příklad výstupu | Kontext |
|---|---|---|
| `%D%y%C%4` | `126A0001` | FVB, rok 2026, řada A, 1. doklad |
| `%D%y%C%4` | `226B0042` | FPB, rok 2026, řada B, 42. doklad |
| `FV-%Y-%C-%5` | `FV-2026-A-00001` | Vlastní formát s pomlčkami |
| `%D%5` | `100001` | Bez roku, prostě číslo |

### 5.5 Životní cyklus čísla dokladu

```
Vytvoření Konceptu (id = 123):
  doc_number      = '!0000000123'
  sequence_number = NULL
  fiscal_year     = NULL  (resolvuje se až při Potvrzení)

Přechod Koncept → Potvrzeno:
  doc_number      = '126A0001'  (resolved)
  sequence_number = 1
  fiscal_year     = <id roku 2026>

Potvrzeno → V pořádku:
  beze změny

V pořádku → V opravě → V pořádku:
  beze změny

40 → 30 (Storno):
  beze změny — Storno si drží své původní číslo

20 → 10 (Potvrzeno → Koncept), JEN POKUD JE POSLEDNÍ V ŘADĚ:
  doc_number      = '!0000000123'  (zpět)
  sequence_number = NULL
  fiscal_year     = NULL
  + counter dekrementován o 1
```

### 5.6 Provisioner

Při každém `bin/shpd-ds ds-upgrade` projde cfgItem `docs.core.docTypes`
a pro každý typ kontroluje existenci alespoň jedné řady (v jakémkoli stavu
kromě `Smazáno`):

```php
foreach ($docTypes as $key => $docType) {
    $exists = $db->fetchSingle(
        "SELECT id FROM docs_core_number_series 
         WHERE doc_type = ? AND docState != 90
         LIMIT 1",
        $key
    );
    if (!$exists) {
        $db->insert('docs_core_number_series', [
            'doc_type' => $key,
            'name' => $docType['name:cs'] ?? $docType['name'],
            'doc_number_code' => null,
            'doc_number_pattern' => $docType['doc_number_pattern_default'],
            'reset_scope' => 'fiscal_year',
            'docState' => 40,
            'docStateMain' => 3
        ]);
    }
}
```

Idempotence: lookup před insertem. Uživatel si může výchozí řadu zarchivovat
(40 → 70) a založit vlastní; provisioner pak nezasáhne.

---

## 6. Hlavička `docs_core_heads`

### 6.1 Přehled — skupiny sloupců

| Skupina | Účel | Sloupce |
|---|---|---|
| `identity` | Identifikace dokladu | `doc_type`, `number_series`, `sequence_number`, `doc_number`, `doc_text` |
| `partner` | Partner a jeho údaje | `partner`, `partner_address`, `partner_bank` + 3 string sloupce |
| `dates` | Datumy | `issue_date`, `due_date`, `accounting_date`, `vat_duzp`, `vat_dppd`, `period_from`, `period_to` |
| `accounting` | Účetní mapování (system) | `fiscal_year`, `fiscal_month`, `vat_registration`, `vat_period` |
| `vat` | DPH chování | `vat_mode`, `vat_calc_source`, `vat_place` |
| `currency` | Měna a kurz | `doc_currency`, `home_currency`, `exchange_rate` |
| `rounding` | Zaokrouhlení | `total_rounding_mode`, `vat_rounding_mode` |
| `totals` | Součtové částky (system) | `total_base`, `total_vat`, `total_amount`, `total_rounding`, `*_dom` |
| `payment` | Platba a symboly | `payment_method`, `bank_account`, `payment_reference`, `specific_symbol`, `constant_symbol` |
| `snapshots` | JSON snapshoty (system) | `supplier_snapshot`, `customer_snapshot` |
| `notes` | Poznámky | `notice`, `doc_notice` |
| (system) | Stavy | `docState`, `docStateMain` |

### 6.2 Kompletní JSONC

```jsonc
{
    "tableId": 401,
    "name": "Document headers",
    "name:cs": "Hlavičky dokladů",
    "name:en": "Document headers",

    "displayPattern": "{doc_number} — {doc_text}",

    "docStates": {
        "stateColumn": "docState",
        "mainColumn":  "docStateMain",
        "cfgItem":     "docs.core.docStates"
    },

    "childTables": [
        { "table": "docs_core_rows",      "foreignKey": "doc_head", "dataKey": "rows" },
        { "table": "docs_core_vat_recap", "foreignKey": "doc_head", "dataKey": "vatRecap" }
    ],

    "columnGroups": [
        {"id": "identity",   "name:cs": "Identifikace"},
        {"id": "partner",    "name:cs": "Partner"},
        {"id": "dates",      "name:cs": "Datumy"},
        {"id": "accounting", "name:cs": "Účetní zařazení"},
        {"id": "vat",        "name:cs": "DPH"},
        {"id": "currency",   "name:cs": "Měna"},
        {"id": "rounding",   "name:cs": "Zaokrouhlení"},
        {"id": "totals",     "name:cs": "Součty"},
        {"id": "payment",    "name:cs": "Platba"},
        {"id": "snapshots",  "name:cs": "Snapshoty"},
        {"id": "notes",      "name:cs": "Poznámky"}
    ],

    "columns": [
        {"id": "id", "type": "int", "autoIncrement": true, "primaryKey": true},

        // -- identity --
        {"id": "doc_type", "type": "enumString", "length": 20,
         "cfgItem": "docs.core.docTypes", "nullable": false,
         "system": true, "group": "identity",
         "name:cs": "Typ dokladu"},
        {"id": "number_series", "type": "int", "nullable": false,
         "reference": "docs_core_number_series",
         "group": "identity", "name:cs": "Číselná řada"},
        {"id": "sequence_number", "type": "int", "nullable": true,
         "system": true, "group": "identity",
         "name:cs": "Pořadové číslo"},
        {"id": "doc_number", "type": "varchar", "length": 40, "nullable": false,
         "system": true, "group": "identity",
         "name:cs": "Číslo dokladu"},
        {"id": "doc_text", "type": "varchar", "length": 200, "nullable": true,
         "group": "identity", "name:cs": "Text dokladu"},

        // -- partner --
        {"id": "partner", "type": "int", "nullable": true,
         "reference": "base_persons_persons",
         "group": "partner", "name:cs": "Partner"},
        {"id": "partner_address", "type": "int", "nullable": true,
         "reference": "base_persons_addresses",
         "group": "partner", "name:cs": "Adresa partnera"},
        {"id": "partner_bank", "type": "int", "nullable": true,
         "reference": "base_persons_bank_accounts",
         "group": "partner", "name:cs": "Bankovní účet partnera"},
        {"id": "partner_bank_account", "type": "varchar", "length": 50, "nullable": true,
         "group": "partner", "name:cs": "Číslo účtu (text)"},
        {"id": "partner_bank_iban", "type": "varchar", "length": 34, "nullable": true,
         "group": "partner", "name:cs": "IBAN"},
        {"id": "partner_bank_bic", "type": "varchar", "length": 11, "nullable": true,
         "group": "partner", "name:cs": "BIC/SWIFT"},

        // -- dates --
        {"id": "issue_date", "type": "date", "nullable": false,
         "group": "dates", "name:cs": "Datum vystavení"},
        {"id": "due_date", "type": "date", "nullable": true,
         "group": "dates", "name:cs": "Datum splatnosti"},
        {"id": "accounting_date", "type": "date", "nullable": false,
         "group": "dates", "name:cs": "Účetní datum"},
        {"id": "vat_duzp", "type": "date", "nullable": true,
         "group": "dates", "name:cs": "Datum uskutečnění zdan. plnění (DUZP)"},
        {"id": "vat_dppd", "type": "date", "nullable": true,
         "group": "dates", "name:cs": "Datum povinnosti přiznat daň"},
        {"id": "period_from", "type": "date", "nullable": true,
         "group": "dates", "name:cs": "Začátek období"},
        {"id": "period_to", "type": "date", "nullable": true,
         "group": "dates", "name:cs": "Konec období"},

        // -- accounting --
        {"id": "fiscal_year", "type": "int", "nullable": true,
         "reference": "economy_codebooks_fiscal_years",
         "system": true, "group": "accounting", "name:cs": "Fiskální rok"},
        {"id": "fiscal_month", "type": "int", "nullable": true,
         "reference": "economy_codebooks_fiscal_months",
         "system": true, "group": "accounting", "name:cs": "Fiskální měsíc"},
        {"id": "vat_registration", "type": "int", "nullable": true,
         "reference": "economy_codebooks_vat_registrations",
         "group": "accounting", "name:cs": "Registrace DPH"},
        {"id": "vat_period", "type": "int", "nullable": true,
         "reference": "economy_codebooks_vat_periods",
         "system": true, "group": "accounting", "name:cs": "Období DPH"},

        // -- vat --
        {"id": "vat_mode", "type": "enumInt", "default": 1,
         "cfgItem": "docs.core.vatModes",
         "group": "vat", "name:cs": "Režim DPH"},
        {"id": "vat_calc_source", "type": "enumInt", "default": 0,
         "cfgItem": "docs.core.vatCalcSources",
         "group": "vat", "name:cs": "DPH počítat z"},
        {"id": "vat_place", "type": "enumInt", "default": 0,
         "cfgItem": "docs.core.vatPlaces",
         "group": "vat", "name:cs": "Místo plnění"},

        // -- currency --
        {"id": "doc_currency", "type": "enumString", "length": 3, "default": "czk",
         "cfgItem": "world.base.currencies",
         "group": "currency", "name:cs": "Měna dokladu"},
        {"id": "home_currency", "type": "enumString", "length": 3, "default": "czk",
         "cfgItem": "world.base.currencies",
         "system": true, "group": "currency", "name:cs": "Domácí měna"},
        {"id": "exchange_rate", "type": "numeric", "precision": 15, "scale": 6,
         "nullable": true, "group": "currency", "name:cs": "Kurz"},

        // -- rounding --
        {"id": "total_rounding_mode", "type": "enumInt", "default": 0,
         "cfgItem": "docs.core.roundingModes",
         "group": "rounding", "name:cs": "Zaokrouhlení částky"},
        {"id": "vat_rounding_mode", "type": "enumInt", "default": 0,
         "cfgItem": "docs.core.roundingModes",
         "group": "rounding", "name:cs": "Zaokrouhlení DPH"},

        // -- totals (vše system, plněné v beforeSave) --
        {"id": "total_base",       "type": "numeric", "precision": 15, "scale": 2, "default": 0, "system": true, "group": "totals"},
        {"id": "total_vat",        "type": "numeric", "precision": 15, "scale": 2, "default": 0, "system": true, "group": "totals"},
        {"id": "total_amount",     "type": "numeric", "precision": 15, "scale": 2, "default": 0, "system": true, "group": "totals"},
        {"id": "total_rounding",   "type": "numeric", "precision": 15, "scale": 2, "default": 0, "system": true, "group": "totals"},
        {"id": "total_base_dom",   "type": "numeric", "precision": 15, "scale": 2, "default": 0, "system": true, "group": "totals"},
        {"id": "total_vat_dom",    "type": "numeric", "precision": 15, "scale": 2, "default": 0, "system": true, "group": "totals"},
        {"id": "total_amount_dom", "type": "numeric", "precision": 15, "scale": 2, "default": 0, "system": true, "group": "totals"},

        // -- payment --
        {"id": "payment_method", "type": "enumInt", "default": 1,
         "cfgItem": "docs.core.paymentMethods",
         "group": "payment", "name:cs": "Způsob platby"},
        {"id": "bank_account", "type": "int", "nullable": true,
         "reference": "economy_codebooks_bank_accounts",
         "group": "payment", "name:cs": "Náš bankovní účet"},
        {"id": "payment_reference", "type": "varchar", "length": 35, "nullable": true,
         "group": "payment", "name:cs": "Variabilní symbol"},
        {"id": "specific_symbol", "type": "varchar", "length": 20, "nullable": true,
         "group": "payment", "name:cs": "Specifický symbol"},
        {"id": "constant_symbol", "type": "varchar", "length": 10, "nullable": true,
         "group": "payment", "name:cs": "Konstantní symbol"},

        // -- snapshots --
        {"id": "supplier_snapshot", "type": "json", "nullable": true,
         "system": true, "group": "snapshots", "name:cs": "Snapshot dodavatele"},
        {"id": "customer_snapshot", "type": "json", "nullable": true,
         "system": true, "group": "snapshots", "name:cs": "Snapshot odběratele"},

        // -- notes --
        {"id": "notice", "type": "text", "nullable": true,
         "group": "notes", "name:cs": "Interní poznámka"},
        {"id": "doc_notice", "type": "text", "nullable": true,
         "group": "notes", "name:cs": "Poznámka na doklad"},

        // -- system --
        {"id": "docState",     "type": "tinyint", "default": 10, "system": true},
        {"id": "docStateMain", "type": "tinyint", "default": 1,  "system": true}
    ],

    "indexes": [
        // primární přístupová cesta — viewer per řada
        {"id": "idx_series_seq", "type": "index",
         "columns": [
             {"column": "number_series"},
             {"column": "fiscal_year"},
             {"column": "sequence_number", "order": "DESC"}
         ]},

        // pojistka proti duplicitám
        {"id": "unq_series_seq", "type": "unique",
         "columns": [
             {"column": "number_series"},
             {"column": "fiscal_year"},
             {"column": "sequence_number"}
         ]},

        // doc state
        {"id": "idx_doc_state", "type": "index",
         "columns": [
             {"column": "docStateMain"},
             {"column": "doc_number", "order": "DESC"}
         ]},

        // partner
        {"id": "idx_partner", "type": "index",
         "columns": [{"column": "partner"}]},

        // datumy pro reporty
        {"id": "idx_accounting_date", "type": "index",
         "columns": [{"column": "accounting_date"}]},
        {"id": "idx_vat_duzp", "type": "index",
         "columns": [{"column": "vat_duzp"}]},

        // fulltext na doc_text
        {"id": "ft_doc_text", "type": "fulltext",
         "columns": [{"column": "doc_text"}]}
    ]
}
```

#### Poznámka k UNIQUE indexu

`unq_series_seq` má NULL hodnoty pro `sequence_number` (Koncepty) i pro
`fiscal_year` (řady s reset_scope=none v Konceptech). MariaDB počítá NULL
jako neporušující UNIQUE — tj. víc Konceptů koexistuje bez kolize. Při
přechodu na Potvrzeno se obě hodnoty naplní, a UNIQUE se aktivuje (je-li
v sekvenci čisto, INSERT/UPDATE projde).

### 6.3 Default values logic

Defaults se naplňují **na klientovi** (ze `formData` nebo cfgItem) nebo **na
serveru v `Document::beforeSave`** podle povahy:

| Sloupec | Default |
|---|---|
| `issue_date` | dnes (klient) |
| `accounting_date` | = `issue_date` (klient i recalculate) |
| `vat_duzp` | = `issue_date` (klient i recalculate) |
| `vat_dppd` | = `vat_duzp` (klient i recalculate) |
| `due_date` | = `issue_date` + (payment_term_days z partnera, fallback 14) — recalculate při změně partnera |
| `doc_currency` | `czk` (z DS konfigurace) |
| `home_currency` | z DS konfigurace, system, nepřepínatelný uživatelem |
| `exchange_rate` | NULL pokud `doc_currency == home_currency`, jinak povinný |
| `vat_mode` | 1 (ze základu) |
| `vat_calc_source` | 0 (z hlavičky) |
| `vat_place` | 0 (tuzemsko) |
| `partner_address` | sídlo (`address_type=1`) partnera, fallback první adresa — recalculate při změně partnera |
| `bank_account` | default účet pro `doc_currency` z `economy_codebooks_bank_accounts` |
| `vat_registration` | jediná aktivní registrace, pokud je jen jedna |
| `payment_method` | 1 (převodem) |
| `total_rounding_mode` | 1 (matematicky na 1 — celé Kč) |
| `vat_rounding_mode` | 2 (matematicky na 0,01) |
| `fiscal_year`, `fiscal_month` | resolvované z `accounting_date` v `beforeSave` |
| `vat_period` | resolvované z `vat_duzp` + `vat_registration` v `beforeSave` |
| `payment_reference` | = `sequence_number` po Potvrzeno (jen pokud uživatel nezadal jinak) |

### 6.4 Snapshot logika

Snapshoty jsou **JSON sloupce** plněné v `Document::beforeSave` při přechodu
Koncept → Potvrzeno a aktualizované při změně partnera v Potvrzeno / V opravě.

Pseudokód:

```php
public function maintainSnapshots(array &$data, ?array $originalData): void
{
    $stateNow      = (int) ($data['docState'] ?? 10);
    $stateOriginal = (int) ($originalData['docState'] ?? 10);

    // Snapshoty se plní/aktualizují jen v editovatelných stavech 20 / 80
    if (!in_array($stateNow, [20, 80])) {
        return;
    }

    // Buď je snapshot prázdný (první přechod do 20), nebo se změnil partner
    $partnerChanged = ($data['partner'] ?? null) !== ($originalData['partner'] ?? null);
    $snapshotEmpty  = empty($data['supplier_snapshot']) || empty($data['customer_snapshot']);

    if ($snapshotEmpty || $partnerChanged) {
        $this->buildSnapshots($data);
    }
}

protected function buildSnapshots(array &$data): void
{
    $docType = $this->cfgItem("docs.core.docTypes")[$data['doc_type']];
    $tradeDir = $docType['trade_dir'];  // 1=výstup (my=dodavatel), 2=vstup (my=odběratel)

    $partnerSnap = $this->buildPersonSnapshot(
        personId:  $data['partner'],
        addressId: $data['partner_address']
    );

    $ownPersonId = $this->getOwnPersonId();  // SELECT id FROM base_persons_persons WHERE is_own=1
    if (!$ownPersonId) {
        throw new \LogicException('No own person configured (base_persons_persons.is_own=1 missing)');
    }

    $ownSnap = $this->buildPersonSnapshot(
        personId:  $ownPersonId,
        addressId: $this->resolveOwnHeadquarters($ownPersonId),  // address_type=1
        vatRegistrationId: $data['vat_registration'],
        bankAccountId:     $data['bank_account']
    );

    if ($tradeDir === 1) {
        // výstup — vydaná faktura — my = dodavatel
        $data['supplier_snapshot'] = $ownSnap;
        $data['customer_snapshot'] = $partnerSnap;
    } else {
        // vstup — přijatá faktura — my = odběratel
        $data['supplier_snapshot'] = $partnerSnap;
        $data['customer_snapshot'] = $ownSnap;
    }
}
```

#### Struktura JSON snapshotu

```json
{
    "name": "Beta Gastro s.r.o.",
    "company_id": "12345678",
    "tax_id": "CZ12345678",
    "vat_id": "CZ12345678",
    "court_registration": "Městský soud v Praze, oddíl C, vložka 12345",
    "address": {
        "street": "Hlavní 123",
        "house_number": "12",
        "city": "Praha",
        "city_part": "Vinohrady",
        "zip": "11000",
        "country": "cz",
        "display_block": "Beta Gastro s.r.o.\nHlavní 123\n110 00 Praha 2 - Vinohrady\nČesko"
    },
    "contact": {
        "email": "info@beta-gastro.cz",
        "phone": "+420 123 456 789"
    },
    "bank_account": {           // jen u dodavatele na vydané faktuře (= náš účet)
        "name": "ČSOB hlavní",
        "account_number": "123456789/0300",
        "iban": "CZ65 0300 0000 0001 2345 6789",
        "bic": "CEKOCZPP",
        "currency": "czk"
    },
    "vat_registration": {       // jen u dodavatele na vydané faktuře (= naše DPH)
        "country": "cz",
        "vat_id": "CZ12345678"
    }
}
```

Snapshot je **nezávislý dump** dat — kdykoli partner změní adresu, doklady
zůstávají s původními údaji. Při explicitním volání „obnovit snapshot"
(přechod do V opravě, změna partnera) se znovu sestaví.

### 6.5 Validace na úrovni `Document`

V `Document::validate` se per stav kontrolují různé požadavky:

| Stav | Požadavky |
|---|---|
| 10 (Koncept) | Stačí `doc_type`, `number_series`, `issue_date`, `accounting_date` |
| 20 (Potvrzeno) | Plus: `partner`, `vat_registration` (pokud `vat_mode != 0`), aspoň 1 řádek, `exchange_rate` (pokud cizí měna), validní snapshoty po sestavení |
| 40 (V pořádku) | Stejně jako Potvrzeno |
| 80 (V opravě) | Stejně jako Potvrzeno |
| 30 (Storno) | Beze změny — co bylo OK ve 40, je OK ve 30 |

---

## 7. Řádky `docs_core_rows`

### 7.1 Sloupce

```jsonc
{
    "tableId": 402,
    "name": "Document rows",
    "name:cs": "Řádky dokladů",
    "name:en": "Document rows",

    "columns": [
        {"id": "id", "type": "int", "autoIncrement": true, "primaryKey": true},

        {"id": "doc_head", "type": "int", "nullable": false,
         "reference": "docs_core_heads",
         "name:cs": "Doklad"},

        {"id": "row_kind", "type": "enumInt", "default": 1,
         "cfgItem": "docs.core.rowKinds",
         "name:cs": "Typ řádku"},

        {"id": "sort_order", "type": "smallint", "default": 0,
         "name:cs": "Pořadí"},

        // identification
        {"id": "item", "type": "int", "nullable": true,
         "reference": "economy_items",
         "name:cs": "Položka"},
        {"id": "description", "type": "varchar", "length": 500, "nullable": true,
         "name:cs": "Popis"},

        // quantity & price
        {"id": "unit", "type": "int", "nullable": true,
         "reference": "core_units",
         "name:cs": "Jednotka"},
        {"id": "quantity", "type": "numeric", "precision": 15, "scale": 4, "nullable": true,
         "name:cs": "Množství"},
        {"id": "unit_price", "type": "numeric", "precision": 15, "scale": 4, "nullable": true,
         "name:cs": "Cena/jednotka"},
        {"id": "total_price", "type": "numeric", "precision": 15, "scale": 2, "nullable": true,
         "name:cs": "Cena celkem"},
        {"id": "price_calc_mode", "type": "enumInt", "default": 0,
         "cfgItem": "docs.core.priceCalcModes",
         "name:cs": "Způsob výpočtu"},

        // discount
        {"id": "discount_pct", "type": "numeric", "precision": 5, "scale": 2, "nullable": true,
         "name:cs": "Sleva (%)"},
        {"id": "discount_amount", "type": "numeric", "precision": 15, "scale": 2, "nullable": true,
         "name:cs": "Sleva (částka)"},

        // VAT
        {"id": "vat_code", "type": "enumString", "length": 20, "nullable": true,
         "name:cs": "Kód DPH"},
        {"id": "vat_pct", "type": "numeric", "precision": 5, "scale": 2, "nullable": true,
         "name:cs": "DPH %"},

        // calculated (system)
        {"id": "vat_base",   "type": "numeric", "precision": 15, "scale": 2, "nullable": true, "system": true},
        {"id": "vat_amount", "type": "numeric", "precision": 15, "scale": 2, "nullable": true, "system": true},
        {"id": "vat_total",  "type": "numeric", "precision": 15, "scale": 2, "nullable": true, "system": true}
    ],

    "indexes": [
        {"id": "idx_doc_head", "type": "index",
         "columns": [{"column": "doc_head"}, {"column": "sort_order"}]},
        {"id": "idx_item", "type": "index",
         "columns": [{"column": "item"}]},
        {"id": "idx_vat_code", "type": "index",
         "columns": [{"column": "vat_code"}]}
    ]
}
```

`vat_code` je `enumString` **bez fixního cfgItem** v JSONC definici — cfgItem
se odvozuje za běhu podle státu z `vat_registration` na hlavičce. Frontend
v `recalculate` doplní nabídku do `select` elementu řádku.

### 7.2 row_kind

cfgItem `docs.core.rowKinds`:

```jsonc
{
    "0": { "name:cs": "Textový řádek (jen popis)" },
    "1": { "name:cs": "Běžný řádek (s množstvím a cenou)" }
}
```

- Textový řádek (0) — jen `description`, ostatní sloupce ignorovány. Slouží
  pro mezititulky, komentáře v doklad. Nepřispívá do součtů.
- Běžný řádek (1) — kompletní data.

### 7.3 Výpočet ceny

cfgItem `docs.core.priceCalcModes`:

```jsonc
{
    "0": { "name:cs": "Z ceny za jednotku" },
    "1": { "name:cs": "Z ceny celkem" }
}
```

V `Document::beforeSave` (volá se per řádek):

```php
public function calculateRowPrice(array &$row): void
{
    if ($row['row_kind'] !== 1) return;

    $qty = (float) ($row['quantity'] ?? 0);

    if ($row['price_calc_mode'] === 0) {
        // total_price = quantity * unit_price
        $unitPrice = (float) ($row['unit_price'] ?? 0);
        $row['total_price'] = round($qty * $unitPrice, 2);
    } else {
        // unit_price = total_price / quantity
        $totalPrice = (float) ($row['total_price'] ?? 0);
        $row['unit_price'] = $qty > 0 ? round($totalPrice / $qty, 4) : 0;
    }

    // Apply discount
    if (!empty($row['discount_pct'])) {
        $discount = round($row['total_price'] * ((float) $row['discount_pct']) / 100.0, 2);
        $row['total_price'] -= $discount;
    } elseif (!empty($row['discount_amount'])) {
        $row['total_price'] -= (float) $row['discount_amount'];
    }
}
```

Slevy: uživatel zadá buď `discount_pct` nebo `discount_amount` (ne obě). V UI
to může být jeden vstup s přepínačem "% / Kč", v datech jsou dva sloupce.

### 7.4 Výpočet DPH na řádku

Závisí na `vat_mode` na hlavičce:

```php
public function calculateRowVat(array &$row, string $vatMode): void
{
    if ($row['row_kind'] !== 1 || empty($row['vat_code']) || empty($row['vat_pct'])) {
        $row['vat_base'] = $row['total_price'] ?? 0;
        $row['vat_amount'] = 0;
        $row['vat_total'] = $row['total_price'] ?? 0;
        return;
    }

    $totalPrice = (float) $row['total_price'];
    $pct = (float) $row['vat_pct'];

    if ($vatMode == 0) {
        // bez DPH
        $row['vat_base'] = $totalPrice;
        $row['vat_amount'] = 0;
        $row['vat_total'] = $totalPrice;
    } elseif ($vatMode == 1) {
        // ceny v řádcích jsou bez DPH
        $row['vat_base'] = $totalPrice;
        $row['vat_amount'] = round($totalPrice * $pct / 100.0, 2);
        $row['vat_total'] = round($row['vat_base'] + $row['vat_amount'], 2);
    } elseif ($vatMode == 2) {
        // ceny v řádcích jsou s DPH
        $row['vat_total'] = $totalPrice;
        $row['vat_base'] = round($totalPrice / (1 + $pct / 100.0), 2);
        $row['vat_amount'] = round($row['vat_total'] - $row['vat_base'], 2);
    }
}
```

Toto se týká řádkového počítání DPH (`vat_calc_source = 1` z hlavičky).
V případě `vat_calc_source = 0` (z hlavičky) jsou hodnoty `vat_base`,
`vat_amount`, `vat_total` na řádku ne plně autoritativní — autoritativní
je rekapitulace, která se počítá ze součtu základů.

---

## 8. Rekapitulace `docs_core_vat_recap`

### 8.1 Sloupce

```jsonc
{
    "tableId": 403,
    "name": "VAT recapitulation",
    "name:cs": "Rekapitulace DPH",

    "columns": [
        {"id": "id", "type": "int", "autoIncrement": true, "primaryKey": true},

        {"id": "doc_head", "type": "int", "nullable": false,
         "reference": "docs_core_heads"},

        {"id": "vat_code", "type": "enumString", "length": 20, "nullable": false,
         "name:cs": "Kód DPH"},
        {"id": "vat_pct", "type": "numeric", "precision": 5, "scale": 2,
         "nullable": false, "name:cs": "DPH %"},

        // amounts in document currency
        {"id": "base",   "type": "numeric", "precision": 15, "scale": 2, "default": 0},
        {"id": "tax",    "type": "numeric", "precision": 15, "scale": 2, "default": 0},
        {"id": "total",  "type": "numeric", "precision": 15, "scale": 2, "default": 0},

        // amounts in home currency
        {"id": "base_dom",   "type": "numeric", "precision": 15, "scale": 2, "default": 0},
        {"id": "tax_dom",    "type": "numeric", "precision": 15, "scale": 2, "default": 0},
        {"id": "total_dom",  "type": "numeric", "precision": 15, "scale": 2, "default": 0},

        // flags from vatCode
        {"id": "sum_base",   "type": "boolean", "default": 1},
        {"id": "sum_tax",    "type": "boolean", "default": 1},
        {"id": "sum_total",  "type": "boolean", "default": 1},

        // marker for reverse charge pair
        {"id": "is_reverse_pair", "type": "boolean", "default": 0,
         "name:cs": "Druhá strana reverse charge páru"},

        {"id": "sort_order", "type": "smallint", "default": 0}
    ],

    "indexes": [
        {"id": "idx_doc_head", "type": "index",
         "columns": [{"column": "doc_head"}, {"column": "sort_order"}]},

        // for future VAT report (sčítání per period)
        {"id": "idx_vat_code", "type": "index",
         "columns": [{"column": "vat_code"}]}
    ]
}
```

### 8.2 Sestavení v `beforeSave`

Algoritmus:

```php
public function buildVatRecapitulation(array &$data): array
{
    $rows = $data['rows'] ?? [];
    $vatMode = (int) $data['vat_mode'];
    $exchRate = (float) ($data['exchange_rate'] ?? 1.0);
    $countryCode = $this->resolveCountryFromVatRegistration($data['vat_registration']);
    
    // 1. Group rows by (vat_code, vat_pct), sum base
    $grouped = [];
    foreach ($rows as $row) {
        if ($row['row_kind'] !== 1 || empty($row['vat_code'])) continue;
        $key = $row['vat_code'] . '|' . $row['vat_pct'];
        if (!isset($grouped[$key])) {
            $grouped[$key] = [
                'vat_code' => $row['vat_code'],
                'vat_pct'  => (float) $row['vat_pct'],
                'base'     => 0
            ];
        }
        $grouped[$key]['base'] += (float) ($row['total_price'] ?? 0);
    }

    // 2. For each group, compute tax + flags from vatCode definition
    $vatCodes = $this->cfgItem("world.vat.{$countryCode}")['vatCodes'];
    $recap = [];
    $sortOrder = 0;

    foreach ($grouped as $entry) {
        $codeDef = $vatCodes[$entry['vat_code']];

        // Compute primary line
        $base = round($entry['base'], 2);
        $tax  = empty($codeDef['noPayTax'])
            ? round($base * $entry['vat_pct'] / 100, 2)
            : 0;

        $primary = [
            'vat_code'  => $entry['vat_code'],
            'vat_pct'   => $entry['vat_pct'],
            'base'      => $base,
            'tax'       => $tax,
            'total'     => $base + $tax,
            'sum_base'  => $codeDef['sumBase']  ?? 1,
            'sum_tax'   => $codeDef['sumTax']   ?? 1,
            'sum_total' => $codeDef['sumTotal'] ?? 1,
            'is_reverse_pair' => 0,
            'sort_order' => $sortOrder++,
        ];

        // Apply exchange rate to *_dom
        $primary['base_dom']  = round($base * $exchRate, 2);
        $primary['tax_dom']   = round($tax  * $exchRate, 2);
        $primary['total_dom'] = round($primary['total'] * $exchRate, 2);

        $recap[] = $primary;

        // If reverse charge — add paired row (oddanění)
        if (!empty($codeDef['reverseVatCode'])) {
            $reverseCode = $codeDef['reverseVatCode'];
            $reverseDef  = $vatCodes[$reverseCode];

            // Resolve % from time of DUZP for the reverse code
            $reversePct = $this->resolveVatPct($countryCode, $reverseCode, $data['vat_duzp']);
            $reverseTax = round($base * $reversePct / 100, 2);

            $paired = [
                'vat_code'  => $reverseCode,
                'vat_pct'   => $reversePct,
                'base'      => $base,
                'tax'       => $reverseTax,
                'total'     => $base + $reverseTax,
                'sum_base'  => $reverseDef['sumBase']  ?? 1,
                'sum_tax'   => $reverseDef['sumTax']   ?? 1,
                'sum_total' => $reverseDef['sumTotal'] ?? 1,
                'is_reverse_pair' => 1,
                'sort_order' => $sortOrder++,
            ];
            $paired['base_dom']  = round($paired['base']  * $exchRate, 2);
            $paired['tax_dom']   = round($paired['tax']   * $exchRate, 2);
            $paired['total_dom'] = round($paired['total'] * $exchRate, 2);

            $recap[] = $paired;
        }
    }

    return $recap;
}
```

Výsledek se pak uloží do child tabulky `docs_core_vat_recap` standardním
mechanismem (sync přes `TableGateway`). Stará rekapitulace se smaže, nová
se vloží.

### 8.3 Výpočet hlavičkových součtů

Po sestavení rekapitulace se hlavičkové součty vypočtou takto:

```php
$data['total_base']   = 0;
$data['total_vat']    = 0;
$data['total_amount'] = 0;

foreach ($recap as $r) {
    if ($r['sum_base'])  $data['total_base']   += $r['base'];
    if ($r['sum_tax'])   $data['total_vat']    += $r['tax'];
    if ($r['sum_total']) $data['total_amount'] += $r['total'];
}

// Apply rounding
$data['total_amount']    = $this->applyRounding($data['total_amount'], $data['total_rounding_mode']);
$data['total_rounding'] = ...; // diff caused by rounding

// Convert to home currency
$data['total_base_dom']   = round($data['total_base']   * $exchRate, 2);
$data['total_vat_dom']    = round($data['total_vat']    * $exchRate, 2);
$data['total_amount_dom'] = round($data['total_amount'] * $exchRate, 2);
```

Flagy `sum_base`/`sum_tax`/`sum_total` zajišťují, že reverse charge páry
nepřičítají do součtů (oddanění má všechny tři flagy = 0).

---

## 9. Tok dat při přechodech stavů

Tady jsou podrobné scénáře, co se děje v `Document::beforeSave` při různých
přechodech. Hlavní orchestrátor je `IssuedInvoiceDocument::beforeSave` (resp.
`ReceivedInvoiceDocument::beforeSave`). Většinu logiky drží společná rodičovská
třída `DocDocument` v `docs.core`.

### 9.1 Vytvoření Konceptu (nový doklad)

```
1. Klient pošle: { doc_type: "invno", number_series: 5, issue_date: "2026-05-06", ... }
2. CrudController.create() volá TableGateway.saveDocument()
3. Document.validate() — minimální kontrola (povinná pole)
4. Document.beforeSave():
   a. doc_type = number_series.doc_type  (denormalizace)
   b. accounting_date defaults to issue_date
   c. vat_duzp defaults to issue_date
   d. fiscal_year = resolveFiscalYearId(accounting_date)
   e. fiscal_month = resolveFiscalMonthId(accounting_date)
   f. vat_period = resolveVatPeriodId(vat_duzp, vat_registration) (pokud DPH)
   g. doc_number = '!{id_padded}'  // zatím id ještě neznáme
   h. row.* — calculateRowPrice + calculateRowVat
   i. recap = buildVatRecapitulation(...)
   j. total_* = sumTotals(recap, rounding)
5. INSERT do docs_core_heads
6. Po INSERT známe ID — UPDATE doc_number = '!0000000123'
7. INSERT child rows + recap
8. COMMIT
```

### 9.2 Koncept (10) → Potvrzeno (20)

```
1. Klient pošle PUT s { docState: 20, ...ostatní data... }
   (Pozn: na rozdíl od standardního state-only přechodu jsou součástí 
   i editovaná data, protože uživatel mohl něco upravit těsně před potvrzením)
2. CrudController.processDocState() ověří přechod 10 → 20 (povolen)
3. Document.beforeSave():
   a. assignDocumentNumber() — atomicky, viz sekce 5.3
   b. maintainSnapshots() — sestaví supplier_snapshot + customer_snapshot
   c. payment_reference default = sequence_number, pokud user nenastavil
   d. ostatní jako v 9.1 (defaults, calculations)
4. UPDATE heads, sync rows, sync recap
5. COMMIT
```

### 9.3 Potvrzeno (20) → V pořádku (40)

```
1. Klient pošle PUT s { docState: 40 }
2. processDocState ověří přechod
3. Document.beforeSave() — žádná specifická akce; pokud uživatel
   neupravoval data, jen přepíše state
4. UPDATE heads SET docState=40, docStateMain=4
5. closeForm: 1 — UI zavře formulář a vrátí do vieweru
```

### 9.4 V pořádku (40) → V opravě (80)

```
1. Klient pošle PUT s { docState: 80 }
2. processDocState ověří přechod (40 → 80 povolen, opouštíme readOnly)
3. Document.beforeSave() — žádná akce
4. UPDATE heads
5. closeForm: 0 — formulář zůstává otevřený, již editovatelný
```

### 9.5 V opravě (80) → V pořádku (40)

```
1. Klient pošle PUT s { docState: 40, ...edited fields... }
2. processDocState ověří
3. Document.beforeSave():
   a. recalculate row.*, recap, totals — uživatel mohl změnit cokoli
   b. maintainSnapshots() — pokud změněn partner, znovu sestaví snapshot
4. UPDATE heads, sync rows, sync recap
```

### 9.6 V opravě (80) → Storno (30) / V pořádku (40) → Storno (30)

```
1. Klient pošle PUT s { docState: 30 }
2. processDocState ověří přechod
3. Document.beforeSave() — žádná akce
4. UPDATE heads SET docState=30, docStateMain=4
   (mainState = 4, stejně jako V pořádku — řadí se v seznamu vedle 
   validních dokladů podle čísla)
5. closeForm: 1
```

Storno **zachovává všechna data dokladu** včetně rekapitulace, počtů,
snapshotů. Je to jen flag „neúčinné účetně".

### 9.7 → Smazáno (90)

```
1. Klient pošle PUT s { docState: 90 }
2. processDocState ověří přechod (90 dosažitelné z 10, 20, 80, 40, 30)
3. Document.beforeSave() — žádná akce
4. UPDATE heads SET docState=90, docStateMain=5
5. closeForm: 1
```

Smazáno je „v koši". Doklad zůstává v DB se všemi daty. Z koše lze obnovit
jen do V opravě (90 → 80).

### 9.8 Potvrzeno (20) → Koncept (10) — uvolnění čísla

Speciální případ, jediný přechod kde se sahá na sequence_number.

```
1. Klient pošle PUT s { docState: 10 }
2. processDocState():
   a. Načti aktuální data, zjisti sequence_number a number_series, fiscal_year
   b. Ověř, že tento doklad je posledním v řadě:
      SELECT MAX(sequence_number) FROM docs_core_heads
      WHERE number_series = ? AND fiscal_year <=> ?
   c. Pokud ne MAX → 422 INVALID_STATE_TRANSITION s textem
      "Přechod není povolen — doklad není poslední v řadě"
3. Document.beforeSave():
   a. UPDATE docs_core_number_counters SET last_assigned = last_assigned - 1
      WHERE number_series = ? AND fiscal_year <=> ?
      AND last_assigned = current_seq  -- safety check
   b. data['sequence_number'] = NULL
   c. data['fiscal_year'] = NULL  (Koncept nemá závazné fiscal_year)
   d. data['doc_number'] = '!' . str_pad((string)$id, 10, '0', STR_PAD_LEFT)
   e. data['supplier_snapshot'] = NULL
   f. data['customer_snapshot'] = NULL
4. UPDATE heads
```

V `getAvailableTransitions` pro stav 20 se přechod 20 → 10 nabídne, **jen
když je doklad poslední v řadě** — UI tak nemate uživatele nedostupným
tlačítkem.

---

## 10. Rozšíření `base.persons`

### 10.1 Nové sloupce

Do `modules/base/persons/tables/base_persons_persons.jsonc`:

```jsonc
// Skupina identity, na konec za vat_id
{
    "id": "court_registration",
    "name": "Court registration",
    "name:cs": "Zápis v obchodním rejstříku",
    "name:en": "Court registration",
    "type": "varchar",
    "length": 250,
    "nullable": true,
    "group": "identity"
},

// Nová skupina "ownership" nebo na konec status, jak vypadá lepší
{
    "id": "is_own",
    "name": "Own company",
    "name:cs": "Vlastní firma",
    "name:en": "Own company",
    "type": "boolean",
    "default": 0,
    "group": "status"
}
```

### 10.2 Validace `is_own` unikátnost

V `modules/base/persons/src/PersonDocument.php`:

```php
public function validate(array &$data): ValidationResult
{
    $result = parent::validate($data);
    
    // ... existing validations ...

    // is_own uniqueness
    if (!empty($data['is_own'])) {
        $sql = "SELECT id FROM base_persons_persons 
                WHERE is_own = 1 AND docState != 90";
        $params = [];
        if (!empty($data['id'])) {
            $sql .= " AND id != ?";
            $params[] = $data['id'];
        }
        $existing = $this->db->fetchSingle($sql, ...$params);
        if ($existing) {
            $result->addError('is_own', 'Vlastní firma už je nastavena na jiném záznamu', 'is_own_duplicate');
        }
    }

    // is_own only for companies
    if (!empty($data['is_own']) && (int) ($data['person_type'] ?? 0) !== 2) {
        $result->addError('is_own', 'Vlastní firma musí být typu Firma (právnická osoba)', 'is_own_not_company');
    }

    return $result;
}
```

### 10.3 Setup workflow

Při instalaci nového DS:

1. `ds-create` vytvoří DS
2. `ds-upgrade` zkompiluje konfiguraci a spustí provisionery
3. **Uživatel musí ručně v UI:**
   - Otevřít Osoby viewer
   - Vytvořit záznam typu "Firma" se základními údaji (IČO, DIČ, název, adresa
     sídla, court_registration)
   - Označit `is_own = 1`
4. (Volitelné, doporučené) Vytvořit registraci DPH v Číselníky → Registrace DPH
5. (Volitelné) Vytvořit vlastní bankovní účet v Číselníky → Bankovní spojení

Bez kroku 3 nelze vystavit fakturu — `Document::validate` při Potvrzení
hlásí chybu „Není nastavena vlastní firma".

### 10.4 Helper třída

V `docs.core` vznikne pomocná třída pro práci s vlastní firmou:

```php
// modules/docs/core/src/OwnCompanyResolver.php
class OwnCompanyResolver
{
    public function getOwnPersonId(): ?int
    {
        return $this->db->fetchSingle(
            "SELECT id FROM base_persons_persons 
             WHERE is_own = 1 AND docState IN (10, 40, 80) 
             LIMIT 1"
        );
    }

    public function getOwnPersonData(): ?array
    {
        $id = $this->getOwnPersonId();
        if (!$id) return null;
        return $this->db->fetchRow(
            "SELECT * FROM base_persons_persons WHERE id = ?", $id
        );
    }
}
```

---

## 11. Implementační plán pro Claude Code

### Pořadí fází

| Fáze | Co | Závisí na | Tasks |
|---|---|---|---|
| 1 | Rozšíření `base.persons` | — | 1 task |
| 2 | Modul `world.vat` (jen CZ) | base.persons | 1 task |
| 3 | `docs.core` — tabulky, doc states, base entity | world.vat, economy.codebooks, economy.items | 2-3 tasky |
| 4 | `docs.core` — výpočty (DPH, ceny, rekapitulace, snapshoty) | Fáze 3 | 2 tasky |
| 5 | `docs.core` — Form a Viewer | Fáze 4 | 1-2 tasky |
| 6 | `docs.invoicesOut`, `docs.invoicesIn` — Document subclasses + viewers | Fáze 5 | 1 task |
| 7 | E2E testování celého toku | vše | 1 task |

### Fáze 1 — Rozšíření `base.persons`

**Cíl:** Přidat sloupce `is_own` a `court_registration` do tabulky osob,
implementovat validaci unikátnosti.

**Tasks:**
- Aktualizovat `modules/base/persons/tables/base_persons_persons.jsonc`
- Aktualizovat `modules/base/persons/tables/base_persons_persons.md`
- Aktualizovat `PersonDocument.php` — validace `is_own` unikátnost a typu
- Aktualizovat `PersonsForm.php` — přidat sloupce do UI

### Fáze 2 — Modul `world.vat` (CZ)

**Cíl:** Vytvořit modul `world.vat` s kompletní CZ konfigurací DPH.

**Tasks:**
- Vytvořit `modules/world/vat/module.jsonc`
- Vytvořit `modules/world/vat/config/vat-cz.jsonc` — kompletní migrace ze
  starého Shipardu (kódy `EUCZ*` → `cz-*`), `vatCategories`, `vatPercents`,
  `vatNotes`
- Vytvořit `modules/world/vat/README.md`
- Implementovat `world.vat.VatRateResolver` (PHP třída pro `resolveVatPct`,
  `getVatCodes`, filtrování podle direction/place atd.)
- Aktualizovat `install.base/module.jsonc` — přidat `world.vat` do dependencies

### Fáze 3 — `docs.core` tabulky + doc states + minimální entity

**Cíl:** Postavit kostru modulu — tabulky, JSON schemas, Document třída
základ, číselné řady. Bez výpočtů a UI.

**Tasks:**
- Vytvořit `modules/docs/core/module.jsonc` (s `documentClasses`, `forms`,
  `viewers` placeholdery)
- Vytvořit tabulky:
  - `docs_core_heads.jsonc`
  - `docs_core_rows.jsonc`
  - `docs_core_vat_recap.jsonc`
  - `docs_core_number_series.jsonc`
  - `docs_core_number_counters.jsonc`
- Per tabulka `.md` dokumentace (per docs/documentation.md konvence)
- Vytvořit cfgItem soubory:
  - `docs.core.docTypes` (jen `invno`, `invni`)
  - `docs.core.docStates`
  - `docs.core.vatModes`
  - `docs.core.vatCalcSources`
  - `docs.core.vatPlaces`
  - `docs.core.priceCalcModes`
  - `docs.core.rowKinds`
  - `docs.core.roundingModes`
  - `docs.core.paymentMethods`
  - `docs.core.resetScopes`
- Vytvořit `DocDocument.php` (abstract base) — minimální s validate skeleton
- Vytvořit `NumberSeriesDocument.php`, `NumberSeriesProvisioner.php`
- Vytvořit `OwnCompanyResolver.php`

**Validace:** `bin/shpd-ds ds-upgrade` projde, tabulky se vytvoří, provisioner
naplní default řady pro `invno` a `invni`.

### Fáze 4 — Výpočty

**Cíl:** Implementovat DPH model, výpočty cen, rekapitulaci, snapshoty.

**Tasks:**
- `DocDocument::calculateRowPrice` (price calc + slevy)
- `DocDocument::calculateRowVat`
- `DocDocument::buildVatRecapitulation` (vč. reverse charge páry)
- `DocDocument::sumTotals`
- `DocDocument::applyRounding`
- `DocDocument::maintainSnapshots` + `buildPersonSnapshot`
- `DocDocument::assignDocumentNumber` + `resolvePattern`
- `DocDocument::resolveFiscalYearId/MonthId` + `resolveVatPeriodId`
- Unit testy pro každý výpočet

**Validace:** Insert/update doklad přes API zachová správně rekapitulaci,
snapshoty, čísla. CRUD test pokrývá životní cyklus Koncept → Potvrzeno →
V pořádku → V opravě → Storno.

### Fáze 5 — Form a Viewer

**Cíl:** UI pro pořizování dokladu.

**Tasks:**
- `DocsHeadsForm.php` (PHP TableForm subclass) s:
  - Tab Hlavička (identity, dates, partner, vat, currency, payment)
  - Tab Řádky (subtable docs_core_rows, vlastní form pro řádek)
  - Tab Rekapitulace (read-only zobrazení vatRecap)
  - Tab Snapshoty (jen ve stavech ≥ Potvrzeno, read-only zobrazení)
  - Tab Přílohy (přes core.attachments)
  - Tab Poznámky
- `DocRowsForm.jsonc` (sub-form pro řádek) — declarative
- `DocsHeadsForm::recalculate` — change handler pro:
  - partner → partner_address default, due_date z payment_terms
  - issue_date → accounting_date / vat_duzp default
  - accounting_date → fiscal_year/month
  - vat_duzp → vat_period
  - vat_registration → filtrace vat_codes
  - exchange_rate calculation triggers
- Frontend Svelte — řešení pro dynamický `select` s VAT kódy (rozšíření
  FormElement nebo vlastní komponenta)

**Validace:** Uživatel projde celým CRUD flow přes UI. Změna partnera
způsobí recalculate (partner_address, due_date). Změna kategorie DPH na
řádku doplní procento. Saved doklad má správnou rekapitulaci.

### Fáze 6 — `docs.invoicesOut` + `docs.invoicesIn`

**Cíl:** Specifické moduly s Document subclasses a Viewers.

**Tasks:**
- `modules/docs/invoicesOut/module.jsonc`
- `IssuedInvoiceDocument extends DocDocument` — overrides specifické pro
  vydané faktury (např. validace `bank_account` povinný v Potvrzení)
- `IssuedInvoicesViewer extends TableViewer`:
  - filter `WHERE doc_type = 'invno'`
  - Spodní taby s číselnými řadami (jako v screenshotu od Davida) — ✓ viewer-number-series-tabs.md
  - Sloupce: Partner, doc_number, doc_text, datumy, totals, stav badge
- `modules/docs/invoicesIn/module.jsonc`
- `ReceivedInvoiceDocument extends DocDocument`
- `ReceivedInvoicesViewer extends TableViewer`
- Aktualizace `install.base` deps

### Fáze 7 — E2E test

- Vytvořit DS s install.base
- Vytvořit Osobu vlastní firmy + DPH registraci + bank účet
- Vytvořit Osobu odběratele + adresu + bank účet
- Vytvořit pár dokladů různých druhů (FVB tuzemsko CZK, FVB CZ EUR s reverse
  charge, FPB tuzemsko, FPB EU intracom)
- Ověřit kompletní lifecycle, snapshoty, rekapitulace

---

## 12. Otevřené body k upřesnění při implementaci

Některá rozhodnutí jsou v dokumentu uvedena jako návrh a potřebují
upřesnit při startu implementace nebo až vznikne potřeba:

1. **cfgItem `docs.core.paymentMethods`** — přesné hodnoty (návrh: 0
   hotovost, 1 převodem, 2 kartou, 3 dobírkou, 4 zápočtem)
2. **cfgItem `docs.core.roundingModes`** — přesné hodnoty (návrh: 0 bez
   zaokrouhlení, 1 matematicky na 1, 2 matematicky na 0,01)
3. **payment_term_days** — má být v `base.persons` extension z `docs.core`,
   nebo přímo v `base.persons`? (Patrně extension — je to ekonomická
   informace.)
4. **Aktualizace partnerova bank účtu** ze stringů na hlavičce — UI tlačítko
   „Uložit jako účet partnera" je nice-to-have, do MVP nepatří striktně,
   ale dává smysl ho udělat ve Fázi 5.
5. **Frontend select pro vat_code** — dynamický s API call při change
   `vat_registration` / `vat_place`, nebo natáhnout všechny kódy do
   formuláře a filtrovat na klientovi? Záleží na velikosti — ~50 kódů per
   stát je v pohodě klientsky, takže preferuji klientské filtrování s
   načítáním celého `world.vat.{country}` cfgItem do formuláře.
6. **Dark mode pro vat_recap rendering** s grayed-out hodnotami (sum_tax=0)
   — ve fázi 5 prozkoumat, jestli stačí `opacity: 0.4` nebo si zaslouží
   vlastní tokeny.

---

[← README.md](README.md) · [doc-states.md](doc-states.md) · [edit-forms.md](edit-forms.md)
