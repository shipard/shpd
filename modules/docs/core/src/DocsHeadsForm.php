<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\Core;

use Shipard\Core\Form\FormDefinition;
use Shipard\Core\Form\FormTab;
use Shipard\Core\Form\RecalculateResult;
use Shipard\Core\Form\TableForm;

/**
 * Form for docs_core_heads (Phase 3).
 *
 * Tabbed layout:
 *   - basic       — header in 8 sections
 *   - rows        — subtable referencing DocRowsForm
 *   - recap       — pre-rendered VAT recap HTML (always present)
 *   - snapshots   — supplier/customer snapshots (only when populated)
 *   - notes       — internal + on-document notes
 *   - attachments
 */
class DocsHeadsForm extends TableForm
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

        return new FormDefinition(
            table: $this->table,
            title: 'Doklad',
            titleNew: 'Nový doklad',
            tabs: $tabs,
            fullSize: true,
        );
    }

    /**
     * Client-side defaults that don't require server lookups.
     * Server-side defaults (fiscal_year, vat_period, snapshots, totals) are
     * computed in DocDocument::beforeSave.
     *
     * @param array<string, mixed> $data
     */
    private function applyClientDefaults(array &$data, bool $isNew): void
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
    private function buildHeaderTab(array $data, bool $isNew): FormTab
    {
        $vatMode = (int) ($data['vat_mode'] ?? 1);
        $hasVat = $vatMode !== 0;
        $docCurrency = strtolower((string) ($data['doc_currency'] ?? 'czk'));
        $homeCurrency = strtolower((string) ($data['home_currency'] ?? 'czk'));
        $hasForeignCurrency = $docCurrency !== '' && $homeCurrency !== ''
            && $docCurrency !== $homeCurrency;
        $partnerId = (int) ($data['partner'] ?? 0);

        $tab = $this->tab('basic', 'Hlavička')
            ->addSeparator('Identifikace')
            ->addSelect('number_series', cols: 2,
                options: $this->resolveNumberSeriesOptions(
                    !empty($data['doc_type']) ? (string) $data['doc_type'] : null,
                ),
                required: true,
                readOnly: !$isNew,
            )
            ->addInput('doc_number', cols: 1, readOnly: true)
            ->addInput('doc_text', cols: 1)

            ->addSeparator('Partner')
            ->addSelect('partner', cols: 2,
                options: $this->resolvePartnerOptions(),
                triggers: 'reload',
            )
            ->addSelect('partner_address', cols: 2,
                options: $this->resolvePartnerAddressOptions($partnerId),
            )
            ->addSelect('partner_bank', cols: 1,
                options: $this->resolvePartnerBankOptions($partnerId),
            )
            ->addInput('partner_bank_account', cols: 1, label: 'Číslo účtu')
            ->addInput('partner_bank_iban', cols: 1, label: 'IBAN')
            ->addInput('partner_bank_bic', cols: 1, label: 'BIC/SWIFT')

            ->addSeparator('Datumy')
            ->addDate('issue_date', cols: 1, required: true, triggers: 'reload')
            ->addDate('due_date', cols: 1)
            ->addDate('accounting_date', cols: 1, required: true)
            ->addDate('vat_duzp', cols: 1, hidden: !$hasVat)
            ->addDate('vat_dppd', cols: 1, hidden: !$hasVat)
            ->addDate('period_from', cols: 1, hint: 'Volitelné, např. pronájem za období')
            ->addDate('period_to', cols: 1)

            ->addSeparator('DPH')
            ->addSelect('vat_mode', cols: 1,
                options: $this->resolveCfgItemOptions('docs.core.vatModes'),
                triggers: 'reload',
            )
            ->addSelect('vat_calc_source', cols: 1,
                options: $this->resolveCfgItemOptions('docs.core.vatCalcSources'),
                hidden: !$hasVat,
            )
            ->addSelect('vat_place', cols: 1,
                options: $this->resolveCfgItemOptions('docs.core.vatPlaces'),
                triggers: 'reload',
                hidden: !$hasVat,
            )
            ->addSelect('vat_registration', cols: 1,
                options: $this->resolveVatRegistrationOptions(),
                triggers: 'reload',
                hidden: !$hasVat,
            )

            ->addSeparator('Měna')
            ->addSelect('doc_currency', cols: 1,
                options: $this->resolveCurrencyOptions(),
                triggers: 'reload',
            )
            ->addInput('home_currency', cols: 1, readOnly: true)
            ->addNumber('exchange_rate', cols: 1, hidden: !$hasForeignCurrency)

            ->addSeparator('Zaokrouhlení')
            ->addSelect('total_rounding_mode', cols: 1,
                options: $this->resolveCfgItemOptions('docs.core.roundingModes'),
            )
            ->addSelect('vat_rounding_mode', cols: 1,
                options: $this->resolveCfgItemOptions('docs.core.roundingModes'),
                hidden: !$hasVat,
            )

            ->addSeparator('Platba')
            ->addSelect('payment_method', cols: 1,
                options: $this->resolveCfgItemOptions('docs.core.paymentMethods'),
            )
            ->addSelect('bank_account', cols: 1,
                options: $this->resolveBankAccountOptions($docCurrency),
            )
            ->addInput('variable_symbol', cols: 1)
            ->addInput('specific_symbol', cols: 1)
            ->addInput('constant_symbol', cols: 1)

            ->addSeparator('Součty')
            ->addNumber('total_base', cols: 1, readOnly: true, label: 'Základ DPH')
            ->addNumber('total_vat', cols: 1, readOnly: true, label: 'DPH')
            ->addNumber('total_amount', cols: 1, readOnly: true, label: 'Celkem')
            ->addNumber('total_rounding', cols: 1, readOnly: true, label: 'Zaokrouhlení');

        return $tab->build();
    }

    private function buildRowsTab(): FormTab
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

    /** @param array<string, mixed> $data */
    private function buildRecapTab(array $data): FormTab
    {
        return $this->tab('recap', 'Rekapitulace DPH')
            ->addHtml($this->renderRecapHtml($data), cols: 4)
            ->build();
    }

    /** @param array<string, mixed> $data */
    private function buildSnapshotsTab(array $data): FormTab
    {
        return $this->tab('snapshots', 'Fakturační údaje')
            ->addHtml($this->renderSnapshotsHtml($data), cols: 4)
            ->build();
    }

    private function buildNotesTab(): FormTab
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
        if ($changedColumn === 'partner' && !empty($data['partner']) && $this->db !== null) {
            $addr = $this->db->fetchRow(
                'SELECT `id` FROM `base_persons_addresses`'
                . ' WHERE `person` = %i'
                . ' ORDER BY (`address_type` = 1) DESC, `order_pos` ASC, `id` ASC'
                . ' LIMIT 1',
                (int) $data['partner'],
            );
            if ($addr !== null && empty($data['partner_address'])) {
                $data['partner_address'] = (int) $addr['id'];
            }

            if (!empty($data['issue_date']) && empty($data['due_date'])) {
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
    private function resolveNumberSeriesOptions(?string $docType = null): array
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
    private function resolvePartnerOptions(): array
    {
        if ($this->db === null) {
            return [];
        }
        $rows = $this->db->fetchAll(
            'SELECT `id`, `full_name` FROM `base_persons_persons`'
            . ' WHERE `docState` IN (10, 40, 80)'
            . ' ORDER BY `full_name` ASC'
            . ' LIMIT 500',
        );
        $options = [];
        foreach ($rows as $row) {
            $options[] = [
                'value' => (int) $row['id'],
                'label' => (string) ($row['full_name'] ?? ''),
            ];
        }
        return $options;
    }

    /** @return list<array{value: int, label: string}> */
    private function resolvePartnerAddressOptions(int $partnerId): array
    {
        if ($this->db === null || $partnerId === 0) {
            return [];
        }
        // base_persons_addresses has no docState column — active records are
        // selected via period validity instead.
        $rows = $this->db->fetchAll(
            'SELECT `id`, `display_line` FROM `base_persons_addresses`'
            . ' WHERE `person` = %i'
            . ' AND (`valid_from` IS NULL OR `valid_from` <= CURDATE())'
            . ' AND (`valid_to`   IS NULL OR `valid_to`   >= CURDATE())'
            . ' ORDER BY `order_pos` ASC, `id` ASC',
            $partnerId,
        );
        $options = [];
        foreach ($rows as $row) {
            $options[] = [
                'value' => (int) $row['id'],
                'label' => (string) ($row['display_line'] ?? ('#' . $row['id'])),
            ];
        }
        return $options;
    }

    /** @return list<array{value: int, label: string}> */
    private function resolvePartnerBankOptions(int $partnerId): array
    {
        if ($this->db === null || $partnerId === 0) {
            return [];
        }
        // base_persons_bank_accounts has no docState column — active records
        // are selected via period validity instead.
        $rows = $this->db->fetchAll(
            'SELECT `id`, `account_number`, `iban` FROM `base_persons_bank_accounts`'
            . ' WHERE `person` = %i'
            . ' AND (`valid_from` IS NULL OR `valid_from` <= CURDATE())'
            . ' AND (`valid_to`   IS NULL OR `valid_to`   >= CURDATE())'
            . ' ORDER BY `id` ASC',
            $partnerId,
        );
        $options = [];
        foreach ($rows as $row) {
            $label = (string) ($row['account_number'] ?? '');
            $iban = (string) ($row['iban'] ?? '');
            if ($iban !== '') {
                $label = $label !== '' ? "{$label} ({$iban})" : $iban;
            }
            if ($label === '') {
                $label = '#' . $row['id'];
            }
            $options[] = ['value' => (int) $row['id'], 'label' => $label];
        }
        return $options;
    }

    /** @return list<array{value: int, label: string}> */
    private function resolveVatRegistrationOptions(): array
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
    private function resolveBankAccountOptions(string $docCurrency): array
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
    private function resolveCurrencyOptions(): array
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
    private function resolveCfgItemOptions(string $cfgItemId): array
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

    /** @param array<string, mixed> $data */
    private function renderRecapHtml(array $data): string
    {
        $recap = $data['vatRecap'] ?? [];
        if (!is_array($recap) || $recap === []) {
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
    private function renderSnapshotsHtml(array $data): string
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
    private function decodeSnapshot(mixed $value): ?array
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
    private function renderPersonSnapshot(?array $snap): string
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

    private function formatMoney(mixed $amount): string
    {
        return number_format((float) ($amount ?? 0), 2, ',', ' ');
    }

    /** @param array<string, mixed> $data */
    private function formatExchangeRate(array $data): string
    {
        $rate = (float) ($data['exchange_rate'] ?? 1.0);
        $doc = strtoupper((string) ($data['doc_currency'] ?? ''));
        $home = strtoupper((string) ($data['home_currency'] ?? ''));
        return "1 {$doc} = " . rtrim(rtrim(number_format($rate, 4, '.', ''), '0'), '.') . " {$home}";
    }

    /** @param array<string, mixed> $data */
    private function vatCodeLabel(string $code, array $data): string
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
