# Task: `docs.core` — Fáze 1: Skeleton modulu

**Stav:** hotovo

## Kontext

Implementujeme jádro dokladového systému — společný modul `docs.core` pro
všechny budoucí typy dokladů (faktury vydané, faktury přijaté, …) v polymorfní
architektuře. V této fázi vznikne **kostra modulu**: tabulky, konfigurační
soubory, abstract Document base třída, číselné řady (jediná entita, která má
už ve Fázi 1 plné UI), helper pro vlastní firmu.

**Co tato fáze NEdělá:** výpočty cen, DPH, rekapitulace, snapshoty, automatické
přidělení čísla dokladu, viewer dokladů, formulář dokladu. Vše dorazí ve
Fázích 2 (`docs-core-phase2.md`) a 3 (`docs-core-phase3.md`).

**Důsledek:** doklad lze v této fázi uložit pouze jako **prázdný Koncept**
(bez řádků, bez výpočtů). To stačí pro ověření, že schéma + cfgItem + Document
infrastructure funguje end-to-end.

Před implementací **přečti**:

- `docs/docs-mvp.md` — kompletní designový dokument MVP, zejména:
  - Sekce 2 (modulová struktura)
  - Sekce 3 (stavový model `docs.core.docStates`)
  - Sekce 5 (číselné řady — tabulky a algoritmus, ale bez assignDocumentNumber
    z fáze 4)
  - Sekce 6, 7, 8 (kompletní JSONC pro tabulky)
  - Sekce 9 (tok dat — orientačně, kód k tomu je až ve Fázi 4)
  - Sekce 12 (otevřené body — některé řešíme zde)
- `tasks/world-vat-cz.md` (hotovo) a `tasks/persons-is-own-extension.md`
  (hotovo) — staví na nich
- `docs/modules.md`, `docs/table-definitions.md`, `docs/doc-states.md`,
  `docs/edit-forms.md`

Vzorové existující moduly:

- `modules/economy/codebooks/` — komplexní modul s několika tabulkami,
  vlastními formy/viewers/document classes, provisionery — **nejbližší vzor**
- `modules/economy/items/` — vzor pro modul s vlastními cfgItem soubory
- `modules/world/vat/` (právě hotovo) — vzor pro modul bez vlastních tabulek
  s konfiguračním cfgItem

Vzor pro provisioner: `modules/economy/codebooks/src/FiscalYearsProvisioner.php`
(volá ho `DsUpgradeCommand::provisionFiscalYears`).

## Cíl Fáze 3

Po dokončení této fáze platí:

- Existuje modul `docs.core` se závislostmi na `world.vat`, `base.persons`,
  `economy.codebooks`, `economy.items`, `core.units`, `core.attachments`,
  `core.system`
- 5 tabulek vzniká v DB při `bin/shpd-ds ds-upgrade`:
  `docs_core_heads`, `docs_core_rows`, `docs_core_vat_recap`,
  `docs_core_number_series`, `docs_core_number_counters`
- 10 cfgItem souborů je registrovaných (docTypes, docStates, vatModes,
  vatCalcSources, vatPlaces, priceCalcModes, rowKinds, roundingModes,
  paymentMethods, resetScopes)
- Číselné řady mají kompletní CRUD: Document, Form (s recalculate),
  Viewer, Provisioner (default 1 řada per typ dokladu)
- `OwnCompanyResolver` helper umí najít vlastní firmu (`is_own = 1`)
- `DocDocument` abstract base třída drží minimum logiky pro Fázi 1 (init
  doc_number jako `!{id_padded}`, denormalizace `doc_type` z řady)
- Insert prázdného Konceptu přes API projde — uloží se s `docState = 10`,
  `doc_number = '!0000000123'`, žádné výpočty, žádné řádky

## Návaznost

- Závisí na: `persons-is-own-extension.md` (hotovo), `world-vat-cz.md` (hotovo),
  `economy.codebooks` (existuje), `economy.items` (existuje), edit-forms
  infrastructure (existuje)
- Otevírá: Fáze 4 (`docs-core-phase2.md`) — výpočty cen, DPH, rekapitulace,
  snapshoty, atomické přidělení čísla

## Scope

### V rozsahu

- Kompletní struktura modulu `docs.core` (`module.jsonc`, README, adresářová
  struktura)
- 5 tabulek (JSONC + per-tabulka `.md` dokumentace)
- 10 cfgItem souborů
- `DocDocument` abstract base (s minimální `beforeSave` logikou pro Koncept)
- `NumberSeriesDocument` + `NumberSeriesForm` + `NumberSeriesViewer`
- `NumberSeriesProvisioner` + hook do `DsUpgradeCommand`
- `OwnCompanyResolver` helper
- Aktualizace `install.base/module.jsonc` — `docs.core` do dependencies
- Settings UI integrace — viewer číselných řad v sekci "accounting"

### Mimo rozsah (řeší Fáze 4)

- `assignDocumentNumber` (atomické přidělení sequence_number při Koncept → Potvrzeno)
- `calculateRowPrice`, `calculateRowVat`
- `buildVatRecapitulation`, `sumTotals`, `applyRounding`
- `maintainSnapshots`, `buildPersonSnapshot`
- `resolveFiscalYearId`, `resolveFiscalMonthId`, `resolveVatPeriodId`

### Mimo rozsah (řeší Fáze 5)

- `DocsHeadsForm` (formulář faktury — hlavička + řádky + rekapitulace + …)
- Frontend rozšíření pro dynamický VAT code select

### Mimo rozsah (řeší Fáze 6)

- `IssuedInvoiceDocument`, `ReceivedInvoiceDocument` subclasses v modulech
  `docs.invoicesOut`, `docs.invoicesIn`
- `IssuedInvoicesViewer`, `ReceivedInvoicesViewer` (per-typ viewers se spodními
  taby pro číselné řady)

## Adresářová struktura

```
modules/docs/core/
├── module.jsonc
├── README.md
├── tables/
│   ├── docs_core_heads.jsonc
│   ├── docs_core_heads.md
│   ├── docs_core_rows.jsonc
│   ├── docs_core_rows.md
│   ├── docs_core_vat_recap.jsonc
│   ├── docs_core_vat_recap.md
│   ├── docs_core_number_series.jsonc
│   ├── docs_core_number_series.md
│   └── docs_core_number_counters.jsonc
│   └── docs_core_number_counters.md
├── config/
│   ├── docTypes.jsonc
│   ├── docStates.jsonc
│   ├── vatModes.jsonc
│   ├── vatCalcSources.jsonc
│   ├── vatPlaces.jsonc
│   ├── priceCalcModes.jsonc
│   ├── rowKinds.jsonc
│   ├── roundingModes.jsonc
│   ├── paymentMethods.jsonc
│   └── resetScopes.jsonc
└── src/
    ├── DocDocument.php
    ├── NumberSeriesDocument.php
    ├── NumberSeriesForm.php
    ├── NumberSeriesViewer.php
    ├── NumberSeriesProvisioner.php
    └── OwnCompanyResolver.php
```

Namespace: `Shipard\Module\Docs\Core\*`.

## `module.jsonc`

```jsonc
{
    "id": "docs.core",
    "name": "Document core",
    "name:cs": "Doklady — jádro",
    "name:en": "Document core",
    "description": "Polymorphic core for all document types (invoices, …)",
    "description:cs": "Polymorfní jádro pro všechny typy dokladů (faktury, …)",
    "description:en": "Polymorphic core for all document types (invoices, …)",

    "dependencies": [
        "core.system",
        "core.units",
        "core.attachments",
        "base.persons",
        "world.base",
        "world.vat",
        "economy.codebooks",
        "economy.items"
    ],

    "tables": [
        "docs_core_heads",
        "docs_core_rows",
        "docs_core_vat_recap",
        "docs_core_number_series",
        "docs_core_number_counters"
    ],

    "settingsItems": [
        { "viewer": "docs.core.numberSeries", "section": "accounting" }
    ],

    "viewers": [
        {
            "id": "docs.core.numberSeries",
            "name": "Document number series",
            "name:cs": "Číselné řady dokladů",
            "name:en": "Document number series",
            "icon": "hash",
            "table": "docs_core_number_series",
            "class": "Shipard\\Module\\Docs\\Core\\NumberSeriesViewer"
        }
    ],

    "forms": [
        {
            "table": "docs_core_number_series",
            "class": "Shipard\\Module\\Docs\\Core\\NumberSeriesForm"
        }
    ],

    "documentClasses": [
        {
            "table": "docs_core_number_series",
            "class": "Shipard\\Module\\Docs\\Core\\NumberSeriesDocument"
        }
        // docs_core_heads document class přijde ve Fázi 6
        // (IssuedInvoiceDocument, ReceivedInvoiceDocument)
    ],

    "config": [
        { "id": "docs.core.docTypes",        "file": "config/docTypes.jsonc" },
        { "id": "docs.core.docStates",       "file": "config/docStates.jsonc" },
        { "id": "docs.core.vatModes",        "file": "config/vatModes.jsonc" },
        { "id": "docs.core.vatCalcSources",  "file": "config/vatCalcSources.jsonc" },
        { "id": "docs.core.vatPlaces",       "file": "config/vatPlaces.jsonc" },
        { "id": "docs.core.priceCalcModes",  "file": "config/priceCalcModes.jsonc" },
        { "id": "docs.core.rowKinds",        "file": "config/rowKinds.jsonc" },
        { "id": "docs.core.roundingModes",   "file": "config/roundingModes.jsonc" },
        { "id": "docs.core.paymentMethods",  "file": "config/paymentMethods.jsonc" },
        { "id": "docs.core.resetScopes",     "file": "config/resetScopes.jsonc" }
    ]
}
```

Pozn: ikona `hash` — pravděpodobně už v `iconMap` neexistuje, takže přidat:
v `frontend/src/icons.js` import `faHashtag` a registrace `'hash': iconHash`.
Pokud preferuješ jinou ikonu, vyber z dostupných.

## Datový model — tabulky

Kompletní JSONC definice 5 tabulek najdeš v `docs/docs-mvp.md`:

- **`docs_core_heads`** (sekce 6.2) — hlavička dokladu (~40 sloupců v 11
  skupinách)
- **`docs_core_rows`** (sekce 7.1) — řádky dokladu
- **`docs_core_vat_recap`** (sekce 8.1) — rekapitulace DPH (vyplňovaná až
  ve Fázi 4)
- **`docs_core_number_series`** (sekce 5.1) — číselné řady
- **`docs_core_number_counters`** (sekce 5.2) — atomické countery

**TableId přidělení:**
- 401 = `docs_core_heads`
- 402 = `docs_core_rows`
- 403 = `docs_core_vat_recap`
- 404 = `docs_core_number_series`
- 405 = `docs_core_number_counters`

**Důležité poznámky k JSONC:**

1. `docs_core_heads.docStates.cfgItem` = `"docs.core.docStates"` (NE
   `core.system.docStatesArchive` — máme vlastní rozšířený model).
2. `docs_core_number_series.docStates.cfgItem` = `"core.system.docStatesArchive"`
   (řady používají standardní archive model — žádný Potvrzeno/Storno).
3. `docs_core_vat_recap` a `docs_core_number_counters` **nemají
   `docStates`** — jsou to čistě technické tabulky bez životního cyklu.
4. `docs_core_heads.childTables`:
   ```jsonc
   "childTables": [
       { "table": "docs_core_rows",      "foreignKey": "doc_head", "dataKey": "rows" },
       { "table": "docs_core_vat_recap", "foreignKey": "doc_head", "dataKey": "vatRecap" }
   ]
   ```
5. Ve sloupci `docs_core_rows.vat_code` ponechat `enumString` **bez fixního
   `cfgItem`** — pole se chová jako volný string s enforcement na úrovni
   aplikace (cfgItem se odvozuje z `vat_registration` na hlavičce, řešíme až
   ve Fázi 5 UI).
6. `docs_core_heads.indexes` musí obsahovat `unq_series_seq` UNIQUE na
   `(number_series, fiscal_year, sequence_number)` — pojistka proti
   duplicitám čísla dokladu.
7. `docs_core_number_counters.indexes` musí obsahovat `unq_series_year`
   UNIQUE na `(number_series, fiscal_year)`.

## cfgItem soubory — kompletní obsah

Všech 10 souborů. Každý má lidsky čitelný popisný komentář na začátku.

### `config/docTypes.jsonc`

```jsonc
{
    // docs.core.docTypes
    //
    // Typy dokladů v systému. V MVP jen invno (faktura vydaná) a invni
    // (faktura přijatá). Postupně přibudou další (cash, bank, prfmin, …).
    //
    // Atributy:
    //   doc_id_code: string vkládaný do %D placeholderu vzorce čísla dokladu
    //   trade_dir:   1 = výstup (my=dodavatel), 2 = vstup (my=odběratel)
    //   doc_number_pattern_default: vzorec, který provisioner použije při
    //                               vytvoření default číselné řady
    //   subclass:    plný název Document třídy v navazujícím modulu
    //                (přijde s Fází 6 — zatím jen referenční hint)

    "invno": {
        "name": "Issued invoice",
        "name:cs": "Faktura vydaná",
        "name:en": "Issued invoice",
        "shortcut": "FVB",
        "shortcut:cs": "FVB",
        "shortcut:en": "IV",
        "doc_id_code": "1",
        "trade_dir": 1,
        "doc_number_pattern_default": "%D%y%C%4",
        "subclass": "Shipard\\Module\\Docs\\InvoicesOut\\IssuedInvoiceDocument"
    },

    "invni": {
        "name": "Received invoice",
        "name:cs": "Faktura přijatá",
        "name:en": "Received invoice",
        "shortcut": "FPB",
        "shortcut:cs": "FPB",
        "shortcut:en": "RI",
        "doc_id_code": "2",
        "trade_dir": 2,
        "doc_number_pattern_default": "%D%y%C%4",
        "subclass": "Shipard\\Module\\Docs\\InvoicesIn\\ReceivedInvoiceDocument"
    }
}
```

### `config/docStates.jsonc`

Kompletní obsah viz `docs/docs-mvp.md` sekce 3 ("Kompletní cfgItem soubor").
Stavy: 10 Koncept, 20 Potvrzeno, 80 V opravě, 40 V pořádku, 30 Storno,
90 Smazáno (bez 70 V archívu).

### `config/vatModes.jsonc`

```jsonc
{
    // docs.core.vatModes
    //
    // Sloučený flag DPH + způsob výpočtu na hlavičce dokladu.
    //   0 = bez DPH (řádky neobsahují DPH, výpočet vynechán)
    //   1 = ze základu (cena na řádku je BEZ DPH, DPH se připočítává)
    //   2 = z ceny celkem (cena na řádku je VČETNĚ DPH, DPH se vypočte zpětně)

    "0": {
        "name": "No VAT",
        "name:cs": "Bez DPH",
        "name:en": "No VAT"
    },
    "1": {
        "name": "From base",
        "name:cs": "Ze základu",
        "name:en": "From base"
    },
    "2": {
        "name": "From total",
        "name:cs": "Z ceny celkem",
        "name:en": "From total"
    }
}
```

### `config/vatCalcSources.jsonc`

```jsonc
{
    // docs.core.vatCalcSources
    //
    // Odkud se počítá DPH:
    //   0 = z hlavičky (sečtu základy řádků se stejnou sazbou, pak vypočtu DPH)
    //   1 = z řádků (každý řádek má vlastní DPH zaokrouhlené, sečtu)
    // Pro většinu případů 0 (default) — řádkové počítání je méně časté.

    "0": {
        "name": "From header",
        "name:cs": "Z hlavičky",
        "name:en": "From header"
    },
    "1": {
        "name": "From rows",
        "name:cs": "Z řádků",
        "name:en": "From rows"
    }
}
```

### `config/vatPlaces.jsonc`

```jsonc
{
    // docs.core.vatPlaces
    //
    // Místo plnění:
    //   0 = tuzemsko (CZ obchody, vč. tuzemského PDP — to je flag na vatCode)
    //   1 = intrakomunitární (EU mimo ČR)
    //   2 = zahraničí (mimo EU)

    "0": {
        "name": "Domestic",
        "name:cs": "Tuzemsko",
        "name:en": "Domestic"
    },
    "1": {
        "name": "Intra-community",
        "name:cs": "Intrakomunitární plnění",
        "name:en": "Intra-community"
    },
    "2": {
        "name": "Foreign",
        "name:cs": "Zahraničí",
        "name:en": "Foreign"
    }
}
```

### `config/priceCalcModes.jsonc`

```jsonc
{
    // docs.core.priceCalcModes
    //
    // Způsob výpočtu na řádku dokladu:
    //   0 = z ceny za jednotku (quantity * unit_price = total_price)
    //   1 = z celkové ceny (total_price / quantity = unit_price)

    "0": {
        "name": "From unit price",
        "name:cs": "Z ceny za jednotku",
        "name:en": "From unit price"
    },
    "1": {
        "name": "From total price",
        "name:cs": "Z ceny celkem",
        "name:en": "From total price"
    }
}
```

### `config/rowKinds.jsonc`

```jsonc
{
    // docs.core.rowKinds
    //
    //   0 = textový řádek (jen popis, nepřispívá do součtů)
    //   1 = běžný řádek (s množstvím a cenou)

    "0": {
        "name": "Text row",
        "name:cs": "Textový řádek",
        "name:en": "Text row"
    },
    "1": {
        "name": "Standard row",
        "name:cs": "Běžný řádek",
        "name:en": "Standard row"
    }
}
```

### `config/roundingModes.jsonc`

```jsonc
{
    // docs.core.roundingModes
    //
    //   0 = bez zaokrouhlení
    //   1 = matematicky na 1 (celé Kč)
    //   2 = matematicky na 0,01 (haléře)

    "0": {
        "name": "No rounding",
        "name:cs": "Bez zaokrouhlení",
        "name:en": "No rounding"
    },
    "1": {
        "name": "Round to 1",
        "name:cs": "Matematicky na 1",
        "name:en": "Round to 1"
    },
    "2": {
        "name": "Round to 0.01",
        "name:cs": "Matematicky na 0,01",
        "name:en": "Round to 0.01"
    }
}
```

### `config/paymentMethods.jsonc`

```jsonc
{
    // docs.core.paymentMethods
    //
    //   0 = hotovost
    //   1 = převodem (default)
    //   2 = kartou
    //   3 = dobírkou
    //   4 = zápočtem

    "0": {
        "name": "Cash",
        "name:cs": "Hotovost",
        "name:en": "Cash"
    },
    "1": {
        "name": "Bank transfer",
        "name:cs": "Převodem",
        "name:en": "Bank transfer"
    },
    "2": {
        "name": "Card",
        "name:cs": "Kartou",
        "name:en": "Card"
    },
    "3": {
        "name": "Cash on delivery",
        "name:cs": "Dobírkou",
        "name:en": "Cash on delivery"
    },
    "4": {
        "name": "Set-off",
        "name:cs": "Zápočtem",
        "name:en": "Set-off"
    }
}
```

### `config/resetScopes.jsonc`

```jsonc
{
    // docs.core.resetScopes
    //
    // Kdy se restartuje counter čísel dokladů v číselné řadě:
    //   "none" — counter je průběžný napříč všemi roky
    //   "fiscal_year" — counter restartuje každý fiskální rok (default)

    "none": {
        "name": "No reset (continuous)",
        "name:cs": "Bez restartu (průběžné)",
        "name:en": "No reset (continuous)"
    },
    "fiscal_year": {
        "name": "Reset each fiscal year",
        "name:cs": "Restart každý fiskální rok",
        "name:en": "Reset each fiscal year"
    }
}
```

## PHP třídy

### `src/DocDocument.php`

Abstract base třída pro všechny typy dokladů. Ve Fázi 1 obsahuje **minimum
logiky**: init `doc_number` jako `!{id_padded}` pro nový Koncept, denormalizace
`doc_type` z `number_series`. Konkrétní výpočty jsou stub metody — Fáze 2 je
naplní.

```php
<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\Core;

use Shipard\Core\Document\Document;
use Shipard\Core\Document\ValidationResult;

/**
 * Base class for all document types (issued invoice, received invoice, …).
 *
 * In Phase 1 contains only minimal logic — concrete calculations land in Phase 2.
 *
 * The polymorphism: docs_core_heads has `doc_type` column (enumString) which
 * resolves to a specific subclass via cfgItem docs.core.docTypes (`subclass`
 * attribute). Concrete subclasses live in modules docs.invoicesOut and
 * docs.invoicesIn.
 */
abstract class DocDocument extends Document
{
    /**
     * Validate the document. Subclasses override and call parent::validate().
     */
    public function validate(array &$data): ValidationResult
    {
        $result = new ValidationResult();

        if (empty($data['number_series'])) {
            $result->addError('number_series', 'Číselná řada je povinná', 'required');
        }
        if (empty($data['issue_date'])) {
            $result->addError('issue_date', 'Datum vystavení je povinné', 'required');
        }
        if (empty($data['accounting_date'])) {
            $result->addError('accounting_date', 'Účetní datum je povinné', 'required');
        }

        return $result;
    }

    /**
     * Pre-save hook. In Phase 1 only handles new draft init; Phase 2 adds
     * defaults, calculations, snapshots, number assignment, etc.
     */
    public function beforeSave(array &$data): void
    {
        $isNew = empty($data['id']);

        // Denormalize doc_type from number_series — invariant
        if (!empty($data['number_series']) && $this->db !== null) {
            $row = $this->db->fetch(
                'SELECT doc_type FROM docs_core_number_series WHERE id = %i',
                (int) $data['number_series'],
            );
            if ($row !== null) {
                $data['doc_type'] = $row['doc_type'];
            }
        }

        // For new drafts: init doc_number as placeholder. Real number
        // is assigned in Phase 2 on Concept → Confirmed transition.
        if ($isNew) {
            // We do not know id yet — placeholder will be re-set in afterSave
            // (or we use a temporary value that gets overwritten on insert
            // and updated post-insert; the framework convention is to use
            // afterSave for ID-dependent values).
            // For Phase 1 simplicity: leave doc_number empty here, the
            // wrapper layer (TableGateway / CrudController) will insert the
            // row, then we patch doc_number with the assigned id.
            //
            // SEE: afterPersist hook below.
        }

        // Phase 2 will add here:
        //   - accounting_date / vat_duzp defaults from issue_date
        //   - fiscal_year/month resolution
        //   - vat_period resolution
        //   - row calculations (price, vat)
        //   - vat recapitulation build
        //   - totals sum
        //   - snapshots
        //   - on Concept → Confirmed: assignDocumentNumber
    }

    /**
     * Called by the framework after the row has been persisted (so $data['id']
     * is known). Patches doc_number for new drafts.
     *
     * NOTE: Adjust the actual hook name to whatever Document base class
     * supports. If there's no afterPersist hook, do this work via a small
     * SQL UPDATE in a separate code path — see existing modules for the
     * pattern.
     */
    public function afterPersist(array $data): void
    {
        if (empty($data['doc_number']) && !empty($data['id']) && $this->db !== null) {
            $placeholder = '!' . str_pad((string) $data['id'], 10, '0', STR_PAD_LEFT);
            $this->db->query(
                'UPDATE docs_core_heads SET doc_number = %s WHERE id = %i AND (doc_number IS NULL OR doc_number = %s)',
                $placeholder,
                (int) $data['id'],
                '',
            );
        }
    }

    // ── Phase 2 stub methods ────────────────────────────────────────────────
    //
    // The following methods are stubs — they exist so that subclasses can
    // already reference them, but do nothing in Phase 1. Phase 2 fills them in.

    protected function calculateRowPrice(array &$row): void
    {
        // Phase 2
    }

    protected function calculateRowVat(array &$row, int $vatMode): void
    {
        // Phase 2
    }

    /** @return array<int, array<string, mixed>> */
    protected function buildVatRecapitulation(array &$data): array
    {
        // Phase 2
        return [];
    }

    protected function sumTotals(array &$data, array $recap): void
    {
        // Phase 2
    }

    protected function applyRounding(float $amount, int $mode): float
    {
        // Phase 2
        return $amount;
    }

    protected function maintainSnapshots(array &$data, ?array $originalData): void
    {
        // Phase 2
    }

    protected function assignDocumentNumber(array &$data): void
    {
        // Phase 2
    }

    protected function resolveFiscalYearId(string $accountingDate): ?int
    {
        // Phase 2
        return null;
    }

    protected function resolveFiscalMonthId(string $accountingDate): ?int
    {
        // Phase 2
        return null;
    }

    protected function resolveVatPeriodId(string $vatDuzp, ?int $vatRegistrationId): ?int
    {
        // Phase 2
        return null;
    }
}
```

**Pozn k `afterPersist`:** Pokud Document base class v projektu zatím nepodporuje
hook po insertu (kde už víme ID), zvol jednu z těchto cest:
1. **Doplň hook do framework** — preferované, je to malá změna a Fáze 2 to
   stejně využije
2. **Použij `beforeSave` + flag**: po prvním uložení v `afterSave` (pokud
   existuje) updatuj doc_number; jinak v separátní SQL UPDATE volaný z
   `CrudController` po insertu
3. **Provizorní řešení**: nech `doc_number` v `beforeSave` jako `'!_NEW_'`
   a v separátním job (např. nightly) nebo v explicitním fixup commandu
   přepiš na `'!{id_padded}'`. Tato cesta není doporučená pro produkci.

Doporučení: zkontroluj `Shipard\Core\Document\Document` base class — pravděpodobně
existuje hook jako `afterSave(array $data, bool $isNew): void` nebo podobně.
Pokud ne, přidej ho — je to drobná změna a Fáze 2 z ní těží.

### `src/NumberSeriesDocument.php`

```php
<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\Core;

use Shipard\Core\Document\Document;
use Shipard\Core\Document\ValidationResult;

class NumberSeriesDocument extends Document
{
    public function validate(array &$data): ValidationResult
    {
        $result = new ValidationResult();

        if (empty($data['name'])) {
            $result->addError('name', 'Název řady je povinný', 'required');
        }
        if (empty($data['doc_type'])) {
            $result->addError('doc_type', 'Typ dokladu je povinný', 'required');
        }
        if (empty($data['doc_number_pattern'])) {
            $result->addError('doc_number_pattern', 'Vzorec čísla dokladu je povinný', 'required');
        }

        // Validate %C placeholder: if pattern contains %C, doc_number_code
        // must be non-empty (otherwise the resolved doc_number would be
        // missing the series identifier).
        $pattern = (string) ($data['doc_number_pattern'] ?? '');
        if (str_contains($pattern, '%C') && empty($data['doc_number_code'])) {
            $result->addError(
                'doc_number_code',
                'Vzorec obsahuje %C — kód řady je povinný',
                'required_for_pattern',
            );
        }

        // Validate pattern uses only known placeholders
        if ($pattern !== '' && preg_match_all('/%([A-Za-z0-9])/', $pattern, $matches)) {
            $known = ['D', 'C', 'y', 'Y', '3', '4', '5', '6'];
            foreach ($matches[1] as $placeholder) {
                if (!in_array($placeholder, $known, true)) {
                    $result->addError(
                        'doc_number_pattern',
                        "Neznámý placeholder %{$placeholder}",
                        'unknown_placeholder',
                    );
                    break;
                }
            }
        }

        // Validate reset_scope
        if (!empty($data['reset_scope'])
            && !in_array($data['reset_scope'], ['none', 'fiscal_year'], true)) {
            $result->addError('reset_scope', 'Neplatný typ restartu', 'invalid_value');
        }

        // Validate valid_from <= valid_to
        if (!empty($data['valid_from']) && !empty($data['valid_to'])
            && (string) $data['valid_from'] > (string) $data['valid_to']) {
            $result->addError(
                'valid_to',
                'Konec platnosti musí být později než začátek',
                'invalid_range',
            );
        }

        return $result;
    }
}
```

### `src/NumberSeriesForm.php`

PHP TableForm s recalculate na změnu `doc_type` — doplní default
`doc_number_pattern` z cfgItem.

```php
<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\Core;

use Shipard\Core\Form\FormDefinition;
use Shipard\Core\Form\RecalculateResult;
use Shipard\Core\Form\TableForm;

class NumberSeriesForm extends TableForm
{
    public function buildFormDefinition(array $data, bool $isNew): FormDefinition
    {
        $docTypeOptions   = $this->resolveDocTypeOptions();
        $resetScopeOptions = $this->resolveResetScopeOptions();

        // Pattern is read-only on existing series (changing it mid-flight
        // would corrupt continuity); for new it can be edited.
        $patternReadOnly = !$isNew;

        $basic = $this->tab('basic', 'Základní údaje')
            ->addInput('name', cols: 2, required: true)
            ->addSelect('doc_type', cols: 1, options: $docTypeOptions,
                triggers: 'reload', required: true, readOnly: !$isNew)
            ->addInput('doc_number_code', cols: 1)
            ->addInput('doc_number_pattern', cols: 2, required: true,
                readOnly: $patternReadOnly)
            ->addSelect('reset_scope', cols: 1, options: $resetScopeOptions,
                required: true)
            ->addSeparator('Platnost')
            ->addDate('valid_from', cols: 1)
            ->addDate('valid_to', cols: 1)
            ->addSeparator('Poznámka')
            ->addTextArea('notice', cols: 4, rows: 3)
            ->build();

        return new FormDefinition(
            table: $this->table,
            title: 'Číselná řada',
            titleNew: 'Nová číselná řada',
            tabs: [$basic, $this->attachmentsTab()],
            fullSize: false,
        );
    }

    public function recalculate(string $changedColumn, array $data): RecalculateResult
    {
        // When user picks doc_type, fill default doc_number_pattern from cfgItem
        if ($changedColumn === 'doc_type' && !empty($data['doc_type']) && empty($data['doc_number_pattern'])) {
            $docTypes = $this->config?->cfgItem('docs.core.docTypes');
            if (is_array($docTypes) && isset($docTypes[$data['doc_type']]['doc_number_pattern_default'])) {
                $data['doc_number_pattern'] = $docTypes[$data['doc_type']]['doc_number_pattern_default'];
            }
        }

        // When user picks doc_type and name is empty, suggest a name
        if ($changedColumn === 'doc_type' && !empty($data['doc_type']) && empty($data['name'])) {
            $docTypes = $this->config?->cfgItem('docs.core.docTypes');
            if (is_array($docTypes) && isset($docTypes[$data['doc_type']]['name:cs'])) {
                $data['name'] = $docTypes[$data['doc_type']]['name:cs'];
            }
        }

        $formDefinition = $this->buildFormDefinition($data, empty($data['id']));
        return new RecalculateResult($formDefinition, $data);
    }

    /** @return list<array{value: string, label: string}> */
    private function resolveDocTypeOptions(): array
    {
        if ($this->config === null) {
            return [];
        }
        $cfg = $this->config->cfgItem('docs.core.docTypes');
        if (!is_array($cfg)) {
            return [];
        }
        $options = [];
        foreach ($cfg as $key => $entry) {
            if (is_array($entry) && isset($entry['name'])) {
                $options[] = ['value' => (string) $key, 'label' => $entry['name']];
            }
        }
        return $options;
    }

    /** @return list<array{value: string, label: string}> */
    private function resolveResetScopeOptions(): array
    {
        if ($this->config === null) {
            return [];
        }
        $cfg = $this->config->cfgItem('docs.core.resetScopes');
        if (!is_array($cfg)) {
            return [];
        }
        $options = [];
        foreach ($cfg as $key => $entry) {
            if (is_array($entry) && isset($entry['name'])) {
                $options[] = ['value' => (string) $key, 'label' => $entry['name']];
            }
        }
        return $options;
    }
}
```

### `src/NumberSeriesViewer.php`

Standardní viewer, podobný `economy/codebooks/CashDesksViewer`. Filtrování
přes `viewGroup` (active/archive/trash), search přes `name`/`doc_number_code`,
sloupce: `name`, label `doc_type` z cfgItem, `doc_number_pattern`, badge
stavu. ORDER BY `docStateMain ASC, doc_type ASC, name ASC`.

Vzor: `modules/economy/codebooks/src/CashDesksViewer.php` (pokud takový
existuje) nebo `modules/economy/codebooks/src/FiscalYearsViewer.php`.

### `src/NumberSeriesProvisioner.php`

```php
<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\Core;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;

/**
 * Idempotentní seed číselných řad — zajistí, že existuje aspoň jedna řada
 * pro každý typ dokladu z cfgItem docs.core.docTypes.
 */
class NumberSeriesProvisioner
{
    public function __construct(
        private readonly DataSourceConnection $db,
        private readonly ConfigRuntime $config,
    ) {}

    /**
     * @return array{numberSeries: array{created: int, existing: int}}
     */
    public function provision(): array
    {
        $docTypes = $this->config->cfgItem('docs.core.docTypes');
        if (!is_array($docTypes)) {
            return ['numberSeries' => ['created' => 0, 'existing' => 0]];
        }

        $created = 0;
        $existing = 0;

        foreach ($docTypes as $docTypeKey => $docType) {
            // Idempotence: skip if any non-deleted series exists for this type
            $row = $this->db->fetch(
                'SELECT id FROM docs_core_number_series
                 WHERE doc_type = %s AND docState != %i
                 LIMIT 1',
                $docTypeKey,
                90,
            );
            if ($row !== null) {
                $existing++;
                continue;
            }

            $name = $docType['name:cs'] ?? $docType['name'] ?? $docTypeKey;
            $pattern = $docType['doc_number_pattern_default'] ?? '%D%y%4';

            $this->db->insertRow('docs_core_number_series', [
                'doc_type'           => $docTypeKey,
                'name'               => $name,
                'doc_number_code'    => null,
                'doc_number_pattern' => $pattern,
                'reset_scope'        => 'fiscal_year',
                'docState'           => 40,
                'docStateMain'       => 3,
            ]);
            $created++;
        }

        return ['numberSeries' => ['created' => $created, 'existing' => $existing]];
    }
}
```

### `src/OwnCompanyResolver.php`

```php
<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\Core;

use Shipard\Core\Database\DataSourceConnection;

/**
 * Helper for looking up the "own company" — the base_persons_persons row
 * with is_own = 1. Used by document snapshot building (Phase 2) and validation
 * checks at Confirm transition.
 */
final class OwnCompanyResolver
{
    public function __construct(
        private readonly DataSourceConnection $db,
    ) {}

    /**
     * @return int|null  ID of the own person, or null if none configured
     */
    public function getOwnPersonId(): ?int
    {
        $row = $this->db->fetch(
            'SELECT id FROM base_persons_persons
             WHERE is_own = 1 AND docState IN (%i, %i, %i)
             LIMIT 1',
            10, 40, 80,
        );
        return $row !== null ? (int) $row['id'] : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getOwnPersonData(): ?array
    {
        $id = $this->getOwnPersonId();
        if ($id === null) {
            return null;
        }
        return $this->db->fetch(
            'SELECT * FROM base_persons_persons WHERE id = %i',
            $id,
        );
    }

    /**
     * Find the headquarters address (address_type = 1) of the own person.
     * Returns null if no own person or no HQ address.
     *
     * @return array<string, mixed>|null
     */
    public function getOwnHeadquartersAddress(): ?array
    {
        $personId = $this->getOwnPersonId();
        if ($personId === null) {
            return null;
        }
        return $this->db->fetch(
            'SELECT * FROM base_persons_addresses
             WHERE person = %i AND address_type = %i
             LIMIT 1',
            $personId,
            1,
        );
    }
}
```

## Hook do `DsUpgradeCommand`

V `src/Command/DataSource/DsUpgradeCommand.php`:

1. Přidej `use` pro `NumberSeriesProvisioner`:
   ```php
   use Shipard\Module\Docs\Core\NumberSeriesProvisioner;
   ```

2. V metodě `execute` za `provisionVatPeriods` (nebo na vhodné místo —
   musí být po `provisionFiscalYears`, protože by v budoucnu řady mohly
   referencovat fiskální rok):
   ```php
   $this->provisionDocCoreNumberSeries($resolvedModules, $dsDir, $dsConnection, $output);
   ```

3. Přidej privátní metodu (vzor: `provisionVatPeriods` nebo `provisionFiscalYears`):
   ```php
   /**
    * @param list<\Shipard\Core\Module\ModuleDefinition> $resolvedModules
    */
   private function provisionDocCoreNumberSeries(
       array $resolvedModules,
       string $dsDir,
       DataSourceConnection $dsConnection,
       OutputInterface $output,
   ): void {
       $output->writeln('');
       $output->writeln('Provisioning docs.core number series...');

       if (!$this->isModuleActive($resolvedModules, 'docs.core')) {
           $output->writeln('  <comment>[SKIP] docs.core module not active</comment>');
           return;
       }

       $compiledFile = $dsDir . '/config/configuration/compiled.cs.json';
       if (!is_file($compiledFile)) {
           $output->writeln('  <comment>[SKIP] config not compiled yet</comment>');
           return;
       }

       $config = ConfigRuntime::load($dsDir, 'cs');
       $provisioner = new NumberSeriesProvisioner($dsConnection, $config);
       $result = $provisioner->provision();

       $series = $result['numberSeries'];
       $output->writeln(sprintf(
           '  [OK]    number series — created: %d, existing: %d',
           $series['created'],
           $series['existing'],
       ));
   }
   ```

## Aktualizace `install.base/module.jsonc`

Do `dependencies` přidat `"docs.core"` (na vhodné místo, pravděpodobně za
`economy.items`).

## Aktualizace frontend ikon

Pokud `hash` ikona ještě v `iconMap` neexistuje, v `frontend/src/icons.js`:

```js
import {
  // ... existing imports ...
  faHashtag,
} from '@fortawesome/free-solid-svg-icons';

export const iconHash = faHashtag;
```

A do `iconMap`:
```js
'hash': iconHash,
```

Spustit `npm run build` v `frontend/`.

## README modulu

`modules/docs/core/README.md`:

```markdown
# Modul: docs.core

Polymorfní jádro dokladového systému — společné tabulky, číselné řady,
stavový model, konfigurační cfgItem soubory pro všechny typy dokladů.

## Účel

Drží **5 univerzálních tabulek** sdílených všemi typy dokladů (faktura
vydaná, faktura přijatá, …):

- `docs_core_heads` — hlavička dokladu (~40 sloupců, podle typu se používají
  různé)
- `docs_core_rows` — řádky dokladu
- `docs_core_vat_recap` — rekapitulace DPH (sestavovaná v `beforeSave` hlavičky)
- `docs_core_number_series` — číselné řady dokladů
- `docs_core_number_counters` — atomické countery pro generování čísla
  dokladu

Konkrétní typy dokladů žijí v navazujících modulech:

- `docs.invoicesOut` — faktura vydaná (Document subclass + viewer)
- `docs.invoicesIn` — faktura přijatá

Polymorfismus: hlavička má `doc_type` (enumString), který určuje konkrétní
Document třídu přes cfgItem `docs.core.docTypes`.

## Stav (současná fáze)

V této fázi je implementovaná **kostra**:

- ✅ 5 tabulek + 10 cfgItem souborů
- ✅ Číselné řady — kompletní CRUD (Document + Form + Viewer + Provisioner)
- ✅ `OwnCompanyResolver` helper
- ✅ `DocDocument` abstract base s minimální logikou (init doc_number,
  denormalizace doc_type)
- ⏳ **Výpočty cen, DPH, rekapitulace, snapshoty** — Fáze 2
- ⏳ **Formulář dokladu** (DocsHeadsForm s tabovanou hlavičkou + řádky +
  rekapitulace) — Fáze 3
- ⏳ **Per-typ viewers a Document subclasses** — modul docs.invoicesOut/In

V této fázi lze uložit jen **prázdný Koncept dokladu** přes přímé volání
API (žádné UI). To stačí jako sanity check, že schéma a Document
infrastructure funguje.

## Konfigurace

Modul registruje 10 cfgItem souborů — viz `config/`:

- `docs.core.docTypes` — typy dokladů (zatím `invno`, `invni`)
- `docs.core.docStates` — stavový automat (Koncept → Potvrzeno → V pořádku
  → V opravě → Storno → Smazáno)
- `docs.core.vatModes` — režim DPH na hlavičce
- `docs.core.vatCalcSources` — odkud počítat DPH
- `docs.core.vatPlaces` — místo plnění
- `docs.core.priceCalcModes` — způsob výpočtu ceny na řádku
- `docs.core.rowKinds` — typ řádku (text / běžný)
- `docs.core.roundingModes` — zaokrouhlení
- `docs.core.paymentMethods` — způsoby platby
- `docs.core.resetScopes` — kdy se restartuje counter řady

## Závislosti

```
docs.core
├── core.system
├── core.units
├── core.attachments
├── base.persons (vyžaduje is_own + court_registration)
├── world.base
├── world.vat (vyžaduje vat-cz.jsonc)
├── economy.codebooks (fiscal_years, vat_periods, …)
└── economy.items (katalog položek)
```

## Číselné řady

Číselná řada je samostatný číselník (`docs_core_number_series`) editovatelný
přes UI v sekci Settings → Účtování. Každý typ dokladu má aspoň jednu řadu;
provisioner volaný z `ds-upgrade` automaticky založí default řadu pro každý
typ z cfgItem `docs.core.docTypes`.

Vzorec čísla dokladu používá `%X` placeholdery — viz cfgItem `docs.core.docTypes`
pro default vzorce a `docs/docs-mvp.md` sekce 5.4 pro popis placeholderů.

## Stavový model

Doklady mají vlastní rozšířenou sadu stavů (cfgItem `docs.core.docStates`),
**nikoli** standardní `core.system.docStatesArchive`. Klíčové rozdíly:

- **+ 20 Potvrzeno** — doklad má přidělené číslo, ale je stále editovatelný
- **+ 30 Storno** — náhrada za smazání po Potvrzení; zachovává číslo dokladu
  v sekvenci, sdílí `mainState=4` s V pořádku
- **− 70 V archívu** — u dokladů nadbytečné

Detaily v `docs/docs-mvp.md` sekce 3.

## Pro vývojáře

`OwnCompanyResolver` najde záznam vlastní firmy v `base_persons_persons`
(`is_own = 1`). Bez vlastní firmy nelze vystavovat doklady — od Fáze 2 se
to kontroluje při Potvrzení.

`DocDocument` (abstract) v této fázi pouze inicializuje `doc_number` jako
placeholder `!{id_padded}` (10 číslic) a denormalizuje `doc_type` z
`number_series`. Reálné výpočty (cena, DPH, rekapitulace, snapshoty)
přijdou ve Fázi 2.
```

## Dokumentace tabulek (`tables/*.md`)

Pro každou z 5 tabulek vytvoř `.md` soubor s přehledem sloupců po skupinách,
indexů, business pravidel, návazností. Vzor: `modules/base/persons/tables/
base_persons_persons.md` nebo `modules/economy/codebooks/tables/
economy_codebooks_fiscal_years.md`.

Klíčové body, které musí být zdokumentované:

**`docs_core_heads.md`:**
- Polymorfní využití (`doc_type` rozhoduje o subclass)
- 11 skupin sloupců s vysvětlením každé
- `unq_series_seq` UNIQUE pojistka
- `system: true` sloupce vs. uživatelské
- Vazba na `docs_core_rows` a `docs_core_vat_recap` přes child tables
- Snapshot sloupce (JSON) — sestavované ve Fázi 2

**`docs_core_rows.md`:**
- `vat_code` bez fixního cfgItem (důvod: dynamicky odvozený podle státu)
- `row_kind` rozdíl textový vs. běžný
- Sleva: `discount_pct` × `discount_amount` jsou alternativy

**`docs_core_vat_recap.md`:**
- Persistovaná tabulka, sestavovaná v `beforeSave` hlavičky (Fáze 2)
- `is_reverse_pair` flag pro reverse charge páry
- `sum_*` flagy odvozené z vatCode definice

**`docs_core_number_series.md`:**
- Vzorec a placeholdery
- `reset_scope` chování
- Provisioner default

**`docs_core_number_counters.md`:**
- Atomický counter, `FOR UPDATE` lock
- Není doc-state model

## Hotovo když

- [ ] `bin/shpd-ds ds-upgrade` projde bez chyb na čistém i existujícím DS
- [ ] V DB existuje 5 tabulek `docs_core_*` se správnými sloupci a indexy
- [ ] Po prvním upgrade existují 2 default řady v `docs_core_number_series`
      — jedna pro `invno` (FVB) a jedna pro `invni` (FPB), `docState=40`
- [ ] Druhý běh `ds-upgrade` neduplikuje řady (output: `existing: 2, created: 0`)
- [ ] V Settings UI sekci Účtování se objeví "Číselné řady dokladů" s ikonou
- [ ] Lze vytvořit/editovat/archivovat/smazat číselnou řadu přes UI; změna
      `doc_type` ve formě recalculuje default `doc_number_pattern`; změna
      `doc_type` se zablokuje u existujícího záznamu (read-only)
- [ ] Validace v `NumberSeriesDocument`:
  - Chybějící `name`, `doc_type`, `doc_number_pattern` → error required
  - Pattern obsahující `%C` ale prázdný `doc_number_code` → error
    `required_for_pattern`
  - Neznámý placeholder ve vzorci (např. `%X`) → error `unknown_placeholder`
  - `valid_from > valid_to` → error `invalid_range`
- [ ] `OwnCompanyResolver::getOwnPersonId()` vrací ID firmy s `is_own = 1`
      (po manuálním nastavení v UI Osoby), `null` jinak
- [ ] Insert prázdného Konceptu do `docs_core_heads` přes API/CLI projde
      (povinné: `number_series`, `issue_date`, `accounting_date`); záznam
      má po insertu `doc_number = '!0000000123'`, `docState = 10`,
      `doc_type` = denormalizovaný z řady
- [ ] PHPUnit testy pro `NumberSeriesDocument` pokrývají všechny error
      kódy validace
- [ ] PHPUnit testy pro `NumberSeriesProvisioner` ověřují idempotenci
      (vzor: `FiscalYearsProvisionerTest` pokud existuje)
- [ ] `install.base` má `docs.core` v dependencies
- [ ] README + per-tabulka `.md` napsané

## Konvence

- **Jazyk**: UI texty čeština, kód a komentáře angličtina
- **Vícejazyčnost**: každé `name` v JSONC má `:cs` a `:en` variantu
- **PHP 8.3** strict_types, readonly properties kde možné
- **TableId**: 401–405 (volný blok 4xx)
- Po úpravě `module.jsonc`, JSONC tabulky nebo cfgItem volat `bin/shpd-ds ds-upgrade`
- **Composer autoload**: po vytvoření nových adresářů spustit `composer dump-autoload`

## Doporučené pořadí implementace

1. **Modul kostra**: `module.jsonc`, prázdný README, prázdná struktura adresářů
2. **cfgItem soubory** (10 souborů) — všechny v jednom dávce, krátké soubory
3. **5 tabulek JSONC** + per-tabulka `.md` (může být výchozí stub, plná
   dokumentace na konci)
4. **`ds-upgrade`** — ověř, že schéma se vytvořilo (5 tabulek, indexy,
   reference)
5. **`NumberSeriesDocument`** + PHPUnit testy
6. **`NumberSeriesForm`** + ověření UI ve frontendu
7. **`NumberSeriesViewer`** + ověření v Settings → Účtování
8. **`NumberSeriesProvisioner`** + hook do `DsUpgradeCommand`
9. **Druhý `ds-upgrade`** — ověř, že provisioner vytvořil 2 řady a druhý
   běh je no-op
10. **`OwnCompanyResolver`** + případný test
11. **`DocDocument`** abstract base — minimální skeleton
12. **End-to-end test:** ručně přes `bin/shpd-ds query` nebo přes API
    insert prázdného Konceptu do `docs_core_heads` — ověř, že to projde
    a `doc_number` má placeholder
13. **`install.base` aktualizace + frontend ikon**
14. **Dokumentace** — README + `.md` per tabulka

## Otevřené body (ne-blokující)

Některá rozhodnutí přijdou až ve Fázi 2:

- **`afterPersist` / `afterSave` hook v `Document` base** — Fáze 1 ho
  potřebuje pro init `doc_number`. Pokud ještě neexistuje, přidat ho do
  `Shipard\Core\Document\Document` jako součást této fáze.
- **Settings UI — kde přesně viewer sedí** — `settingsItems` registrace
  do sekce "accounting" jak je navrženo, případně do nové sekce "documents".
  Pokud existuje rozumná konvence z předchozích modulů, drž se jí.
- **`displayPattern` pro `docs_core_heads`** — `"{doc_number} — {doc_text}"`.
  Pro Koncept je `doc_number = '!0000000123'`, což je trochu ošklivé. Pro
  MVP to stačí, případnou kosmetiku řešíme později (např. zobrazování
  "Koncept #123" pro doc_number začínající `!`).
- **Default `viewerDetailLabels`** — Fáze 5 přidá tento cfgItem až bude
  potřeba lokalizovat detail tabů ve vieweru. Pro Fázi 1 nepotřebujeme.

## Vztah k navazujícím fázím

Po dokončení této fáze:

- **Fáze 4 (`docs-core-phase2.md`)** — naplní stub metody v `DocDocument`:
  výpočty cen, DPH, rekapitulace, snapshoty, atomické přidělení čísla
  dokladu při Koncept → Potvrzeno
- **Fáze 5 (`docs-core-phase3.md`)** — `DocsHeadsForm` (tabovaný formulář
  hlavičky + řádků + rekapitulace), frontend rozšíření pro dynamický
  VAT code select
- **Fáze 6 (`docs-invoices.md`)** — moduly `docs.invoicesOut` a
  `docs.invoicesIn` s konkrétními Document subclasses a per-typ viewers
  s tabovaným spodním panelem pro číselné řady
