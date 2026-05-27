<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\Core;

use Shipard\Core\Form\FormDefinition;
use Shipard\Core\Form\FormTab;
use Shipard\Core\Form\RecalculateResult;
use Shipard\Core\Form\TableForm;

/**
 * Abstraktní base formulář nad `docs_core_heads`.
 *
 * Drží společnou logiku pro všechny typy dokladů: build tabů, recalculate
 * cascade (partner → adresa/banka, issue_date → splatnost), resolve options
 * z DB / cfgItem, render rekapitulace DPH a fakturačních snapshotů.
 *
 * Per-typ subclassy (`DocsHeadsForm`, `IssuedInvoiceForm`, `ReceivedInvoiceForm`)
 * dnes přepisují pouze titulky modalu přes virtuální metody
 * `getFormTitle()` / `getNewFormTitle()`; do budoucna budou rozšiřovat o
 * FVB/FPB-specifické sekce (splátkový kalendář, schvalovací workflow, ...).
 *
 * Tabbed layout:
 *   - basic       — header v 8 sekcích
 *   - rows        — subtable referencující DocRowsForm
 *   - recap       — pre-rendered VAT recap HTML (vždy)
 *   - snapshots   — supplier/customer snapshoty (jen když jsou vyplněné)
 *   - notes       — interní + on-document poznámky
 *   - attachments
 *   - + extra taby z `buildExtraTabs()` (např. FPB „Nastavení“)
 */
abstract class DocsHeadsFormBase extends TableForm
{
    public function buildFormDefinition(array $data, bool $isNew): FormDefinition
    {
        $this->applyClientDefaults($data, $isNew);

        $tabs = [
            $this->buildHeaderTab($data, $isNew),
            $this->buildRowsTab(),
            $this->buildRecapTab($data),
        ];

        if (!empty($data['supplier_snapshot']) || !empty($data['customer_snapshot'])) {
            $tabs[] = $this->buildSnapshotsTab($data);
        }

        $tabs[] = $this->buildNotesTab();
        $tabs[] = $this->attachmentsTab();

        // Per-type extra taby na konci formuláře (např. FPB „Nastavení“).
        // Default v base třídě je prázdné pole — subclassy přepisují
        // `buildExtraTabs()`, pokud chtějí přidat per-typ taby.
        foreach ($this->buildExtraTabs($data, $isNew) as $extraTab) {
            $tabs[] = $extraTab;
        }

        return new FormDefinition(
            table: $this->table,
            title: $this->getFormTitle(),
            titleNew: $this->getNewFormTitle(),
            tabs: $tabs,
            fullSize: true,
        );
    }

    /**
     * Titulek modalu pro existující záznam. Per-typ subclassy přepisují
     * (např. „Faktura vydaná").
     */
    protected function getFormTitle(): string
    {
        return 'Doklad';
    }

    /**
     * Titulek modalu pro nový záznam. Per-typ subclassy přepisují
     * (např. „Nová faktura vydaná").
     */
    protected function getNewFormTitle(): string
    {
        return 'Nový doklad';
    }

    /**
     * Hook pro per-typ subclassy — vrací pole tabů, které se přidají
     * na konec formuláře (za Přílohy). Default: žádné extra taby.
     *
     * Vzor: `ReceivedInvoiceForm` přepisuje a vrací `[buildSettingsTab($data)]`
     * (FPB má vlastní tab „Nastavení“ s registrací DPH, naším bankovním účtem
     * a readOnly domácí měnou).
     *
     * @param array<string, mixed> $data
     * @return list<FormTab>
     */
    protected function buildExtraTabs(array $data, bool $isNew): array
    {
        return [];
    }

    /**
     * Client-side defaults that don't require server lookups.
     * Server-side defaults (fiscal_year, vat_period, snapshots, totals) are
     * computed in DocDocument::beforeSave.
     *
     * @param array<string, mixed> $data
     */
    protected function applyClientDefaults(array &$data, bool $isNew): void
    {
        if (!$isNew) {
            return;
        }
        // Per-type viewer hint: if doc_type is provided (e.g. 'invno' from
        // IssuedInvoicesViewer.getNewRecordDefaults) and number_series is not
        // yet set, pre-select the first active series of that type.
        if (empty($data['number_series']) && !empty($data['doc_type']) && $this->db !== null) {
            $row = $this->db->fetchRow(
                'SELECT `id` FROM `docs_core_number_series`'
                . ' WHERE `doc_type` = %s AND `docState` IN (10, 40, 80)'
                . ' ORDER BY `id` ASC LIMIT 1',
                (string) $data['doc_type'],
            );
            if ($row !== null) {
                $data['number_series'] = (int) $row['id'];
            }
        }
        if (empty($data['issue_date'])) {
            $data['issue_date'] = date('Y-m-d');
        }
        if (!isset($data['vat_mode'])) {
            $data['vat_mode'] = 1;
        }
        if (!isset($data['vat_calc_source'])) {
            $data['vat_calc_source'] = 0;
        }
        if (!isset($data['vat_place'])) {
            $data['vat_place'] = 0;
        }
        if (!isset($data['payment_method'])) {
            $data['payment_method'] = 1;
        }
        if (!isset($data['total_rounding_mode'])) {
            $data['total_rounding_mode'] = 1;
        }
        if (!isset($data['vat_rounding_mode'])) {
            $data['vat_rounding_mode'] = 2;
        }
        if (empty($data['doc_currency'])) {
            $data['doc_currency'] = 'czk';
        }
        if (empty($data['home_currency'])) {
            $data['home_currency'] = 'czk';
        }
    }

    /** @param array<string, mixed> $data */
    protected function buildHeaderTab(array $data, bool $isNew): FormTab
    {
        $vatMode = (int) ($data['vat_mode'] ?? 1);
        $hasVat = $vatMode !== 0;
        $docCurrency = strtolower((string) ($data['doc_currency'] ?? 'czk'));
        $homeCurrency = strtolower((string) ($data['home_currency'] ?? 'czk'));
        $hasForeignCurrency = $docCurrency !== '' && $homeCurrency !== ''
            && $docCurrency !== $homeCurrency;
        $partnerId = (int) ($data['partner'] ?? 0);

        return $this->tab('basic', 'Hlavička')
            ->section()
                ->col()
                    ->separator('Identifikace')
                    ->select('number_series',
                        options: $this->resolveNumberSeriesOptions(
                            !empty($data['doc_type']) ? (string) $data['doc_type'] : null,
                        ),
                        required: true,
                        readOnly: !$isNew,
                    )
                    ->input('doc_number', readOnly: true)
                    ->input('doc_text')

                    ->separator('Partner')
                    ->lookup('partner',
                        table: 'base_persons_persons',
                        placeholder: 'Hledat partnera…',
                        triggers: 'reload',
                        editForm: true,
                        createForm: true,
                    )
                    ->lookup('partner_address',
                        table: 'base_persons_addresses',
                        filter: $partnerId !== 0 ? ['person' => $partnerId] : null,
                        placeholder: $partnerId !== 0 ? 'Vyberte adresu…' : 'Nejdřív vyberte partnera',
                        readOnly: $partnerId === 0,
                    )
                    ->lookup('partner_bank',
                        table: 'base_persons_bank_accounts',
                        filter: $partnerId !== 0 ? ['person' => $partnerId] : null,
                        placeholder: $partnerId !== 0 ? 'Vyberte bankovní účet…' : 'Nejdřív vyberte partnera',
                        readOnly: $partnerId === 0,
                    )
                    ->input('partner_bank_account', label: 'Číslo účtu')
                    ->input('partner_bank_iban', label: 'IBAN')
                    ->input('partner_bank_bic', label: 'BIC/SWIFT')

                    ->separator('Datumy')
                    ->date('issue_date', required: true, triggers: 'reload')
                    ->date('due_date')
                    ->date('accounting_date', required: true)
                    ->date('vat_duzp', hidden: !$hasVat)
                    ->date('vat_dppd', hidden: !$hasVat)
                    ->date('period_from', hint: 'Volitelné, např. pronájem za období')
                    ->date('period_to')

                    ->separator('DPH')
                    ->select('vat_mode',
                        options: $this->resolveCfgItemOptions('docs.core.vatModes'),
                        triggers: 'reload',
                    )
                    ->select('vat_calc_source',
                        options: $this->resolveCfgItemOptions('docs.core.vatCalcSources'),
                        hidden: !$hasVat,
                    )
                    ->select('vat_place',
                        options: $this->resolveCfgItemOptions('docs.core.vatPlaces'),
                        triggers: 'reload',
                        hidden: !$hasVat,
                    )
                    ->select('vat_registration',
                        options: $this->resolveVatRegistrationOptions(),
                        triggers: 'reload',
                        hidden: !$hasVat,
                    )

                    ->separator('Měna')
                    ->select('doc_currency',
                        options: $this->resolveCurrencyOptions(),
                        triggers: 'reload',
                    )
                    ->input('home_currency', readOnly: true)
                    ->number('exchange_rate', hidden: !$hasForeignCurrency)

                    ->separator('Zaokrouhlení')
                    ->select('total_rounding_mode',
                        options: $this->resolveCfgItemOptions('docs.core.roundingModes'),
                    )
                    ->select('vat_rounding_mode',
                        options: $this->resolveCfgItemOptions('docs.core.roundingModes'),
                        hidden: !$hasVat,
                    )

                    ->separator('Platba')
                    ->select('payment_method',
                        options: $this->resolveCfgItemOptions('docs.core.paymentMethods'),
                    )
                    ->select('bank_account',
                        options: $this->resolveBankAccountOptions($docCurrency),
                    )
                    ->input('variable_symbol')
                    ->input('specific_symbol')
                    ->input('constant_symbol')

                    ->separator('Součty')
                    ->number('total_base', readOnly: true, label: 'Základ DPH')
                    ->number('total_vat', readOnly: true, label: 'DPH')
                    ->number('total_amount', readOnly: true, label: 'Celkem')
                    ->number('total_rounding', readOnly: true, label: 'Zaokrouhlení')
            ->build();
    }

    protected function buildRowsTab(): FormTab
    {
        return $this->subtableTab(
            'rows',
            'Řádky',
            'docs_core_rows',
            'doc_head',
            formId: 'docs.core.rows',
        );
    }

    /** @param array<string, mixed> $data */
    protected function buildRecapTab(array $data): FormTab
    {
        // FormController::meta loads only the head row (SELECT * FROM heads),
        // so $data doesn't carry the recap from the docs_core_vat_recap child
        // table. Load it here — same pattern as DocDocument::resolveRowsForCompute.
        $recap = $this->resolveRecapForRender($data);
        return $this->tab('recap', 'Rekapitulace DPH')
            ->section()
                ->col()
                    ->html($this->renderRecapHtml($data, $recap))
            ->build();
    }

    /**
     * Get recap rows: prefer payload (server-computed during recalculate),
     * else load current state from DB.
     *
     * @return list<array<string, mixed>>
     */
    protected function resolveRecapForRender(array $data): array
    {
        if (isset($data['vatRecap']) && is_array($data['vatRecap'])) {
            return $data['vatRecap'];
        }
        if (empty($data['id']) || $this->db === null) {
            return [];
        }
        $rows = $this->db->fetchAll(
            'SELECT * FROM `docs_core_vat_recap`'
            . ' WHERE `doc_head` = %i'
            . ' ORDER BY `order_pos` ASC',
            (int) $data['id'],
        );
        return $rows;
    }

    /** @param array<string, mixed> $data */
    protected function buildSnapshotsTab(array $data): FormTab
    {
        return $this->tab('snapshots', 'Fakturační údaje')
            ->section()
                ->col()
                    ->html($this->renderSnapshotsHtml($data))
            ->build();
    }

    protected function buildNotesTab(): FormTab
    {
        return $this->tab('notes', 'Poznámky')
            ->section()
                ->col()
                    ->textarea('notice', label: 'Interní poznámka',
                        hint: 'Vidíme jen my, netiskne se')
                    ->textarea('doc_notice', label: 'Poznámka na doklad',
                        hint: 'Bude na PDF dokladu')
            ->build();
    }

    public function recalculate(string $changedColumn, array $data): RecalculateResult
    {
        if ($changedColumn === 'partner') {
            // Cascading reset: změna partnera invaliduje dříve vybranou adresu
            // a bankovní účet — ty patřily bývalému partnerovi. Uživatel musí
            // vybrat znovu z dropdownu (který je už filtrovaný na nového partnera).
            $data['partner_address'] = null;
            $data['partner_bank'] = null;

            // Auto-fill due_date podle payment_term_days partnera (pouze pokud
            // je nový partner zadaný a due_date ještě není vyplněný).
            if (!empty($data['partner']) && $this->db !== null
                && !empty($data['issue_date']) && empty($data['due_date'])
            ) {
                $partner = $this->db->fetchRow(
                    'SELECT `payment_term_days` FROM `base_persons_persons` WHERE `id` = %i',
                    (int) $data['partner'],
                );
                $days = (int) ($partner['payment_term_days'] ?? 14);
                if ($days < 0) {
                    $days = 14;
                }
                $issue = (string) $data['issue_date'];
                try {
                    $data['due_date'] = (new \DateTimeImmutable($issue))
                        ->modify("+{$days} days")
                        ->format('Y-m-d');
                } catch (\Exception) {
                    // leave due_date alone if issue_date is unparseable
                }
            }
        }

        if ($changedColumn === 'issue_date' && !empty($data['issue_date'])) {
            if (empty($data['accounting_date'])) {
                $data['accounting_date'] = $data['issue_date'];
            }
            if (empty($data['vat_duzp'])) {
                $data['vat_duzp'] = $data['issue_date'];
            }
        }

        $isNew = !isset($data['id']) || $data['id'] === null || $data['id'] === '';
        return new RecalculateResult(
            $this->buildFormDefinition($data, $isNew),
            $data,
        );
    }

    // ── Options resolvers ───────────────────────────────────────────────────

    /**
     * Build options for the `number_series` select.
     *
     * When `$docType` is supplied (per-type form context, e.g. issued invoices),
     * the result is restricted to series of that type. Otherwise all active
     * series are listed (generic "Documents" form).
     *
     * @return list<array{value: int, label: string}>
     */
    protected function resolveNumberSeriesOptions(?string $docType = null): array
    {
        if ($this->db === null) {
            return [];
        }
        if ($docType !== null && $docType !== '') {
            $rows = $this->db->fetchAll(
                'SELECT `id`, `name`, `doc_type` FROM `docs_core_number_series`'
                . ' WHERE `doc_type` = %s AND `docState` IN (10, 40, 80)'
                . ' ORDER BY `name` ASC',
                $docType,
            );
        } else {
            $rows = $this->db->fetchAll(
                'SELECT `id`, `name`, `doc_type` FROM `docs_core_number_series`'
                . ' WHERE `docState` IN (10, 40, 80)'
                . ' ORDER BY `doc_type` ASC, `name` ASC',
            );
        }
        $options = [];
        foreach ($rows as $row) {
            $rowDocType = (string) ($row['doc_type'] ?? '');
            $label = (string) ($row['name'] ?? '');
            // For the generic form (no $docType filter) keep the type tag
            // in the label so users can tell series apart.
            if ($docType === null && $rowDocType !== '') {
                $label .= ' (' . $rowDocType . ')';
            }
            $options[] = ['value' => (int) $row['id'], 'label' => $label];
        }
        return $options;
    }

    /** @return list<array{value: int, label: string}> */
    protected function resolveVatRegistrationOptions(): array
    {
        if ($this->db === null) {
            return [];
        }
        $rows = $this->db->fetchAll(
            'SELECT `id`, `country`, `vat_id` FROM `economy_codebooks_vat_registrations`'
            . ' WHERE `docState` IN (10, 40, 80)'
            . ' ORDER BY `country` ASC, `id` ASC',
        );
        $options = [];
        foreach ($rows as $row) {
            $country = strtoupper((string) ($row['country'] ?? ''));
            $vatId = (string) ($row['vat_id'] ?? '');
            $label = $country;
            if ($vatId !== '') {
                $label = $label !== '' ? "{$country} — {$vatId}" : $vatId;
            }
            if ($label === '') {
                $label = '#' . $row['id'];
            }
            $options[] = ['value' => (int) $row['id'], 'label' => $label];
        }
        return $options;
    }

    /** @return list<array{value: int, label: string}> */
    protected function resolveBankAccountOptions(string $docCurrency): array
    {
        if ($this->db === null) {
            return [];
        }
        $rows = $this->db->fetchAll(
            'SELECT `id`, `code`, `name`, `account_number`, `iban`, `currency`'
            . ' FROM `economy_codebooks_bank_accounts`'
            . ' WHERE `docState` IN (10, 40, 80)'
            . ' ORDER BY `code` ASC, `name` ASC',
        );
        $options = [];
        $docCurrencyLc = strtolower($docCurrency);
        foreach ($rows as $row) {
            $rowCurrency = strtolower((string) ($row['currency'] ?? ''));
            // Soft-filter: prefer matching currency, but include all so users
            // can still pick mismatched accounts manually if needed.
            $label = trim((string) ($row['code'] ?? '') . ' — ' . (string) ($row['name'] ?? ''), ' —');
            if ($rowCurrency !== '' && $rowCurrency !== $docCurrencyLc) {
                $label .= ' (' . strtoupper($rowCurrency) . ')';
            }
            $options[] = ['value' => (int) $row['id'], 'label' => $label];
        }
        return $options;
    }

    /** @return list<array{value: string, label: string}> */
    protected function resolveCurrencyOptions(): array
    {
        if ($this->config === null) {
            return [];
        }
        $cfg = $this->config->cfgItem('world.base.currencies');
        if (!is_array($cfg)) {
            return [];
        }
        $options = [];
        foreach ($cfg as $key => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $alpha3 = (string) ($entry['alpha3'] ?? strtoupper((string) $key));
            $name = (string) ($entry['name'] ?? $alpha3);
            $options[] = ['value' => (string) $key, 'label' => "{$alpha3} — {$name}"];
        }
        return $options;
    }

    /** @return list<array{value: int, label: string}> */
    protected function resolveCfgItemOptions(string $cfgItemId): array
    {
        if ($this->config === null) {
            return [];
        }
        $cfg = $this->config->cfgItem($cfgItemId);
        if (!is_array($cfg)) {
            return [];
        }
        $options = [];
        foreach ($cfg as $key => $entry) {
            if (is_array($entry) && isset($entry['name'])) {
                $options[] = ['value' => (int) $key, 'label' => (string) $entry['name']];
            }
        }
        return $options;
    }

    // ── HTML renderers ──────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $data
     * @param list<array<string, mixed>> $recap
     */
    protected function renderRecapHtml(array $data, array $recap = []): string
    {
        if ($recap === []) {
            return '<p class="muted"><em>Doklad zatím nemá rekapitulaci'
                . ' — přidejte řádky a uložte doklad pro přepočet.</em></p>';
        }

        $currency = strtolower((string) ($data['doc_currency'] ?? 'czk'));
        $homeCurrency = strtolower((string) ($data['home_currency'] ?? 'czk'));
        $hasExchange = $currency !== $homeCurrency && $homeCurrency !== '';

        $html = '<table class="vat-recap">';
        $html .= '<thead><tr>'
            . '<th>#</th><th>Sazba</th><th>%</th>'
            . '<th>Měna</th><th>Základ</th><th>Daň</th><th>Celkem</th>'
            . '</tr></thead><tbody>';

        $lineNo = 1;
        foreach ($recap as $r) {
            if (!is_array($r)) {
                continue;
            }
            $code = (string) ($r['vat_code'] ?? '');
            $pct = number_format((float) ($r['vat_pct'] ?? 0), 0, ',', ' ');
            $isPair = !empty($r['is_reverse_pair']);
            $sumBase  = !empty($r['sum_base']);
            $sumTax   = !empty($r['sum_tax']);
            $sumTotal = !empty($r['sum_total']);

            $rowClass = $isPair ? 'reverse-pair' : 'primary';
            $rowSpan = $hasExchange ? 2 : 1;

            $html .= '<tr class="' . $rowClass . '">';
            $html .= "<td rowspan=\"{$rowSpan}\">{$lineNo}.</td>";
            $html .= "<td rowspan=\"{$rowSpan}\">"
                . htmlspecialchars($this->vatCodeLabel($code, $data)) . '</td>';
            $html .= "<td rowspan=\"{$rowSpan}\">{$pct} %</td>";
            $html .= '<td>' . strtoupper(htmlspecialchars($currency)) . '</td>';
            $html .= '<td' . ($sumBase ? '' : ' class="grayed"') . '>'
                . $this->formatMoney($r['base'] ?? 0) . '</td>';
            $html .= '<td' . ($sumTax ? '' : ' class="grayed"') . '>'
                . $this->formatMoney($r['tax'] ?? 0) . '</td>';
            $html .= '<td' . ($sumTotal ? '' : ' class="grayed"') . '>'
                . $this->formatMoney($r['total'] ?? 0) . '</td>';
            $html .= '</tr>';

            if ($hasExchange) {
                $html .= '<tr class="' . $rowClass . '">';
                $html .= '<td>' . strtoupper(htmlspecialchars($homeCurrency)) . '</td>';
                $html .= '<td' . ($sumBase ? '' : ' class="grayed"') . '>'
                    . $this->formatMoney($r['base_dom'] ?? 0) . '</td>';
                $html .= '<td' . ($sumTax ? '' : ' class="grayed"') . '>'
                    . $this->formatMoney($r['tax_dom'] ?? 0) . '</td>';
                $html .= '<td' . ($sumTotal ? '' : ' class="grayed"') . '>'
                    . $this->formatMoney($r['total_dom'] ?? 0) . '</td>';
                $html .= '</tr>';
            }

            $lineNo++;
        }

        $html .= '<tr class="total"><td></td><td colspan="2"><strong>Celkem'
            . ($hasExchange ? ' (' . htmlspecialchars($this->formatExchangeRate($data)) . ')' : '')
            . '</strong></td>';
        $html .= '<td>' . strtoupper(htmlspecialchars($currency)) . '</td>';
        $html .= '<td><strong>' . $this->formatMoney($data['total_base']   ?? 0) . '</strong></td>';
        $html .= '<td><strong>' . $this->formatMoney($data['total_vat']    ?? 0) . '</strong></td>';
        $html .= '<td><strong>' . $this->formatMoney($data['total_amount'] ?? 0) . '</strong></td>';
        $html .= '</tr>';

        if ($hasExchange) {
            $html .= '<tr class="total"><td></td><td colspan="2"></td>';
            $html .= '<td>' . strtoupper(htmlspecialchars($homeCurrency)) . '</td>';
            $html .= '<td><strong>' . $this->formatMoney($data['total_base_dom']   ?? 0) . '</strong></td>';
            $html .= '<td><strong>' . $this->formatMoney($data['total_vat_dom']    ?? 0) . '</strong></td>';
            $html .= '<td><strong>' . $this->formatMoney($data['total_amount_dom'] ?? 0) . '</strong></td>';
            $html .= '</tr>';
        }

        $html .= '</tbody></table>';

        return $html;
    }

    /** @param array<string, mixed> $data */
    protected function renderSnapshotsHtml(array $data): string
    {
        $supplier = $this->decodeSnapshot($data['supplier_snapshot'] ?? null);
        $customer = $this->decodeSnapshot($data['customer_snapshot'] ?? null);

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

    /** @return array<string, mixed>|null */
    protected function decodeSnapshot(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : null;
        }
        return null;
    }

    /** @param array<string, mixed>|null $snap */
    protected function renderPersonSnapshot(?array $snap): string
    {
        if ($snap === null || $snap === []) {
            return '<p class="muted"><em>Není vyplněno</em></p>';
        }

        $h = '';
        if (!empty($snap['name'])) {
            $h .= '<p class="name"><strong>' . htmlspecialchars((string) $snap['name']) . '</strong></p>';
        }

        $idLines = [];
        if (!empty($snap['company_id'])) {
            $idLines[] = 'IČO: ' . htmlspecialchars((string) $snap['company_id']);
        }
        if (!empty($snap['tax_id'])) {
            $idLines[] = 'DIČ: ' . htmlspecialchars((string) $snap['tax_id']);
        }
        if (!empty($snap['vat_id']) && ($snap['vat_id'] ?? null) !== ($snap['tax_id'] ?? null)) {
            $idLines[] = 'DIČ DPH: ' . htmlspecialchars((string) $snap['vat_id']);
        }
        if ($idLines !== []) {
            $h .= '<p>' . implode('<br>', $idLines) . '</p>';
        }

        $address = $snap['address'] ?? null;
        if (is_array($address) && !empty($address['display_block'])) {
            $h .= '<pre class="address">'
                . htmlspecialchars((string) $address['display_block']) . '</pre>';
        }

        if (!empty($snap['court_registration'])) {
            $h .= '<p class="muted">' . htmlspecialchars((string) $snap['court_registration']) . '</p>';
        }

        $contact = $snap['contact'] ?? null;
        if (is_array($contact)) {
            $contactLines = [];
            if (!empty($contact['email'])) {
                $contactLines[] = 'E-mail: ' . htmlspecialchars((string) $contact['email']);
            }
            if (!empty($contact['phone'])) {
                $contactLines[] = 'Tel: ' . htmlspecialchars((string) $contact['phone']);
            }
            if ($contactLines !== []) {
                $h .= '<p class="contact">' . implode('<br>', $contactLines) . '</p>';
            }
        }

        $bank = $snap['bank_account'] ?? null;
        if (is_array($bank)) {
            $bankLines = [];
            if (!empty($bank['account_number'])) {
                $bankLines[] = htmlspecialchars((string) $bank['account_number']);
            }
            if (!empty($bank['iban'])) {
                $bankLines[] = 'IBAN: ' . htmlspecialchars((string) $bank['iban']);
            }
            if (!empty($bank['bic'])) {
                $bankLines[] = 'BIC: ' . htmlspecialchars((string) $bank['bic']);
            }
            if ($bankLines !== []) {
                $h .= '<p class="bank"><strong>Bankovní spojení:</strong><br>'
                    . implode('<br>', $bankLines) . '</p>';
            }
        }

        $vatReg = $snap['vat_registration'] ?? null;
        if (is_array($vatReg) && !empty($vatReg['vat_id'])) {
            $h .= '<p class="vat-reg"><strong>DIČ pro DPH:</strong> '
                . htmlspecialchars((string) $vatReg['vat_id']) . '</p>';
        }

        return $h;
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    protected function formatMoney(mixed $amount): string
    {
        return number_format((float) ($amount ?? 0), 2, ',', ' ');
    }

    /** @param array<string, mixed> $data */
    protected function formatExchangeRate(array $data): string
    {
        $rate = (float) ($data['exchange_rate'] ?? 1.0);
        $doc = strtoupper((string) ($data['doc_currency'] ?? ''));
        $home = strtoupper((string) ($data['home_currency'] ?? ''));
        return "1 {$doc} = " . rtrim(rtrim(number_format($rate, 4, '.', ''), '0'), '.') . " {$home}";
    }

    /** @param array<string, mixed> $data */
    protected function vatCodeLabel(string $code, array $data): string
    {
        if ($code === '') {
            return $code;
        }
        $vatRegId = $data['vat_registration'] ?? null;
        if ($vatRegId === null || $this->db === null) {
            return $code;
        }
        $reg = $this->db->fetchRow(
            'SELECT `country` FROM `economy_codebooks_vat_registrations` WHERE `id` = %i',
            (int) $vatRegId,
        );
        if ($reg === null || empty($reg['country'])) {
            return $code;
        }
        $cfg = $this->config?->cfgItem('world.vat.' . (string) $reg['country']);
        if (!is_array($cfg) || !isset($cfg['vatCodes'][$code])) {
            return $code;
        }
        $codeDef = $cfg['vatCodes'][$code];
        return (string) ($codeDef['fullName'] ?? $codeDef['name'] ?? $code);
    }
}
