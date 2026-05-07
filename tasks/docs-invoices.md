# Task: Doklady — Fáze 6: Per-typ moduly `docs.invoicesOut` a `docs.invoicesIn`

## Kontext

Pokračujeme z **Fáze 5** (`docs-core-phase3.md` — hotovo). Doklady mají
plně funkční backend i UI: tabulky, výpočty, číselné řady, snapshoty,
formulář, viewer. Vše ale jako **jeden generický typ** — viewer "Doklady"
zobrazuje všechny typy najednou, Document třída je shared `DocsHeadsDocument`
bez per-typ specializace.

Tato fáze (poslední v MVP plánu) přidává **dva specifické moduly**:

- `docs.invoicesOut` — Faktura vydaná (`invno`)
- `docs.invoicesIn` — Faktura přijatá (`invni`)

Klíčové dodávky:

1. **Polymorfní dispatch** přes `typeColumn = 'doc_type'` v `DocumentRegistry`.
   Když framework načítá doklad s `doc_type = 'invno'`, dostane instanci
   `IssuedInvoiceDocument`; pro `invni` `ReceivedInvoiceDocument`.
2. **Per-typ viewery** v hlavní navigaci — "Faktury vydané" a "Faktury
   přijaté" jako samostatné položky s pevným filtrem `WHERE doc_type = ?`.
3. **Per-typ specifická validace** v Document subclasses — např. `bank_account`
   povinný pro vydané faktury při Potvrzení (na přijatých není).
4. **Sloučená registrace `documentClasses`** — DocumentLoader slučuje typové
   varianty z více modulů (`docs.core` definuje typeColumn + defaultClass,
   `docs.invoicesOut` přidává `invno → IssuedInvoiceDocument`,
   `docs.invoicesIn` přidává `invni → ReceivedInvoiceDocument`).

Generický `docs.core.heads` viewer **zůstává** jako "Všechny doklady" —
pro reportní přehled napříč typy.

Před implementací **přečti**:

- `docs/docs-mvp.md` — sekce 2 (modulová struktura), sekce 6.5 (validace
  per stav)
- `tasks/docs-core-phase3.md` (hotovo) — referenční stav po Fázi 5
- `modules/docs/core/src/DocsHeadsDocument.php` — vzor pro thin subclass
- `modules/docs/core/src/DocsHeadsViewer.php` — vzor pro generický viewer,
  per-typ se od něj odvíjí

Vzorové existující soubory:

- `src/Core/Document/DocumentRegistry.php` — má již podporu
  `typeColumn` dispatch (viz cíl architektonické sekce níže)
- `src/Api/DocumentLoader.php` — bude potřeba upravit pro merge
- `modules/docs/core/module.jsonc` — komentář v `documentClasses` přímo
  zmiňuje, že Fáze 6 přepne na typeColumn

## Cíl Fáze 6

Po dokončení této fáze platí:

- Existují moduly `docs.invoicesOut` a `docs.invoicesIn` (žádné nové
  tabulky, jen PHP třídy, viewer registrace)
- `DocumentRegistry::getDocument('docs_core_heads', ['doc_type' => 'invno', ...])`
  vrátí instanci `IssuedInvoiceDocument`
- `DocumentRegistry::getDocument('docs_core_heads', ['doc_type' => 'invni', ...])`
  vrátí instanci `ReceivedInvoiceDocument`
- Bez `doc_type` (nebo neznámý typ) vrátí `DocsHeadsDocument` (defaultClass)
- V hlavní navigaci jsou **3 položky** pro doklady:
  - "Faktury vydané" — viewer `docs.invoicesOut.heads`, filter
    `doc_type = 'invno'`
  - "Faktury přijaté" — viewer `docs.invoicesIn.heads`, filter
    `doc_type = 'invni'`
  - "Doklady" — generický viewer `docs.core.heads` (všechny typy)
- Kliknutím "Přidat" v "Faktury vydané" se otevře formulář, ve kterém je
  `number_series` dropdown předfiltrovaný jen na řady typu `invno`
  (analogicky `invni` pro Faktury přijaté)
- Per-typ Document subclasses dědí veškerou logiku z `DocsHeadsDocument` —
  jejich body je zatím prázdný, ale slouží jako **rozšiřovací bod** pro
  budoucí specifické validace, hooky, atd.
- `IssuedInvoiceDocument::validate` přidává: `bank_account` povinný při
  Potvrzení (FVB musí mít určený náš účet)
- `DocumentLoader` slučuje `documentClasses` registrace per `table` — když
  více modulů registruje stejnou tabulku s `typeColumn`, jejich `classes`
  mapy se merge-nou
- `bin/shpd-ds ds-upgrade` nechybí (žádné nové tabulky, ale moduly
  vstoupí do dependency grafu)
- E2E flow: vystavit fakturu vydanou přes UI, dostat polymorfně-routed
  Document subclass, ověřit že per-typ validace funguje

## Návaznost

- Závisí na: Fáze 5 (`docs-core-phase3.md` — hotovo)
- **Tímto MVP končí.** Po Fázi 6 jsou faktury vydané a přijaté v MVP
  plně funkční. Další iterace přidají: PDF výstup, přiznání DPH,
  saldokonto, další typy dokladů (cash, bank, prfmin, …), per-EU stát
  DPH, atd.

## Scope

### V rozsahu

- Modul `docs.invoicesOut`: `module.jsonc`, `README.md`,
  `IssuedInvoiceDocument.php`, `IssuedInvoicesViewer.php`
- Modul `docs.invoicesIn`: `module.jsonc`, `README.md`,
  `ReceivedInvoiceDocument.php`, `ReceivedInvoicesViewer.php`
- Úprava `modules/docs/core/module.jsonc` — přepnutí registrace
  `docs_core_heads` z `class` na `typeColumn + defaultClass`
- Úprava `src/Api/DocumentLoader.php` — sloučení registrací per-table
- Aktualizace `modules/install/base/module.jsonc` — přidání obou
  modulů do dependencies
- Frontend ikony: `file-invoice` (resp. obě varianty pokud dáváme
  vlastní pro out/in)
- PHPUnit testy: dispatch, per-typ validace, viewer filter

### Mimo rozsah

- **Spodní tab bar s číselnými řadami** (jak na screenshotu z designu)
  — vyžaduje frontend úpravy v `TableViewer` komponentě (sekundární
  tab bar nad `viewGroup` taby). Pro MVP zatím stačí pevný `doc_type`
  filtr; tab bar přijde jako samostatný úkol pokud bude reálná potřeba.
  Detail viz "Otevřené body".
- PDF výstup faktur
- Přiznání DPH, Kontrolní hlášení, Souhrnné hlášení
- Zálohové faktury (typy `prfmin`, `invpo`)
- Bankovní výpisy a pokladní doklady (typy `bank`, `cash`)

## Architektonická rozhodnutí

### Polymorfismus přes `typeColumn`

`DocumentRegistry::getDocument(string $tableId, array $data)` už má
nativní podporu pro `typeColumn`-based dispatch:

```php
if (isset($reg['typeColumn'])) {
    $typeValue = $data[$reg['typeColumn']] ?? '';
    $className = $reg['classes'][$typeValue] ?? $reg['defaultClass'] ?? null;
    return $className ? new $className() : new DefaultDocument();
}
```

Stačí, aby registrace v `module.jsonc` měla tvar:

```jsonc
{
    "table": "docs_core_heads",
    "typeColumn": "doc_type",
    "classes": {
        "invno": "Shipard\\Module\\Docs\\InvoicesOut\\IssuedInvoiceDocument",
        "invni": "Shipard\\Module\\Docs\\InvoicesIn\\ReceivedInvoiceDocument"
    },
    "defaultClass": "Shipard\\Module\\Docs\\Core\\DocsHeadsDocument"
}
```

### Sloučení registrací z více modulů

**Problém:** Aktuální `DocumentLoader::load` projde všechny moduly
a každou položku z `documentClasses` přidá do `registrations` array.
`DocumentRegistry::__construct` pak indexuje per-`table` — **poslední
zaregistrovaná pro stejnou tabulku vyhraje**. To znamená:

- Když `docs.core` zaregistruje `docs_core_heads` s defaultClass
- a `docs.invoicesOut` zaregistruje stejnou tabulku s `classes: {invno: ...}`
- a `docs.invoicesIn` registruje s `classes: {invni: ...}`

→ poslední registrace přepíše předchozí, mapa není sloučená.

**Řešení:** `DocumentLoader` (nebo `DocumentRegistry`) **slučuje
registrace per `table`**:

- `class` → poslední vyhraje (compat s existujícími moduly)
- `typeColumn` → musí být shodný, jinak chyba; bere se z první
  registrace, která ho má
- `classes` → merge všech map; při kolizi klíčů (`invno` ve dvou
  modulech) chyba s lokalizací zdroje
- `defaultClass` → bere se z první registrace, která ho má

Doporučená implementace: úprava v `DocumentLoader::load()` — místo
prostého `array_merge` udělat per-table merge logiku.

```php
public static function load(DataSourceConfig $config, string $modulesBasePath): DocumentRegistry
{
    $allModules = ModuleLoader::loadAllModules($modulesBasePath);
    $errors = [];
    $resolvedModules = ModuleResolver::resolve($allModules, $config->getModules(), $errors);

    /** @var array<string, array<string, mixed>> tableId → merged registration */
    $byTable = [];

    foreach ($resolvedModules as $module) {
        foreach ($module->documentClasses as $reg) {
            $table = $reg['table'] ?? null;
            if ($table === null) {
                continue;
            }

            if (!isset($byTable[$table])) {
                $byTable[$table] = $reg;
                continue;
            }

            $existing = $byTable[$table];

            // Validate typeColumn consistency
            if (isset($reg['typeColumn']) && isset($existing['typeColumn'])
                && $reg['typeColumn'] !== $existing['typeColumn']) {
                throw new \LogicException(
                    "Conflicting typeColumn for table '{$table}': "
                    . "'{$existing['typeColumn']}' vs '{$reg['typeColumn']}' (module {$module->id})",
                );
            }

            $merged = $existing;

            // Merge typeColumn
            if (isset($reg['typeColumn']) && !isset($merged['typeColumn'])) {
                $merged['typeColumn'] = $reg['typeColumn'];
            }

            // Merge classes map
            if (isset($reg['classes']) && is_array($reg['classes'])) {
                $merged['classes'] = $merged['classes'] ?? [];
                foreach ($reg['classes'] as $typeKey => $className) {
                    if (isset($merged['classes'][$typeKey])
                        && $merged['classes'][$typeKey] !== $className) {
                        throw new \LogicException(
                            "Duplicate class registration for table '{$table}', "
                            . "type '{$typeKey}': '{$merged['classes'][$typeKey]}' vs '{$className}' "
                            . "(module {$module->id})",
                        );
                    }
                    $merged['classes'][$typeKey] = $className;
                }
            }

            // Merge defaultClass (first wins)
            if (isset($reg['defaultClass']) && !isset($merged['defaultClass'])) {
                $merged['defaultClass'] = $reg['defaultClass'];
            }

            // class fallback (compat with existing simple registrations)
            if (isset($reg['class']) && !isset($merged['class']) && !isset($merged['typeColumn'])) {
                $merged['class'] = $reg['class'];
            }

            $byTable[$table] = $merged;
        }
    }

    return new DocumentRegistry(array_values($byTable));
}
```

Pozn: `DocumentRegistry::__construct` pravděpodobně bude potřeba
mírně upravit, aby zvládl jak `class` (pre-typeColumn era), tak
`typeColumn + classes + defaultClass`. Aktuálně to dělá správně:

```php
if (isset($reg['typeColumn'])) {
    // dispatch via typeColumn
}
if (isset($reg['class'])) {
    // direct instantiation
}
return new DefaultDocument();
```

Tj. registrace s `typeColumn` má prioritu, registrace s prostým `class`
zůstane fungovat pro ostatní tabulky (NumberSeriesDocument, atd.).

### Per-typ viewer s fixním `doc_type` filtrem

Per-typ viewer dědí z `DocsHeadsViewer` a přepisuje `selectRows`
o přidání `WHERE h.doc_type = 'invno'` (resp. `invni`). Vše ostatní
zůstává — `viewGroup`, search, render row, render detail.

Plus **doplnění defaultního `number_series`** při kliku na "Přidat":
viewer poskytuje `getNewRecordDefaults()` (nebo podobný hook), který
vrátí prefill data. Pokud takový hook neexistuje, dispatcher pro
"create" v `FormController` musí vědět, z jakého kontextu uživatel
přišel. Pragmatická cesta:

- Per-typ viewer přidá filter `doc_type = 'invno'` do default options
  pro number_series dropdown ve formuláři
- Frontend pošle při Create requestu hlavičku `X-Doc-Type: invno` nebo
  query param `?doc_type=invno`
- `DocsHeadsForm::buildFormDefinition` při novém záznamu (`isNew=true`)
  přečte query string / data a předvyplní `number_series` první aktivní
  řadou daného typu

Pokud framework nemá pohodlný způsob, jak předat hint z vieweru do
formuláře, alternativa je: viewer při kliku na "Přidat" volá
`/api/v1/_ui/form/docs_core_heads/meta?doc_type=invno`. `FormController`
parametr query přečte a předá do `buildFormDefinition` přes `data`.

**Doporučení:** tato část tasku se může v reakci na realitu lehce
upravit — proto v "Hotovo když" je akceptační kritérium pouze "po
kliku 'Přidat' v Faktury vydané je `number_series` předvyplněna řadou
typu invno".

## Implementace

### Modul `docs.invoicesOut`

#### `modules/docs/invoicesOut/module.jsonc`

```jsonc
{
    "id": "docs.invoicesOut",
    "name": "Issued invoices",
    "name:cs": "Faktury vydané",
    "name:en": "Issued invoices",
    "description": "Document type subclass for issued invoices (invno)",
    "description:cs": "Faktury vydané (invno) — specifická Document třída a viewer",
    "description:en": "Document type subclass for issued invoices (invno)",

    "dependencies": ["docs.core"],

    "viewers": [
        {
            "id": "docs.invoicesOut.heads",
            "name": "Issued invoices",
            "name:cs": "Faktury vydané",
            "name:en": "Issued invoices",
            "icon": "file-invoice-dollar",
            "table": "docs_core_heads",
            "class": "Shipard\\Module\\Docs\\InvoicesOut\\IssuedInvoicesViewer"
        }
    ],

    "documentClasses": [
        {
            "table": "docs_core_heads",
            "typeColumn": "doc_type",
            "classes": {
                "invno": "Shipard\\Module\\Docs\\InvoicesOut\\IssuedInvoiceDocument"
            }
        }
    ]
}
```

#### `modules/docs/invoicesOut/src/IssuedInvoiceDocument.php`

```php
<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\InvoicesOut;

use Shipard\Core\Document\ValidationResult;
use Shipard\Module\Docs\Core\DocsHeadsDocument;

/**
 * Issued invoice (FVB) — `doc_type = 'invno'`.
 *
 * Inherits all logic from DocsHeadsDocument; overrides validate() with
 * per-type rules:
 *   - bank_account is required at Confirm time (we need to tell the
 *     customer where to pay)
 *
 * Future overrides may add VAT-specific rules, due_date defaults,
 * cash-flow integration, etc.
 */
class IssuedInvoiceDocument extends DocsHeadsDocument
{
    public function validate(array &$data): ValidationResult
    {
        $result = parent::validate($data);

        $newState = (int) ($data['docState'] ?? 10);

        // Confirm and beyond: our bank account must be set on issued invoices
        if (in_array($newState, [20, 40, 80], true)) {
            if (empty($data['bank_account'])) {
                $result->addError(
                    'bank_account',
                    'Bankovní účet je povinný — partner musí vědět, kam zaplatit.',
                    'required',
                );
            }
        }

        return $result;
    }
}
```

#### `modules/docs/invoicesOut/src/IssuedInvoicesViewer.php`

```php
<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\InvoicesOut;

use Shipard\Module\Docs\Core\DocsHeadsViewer;

/**
 * Per-type viewer for issued invoices (doc_type = 'invno').
 *
 * Inherits everything from DocsHeadsViewer and adds a fixed type filter.
 * Phase 6 keeps this minimal — the secondary tab bar with number series
 * (see screenshot in design doc) is deferred to a follow-up frontend task.
 */
class IssuedInvoicesViewer extends DocsHeadsViewer
{
    /** Type filter applied to all queries from this viewer. */
    private const DOC_TYPE = 'invno';

    public function selectRows(?string $search, array $filters, int $pageNumber): array
    {
        // Inject doc_type filter as a synthetic filter; DocsHeadsViewer
        // accepts arbitrary filter ids and applies viewGroup logic.
        $filters[] = ['id' => '_doc_type', 'value' => self::DOC_TYPE];
        return parent::selectRows($search, $filters, $pageNumber);
    }

    /**
     * Override the meta endpoint hint for new-record default doc_type.
     * Used by FormController to pre-fill number_series.
     */
    public function getNewRecordDefaults(): array
    {
        return ['doc_type' => self::DOC_TYPE];
    }
}
```

**Pozn:** `DocsHeadsViewer::selectRows` zpracovává `_doc_type`
synthetic filter — to znamená že ho upravujeme i v `docs.core`. Viz
sekce "Úpravy `docs.core`" níže.

#### `modules/docs/invoicesOut/README.md`

```markdown
# Modul: docs.invoicesOut

Modul pro **Faktury vydané** (`doc_type = 'invno'`). Polymorfní
subclass nad `docs.core`.

## Účel

Specializovaný typ dokladu — faktura, kterou vystavujeme zákazníkovi.
Klíčové rozlišení: my jsme dodavatel (snapshot supplier), partner je
odběratel (snapshot customer). Trade direction = 1 (output) — viz
cfgItem `docs.core.docTypes`.

## Co modul přidává

- **Document třída** `IssuedInvoiceDocument extends DocsHeadsDocument` —
  per-typ validace (bank_account povinný při Potvrzení) + budoucí
  rozšíření (cashflow integrace, splátkový kalendář, …)
- **Viewer** `IssuedInvoicesViewer extends DocsHeadsViewer` — viewer
  v hlavní navigaci s fixním filtrem `doc_type = 'invno'`
- **Polymorfní registrace** v `documentClasses` — typeColumn dispatch
  zaroutuje doklad s `doc_type = 'invno'` na `IssuedInvoiceDocument`

## Co modul NEpřidává

- Žádné nové tabulky — všechny doklady leží v `docs_core_heads`
- Žádné nové cfgItem — typy dokladů jsou v `docs.core.docTypes`
- Žádné nové forms — používáme `DocsHeadsForm` z `docs.core`

## Vztah k `docs.invoicesIn`

Symetrický modul pro **Faktury přijaté** (`doc_type = 'invni'`,
trade_dir = 2 = input). Oba moduly mají stejnou strukturu, liší se jen
v doc_type a per-typ validacích.
```

### Modul `docs.invoicesIn`

Symetrický k `docs.invoicesOut`. Kompletní obsah:

#### `modules/docs/invoicesIn/module.jsonc`

```jsonc
{
    "id": "docs.invoicesIn",
    "name": "Received invoices",
    "name:cs": "Faktury přijaté",
    "name:en": "Received invoices",
    "description": "Document type subclass for received invoices (invni)",
    "description:cs": "Faktury přijaté (invni) — specifická Document třída a viewer",
    "description:en": "Document type subclass for received invoices (invni)",

    "dependencies": ["docs.core"],

    "viewers": [
        {
            "id": "docs.invoicesIn.heads",
            "name": "Received invoices",
            "name:cs": "Faktury přijaté",
            "name:en": "Received invoices",
            "icon": "file-invoice",
            "table": "docs_core_heads",
            "class": "Shipard\\Module\\Docs\\InvoicesIn\\ReceivedInvoicesViewer"
        }
    ],

    "documentClasses": [
        {
            "table": "docs_core_heads",
            "typeColumn": "doc_type",
            "classes": {
                "invni": "Shipard\\Module\\Docs\\InvoicesIn\\ReceivedInvoiceDocument"
            }
        }
    ]
}
```

#### `modules/docs/invoicesIn/src/ReceivedInvoiceDocument.php`

```php
<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\InvoicesIn;

use Shipard\Core\Document\ValidationResult;
use Shipard\Module\Docs\Core\DocsHeadsDocument;

/**
 * Received invoice (FPB) — `doc_type = 'invni'`.
 *
 * Inherits all logic from DocsHeadsDocument. For received invoices we
 * don't need our bank_account (the supplier provides theirs in
 * partner_bank or partner_bank_account/iban/bic columns), so we don't
 * add the bank_account requirement.
 *
 * Future overrides may validate that supplier's bank info is filled in,
 * verify VAT_ID format on EU intracom invoices, etc.
 */
class ReceivedInvoiceDocument extends DocsHeadsDocument
{
    public function validate(array &$data): ValidationResult
    {
        $result = parent::validate($data);

        $newState = (int) ($data['docState'] ?? 10);

        // Confirm and beyond: at least one of partner_bank, partner_bank_account,
        // partner_bank_iban must be filled — we need to know how to pay
        if (in_array($newState, [20, 40, 80], true)) {
            $hasBank = !empty($data['partner_bank'])
                    || !empty($data['partner_bank_account'])
                    || !empty($data['partner_bank_iban']);
            if (!$hasBank) {
                $result->addError(
                    '_form',
                    'Bankovní spojení dodavatele je povinné — vyberte jeho účet '
                    . 'nebo vyplňte ručně číslo účtu / IBAN.',
                    'partner_bank_required',
                );
            }
        }

        return $result;
    }
}
```

#### `modules/docs/invoicesIn/src/ReceivedInvoicesViewer.php`

```php
<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\InvoicesIn;

use Shipard\Module\Docs\Core\DocsHeadsViewer;

class ReceivedInvoicesViewer extends DocsHeadsViewer
{
    private const DOC_TYPE = 'invni';

    public function selectRows(?string $search, array $filters, int $pageNumber): array
    {
        $filters[] = ['id' => '_doc_type', 'value' => self::DOC_TYPE];
        return parent::selectRows($search, $filters, $pageNumber);
    }

    public function getNewRecordDefaults(): array
    {
        return ['doc_type' => self::DOC_TYPE];
    }
}
```

#### `modules/docs/invoicesIn/README.md`

Stejná struktura jako `docs.invoicesOut/README.md`, jen s "Faktura
přijatá", `invni`, trade_dir = 2 (input).

### Úpravy `docs.core`

#### `modules/docs/core/module.jsonc`

Přepnout registraci pro `docs_core_heads` z prosté `class` na
`typeColumn + defaultClass`:

```jsonc
"documentClasses": [
    {
        "table": "docs_core_number_series",
        "class": "Shipard\\Module\\Docs\\Core\\NumberSeriesDocument"
    },
    {
        "table": "docs_core_heads",
        "typeColumn": "doc_type",
        "defaultClass": "Shipard\\Module\\Docs\\Core\\DocsHeadsDocument"
        // classes map is filled by docs.invoicesOut and docs.invoicesIn
        // modules via DocumentLoader merge logic
    }
]
```

`NumberSeriesDocument` registrace zůstává pointer-style — žádný
typeColumn potřeba.

#### `modules/docs/core/src/DocsHeadsViewer.php`

Přidat zpracování synthetic filtru `_doc_type`. V `selectRows`:

```php
// Existing viewGroup filter handling
$viewGroup = 'active';
$docTypeFilter = null;
foreach ($filters as $filter) {
    if (($filter['id'] ?? null) === 'viewGroup') {
        $viewGroup = (string) $filter['value'];
    } elseif (($filter['id'] ?? null) === '_doc_type') {
        $docTypeFilter = (string) $filter['value'];
    }
}

// ... after viewGroup conditions ...

if ($docTypeFilter !== null && $docTypeFilter !== '') {
    $conditions[] = 'h.`doc_type` = %s';
    $params[] = $docTypeFilter;
}
```

To je čistá rozšíření — generický viewer dál funguje bez `_doc_type`
filtru, per-typ viewer ho přidává.

### Úpravy `DocumentLoader`

Implementovat per-table merge logiku jak popsáno v sekci
"Architektonická rozhodnutí" výše.

Klíčové akceptační body pro merge:

- Test: dva moduly registrují `docs_core_heads` s `typeColumn='doc_type'`
  a různými `classes` mapami → merged registrace má obě
- Test: dva moduly registrují stejný `(table, typeKey)` s různými
  classes → throw `\LogicException`
- Test: existující "prosté" registrace (`{table, class}`) dál fungují
  beze změny

### Úpravy `install.base`

V `modules/install/base/module.jsonc` do `dependencies`:

```jsonc
"dependencies": [
    // ... existing ...
    "docs.core",
    "docs.invoicesOut",
    "docs.invoicesIn"
]
```

(Pokud `docs.core` tam už je z Fáze 3, jen přidej oba nové.)

### Frontend ikony

V `frontend/src/icons.js` přidat (pokud neexistují):

```js
import {
    faFileInvoiceDollar,
    faFileInvoice,
} from '@fortawesome/free-solid-svg-icons';

export const iconFileInvoiceDollar = faFileInvoiceDollar;
export const iconFileInvoice = faFileInvoice;

// iconMap:
'file-invoice-dollar': iconFileInvoiceDollar,
'file-invoice': iconFileInvoice,
```

Spustit `npm run build` v `frontend/`.

### Předvyplnění `number_series` při novém záznamu

Doporučená implementace v `DocsHeadsForm::buildFormDefinition`:

```php
private function applyClientDefaults(array &$data, bool $isNew): void
{
    // ... existing defaults ...

    // If this is a new record and doc_type was provided as hint
    // (from per-type viewer or query param), preselect a number series.
    if ($isNew && empty($data['number_series']) && !empty($data['doc_type'])) {
        $row = $this->db?->fetchRow(
            'SELECT id FROM docs_core_number_series
             WHERE doc_type = %s AND docState = 40
             ORDER BY id ASC LIMIT 1',
            (string) $data['doc_type'],
        );
        if ($row !== null) {
            $data['number_series'] = (int) $row['id'];
        }
    }
}
```

Question, kterou je třeba ověřit při implementaci: **jak se `doc_type`
hint dostane do `data` při novém záznamu?** Možnosti:

- Frontend posílá `?doc_type=invno` v query stringu při GET
  `/api/v1/_ui/form/docs_core_heads/meta` → `FormController` parametr
  přečte a předá do `buildFormDefinition` přes `data`
- Per-typ viewer při kliku na "Přidat" otevírá modal s explicit
  `?doc_type=` v URL → frontend tu hodnotu posílá serveru
- `getNewRecordDefaults()` viewer hook — vrací prefill data, frontend
  je posílá při GET meta

Detail řeší frontend v existujícím kódu pro `viewer → form` přechod
("Přidat" tlačítko). Zkontroluj, jak se to dělá v ostatních modulech
(např. zda `economy.items` viewer předává nějaký hint).

## Hotovo když

- [ ] `bin/shpd-ds ds-upgrade` projde bez chyb
- [ ] V hlavní navigaci jsou položky "Faktury vydané" a "Faktury přijaté"
      vedle "Doklady" (generický)
- [ ] Klik na "Faktury vydané" zobrazí jen doklady s `doc_type = 'invno'`
- [ ] Klik na "Faktury přijaté" zobrazí jen doklady s `doc_type = 'invni'`
- [ ] Klik na "Doklady" (generický) zobrazí všechny typy
- [ ] Klik "Přidat" v "Faktury vydané" otevře formulář s předvyplněnou
      `number_series` typu `invno` (a dropdown nabízí jen řady tohoto
      typu, jeden způsob — buď serverový filter nebo client-side)
- [ ] Stejně pro "Faktury přijaté" → `invni`
- [ ] Existující doklady při edit/save spustí Document subclass podle
      `doc_type` — ověř logováním nebo explicitním breakpointem v
      `IssuedInvoiceDocument::validate`
- [ ] Validace v `IssuedInvoiceDocument`: pokus uložit fakturu vydanou
      bez `bank_account` při Potvrzení vrátí 422 s error code `required`
      na poli `bank_account`
- [ ] Validace v `ReceivedInvoiceDocument`: pokus uložit fakturu přijatou
      bez bank info partnera při Potvrzení vrátí 422 s error code
      `partner_bank_required`
- [ ] Doklad s `doc_type = ''` (neznámý) nebo bez `doc_type` použije
      `DocsHeadsDocument` (defaultClass)
- [ ] `DocumentLoader` merge: simulovaný test (mock 2 moduly s `classes`
      mapou) vrátí merged registraci
- [ ] PHPUnit testy:
  - `DocumentLoaderTest`: merge logic, conflict detection
  - `IssuedInvoiceDocumentTest`: validate s/bez bank_account
  - `ReceivedInvoiceDocumentTest`: validate s/bez partner_bank info
  - `IssuedInvoicesViewerTest`: selectRows aplikuje doc_type filter
- [ ] `install.base` má `docs.invoicesOut` a `docs.invoicesIn`
      v dependencies
- [ ] README per modul napsané

## Konvence

- **Jazyk**: UI texty čeština, kód a komentáře angličtina
- **Vícejazyčnost**: `name`/`description` v `module.jsonc` s `:cs` a `:en`
- **PHP 8.3** strict_types, readonly properties kde možné
- **Per-typ viewers/Documents jsou thin** — gross of logic žije
  v `docs.core`. Subclasses přidávají jen specifickou validaci nebo
  hooks. Pokud najdete "natural" společnou logiku napříč typy, zvažte
  přesun do `DocsHeadsDocument`.

## Doporučené pořadí implementace

1. **`DocumentLoader` merge** + PHPUnit testy — fundament, na kterém
   vše stojí. Bez merge logiky polymorfní dispatch nefunguje.
2. **`docs.core/module.jsonc` přepnutí** na typeColumn + defaultClass
3. **Sanity check**: po `ds-upgrade` se generic viewer "Doklady" stále
   chová stejně (defaultClass = `DocsHeadsDocument`)
4. **`docs.invoicesOut` modul** — `module.jsonc`, `IssuedInvoiceDocument`,
   `IssuedInvoicesViewer`, README
5. **Sanity check**: viewer "Faktury vydané" se objeví v navigaci, klik
   ukáže filtrované doklady
6. **`docs.invoicesIn` modul** — symetricky
7. **Per-typ validace** — `IssuedInvoiceDocument::validate` (bank_account)
   + `ReceivedInvoiceDocument::validate` (partner_bank info)
8. **PHPUnit testy** pro per-typ validation a viewer filter
9. **Frontend ikony** + sanity check že navigace má správné ikony
10. **Předvyplnění `number_series`** podle `doc_type` při Add → ověř
    UX flow
11. **`install.base` aktualizace**
12. **E2E test:** vystavit fakturu vydanou → uložit Koncept → potvrdit
    → ověřit v DB, že `doc_number` je `1xxx` (FVB) a snapshot supplier
    je naše firma. Pak fakturu přijatou → analogicky.

## Otevřené body

- **Spodní tab bar pro číselné řady** ve viewerech — pro per-typ
  viewer je přirozené mít sekundární řadu tabů "Vše | Řada A | Řada B
  | EUR | …" pod hlavní viewGroup taby. Aktuální `TableViewer`
  Svelte komponenta podporuje jen jednu sadu tabů (viewGroup). Vyžaduje
  frontend úpravy:
  - Backend: viewer `meta` vrací `secondaryTabs: [...]`
  - Frontend: `TableViewer` komponenta podporuje druhou řadu tabů,
    propaguje filter na backend
  Pokud po MVP zůstane potřeba, samostatný úkol.
- **Polymorfní `DocsHeadsForm`** — formulář aktuálně neví, jaký typ
  dokladu zpracovává; všechny pole zobrazuje stejně. V budoucnu by
  mohly per-typ subclasses formuláře skrývat irelevantní pole (např.
  `bank_account` u faktury přijaté). Pro MVP to nepotřebujeme.
- **`getNewRecordDefaults()` hook** ve `TableViewer` — task ho zmiňuje
  jako nepotřebnou abstrakci. Pokud frontend potřebuje hint, jak
  předvyplnit nový záznam, zvažte explicitní viewer hook (vrátí pole
  prefill dat) místo ad-hoc query stringů. Reálná implementace
  vyplyne z toho, jak frontend řeší "Přidat" tlačítko v navigaci.
- **`docs.core.docTypes` cfgItem `subclass` atribut** — od Fáze 3 je
  tam, ale v této fázi se nepoužívá (dispatch je v `module.jsonc`).
  Buďto ho odstranit (zbytečný), nebo zachovat jako dokumentační
  metadata ("kde tenhle typ žije") — preferuji druhé.
- **Polymorfní `DocsHeadsForm` per typ** ↔ pre-fill default pro
  `bank_account` z DataSourceConfig: faktura vydaná by mohla
  default-vat naše banky podle `doc_currency` (`getDefaultBankAccount(currency)`).
  Nice-to-have, ale ne nutné v MVP — `DocsHeadsForm` už má
  `resolveBankAccountOptions(currency)`, takže UI uživateli nabídne
  filtrované účty.

## Konec MVP

Po dokončení Fáze 6 je MVP dokladového systému kompletní:

- ✅ Dva typy dokladů (FVB, FPB) s polymorfní strukturou
- ✅ DPH model pro CZ s reverse charge
- ✅ Číselné řady s atomickým přidělením
- ✅ Snapshoty fakturačních údajů
- ✅ Stavový životní cyklus
- ✅ UI pro pořizování, editaci, prohlížení
- ✅ Per-typ viewers v hlavní navigaci

**Další iterace** (mimo MVP, jako separátní úkoly):

- PDF výstup faktur (engine + šablony)
- Přiznání DPH, Kontrolní hlášení, Souhrnné hlášení (`docs.vatReports`?)
- Saldokonto a párování úhrad
- Zálohové faktury (`prfmin`, `invpo`)
- Bankovní výpisy a pokladní doklady
- DPH per EU stát (SK, DE, AT, …)
- Ceníkový mechanismus (per-partner ceny, množstevní slevy)
- Skladová evidence (vazba na položky s `tracksInventory = true`)
