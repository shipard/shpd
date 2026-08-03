# Task: `docs.core` — Fáze 3: Formulář a viewer dokladu

**Stav:** hotovo

## Kontext

Pokračujeme z **Fáze 2** (`docs-core-phase2.md` — hotovo). Doklady jsou
funkční na úrovni backendu — výpočty cen a DPH, rekapitulace včetně
reverse charge, atomické přidělení čísla, snapshoty. V této fázi přidáme
**uživatelské rozhraní**: formulář pro hlavičku + řádky, viewer dokladů,
a frontend rozšíření pro dynamický VAT code select.

Po dokončení této fáze platí, že uživatel může v UI:
- Otevřít viewer "Doklady" v hlavní navigaci
- Vytvořit nový doklad přes "Přidat"
- Vyplnit hlavičku, přidat řádky s položkami a sazbami DPH
- Potvrdit doklad (přidělí číslo, sestaví snapshoty, zobrazí rekapitulaci)
- Upravit existující doklad
- Stornovat / smazat
- Vidět rekapitulaci DPH a snapshoty fakturačních údajů

**Co tato fáze NEdělá:** per-typ viewers (vydané/přijaté faktury jako
samostatné položky v menu se spodními taby pro číselné řady) — to je
Fáze 6 (`docs-invoices.md`). V Fázi 5 je jen **jeden univerzální viewer**
"Doklady" filtrující všechny typy.

Před implementací **přečti**:

- `docs/edit-forms.md` — kompletní přehled formulářového systému,
  zejména:
  - Sekce 4 — elementy formuláře (input, select, separator, group,
    subtable, html)
  - Sekce 7 — recalculate hook
  - Sekce 11 — TabBuilder API (`addInput`, `addSelect`, `addDate`,
    `addNumber`, `addCheckbox`, `addTextArea`, `addSeparator`,
    `addSubtable`, `addHtml`)
  - Sekce 15 — sub-tabulky a omezení pro nové záznamy
- `docs/docs-mvp.md` — sekce 6.1 (skupiny sloupců hlavičky), sekce 7
  (řádky), sekce 8 (rekapitulace)
- `docs/frontend.md` — viewer systém, ikony, API komunikace
- `tasks/docs-core-phase2.md` (hotovo) — referenční stav po Fázi 4

Vzorové existující soubory:

- `modules/economy/items/src/ItemsForm.php` — vzor PHP `TableForm` s tab,
  separator, recalculate
- `modules/economy/items/src/ItemsViewer.php` — vzor `TableViewer` s
  filtrováním podle viewGroup, search, JOIN, render row, render detail
- `modules/base/persons/src/PersonsForm.php` — vzor s subtable taby
- `modules/docs/core/src/DocDocument.php` — beforeSave logika z Fáze 4

## Cíl Fáze 5

Po dokončení této fáze platí:

- Vznikla třída `DocsHeadsForm` (PHP `TableForm`) s tabovaným formulářem:
  Hlavička / Řádky / Rekapitulace / Snapshoty (jen ≥ Potvrzeno) / Přílohy /
  Poznámky
- Vznikla třída `DocRowsForm` (PHP `TableForm`) jako sub-form pro řádky
  — s recalculate logikou pro výběr položky a VAT kódu
- Sub-form pro řádek dynamicky filtruje VAT codes podle země z
  `vat_registration`, směru z `doc_type`, místa plnění z `vat_place`
  hlavičky
- Vznikla třída `DocsHeadsViewer` (PHP `TableViewer`) — generický viewer
  všech dokladů s filtrem podle viewGroup (active/cancelled/trash)
- Doklady se objeví v hlavní navigaci jako "Doklady" (samostatná položka
  vedle Osob, Položek atd.)
- Recalculate hooky v `DocsHeadsForm`:
  - Změna `partner` → `partner_address` default + `due_date` z
    `payment_term_days`
  - Změna `issue_date` → `accounting_date`/`vat_duzp` default
  - Změna `vat_registration` → reload (kvůli VAT codes na řádcích)
  - Změna `vat_place` → reload (kvůli VAT codes filtraci)
  - Změna `vat_mode` → reload (kvůli zobrazení/skrytí VAT polí)
  - Změna `doc_currency` → reload (kvůli `exchange_rate` viditelnosti)
- Recalculate hooky v `DocRowsForm`:
  - Změna `item` → fill `description`, `unit_price`, `unit` z položky
  - Změna `vat_code` → resolve `vat_pct` z `world.vat.{country}` podle
    `vat_duzp` z hlavičky
  - Změna `quantity`/`unit_price`/`total_price` → reload (server
    přepočítá zbývající)
- Rekapitulace DPH se zobrazuje jako pre-renderovaná HTML tabulka v
  Tab Rekapitulace (přes `addHtml`)
- Snapshot tab zobrazuje 2 sloupce (dodavatel + odběratel) s adresou,
  IČ, DIČ, court_registration, bankou, vat registrací — pre-renderované
  HTML
- E2E flow přes UI: Vytvoření → Vyplnění → Potvrzení → Storno funguje

## Návaznost

- Závisí na: Fáze 4 (`docs-core-phase2.md` — hotovo)
- Otevírá: Fáze 6 (`docs-invoices.md`) — per-typ viewers s tab bar
  číselných řad

## Scope

### V rozsahu

- `DocsHeadsForm.php` (kompletní) + recalculate
- `DocRowsForm.php` (kompletní) + recalculate
- `DocsHeadsViewer.php` (generický)
- HTML rendering helpery pro rekapitulaci a snapshoty
- Aktualizace `module.jsonc` — registrace formů, vieweru, hlavní
  navigace
- Frontend ikona pro `docs.core.heads` viewer (např. `file-invoice` nebo
  `receipt`)
- PHPUnit testy pro PHP třídy (form definition shape, recalculate logic,
  HTML rendering)

### Mimo rozsah (řeší Fáze 6)

- Per-typ viewers `docs.invoicesOut.heads` a `docs.invoicesIn.heads`
- Spodní tab bar s číselnými řadami v per-typ viewerech (jako na
  screenshotu z designu)
- `IssuedInvoiceDocument` a `ReceivedInvoiceDocument` subclasses
- Polymorfní dispatch podle `doc_type`

### Mimo rozsah (řeší později)

- PDF výstup
- Custom interactivity ve snapshot/recap tabech (drill-down, edit)
- Kontextové `getAvailableTransitions(int, array $context)` —
  Fáze 4 to neřešila, Fáze 5 také ne. UI tlačítko "Vrátit na Koncept"
  se prostě ukáže vždy ze stavu Potvrzeno; server vrátí 422 pokud
  doklad není poslední v řadě, frontend chybu zobrazí. Pokud se ukáže
  jako problém, vyřeší se v dedikovaném tasku.

## Architektonická rozhodnutí

### HTML widgety vs. dedikované element types

Pro **rekapitulaci DPH** a **snapshoty fakturačních údajů** používáme
existující `addHtml(content, cols)` element. Server pre-renderuje HTML
podle dat (recap rows, snapshot JSONy) a klient ho jen vykreslí.

Důvod: jednodušší implementace, nevyžaduje úpravy `FormElement`
validátoru ani frontend rendereru. Pokud se ukáže potřeba interaktivity
(např. úprava řádku rekapitulace), navazující úkol zavede dedikovaný
element type. Pro MVP `addHtml` stačí.

### Sub-form pro řádky a sdílení kontextu hlavičky

Sub-form `DocRowsForm` je **samostatný formulář** otevřený jako modal
nad rodičovským formulářem. Podle current `edit-forms` architektury sub-form
nemá přímý přístup k datům hlavičky.

**Řešení:** sub-form si v `buildFormDefinition` načte hlavičku z DB podle
`doc_head` FK (které je v `data` jako foreign key). Z hlavičky vytáhne
`vat_registration`, `doc_type`, `vat_place`, `vat_duzp` — a podle nich
sestaví options pro `vat_code` select a defaults pro nové řádky.

```php
public function buildFormDefinition(array $data, bool $isNew): FormDefinition
{
    $docHeadId = $data['doc_head'] ?? null;
    $headContext = $this->loadHeadContext($docHeadId);  // [country, direction, place, vat_duzp, vat_mode]
    
    $vatCodeOptions = $this->buildVatCodeOptions($headContext);
    // ... use options in addSelect for vat_code
}
```

To znamená, že sub-form pro řádky je **závislý na existenci hlavičky** —
což je v pořádku, protože nový řádek nelze přidat dokud rodičovský doklad
není uložen (subtable disabled tab pro nové záznamy, viz `docs/edit-forms.md`
sekce 15).

### Kdy zobrazovat Tab Snapshoty

Tab Snapshoty se zobrazuje **podmíněně** — jen pokud `data['supplier_snapshot']`
nebo `data['customer_snapshot']` jsou neprázdné. To znamená:
- Pro nový doklad (Koncept): tab není
- Po Potvrzení (snapshot vyplněn): tab se objeví
- Po vrácení 20 → 10 (snapshot vyčištěn): tab zmizí

Implementace v `DocsHeadsForm::buildFormDefinition`:

```php
$tabs = [$basicTab, $rowsTab, $recapTab];
if (!empty($data['supplier_snapshot']) || !empty($data['customer_snapshot'])) {
    $tabs[] = $snapshotsTab;
}
$tabs[] = $notesTab;
$tabs[] = $this->attachmentsTab();
```

### Kdy zobrazovat Tab Rekapitulace

Tab Rekapitulace se zobrazuje **vždy**, ale pro Koncept bez řádků je
prázdný (zpráva "Doklad nemá žádné řádky"). Po přidání řádků se zobrazí
sestavená tabulka.

## Implementace

### `modules/docs/core/src/DocsHeadsForm.php`

Hlavní formulář. Velmi rozsáhlý — Tab Hlavička má cca 30 polí v 8
sekcích. Pro přehlednost rozděl `buildFormDefinition` do helper metod
per-sekce.

```php
<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\Core;

use Shipard\Core\Form\FormDefinition;
use Shipard\Core\Form\FormTab;
use Shipard\Core\Form\RecalculateResult;
use Shipard\Core\Form\TableForm;

class DocsHeadsForm extends TableForm
{
    public function buildFormDefinition(array $data, bool $isNew): FormDefinition
    {
        $this->applyClientDefaults($data, $isNew);

        $tabs = [
            $this->buildHeaderTab($data, $isNew),
            $this->buildRowsTab($data),
            $this->buildRecapTab($data),
        ];

        if (!empty($data['supplier_snapshot']) || !empty($data['customer_snapshot'])) {
            $tabs[] = $this->buildSnapshotsTab($data);
        }

        $tabs[] = $this->buildNotesTab($data);
        $tabs[] = $this->attachmentsTab();

        return new FormDefinition(
            table: $this->table,
            title: 'Doklad',
            titleNew: 'Nový doklad',
            tabs: $tabs,
            fullSize: true,
        );
    }

    /**
     * Apply client-side defaults that don't require server lookup
     * (server-side defaults — fiscal_year, vat_period, snapshots — happen
     * in DocDocument::beforeSave).
     */
    private function applyClientDefaults(array &$data, bool $isNew): void
    {
        if ($isNew && empty($data['issue_date'])) {
            $data['issue_date'] = date('Y-m-d');
        }
        if ($isNew && empty($data['vat_mode'])) {
            $data['vat_mode'] = 1;  // From base
        }
        if ($isNew && empty($data['vat_calc_source'])) {
            $data['vat_calc_source'] = 0;  // From header
        }
        if ($isNew && empty($data['vat_place'])) {
            $data['vat_place'] = 0;  // Domestic
        }
        if ($isNew && empty($data['payment_method'])) {
            $data['payment_method'] = 1;  // Bank transfer
        }
        if ($isNew && empty($data['total_rounding_mode'])) {
            $data['total_rounding_mode'] = 1;  // Whole units
        }
        if ($isNew && empty($data['vat_rounding_mode'])) {
            $data['vat_rounding_mode'] = 2;  // 0.01
        }
    }

    private function buildHeaderTab(array $data, bool $isNew): FormTab
    {
        $hasForeignCurrency = !empty($data['doc_currency'])
            && !empty($data['home_currency'])
            && $data['doc_currency'] !== $data['home_currency'];
        $vatMode = (int) ($data['vat_mode'] ?? 1);
        $hasVat = $vatMode !== 0;

        return $this->tab('basic', 'Hlavička')
            // ── Identifikace ─────────────────────────────────────────
            ->addSeparator('Identifikace')
            ->addSelect('number_series', cols: 2,
                options: $this->resolveNumberSeriesOptions(),
                required: true,
                readOnly: !$isNew,  // cannot change series after first save
            )
            ->addInput('doc_number', cols: 1, readOnly: true)
            ->addInput('doc_text', cols: 1)

            // ── Partner ──────────────────────────────────────────────
            ->addSeparator('Partner')
            ->addSelect('partner', cols: 2,
                options: $this->resolvePartnerOptions(),
                required: false,  // required at Confirm time, validated in Document
                triggers: 'reload',
            )
            ->addSelect('partner_address', cols: 2,
                options: $this->resolvePartnerAddressOptions((int) ($data['partner'] ?? 0)),
            )
            ->addSelect('partner_bank', cols: 1,
                options: $this->resolvePartnerBankOptions((int) ($data['partner'] ?? 0)),
                hint: 'Vyberete-li účet partnera, údaje níže se vyplní automaticky',
            )
            ->addInput('partner_bank_account', cols: 1, label: 'Číslo účtu')
            ->addInput('partner_bank_iban', cols: 1, label: 'IBAN')
            ->addInput('partner_bank_bic', cols: 1, label: 'BIC/SWIFT')

            // ── Datumy ───────────────────────────────────────────────
            ->addSeparator('Datumy')
            ->addDate('issue_date', cols: 1, required: true, triggers: 'reload')
            ->addDate('due_date', cols: 1)
            ->addDate('accounting_date', cols: 1, required: true)
            ->addDate('vat_duzp', cols: 1, hidden: !$hasVat)
            ->addDate('vat_dppd', cols: 1, hidden: !$hasVat)
            ->addDate('period_from', cols: 1, hint: 'Volitelné, např. pronájem za období')
            ->addDate('period_to', cols: 1)

            // ── DPH ──────────────────────────────────────────────────
            ->addSeparator('DPH')
            ->addSelect('vat_mode', cols: 1, triggers: 'reload')
            ->addSelect('vat_calc_source', cols: 1, hidden: !$hasVat)
            ->addSelect('vat_place', cols: 1, triggers: 'reload', hidden: !$hasVat)
            ->addSelect('vat_registration', cols: 1,
                options: $this->resolveVatRegistrationOptions(),
                triggers: 'reload',
                hidden: !$hasVat,
            )

            // ── Měna a kurz ──────────────────────────────────────────
            ->addSeparator('Měna')
            ->addSelect('doc_currency', cols: 1, triggers: 'reload')
            ->addInput('home_currency', cols: 1, readOnly: true)
            ->addNumber('exchange_rate', cols: 1, hidden: !$hasForeignCurrency)

            // ── Zaokrouhlení ─────────────────────────────────────────
            ->addSeparator('Zaokrouhlení')
            ->addSelect('total_rounding_mode', cols: 1)
            ->addSelect('vat_rounding_mode', cols: 1, hidden: !$hasVat)

            // ── Platba ───────────────────────────────────────────────
            ->addSeparator('Platba')
            ->addSelect('payment_method', cols: 1)
            ->addSelect('bank_account', cols: 1,
                options: $this->resolveBankAccountOptions(
                    (string) ($data['doc_currency'] ?? 'czk'),
                ),
                hint: 'Náš účet, na který má partner zaplatit',
            )
            ->addInput('variable_symbol', cols: 1)
            ->addInput('specific_symbol', cols: 1)
            ->addInput('constant_symbol', cols: 1)

            // ── Součty (read-only, naplněné v beforeSave) ───────────
            ->addSeparator('Součty')
            ->addNumber('total_base', cols: 1, readOnly: true, label: 'Základ DPH')
            ->addNumber('total_vat', cols: 1, readOnly: true, label: 'DPH')
            ->addNumber('total_amount', cols: 1, readOnly: true, label: 'Celkem')
            ->addNumber('total_rounding', cols: 1, readOnly: true, label: 'Zaokrouhlení')

            ->build();
    }

    private function buildRowsTab(array $data): FormTab
    {
        return $this->tab('rows', 'Řádky')
            ->addSubtable(
                table: 'docs_core_rows',
                foreignKey: 'doc_head',
                formId: 'docs.core.rows',
                label: 'Řádky dokladu',
            )
            ->build();
    }

    private function buildRecapTab(array $data): FormTab
    {
        $html = $this->renderRecapHtml($data);
        return $this->tab('recap', 'Rekapitulace DPH')
            ->addHtml($html, cols: 4)
            ->build();
    }

    private function buildSnapshotsTab(array $data): FormTab
    {
        $html = $this->renderSnapshotsHtml($data);
        return $this->tab('snapshots', 'Fakturační údaje')
            ->addHtml($html, cols: 4)
            ->build();
    }

    private function buildNotesTab(array $data): FormTab
    {
        return $this->tab('notes', 'Poznámky')
            ->addTextArea('notice', cols: 4, label: 'Interní poznámka',
                hint: 'Vidíme jen my, netiskne se')
            ->addTextArea('doc_notice', cols: 4, label: 'Poznámka na doklad',
                hint: 'Bude na PDF dokladu')
            ->build();
    }

    public function recalculate(string $changedColumn, array $data): RecalculateResult
    {
        // Triggers on partner change → resolve default address + due_date
        if ($changedColumn === 'partner' && !empty($data['partner']) && $this->db !== null) {
            // Default address: type=1 (sídlo), fallback to first
            $addr = $this->db->fetchRow(
                'SELECT id FROM base_persons_addresses
                 WHERE person = %i
                 ORDER BY (address_type = 1) DESC, order_pos ASC, id ASC
                 LIMIT 1',
                (int) $data['partner'],
            );
            if ($addr !== null && empty($data['partner_address'])) {
                $data['partner_address'] = (int) $addr['id'];
            }

            // payment_term_days → due_date
            if (!empty($data['issue_date']) && empty($data['due_date'])) {
                $partner = $this->db->fetchRow(
                    'SELECT payment_term_days FROM base_persons_persons WHERE id = %i',
                    (int) $data['partner'],
                );
                $days = (int) ($partner['payment_term_days'] ?? 14);
                $data['due_date'] = (new \DateTimeImmutable($data['issue_date']))
                    ->modify("+{$days} days")
                    ->format('Y-m-d');
            }
        }

        // Triggers on issue_date change → propagate defaults
        if ($changedColumn === 'issue_date' && !empty($data['issue_date'])) {
            if (empty($data['accounting_date'])) {
                $data['accounting_date'] = $data['issue_date'];
            }
            if (empty($data['vat_duzp'])) {
                $data['vat_duzp'] = $data['issue_date'];
            }
        }

        // Other triggers (vat_mode, vat_place, vat_registration, doc_currency)
        // just need a form reload — already triggered by Svelte recalculate

        $isNew = !isset($data['id']) || $data['id'] === null;
        return new RecalculateResult(
            $this->buildFormDefinition($data, $isNew),
            $data,
        );
    }

    // ── Options resolvers ───────────────────────────────────────────────────

    /** @return list<array{value: int, label: string}> */
    private function resolveNumberSeriesOptions(): array
    {
        if ($this->db === null) {
            return [];
        }
        $rows = $this->db->fetchAll(
            'SELECT id, name, doc_type FROM docs_core_number_series
             WHERE docState IN (10, 40, 80)
             ORDER BY doc_type ASC, name ASC',
        );
        $options = [];
        foreach ($rows as $row) {
            $options[] = [
                'value' => (int) $row['id'],
                'label' => (string) $row['name'] . ' (' . (string) $row['doc_type'] . ')',
            ];
        }
        return $options;
    }

    /** @return list<array{value: int, label: string}> */
    private function resolvePartnerOptions(): array
    {
        if ($this->db === null) {
            return [];
        }
        $rows = $this->db->fetchAll(
            'SELECT id, full_name FROM base_persons_persons
             WHERE docState IN (10, 40, 80)
             ORDER BY full_name ASC
             LIMIT 500',
        );
        $options = [];
        foreach ($rows as $row) {
            $options[] = [
                'value' => (int) $row['id'],
                'label' => (string) $row['full_name'],
            ];
        }
        return $options;
    }

    // resolvePartnerAddressOptions, resolvePartnerBankOptions,
    // resolveVatRegistrationOptions, resolveBankAccountOptions —
    // standard pattern, see ItemsForm vzor.

    // ── HTML renderers ──────────────────────────────────────────────────────

    private function renderRecapHtml(array $data): string
    {
        $recap = $data['vatRecap'] ?? [];
        if (!is_array($recap) || $recap === []) {
            return '<p class="muted"><em>Doklad zatím nemá rekapitulaci '
                . '— přidejte řádky a uložte doklad pro přepočet.</em></p>';
        }

        $currency = (string) ($data['doc_currency'] ?? 'czk');
        $homeCurrency = (string) ($data['home_currency'] ?? 'czk');
        $hasExchange = $currency !== $homeCurrency;

        $html = '<table class="vat-recap">';
        $html .= '<thead><tr>'
            . '<th>#</th><th>Sazba</th><th>%</th>'
            . '<th>Měna</th><th>Základ</th><th>Daň</th><th>Celkem</th>'
            . '</tr></thead>';
        $html .= '<tbody>';

        $lineNo = 1;
        foreach ($recap as $r) {
            $code = (string) $r['vat_code'];
            $pct = number_format((float) $r['vat_pct'], 0, ',', ' ');
            $isPair = !empty($r['is_reverse_pair']);

            $sumBase  = !empty($r['sum_base']);
            $sumTax   = !empty($r['sum_tax']);
            $sumTotal = !empty($r['sum_total']);

            $rowClass = $isPair ? 'reverse-pair' : 'primary';
            $html .= "<tr class='{$rowClass}'>";
            $html .= "<td rowspan='" . ($hasExchange ? 2 : 1) . "'>{$lineNo}.</td>";
            $html .= "<td rowspan='" . ($hasExchange ? 2 : 1) . "'>"
                . htmlspecialchars($this->vatCodeLabel($code, $data))
                . '</td>';
            $html .= "<td rowspan='" . ($hasExchange ? 2 : 1) . "'>{$pct}</td>";

            $html .= "<td>" . strtoupper(htmlspecialchars($currency)) . "</td>";
            $html .= "<td class='" . ($sumBase ? '' : 'grayed') . "'>"
                . $this->formatMoney($r['base']) . "</td>";
            $html .= "<td class='" . ($sumTax ? '' : 'grayed') . "'>"
                . $this->formatMoney($r['tax']) . "</td>";
            $html .= "<td class='" . ($sumTotal ? '' : 'grayed') . "'>"
                . $this->formatMoney($r['total']) . "</td>";
            $html .= '</tr>';

            if ($hasExchange) {
                $html .= '<tr class="' . $rowClass . '">';
                $html .= "<td>" . strtoupper(htmlspecialchars($homeCurrency)) . "</td>";
                $html .= "<td class='" . ($sumBase ? '' : 'grayed') . "'>"
                    . $this->formatMoney($r['base_dom']) . "</td>";
                $html .= "<td class='" . ($sumTax ? '' : 'grayed') . "'>"
                    . $this->formatMoney($r['tax_dom']) . "</td>";
                $html .= "<td class='" . ($sumTotal ? '' : 'grayed') . "'>"
                    . $this->formatMoney($r['total_dom']) . "</td>";
                $html .= '</tr>';
            }

            $lineNo++;
        }

        // Total row
        $html .= '<tr class="total"><td></td><td colspan="2"><strong>Celkem'
            . ($hasExchange ? ' (' . $this->formatExchangeRate($data) . ')' : '')
            . '</strong></td>';
        $html .= '<td>' . strtoupper(htmlspecialchars($currency)) . '</td>';
        $html .= '<td><strong>' . $this->formatMoney($data['total_base'] ?? 0) . '</strong></td>';
        $html .= '<td><strong>' . $this->formatMoney($data['total_vat'] ?? 0) . '</strong></td>';
        $html .= '<td><strong>' . $this->formatMoney($data['total_amount'] ?? 0) . '</strong></td>';
        $html .= '</tr>';

        if ($hasExchange) {
            $html .= '<tr class="total"><td></td><td colspan="2"></td>';
            $html .= '<td>' . strtoupper(htmlspecialchars($homeCurrency)) . '</td>';
            $html .= '<td><strong>' . $this->formatMoney($data['total_base_dom'] ?? 0) . '</strong></td>';
            $html .= '<td><strong>' . $this->formatMoney($data['total_vat_dom'] ?? 0) . '</strong></td>';
            $html .= '<td><strong>' . $this->formatMoney($data['total_amount_dom'] ?? 0) . '</strong></td>';
            $html .= '</tr>';
        }

        $html .= '</tbody></table>';

        return $html;
    }

    private function renderSnapshotsHtml(array $data): string
    {
        $supplier = $data['supplier_snapshot'] ?? null;
        $customer = $data['customer_snapshot'] ?? null;

        // Snapshots are stored as JSON, may already be array or string
        if (is_string($supplier)) {
            $supplier = json_decode($supplier, true);
        }
        if (is_string($customer)) {
            $customer = json_decode($customer, true);
        }

        $html = '<div class="snapshots-container">';
        $html .= '<div class="snapshot-block">';
        $html .= '<h4>Dodavatel</h4>';
        $html .= $this->renderPersonSnapshot($supplier);
        $html .= '</div>';
        $html .= '<div class="snapshot-block">';
        $html .= '<h4>Odběratel</h4>';
        $html .= $this->renderPersonSnapshot($customer);
        $html .= '</div>';
        $html .= '</div>';

        return $html;
    }

    private function renderPersonSnapshot(?array $snap): string
    {
        if ($snap === null || $snap === []) {
            return '<p class="muted"><em>Není vyplněno</em></p>';
        }

        $h = '';
        if (!empty($snap['name'])) {
            $h .= '<p class="name"><strong>' . htmlspecialchars($snap['name']) . '</strong></p>';
        }

        $idLines = [];
        if (!empty($snap['company_id'])) {
            $idLines[] = 'IČO: ' . htmlspecialchars($snap['company_id']);
        }
        if (!empty($snap['tax_id'])) {
            $idLines[] = 'DIČ: ' . htmlspecialchars($snap['tax_id']);
        }
        if (!empty($snap['vat_id']) && $snap['vat_id'] !== ($snap['tax_id'] ?? null)) {
            $idLines[] = 'DIČ DPH: ' . htmlspecialchars($snap['vat_id']);
        }
        if ($idLines !== []) {
            $h .= '<p>' . implode('<br>', $idLines) . '</p>';
        }

        if (!empty($snap['address']['display_block'])) {
            $h .= '<pre class="address">' . htmlspecialchars($snap['address']['display_block']) . '</pre>';
        }

        if (!empty($snap['court_registration'])) {
            $h .= '<p class="muted">' . htmlspecialchars($snap['court_registration']) . '</p>';
        }

        if (!empty($snap['contact'])) {
            $contactLines = [];
            if (!empty($snap['contact']['email'])) {
                $contactLines[] = 'E-mail: ' . htmlspecialchars($snap['contact']['email']);
            }
            if (!empty($snap['contact']['phone'])) {
                $contactLines[] = 'Tel: ' . htmlspecialchars($snap['contact']['phone']);
            }
            if ($contactLines !== []) {
                $h .= '<p class="contact">' . implode('<br>', $contactLines) . '</p>';
            }
        }

        if (!empty($snap['bank_account'])) {
            $bank = $snap['bank_account'];
            $bankLines = [];
            if (!empty($bank['account_number'])) {
                $bankLines[] = htmlspecialchars($bank['account_number']);
            }
            if (!empty($bank['iban'])) {
                $bankLines[] = 'IBAN: ' . htmlspecialchars($bank['iban']);
            }
            if (!empty($bank['bic'])) {
                $bankLines[] = 'BIC: ' . htmlspecialchars($bank['bic']);
            }
            if ($bankLines !== []) {
                $h .= '<p class="bank"><strong>Bankovní spojení:</strong><br>'
                    . implode('<br>', $bankLines) . '</p>';
            }
        }

        if (!empty($snap['vat_registration']['vat_id'])) {
            $h .= '<p class="vat-reg"><strong>DIČ pro DPH:</strong> '
                . htmlspecialchars($snap['vat_registration']['vat_id']) . '</p>';
        }

        return $h;
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function formatMoney(mixed $amount): string
    {
        $f = (float) ($amount ?? 0);
        return number_format($f, 2, ',', ' ');
    }

    private function formatExchangeRate(array $data): string
    {
        $rate = (float) ($data['exchange_rate'] ?? 1.0);
        $doc = strtoupper((string) ($data['doc_currency'] ?? ''));
        $home = strtoupper((string) ($data['home_currency'] ?? ''));
        return "1 {$doc} = " . number_format($rate, 4, '.', '') . " {$home}";
    }

    private function vatCodeLabel(string $code, array $data): string
    {
        $vatRegId = $data['vat_registration'] ?? null;
        if ($vatRegId === null || $this->db === null) {
            return $code;
        }
        $reg = $this->db->fetchRow(
            'SELECT country FROM economy_codebooks_vat_registrations WHERE id = %i',
            (int) $vatRegId,
        );
        if ($reg === null || empty($reg['country'])) {
            return $code;
        }
        $cfg = $this->config?->cfgItem('world.vat.' . (string) $reg['country']);
        if (!is_array($cfg) || !isset($cfg['vatCodes'][$code]['fullName'])) {
            return $code;
        }
        return (string) $cfg['vatCodes'][$code]['fullName'];
    }
}
```

### `modules/docs/core/src/DocRowsForm.php`

Sub-form pro řádek. Filtruje VAT codes dynamicky podle hlavičky.

```php
<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\Core;

use Shipard\Core\Form\FormDefinition;
use Shipard\Core\Form\RecalculateResult;
use Shipard\Core\Form\TableForm;
use Shipard\Module\World\Vat\VatRateResolver;

class DocRowsForm extends TableForm
{
    public function buildFormDefinition(array $data, bool $isNew): FormDefinition
    {
        $headContext = $this->loadHeadContext($data['doc_head'] ?? null);

        $rowKind = (int) ($data['row_kind'] ?? 1);
        $isText = $rowKind === 0;

        if ($isNew) {
            $data['row_kind'] = $rowKind;
            $data['price_calc_mode'] = $data['price_calc_mode'] ?? 0;
        }

        $tab = $this->tab('basic', 'Řádek')
            ->addSelect('row_kind', cols: 1, triggers: 'reload', required: true)
            ->addSelect('item', cols: 2,
                options: $this->resolveItemOptions(),
                triggers: 'reload',
                hidden: $isText,
            )
            ->addInput('description', cols: 4)

            ->addSeparator('Množství a cena', hidden: $isText)
            ->addNumber('quantity', cols: 1, triggers: 'reload', hidden: $isText)
            ->addSelect('unit', cols: 1,
                options: $this->resolveUnitOptions(),
                hidden: $isText,
            )
            ->addNumber('unit_price', cols: 1, triggers: 'reload', hidden: $isText)
            ->addNumber('total_price', cols: 1, triggers: 'reload', hidden: $isText)
            ->addSelect('price_calc_mode', cols: 1, hidden: $isText)

            ->addSeparator('Sleva', hidden: $isText)
            ->addNumber('discount_pct', cols: 1, hint: 'Sleva v %', hidden: $isText)
            ->addNumber('discount_amount', cols: 1, hint: 'Sleva absolutně', hidden: $isText);

        $hasVat = $headContext !== null
            && (int) ($headContext['vat_mode'] ?? 0) !== 0;

        if ($hasVat && !$isText) {
            $tab = $tab
                ->addSeparator('DPH')
                ->addSelect('vat_code', cols: 2,
                    options: $this->buildVatCodeOptions($headContext),
                    triggers: 'reload',
                    required: true,
                )
                ->addNumber('vat_pct', cols: 1, hint: 'Lze přepsat pro doklady z jiného státu')
                ->addNumber('vat_base', cols: 1, readOnly: true, label: 'Základ DPH (vypočteno)')
                ->addNumber('vat_amount', cols: 1, readOnly: true, label: 'Částka DPH (vypočteno)')
                ->addNumber('vat_total', cols: 1, readOnly: true, label: 'Celkem (vypočteno)');
        }

        $tab = $tab->addSeparator('Pořadí')
            ->addNumber('sort_order', cols: 1);

        return new FormDefinition(
            table: $this->table,
            title: 'Řádek dokladu',
            titleNew: 'Nový řádek',
            tabs: [$tab->build()],
            fullSize: false,
        );
    }

    public function recalculate(string $changedColumn, array $data): RecalculateResult
    {
        $headContext = $this->loadHeadContext($data['doc_head'] ?? null);

        // Item changed → fill defaults from item
        if ($changedColumn === 'item' && !empty($data['item']) && $this->db !== null) {
            $item = $this->db->fetchRow(
                'SELECT name, sales_price_no_vat, unit FROM economy_items WHERE id = %i',
                (int) $data['item'],
            );
            if ($item !== null) {
                if (empty($data['description'])) {
                    $data['description'] = (string) $item['name'];
                }
                if (empty($data['unit_price']) && !empty($item['sales_price_no_vat'])) {
                    $data['unit_price'] = (float) $item['sales_price_no_vat'];
                }
                if (empty($data['unit']) && !empty($item['unit'])) {
                    $data['unit'] = (int) $item['unit'];
                }
            }
        }

        // VAT code changed → resolve vat_pct from world.vat.{country}
        if ($changedColumn === 'vat_code'
            && !empty($data['vat_code'])
            && $headContext !== null
            && !empty($headContext['country'])
            && !empty($headContext['vat_duzp'])
        ) {
            $resolver = new VatRateResolver($this->config);
            try {
                $data['vat_pct'] = $resolver->resolveVatPct(
                    $headContext['country'],
                    (string) $data['vat_code'],
                    (string) $headContext['vat_duzp'],
                );
            } catch (\LogicException) {
                // Unknown rate → leave manual entry; UI shows warning
            }
        }

        // quantity / unit_price / total_price triggered just for form reload
        // (server doesn't recompute here; final compute is in DocDocument::beforeSave on save)

        $isNew = !isset($data['id']) || $data['id'] === null;
        return new RecalculateResult(
            $this->buildFormDefinition($data, $isNew),
            $data,
        );
    }

    /** @return array<string, mixed>|null */
    private function loadHeadContext(mixed $docHeadId): ?array
    {
        if ($docHeadId === null || $this->db === null) {
            return null;
        }
        $head = $this->db->fetchRow(
            'SELECT vat_registration, doc_type, vat_place, vat_duzp, vat_mode
             FROM docs_core_heads WHERE id = %i',
            (int) $docHeadId,
        );
        if ($head === null) {
            return null;
        }
        $context = [
            'doc_type'     => (string) $head['doc_type'],
            'vat_place'    => (int) ($head['vat_place'] ?? 0),
            'vat_duzp'     => $head['vat_duzp']     ?? null,
            'vat_mode'     => (int) ($head['vat_mode'] ?? 1),
            'country'      => null,
            'direction'    => null,
            'place'        => null,
        ];

        if (!empty($head['vat_registration'])) {
            $reg = $this->db->fetchRow(
                'SELECT country FROM economy_codebooks_vat_registrations WHERE id = %i',
                (int) $head['vat_registration'],
            );
            if ($reg !== null) {
                $context['country'] = (string) $reg['country'];
            }
        }

        $docTypes = $this->config?->cfgItem('docs.core.docTypes');
        if (is_array($docTypes) && isset($docTypes[$context['doc_type']]['trade_dir'])) {
            $tradeDir = (int) $docTypes[$context['doc_type']]['trade_dir'];
            $context['direction'] = match ($tradeDir) {
                1 => 'output',
                2 => 'input',
                default => null,
            };
        }

        $context['place'] = match ($context['vat_place']) {
            0 => 'domestic',
            1 => 'intracom',
            2 => 'foreign',
            default => 'domestic',
        };

        return $context;
    }

    /** @return list<array{value: string, label: string}> */
    private function buildVatCodeOptions(?array $context): array
    {
        if ($context === null
            || empty($context['country'])
            || empty($context['direction'])
        ) {
            return [];
        }
        $resolver = new VatRateResolver($this->config);
        try {
            $codes = $resolver->getVatCodes(
                $context['country'],
                $context['direction'],
                $context['place'],
                includeHidden: false,
            );
        } catch (\LogicException) {
            return [];
        }
        $options = [];
        foreach ($codes as $key => $code) {
            $options[] = [
                'value' => (string) $key,
                'label' => (string) ($code['fullName'] ?? $code['name'] ?? $key),
            ];
        }
        return $options;
    }

    // resolveItemOptions, resolveUnitOptions — standard pattern
}
```

### `modules/docs/core/src/DocsHeadsViewer.php`

Generický viewer všech dokladů. Vzor: `ItemsViewer`. Klíčové specifika:

- Filter podle `viewGroup` (active = 10/20/30/40/80, cancelled = 30 only,
  trash = 90)
- Search: `doc_number`, `doc_text`, partner `full_name` (přes JOIN)
- Render row: partner full_name jako `t1`, doc_number jako `i1`, datumy
  + totals jako `t2`, `t3` formatted total amount (např. „1 234,00 Kč")
- `stateStyle` per docState (concept/confirmed/edit/done/cancelled/trash)
- Render detail tab: sumarizace, klíčové datumy, partner

```sql
SELECT h.id, h.doc_number, h.doc_text, h.docState, h.docStateMain,
       h.issue_date, h.due_date, h.total_amount, h.doc_currency,
       p.full_name AS partner_name
FROM docs_core_heads h
LEFT JOIN base_persons_persons p ON p.id = h.partner
WHERE [conditions]
ORDER BY h.docStateMain ASC, h.doc_number DESC
```

Nezapomeň: `docStatesCfgItem = 'docs.core.docStates'` (NE
`core.system.docStatesArchive` — naše doklady mají vlastní stavy).

### Aktualizace `module.jsonc`

```jsonc
"viewers": [
    {
        "id": "docs.core.numberSeries",
        "name": "Document number series",
        // ... existing ...
    },
    {
        "id": "docs.core.heads",
        "name": "Documents",
        "name:cs": "Doklady",
        "name:en": "Documents",
        "icon": "file-text",
        "table": "docs_core_heads",
        "class": "Shipard\\Module\\Docs\\Core\\DocsHeadsViewer"
    }
],

"forms": [
    {
        "table": "docs_core_number_series",
        "class": "Shipard\\Module\\Docs\\Core\\NumberSeriesForm"
    },
    {
        "table": "docs_core_heads",
        "class": "Shipard\\Module\\Docs\\Core\\DocsHeadsForm"
    },
    {
        "table": "docs_core_rows",
        "id": "docs.core.rows",
        "class": "Shipard\\Module\\Docs\\Core\\DocRowsForm"
    }
]
```

Pozor — `docs.core.rows` `formId` se používá v `addSubtable(formId: 'docs.core.rows')`
v `DocsHeadsForm`.

### Aktualizace navigace

Pokud existuje sidebar navigace v hlavní menu (mimo settings), zaregistruj
viewer `docs.core.heads` jako jednu z hlavních položek (pravděpodobně přes
nějaký mechanismus v `core.system` nebo v `frontend-phase3-app-sidebar`).
Zkontroluj existující registraci pro `economy.items` a `base.persons` jako
vzor.

### Frontend ikona

V `frontend/src/icons.js` přidej `iconFileText` (pravděpodobně už existuje
pro mail nebo attachments). Pokud ne:

```js
import { faFileInvoice } from '@fortawesome/free-solid-svg-icons';
export const iconFileInvoice = faFileInvoice;
// iconMap:
'file-text': iconFileInvoice,
```

Spustit `npm run build` v `frontend/`.

### CSS styly pro vat-recap a snapshots

Nové styly v `frontend/src/styles/` (nebo kde žijí styly):

```css
.vat-recap {
    width: 100%;
    border-collapse: collapse;
}
.vat-recap th, .vat-recap td {
    padding: 0.5em 0.75em;
    border-bottom: 1px solid var(--border-color);
    text-align: right;
}
.vat-recap th { text-align: left; background: var(--header-bg); }
.vat-recap td.grayed { color: var(--muted-color); opacity: 0.5; }
.vat-recap tr.reverse-pair { background: var(--soft-bg); }
.vat-recap tr.total { font-weight: 600; border-top: 2px solid var(--border-color); }

.snapshots-container {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1.5em;
}
.snapshot-block h4 {
    margin: 0 0 0.5em 0;
    color: var(--accent-color);
}
.snapshot-block .name { font-size: 1.1em; }
.snapshot-block pre.address {
    white-space: pre-wrap;
    font-family: inherit;
    margin: 0.5em 0;
}
```

Detail dle existujícího design systemu — zkontroluj proměnné v
`docs/design-system.md`.

## Hotovo když

- [ ] Po `bin/shpd-ds ds-upgrade` se v hlavní navigaci objeví "Doklady"
      s ikonou
- [ ] Klik na "Doklady" otevře viewer s viewGroup taby (Aktivní / Storna /
      Koš)
- [ ] Klik na "Přidat" otevře formulář s tabovaným layoutem (Hlavička /
      Řádky / Rekapitulace / Poznámky / Přílohy)
- [ ] Hlavička formuláře má všechny sekce: Identifikace, Partner, Datumy,
      DPH, Měna, Zaokrouhlení, Platba, Součty
- [ ] Změna `partner` doplní `partner_address` (sídlo) a `due_date` z
      `payment_term_days`
- [ ] Změna `issue_date` doplní `accounting_date`/`vat_duzp` (pokud byly
      prázdné)
- [ ] Změna `vat_mode = 0` (bez DPH) skryje VAT pole (vat_calc_source,
      vat_place, vat_registration, vat_duzp, vat_dppd)
- [ ] Změna `doc_currency` na cizí měnu odhalí `exchange_rate` pole
- [ ] Po uložení Konceptu lze otevřít Tab Řádky a přidat řádek
- [ ] Sub-form řádku má select `item` s recalculate — výběr položky
      doplní `description`, `unit_price`, `unit`
- [ ] Sub-form řádku má select `vat_code` s options filtrovanými podle
      country (z hlavičkové vat_registration), direction (z doc_type),
      place (z hlavičkové vat_place)
- [ ] Změna `vat_code` na řádku resolvuje `vat_pct` podle `vat_duzp`
      hlavičky
- [ ] Po uložení řádků a re-otevření hlavičky Tab Rekapitulace zobrazí
      sestavenou tabulku DPH
- [ ] Po Potvrzení (10 → 20) se zobrazí Tab Snapshoty se 2 sloupci
      (dodavatel + odběratel)
- [ ] Reverse charge řádek v rekapitulaci má grayed-out hodnoty pro
      `sum_*=0` sloupce
- [ ] Doklad v cizí měně zobrazí v rekapitulaci 2 řádky (měna dokladu +
      domácí měna) s kurzovým hint
- [ ] Storno (40 → 30) zachová všechna data, doklad v viewer má červenou
      stylizaci, zůstává v "Aktivní" viewGroup
- [ ] Vrácení posledního Potvrzeného → Koncept zobrazí confirm a uvolní
      číslo
- [ ] Vrácení ne-posledního Potvrzeného → Koncept zobrazí HTTP 422 chybu
      v UI (toast nebo modal)
- [ ] Smazání (90) přesune doklad do Tab "Koš"
- [ ] PHPUnit testy pro `DocsHeadsForm::buildFormDefinition` (počet tabů
      podle stavu, hidden flag pro vat_mode=0)
- [ ] PHPUnit testy pro `DocRowsForm::recalculate` (item fill,
      vat_code → vat_pct lookup)

## Konvence

- **Jazyk**: UI texty čeština, kód a komentáře angličtina
- **Vícejazyčnost**: každé `name`/`label`/`hint` v PHP/JSONC s `:cs` a `:en`
  variantou (PHP přes inline strings — Fáze 5 může počítat s tím, že CZ
  texty jsou výchozí, EN přidat až ve Fázi 7+)
- **PHP 8.3** strict_types
- **HTML escapování**: vždy `htmlspecialchars()` před vložením user dat
- **Read-only sloupce**: `readOnly: true` v form, NIKDY `system: true` na
  uživatelské polia (system=true znamená že frontend ho neposílá → server
  nikdy neuvidí v `data`)

## Doporučené pořadí implementace

1. **Frontend ikon + module.jsonc viewer registrace** — `docs.core.heads`
   se objeví v navigaci (i když viewer je zatím prázdný)
2. **`DocsHeadsViewer`** — minimální verze (search, filter, render row,
   render detail) bez per-typ specialitky
3. **`DocsHeadsForm` skeleton** — jen Hlavička tab bez Recap/Snapshots
4. **Recalculate hooky** v `DocsHeadsForm`
5. **`DocRowsForm`** + recalculate (item fill, vat_code → vat_pct)
6. **`addSubtable` integrace** v `buildRowsTab`
7. **HTML rendering** pro Tab Rekapitulace + CSS
8. **HTML rendering** pro Tab Snapshoty + CSS
9. **PHPUnit testy** — form definition shape, recalculate
10. **E2E manual test**: Vytvoření → Vyplnění (partner, řádek s VAT
    kódem) → Save → Confirm → ověř Recap → ověř Snapshots → V opravě →
    edit partnera → ověř, že snapshot se přebuduje → Storno

## Otevřené body

- **Custom element types pro Recap a Snapshots** — task používá `addHtml`
  s pre-rendered HTML. Pokud se ukáže, že potřebujeme interaktivitu
  (drill-down do řádků rekapitulace), navazující úkol zavede dedikovaný
  element type. Pro MVP `addHtml` stačí.
- **Default partnera při novém záznamu** — task nepředpokládá automatický
  výběr "obvyklého partnera". Pokud by to bylo užitečné UX, navazující
  iterace.
- **Hint `Lze přepsat pro doklady z jiného státu`** u `vat_pct` na řádku —
  nice-to-have, můžeme ladit text až ve Fázi 6 podle reakce na uživateli.
- **Limit 500 partnerů** v `resolvePartnerOptions` — pro MVP stačí, pro
  velká nasazení bude potřeba autocomplete s async API call. Necháme na
  pozdější optimalizaci.
- **Settings UI sekce** — `numberSeries` viewer je už v "accounting"
  sekci settings. Doklady sami (`docs.core.heads`) patří do hlavní
  navigace, ne settings. Detail registrace v sidebar — viz
  `frontend-phase3-app-sidebar.md`.

## Vztah k Fázi 6

Fáze 6 (`docs-invoices.md`) přidá:

- Modul `docs.invoicesOut` s `IssuedInvoiceDocument extends DocsHeadsDocument`
  (nebo přímo `DocDocument`) a `IssuedInvoicesViewer`
- Modul `docs.invoicesIn` s `ReceivedInvoiceDocument` a `ReceivedInvoicesViewer`
- Per-typ viewers mají **spodní tab bar s číselnými řadami** (jako
  screenshot z designu)
- Polymorfní dispatch — buď přes `typeColumn: 'doc_type'` v `module.jsonc`
  nebo přes overriden `DocumentRegistry::getDocument()`
- Generický `docs.core.heads` viewer **zůstává** jako "Všechny doklady"
  vedle per-typ viewerů (užitečné pro reporty napříč typy)
