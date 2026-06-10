<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\Core;

use Shipard\Core\Document\Document;
use Shipard\Core\Document\ValidationError;
use Shipard\Core\Document\ValidationResult;
use Shipard\Module\World\Vat\VatRateResolver;

/**
 * Base class for all document types (issued invoice, received invoice, …).
 *
 * Polymorphism: docs_core_heads has `doc_type` (enumString) which resolves
 * to a specific subclass via cfgItem docs.core.docTypes (`subclass` attr).
 * In Phase 1/2 the only concrete subclass is DocsHeadsDocument; Phase 6
 * adds IssuedInvoiceDocument / ReceivedInvoiceDocument in docs.invoicesOut
 * / docs.invoicesIn.
 *
 * The orchestration pipeline runs in `beforeSave`:
 *   1. denormalize doc_type from number_series
 *   2. apply date defaults (accounting_date, vat_duzp, vat_dppd, due_date)
 *   3. apply home_currency from DS config
 *   4. resolve fiscal_year/fiscal_month/vat_period
 *   5. calculateRowPrice + calculateRowVat for each row
 *   6. buildVatRecapitulation (with reverse charge pairs)
 *   7. sumTotals + apply rounding + apply exchange rate to *_dom
 *   8. processStateTransition (assignNumber 10→20, releaseNumber 20→10)
 *   9. maintainSnapshots (buildSnapshots when partner changes / first time)
 *  10. applyVariableSymbolDefault from sequence_number
 */
abstract class DocDocument extends Document
{
    /** Default split for due_date when partner has no payment_term_days. */
    private const DEFAULT_PAYMENT_TERM_DAYS = 14;

    /** Snapshots are built/refreshed only in editable confirmed states. */
    private const SNAPSHOT_STATES = [20, 80];

    /** Active doc states for resolver lookups (Koncept, V pořádku, V opravě). */
    private const ACTIVE_DOC_STATES = [10, 40, 80];

    private ?VatRateResolver $vatRateResolver = null;
    private ?OwnCompanyResolver $ownCompanyResolver = null;
    private ?PersonSnapshotBuilder $personSnapshotBuilder = null;

    /**
     * Rows after the compute pipeline (prices, VAT, _dom amounts) from the
     * last beforeSave run. afterPersist persists their computed columns to
     * DB — Phase 2 (accounting engine) reads row values from DB, so they
     * must stay current even for rows managed via the sub-form.
     *
     * @var array<int, array<string, mixed>>
     */
    protected array $computedRows = [];

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

        $newState = (int) ($data['docState'] ?? 10);

        if (in_array($newState, [20, 40, 80], true)) {
            if (empty($data['partner'])) {
                $result->addError('partner', 'Partner je povinný', 'required');
            }
            $vatMode = (int) ($data['vat_mode'] ?? 1);
            if ($vatMode !== 0 && empty($data['vat_registration'])) {
                $result->addError('vat_registration', 'Registrace DPH je povinná', 'required');
            }
            $rows = $data['rows'] ?? null;
            if (!is_array($rows) || count($rows) === 0) {
                $result->addError('rows', 'Doklad musí mít alespoň jeden řádek', 'no_rows');
            }
            if (!empty($data['doc_currency']) && !empty($data['home_currency'])
                && $data['doc_currency'] !== $data['home_currency']
                && empty($data['exchange_rate'])
            ) {
                $result->addError('exchange_rate', 'Kurz je povinný pro cizí měnu', 'required');
            }

            // Own company must be configured before confirming any document
            if ($this->db !== null) {
                $resolver = $this->ownCompanyResolver();
                if ($resolver->getOwnPersonId() === null) {
                    $result->addError(
                        ValidationError::FIELD_FORM,
                        'Není nastavena vlastní firma. Otevři Osoby a označ záznam jako vlastní firmu.',
                        'no_own_company',
                    );
                }
            }
        }

        if ($newState === 40) {
            $this->validateRowOperations($data, $result);
        }

        return $result;
    }

    /**
     * Záchytná síť pro pohyby řádků při přechodu do 40 (V pořádku): řádky
     * uložené před zavedením pohybů nebo importem nešly přes
     * DocRowsDocument::validate, proto se tady zvalidují všechny znovu.
     * Chyby s konvencí `rows.{index}.{column}`.
     */
    protected function validateRowOperations(array &$data, ValidationResult $result): void
    {
        $cfg = $this->config?->cfgItem('docs.core.rowOperations');
        if (!is_array($cfg)) {
            return;
        }

        // validate() runs before beforeSave, so doc_type may not be
        // denormalized yet — idempotent, beforeSave repeats it.
        $this->denormalizeDocType($data);
        $docType = (string) ($data['doc_type'] ?? '');
        if ($docType === '') {
            return;
        }

        foreach ($this->resolveRowsForCompute($data) as $i => $row) {
            foreach (DocRowOperationRules::validateRow($row, $docType, $cfg) as $err) {
                $result->addError("rows.{$i}.{$err['column']}", $err['message'], $err['code']);
            }
        }
    }

    public function beforeSave(array &$data, ?array $originalData = null): void
    {
        // Import mode marker — virtual field, must be consumed + removed before
        // SQL. TableGateway::insertRow does not filter unknown columns, so a
        // leftover `_importNumber` would reach the INSERT and blow up with
        // "unknown column". Pull it out first, branch on it at the end.
        $importNumber = $data['_importNumber'] ?? null;
        unset($data['_importNumber']);

        $this->trackStateChange($data, $originalData);

        $this->denormalizeDocType($data);
        $this->applyDateDefaults($data);
        $this->applyHomeCurrency($data);
        $this->resolveAccountingPeriods($data);

        // Resolve rows for computation. Two scenarios:
        //   1. Client sent rows in payload (e.g. future mass-edit flow) — use them.
        //   2. Header-only save — rows are managed via sub-form, so they're
        //      not in the payload. Load current state from DB so totals and
        //      VAT recap reflect reality.
        // We compute on a local variable and never write rows back to $data:
        // TableGateway only syncs child sets that are present in $data, so
        // omitting them protects existing DB rows from being wiped.
        $vatMode = (int) ($data['vat_mode'] ?? 1);
        $rowsForCompute = $this->resolveRowsForCompute($data);
        foreach ($rowsForCompute as &$row) {
            $this->calculateRowPrice($row);
            $this->calculateRowVat($row, $vatMode);
        }
        unset($row);

        $recap = $this->buildVatRecapitulation($data, $rowsForCompute);
        $data['vatRecap'] = $recap;

        $this->sumTotals($data, $recap);
        $this->applyTotalRounding($data);
        $this->applyDomesticAmounts($data, $rowsForCompute, $recap);

        // Propagate computed values back into the payload child set so the
        // gateway's child sync writes them (covers new rows without id).
        // Only when the key already exists — adding it would trigger child
        // sync and wipe DB rows on header-only saves.
        if (array_key_exists('rows', $data) && is_array($data['rows'])) {
            $data['rows'] = $rowsForCompute;
        }
        $this->computedRows = $rowsForCompute;

        // Number assignment: import mode forces the document's own number;
        // otherwise normal state-transition assignment from the series counter.
        if (is_array($importNumber)) {
            $this->applyImportNumber($data, $importNumber);
        } else {
            $this->processStateTransition($data, $originalData);
        }

        $this->maintainSnapshots($data, $originalData);
        $this->applyVariableSymbolDefault($data);
    }

    /**
     * Get the rows we should compute on. If the client provided rows in the
     * payload, use those. Otherwise read current state from the database.
     * Returns an empty array for new records (no id yet).
     *
     * @return array<int, array<string, mixed>>
     */
    protected function resolveRowsForCompute(array $data): array
    {
        if (array_key_exists('rows', $data) && is_array($data['rows'])) {
            return $data['rows'];
        }
        if (empty($data['id']) || $this->db === null) {
            return [];
        }
        $rows = $this->db->fetchAll(
            'SELECT * FROM [docs_core_rows] WHERE [doc_head] = %i ORDER BY [order_pos]',
            (int) $data['id'],
        );
        return array_map(
            fn($r) => $r instanceof \Dibi\Row ? $r->toArray() : (array) $r,
            $rows,
        );
    }

    /**
     * Eviduje čas poslední změny `docState`. Vstup pro alert check
     * `docs.core.stale_in_repair` (doklady visící v 80 V opravě > 24 h).
     *
     * - Nové záznamy (`$originalData === null`): nastav na NOW, ať od prvního
     *   uložení existuje validní hodnota.
     * - Update s nezměněným `docState`: zachovej původní hodnotu — klient ji
     *   v payloadu nemá nastavovat (sloupec je `system: true`).
     * - Update se změněným `docState`: nastav na NOW.
     */
    protected function trackStateChange(array &$data, ?array $originalData): void
    {
        $this->stateTransition = null;

        if ($originalData === null) {
            $data['doc_state_changed_at'] = date('Y-m-d H:i:s');
            // Nový záznam vzniklý rovnou mimo Koncept (import) je taky
            // přechod — old = 0, ať se importované doklady ve 40 zaúčtují.
            $newState = (int) ($data['docState'] ?? 10);
            if ($newState !== 10) {
                $this->stateTransition = ['old' => 0, 'new' => $newState];
            }
            return;
        }

        $newState = (int) ($data['docState'] ?? $originalData['docState'] ?? 10);
        $oldState = (int) ($originalData['docState'] ?? 10);

        if ($newState !== $oldState) {
            $data['doc_state_changed_at'] = date('Y-m-d H:i:s');
            $this->stateTransition = ['old' => $oldState, 'new' => $newState];
            return;
        }

        // Same state — preserve original. Fallback to NOW if pre-backfill row
        // somehow still has NULL (defensive, should not happen post-upgrade).
        $data['doc_state_changed_at'] = $originalData['doc_state_changed_at']
            ?? date('Y-m-d H:i:s');
    }

    public function afterPersist(array $data): void
    {
        if ($this->db === null || empty($data['id'])) {
            return;
        }

        $this->ensureDocNumberPlaceholder($data);
        $this->persistRowComputedColumns();
    }

    private function ensureDocNumberPlaceholder(array $data): void
    {
        $current = $this->db?->fetch(
            'SELECT [doc_number] FROM [docs_core_heads] WHERE [id] = %i',
            (int) $data['id'],
        );
        if ($current === null) {
            return;
        }

        $docNumber = (string) ($current['doc_number'] ?? '');
        if ($docNumber !== '') {
            return;
        }

        $placeholder = '!' . str_pad((string) $data['id'], 10, '0', STR_PAD_LEFT);
        $this->executeSql(
            'UPDATE [docs_core_heads] SET [doc_number] = %s WHERE [id] = %i',
            $placeholder,
            (int) $data['id'],
        );
    }

    /**
     * Persist computed row columns from the last beforeSave run by direct
     * per-id UPDATEs — never through the child-sync payload (missing 'rows'
     * key must keep protecting DB rows from a wipe). Rows without id (new
     * rows in a full-sync payload) are written by the gateway's child sync
     * instead, because beforeSave propagates computed values into
     * $data['rows'].
     *
     * Public: DocRowsDocument::recomputeHeader calls it inside its own
     * transaction after re-running the compute pipeline.
     */
    public function persistRowComputedColumns(): void
    {
        if ($this->db === null) {
            return;
        }
        foreach ($this->computedRows as $row) {
            $rowId = (int) ($row['id'] ?? 0);
            if ($rowId === 0) {
                continue;
            }
            $this->executeSql(
                'UPDATE [docs_core_rows] SET %a WHERE [id] = %i',
                [
                    'vat_base'       => $row['vat_base']       ?? null,
                    'vat_amount'     => $row['vat_amount']     ?? null,
                    'vat_total'      => $row['vat_total']      ?? null,
                    'vat_base_dom'   => $row['vat_base_dom']   ?? null,
                    'vat_amount_dom' => $row['vat_amount_dom'] ?? null,
                    'vat_total_dom'  => $row['vat_total_dom']  ?? null,
                ],
                $rowId,
            );
        }
    }

    // ── Defaults ────────────────────────────────────────────────────────────

    protected function denormalizeDocType(array &$data): void
    {
        if (empty($data['number_series']) || $this->db === null) {
            return;
        }
        $row = $this->db->fetch(
            'SELECT [doc_type] FROM [docs_core_number_series] WHERE [id] = %i',
            (int) $data['number_series'],
        );
        if ($row !== null) {
            $data['doc_type'] = (string) $row['doc_type'];
        }
    }

    protected function applyDateDefaults(array &$data): void
    {
        if (!empty($data['issue_date'])) {
            if (empty($data['accounting_date'])) {
                $data['accounting_date'] = $data['issue_date'];
            }
            if (empty($data['vat_duzp'])) {
                $data['vat_duzp'] = $data['issue_date'];
            }
        }
        if (!empty($data['vat_duzp']) && empty($data['vat_dppd'])) {
            $data['vat_dppd'] = $data['vat_duzp'];
        }
        if (!empty($data['issue_date']) && empty($data['due_date'])) {
            $days = $this->resolvePartnerPaymentTermDays($data['partner'] ?? null)
                ?? self::DEFAULT_PAYMENT_TERM_DAYS;
            $data['due_date'] = (new \DateTimeImmutable((string) $data['issue_date']))
                ->modify("+{$days} days")
                ->format('Y-m-d');
        }
    }

    protected function resolvePartnerPaymentTermDays(mixed $partnerId): ?int
    {
        if ($partnerId === null || $this->db === null) {
            return null;
        }
        $row = $this->db->fetch(
            'SELECT [payment_term_days] FROM [base_persons_persons] WHERE [id] = %i',
            (int) $partnerId,
        );
        if ($row === null) {
            return null;
        }
        $days = $row['payment_term_days'] ?? null;
        return $days !== null ? (int) $days : null;
    }

    protected function applyHomeCurrency(array &$data): void
    {
        if (empty($data['home_currency'])) {
            $data['home_currency'] = $this->dsConfig?->getDefaultCurrency() ?? 'czk';
        }
        if (empty($data['doc_currency'])) {
            $data['doc_currency'] = $data['home_currency'];
        }
    }

    // ── Accounting period resolvers ─────────────────────────────────────────

    protected function resolveAccountingPeriods(array &$data): void
    {
        if (!empty($data['accounting_date'])) {
            $data['fiscal_year']  = $this->resolveFiscalYearId((string) $data['accounting_date']);
            $data['fiscal_month'] = $this->resolveFiscalMonthId((string) $data['accounting_date']);
        }
        if (!empty($data['vat_duzp']) && !empty($data['vat_registration'])) {
            $data['vat_period'] = $this->resolveVatPeriodId(
                (string) $data['vat_duzp'],
                (int) $data['vat_registration'],
            );
        }
    }

    protected function resolveFiscalYearId(string $accountingDate): ?int
    {
        if ($this->db === null || $accountingDate === '') {
            return null;
        }
        $row = $this->db->fetch(
            'SELECT [id] FROM [economy_codebooks_fiscal_years]
             WHERE [date_begin] <= %d AND [date_end] >= %d
               AND [docState] IN (%i, %i, %i)
             ORDER BY [date_begin] DESC
             LIMIT 1',
            $accountingDate, $accountingDate,
            self::ACTIVE_DOC_STATES[0], self::ACTIVE_DOC_STATES[1], self::ACTIVE_DOC_STATES[2],
        );
        return $row !== null ? (int) $row['id'] : null;
    }

    protected function resolveFiscalMonthId(string $accountingDate): ?int
    {
        if ($this->db === null || $accountingDate === '') {
            return null;
        }
        // Pick regular months (period_type = 1), skip Opening (0) and Closing (2)
        $row = $this->db->fetch(
            'SELECT [id] FROM [economy_codebooks_fiscal_months]
             WHERE [date_begin] <= %d AND [date_end] >= %d AND [period_type] = 1
             LIMIT 1',
            $accountingDate, $accountingDate,
        );
        return $row !== null ? (int) $row['id'] : null;
    }

    protected function resolveVatPeriodId(?string $vatDuzp, ?int $vatRegistrationId): ?int
    {
        if ($vatDuzp === null || $vatRegistrationId === null || $this->db === null) {
            return null;
        }
        $row = $this->db->fetch(
            'SELECT [id] FROM [economy_codebooks_vat_periods]
             WHERE [vat_registration] = %i
               AND [date_begin] <= %d AND [date_end] >= %d
               AND [docState] IN (%i, %i, %i)
             LIMIT 1',
            $vatRegistrationId, $vatDuzp, $vatDuzp,
            self::ACTIVE_DOC_STATES[0], self::ACTIVE_DOC_STATES[1], self::ACTIVE_DOC_STATES[2],
        );
        return $row !== null ? (int) $row['id'] : null;
    }

    // ── Row calculations ────────────────────────────────────────────────────

    protected function calculateRowPrice(array &$row): void
    {
        $rowKind = (int) ($row['row_kind'] ?? 1);
        if ($rowKind !== 1) {
            $row['total_price'] = null;
            return;
        }

        $qty = (float) ($row['quantity'] ?? 0);
        $mode = (int) ($row['price_calc_mode'] ?? 0);

        if ($mode === 0) {
            $unitPrice = (float) ($row['unit_price'] ?? 0);
            $row['total_price'] = round($qty * $unitPrice, 2);
        } else {
            $totalPrice = (float) ($row['total_price'] ?? 0);
            $row['unit_price'] = $qty > 0 ? round($totalPrice / $qty, 4) : 0.0;
        }

        // Apply discount (pct OR amount, not both)
        $totalPrice = (float) ($row['total_price'] ?? 0);
        if (!empty($row['discount_pct'])) {
            $discount = round($totalPrice * ((float) $row['discount_pct']) / 100.0, 2);
            $row['total_price'] = round($totalPrice - $discount, 2);
        } elseif (!empty($row['discount_amount'])) {
            $row['total_price'] = round($totalPrice - (float) $row['discount_amount'], 2);
        }
    }

    protected function calculateRowVat(array &$row, int $vatMode): void
    {
        $rowKind = (int) ($row['row_kind'] ?? 1);
        if ($rowKind !== 1) {
            $row['vat_base'] = null;
            $row['vat_amount'] = null;
            $row['vat_total'] = null;
            return;
        }

        $totalPrice = (float) ($row['total_price'] ?? 0);

        if ($vatMode === 0 || empty($row['vat_code']) || empty($row['vat_pct'])) {
            $row['vat_base']   = $totalPrice;
            $row['vat_amount'] = 0.0;
            $row['vat_total']  = $totalPrice;
            return;
        }

        $pct = (float) $row['vat_pct'];

        if ($vatMode === 1) {
            // From base — total_price is the base
            $row['vat_base']   = $totalPrice;
            $row['vat_amount'] = round($totalPrice * $pct / 100.0, 2);
            $row['vat_total']  = round($row['vat_base'] + $row['vat_amount'], 2);
        } else {
            // From total (vat_mode === 2) — total_price includes VAT
            $row['vat_total']  = $totalPrice;
            $row['vat_base']   = round($totalPrice / (1.0 + $pct / 100.0), 2);
            $row['vat_amount'] = round($row['vat_total'] - $row['vat_base'], 2);
        }
    }

    // ── VAT recapitulation ──────────────────────────────────────────────────

    /**
     * @param array<int, array<string, mixed>> $rowsOverride Pre-computed rows; falls back to $data['rows'] when empty.
     * @return array<int, array<string, mixed>>
     */
    protected function buildVatRecapitulation(array &$data, array $rowsOverride = []): array
    {
        $rows = $rowsOverride !== []
            ? $rowsOverride
            : ($data['rows'] ?? []);
        if (!is_array($rows) || $rows === []) {
            return [];
        }

        $vatRegId = $data['vat_registration'] ?? null;
        $countryCode = $this->resolveCountryFromVatRegistration($vatRegId);
        if ($countryCode === null) {
            return [];
        }

        try {
            $vatCodes = $this->vatRateResolver()->getVatCodes(
                $countryCode,
                direction: null,
                place: null,
                includeHidden: true,
            );
        } catch (\LogicException) {
            return [];
        }

        // 1. Group rows by (vat_code, vat_pct), sum base
        $grouped = [];
        foreach ($rows as $row) {
            $rowKind = (int) ($row['row_kind'] ?? 1);
            if ($rowKind !== 1 || empty($row['vat_code']) || empty($row['vat_pct'])) {
                continue;
            }
            $key = $row['vat_code'] . '|' . $row['vat_pct'];
            if (!isset($grouped[$key])) {
                $grouped[$key] = [
                    'vat_code' => (string) $row['vat_code'],
                    'vat_pct'  => (float) $row['vat_pct'],
                    'base'     => 0.0,
                ];
            }
            $grouped[$key]['base'] += (float) ($row['total_price'] ?? 0);
        }

        // 2. For each group build primary line + optional reverse charge pair
        $recap = [];
        $sortOrder = 0;
        $exchRate = (float) ($data['exchange_rate'] ?? 1.0);
        if ($exchRate <= 0) {
            $exchRate = 1.0;
        }
        $vatRoundingMode = (int) ($data['vat_rounding_mode'] ?? 2);

        foreach ($grouped as $entry) {
            $code = $entry['vat_code'];
            if (!isset($vatCodes[$code])) {
                continue;
            }
            $codeDef = $vatCodes[$code];

            $base = round($entry['base'], 2);
            $tax  = empty($codeDef['noPayTax'])
                ? $this->applyRounding($base * $entry['vat_pct'] / 100.0, $vatRoundingMode)
                : 0.0;

            $primary = [
                'vat_code'        => $code,
                'vat_pct'         => $entry['vat_pct'],
                'base'            => $base,
                'tax'             => $tax,
                'total'           => round($base + $tax, 2),
                'sum_base'        => (int) ($codeDef['sumBase']  ?? 1),
                'sum_tax'         => (int) ($codeDef['sumTax']   ?? 1),
                'sum_total'       => (int) ($codeDef['sumTotal'] ?? 1),
                'is_reverse_pair' => 0,
                'order_pos'       => $sortOrder++,
            ];
            $primary['base_dom']  = round($primary['base']  * $exchRate, 2);
            $primary['tax_dom']   = round($primary['tax']   * $exchRate, 2);
            $primary['total_dom'] = round($primary['total'] * $exchRate, 2);
            $recap[] = $primary;

            // Reverse charge — generate paired (oddanění) row
            if (!empty($codeDef['reverseVatCode']) && isset($vatCodes[$codeDef['reverseVatCode']])) {
                $reverseCodeKey = (string) $codeDef['reverseVatCode'];
                $reverseDef     = $vatCodes[$reverseCodeKey];

                try {
                    $reversePct = $this->vatRateResolver()->resolveVatPct(
                        $countryCode,
                        $reverseCodeKey,
                        (string) ($data['vat_duzp'] ?? date('Y-m-d')),
                    );
                } catch (\LogicException) {
                    // Unknown rate for date — skip pair generation rather than crash.
                    continue;
                }
                $reverseTax = $this->applyRounding($base * $reversePct / 100.0, $vatRoundingMode);

                $paired = [
                    'vat_code'        => $reverseCodeKey,
                    'vat_pct'         => $reversePct,
                    'base'            => $base,
                    'tax'             => $reverseTax,
                    'total'           => round($base + $reverseTax, 2),
                    'sum_base'        => (int) ($reverseDef['sumBase']  ?? 1),
                    'sum_tax'         => (int) ($reverseDef['sumTax']   ?? 1),
                    'sum_total'       => (int) ($reverseDef['sumTotal'] ?? 1),
                    'is_reverse_pair' => 1,
                    'order_pos'       => $sortOrder++,
                ];
                $paired['base_dom']  = round($paired['base']  * $exchRate, 2);
                $paired['tax_dom']   = round($paired['tax']   * $exchRate, 2);
                $paired['total_dom'] = round($paired['total'] * $exchRate, 2);
                $recap[] = $paired;
            }
        }

        return $recap;
    }

    protected function resolveCountryFromVatRegistration(mixed $vatRegId): ?string
    {
        if ($vatRegId === null || $this->db === null) {
            return null;
        }
        $row = $this->db->fetch(
            'SELECT [country] FROM [economy_codebooks_vat_registrations] WHERE [id] = %i',
            (int) $vatRegId,
        );
        return $row !== null ? (string) $row['country'] : null;
    }

    // ── Totals, rounding, exchange ──────────────────────────────────────────

    protected function sumTotals(array &$data, array $recap): void
    {
        $base = 0.0;
        $vat  = 0.0;
        $total = 0.0;

        foreach ($recap as $r) {
            if (!empty($r['sum_base']))  { $base  += (float) $r['base']; }
            if (!empty($r['sum_tax']))   { $vat   += (float) $r['tax']; }
            if (!empty($r['sum_total'])) { $total += (float) $r['total']; }
        }

        $data['total_base']     = round($base, 2);
        $data['total_vat']      = round($vat, 2);
        $data['total_amount']   = round($total, 2);
        $data['total_rounding'] = 0.0;
    }

    protected function applyTotalRounding(array &$data): void
    {
        $original = (float) ($data['total_amount'] ?? 0);
        $mode = (int) ($data['total_rounding_mode'] ?? 0);
        $rounded = $this->applyRounding($original, $mode);
        $data['total_amount']   = $rounded;
        $data['total_rounding'] = round($rounded - $original, 2);
    }

    protected function applyRounding(float $amount, int $mode): float
    {
        return match ($mode) {
            1       => (float) round($amount, 0),  // Whole units
            2       => round($amount, 2),          // 0.01
            default => round($amount, 2),          // No rounding (still 2 decimals)
        };
    }

    /**
     * Domácí měna pro řádky, rekapitulaci a hlavičku — top-down dorovnání.
     *
     * Závazné jsou head totals: rekapitulace se nedopočítává (base_dom/tax_dom
     * = round(cur × rate) z buildVatRecapitulation), head se sčítá z ní a
     * řádky se dorovnávají na rekapitulaci. Výsledné invarianty (testované):
     *
     *   Σ rows.vat_base_dom   (per vat_code+pct) == recap.base_dom
     *   Σ rows.vat_amount_dom (per vat_code+pct) == recap.tax_dom
     *   Σ recap.base_dom (sum_base=1) == total_base_dom
     *   Σ recap.tax_dom  (sum_tax=1)  == total_vat_dom
     *   total_base_dom + total_vat_dom + total_rounding_dom == total_amount_dom
     *
     * total_rounding_dom je odvozený (amount − base − vat), ne kurzový —
     * absorbuje haléřový rozdíl, takže poslední invariant platí konstrukčně.
     * Při rate = 1 jsou všechny diffy nulové a _dom = kopie cur hodnot.
     *
     * @param array<int, array<string, mixed>> $rows Rows after calculateRowPrice/Vat
     * @param array<int, array<string, mixed>> $recap From buildVatRecapitulation
     */
    protected function applyDomesticAmounts(array &$data, array &$rows, array $recap): void
    {
        $exchRate = (float) ($data['exchange_rate'] ?? 1.0);
        if ($exchRate <= 0) {
            $exchRate = 1.0;
        }

        // 1. Per-row domestic amounts; text rows stay NULL
        foreach ($rows as &$row) {
            if ((int) ($row['row_kind'] ?? 1) !== 1) {
                $row['vat_base_dom']   = null;
                $row['vat_amount_dom'] = null;
                $row['vat_total_dom']  = null;
                continue;
            }
            $row['vat_base_dom']   = round((float) ($row['vat_base'] ?? 0)   * $exchRate, 2);
            $row['vat_amount_dom'] = round((float) ($row['vat_amount'] ?? 0) * $exchRate, 2);
        }
        unset($row);

        // 2. Head: base/vat as recap sums (same sum flags as sumTotals — NOT
        //    an independent rate conversion), amount by rate, rounding derived.
        $baseDom = 0.0;
        $vatDom  = 0.0;
        foreach ($recap as $r) {
            if (!empty($r['sum_base'])) { $baseDom += (float) ($r['base_dom'] ?? 0); }
            if (!empty($r['sum_tax']))  { $vatDom  += (float) ($r['tax_dom']  ?? 0); }
        }
        $data['total_base_dom']     = round($baseDom, 2);
        $data['total_vat_dom']      = round($vatDom, 2);
        $data['total_amount_dom']   = round((float) ($data['total_amount'] ?? 0) * $exchRate, 2);
        $data['total_rounding_dom'] = round(
            (float) $data['total_amount_dom']
            - (float) $data['total_base_dom']
            - (float) $data['total_vat_dom'],
            2,
        );

        // 3. Reconcile rows to recap per (vat_code, vat_pct) group. Reverse
        //    charge pairs have no document rows — skip them. The rounding
        //    diff goes to the last row of the group with a nonzero cur value.
        foreach ($recap as $r) {
            if (!empty($r['is_reverse_pair'])) {
                continue;
            }
            $key = $this->vatGroupKey($r['vat_code'] ?? '', $r['vat_pct'] ?? 0);

            $sumBase = 0.0;
            $sumAmount = 0.0;
            $lastIdx = null;
            $lastBaseIdx = null;
            $lastAmountIdx = null;
            foreach ($rows as $i => $row) {
                if ((int) ($row['row_kind'] ?? 1) !== 1
                    || empty($row['vat_code']) || empty($row['vat_pct'])
                    || $this->vatGroupKey($row['vat_code'], $row['vat_pct']) !== $key
                ) {
                    continue;
                }
                $sumBase   += (float) ($row['vat_base_dom'] ?? 0);
                $sumAmount += (float) ($row['vat_amount_dom'] ?? 0);
                $lastIdx = $i;
                if ((float) ($row['vat_base'] ?? 0) !== 0.0)   { $lastBaseIdx = $i; }
                if ((float) ($row['vat_amount'] ?? 0) !== 0.0) { $lastAmountIdx = $i; }
            }
            if ($lastIdx === null) {
                continue;
            }

            $diffBase = round((float) ($r['base_dom'] ?? 0) - $sumBase, 2);
            if ($diffBase !== 0.0) {
                $t = $lastBaseIdx ?? $lastIdx;
                $rows[$t]['vat_base_dom'] = round((float) $rows[$t]['vat_base_dom'] + $diffBase, 2);
            }
            $diffAmount = round((float) ($r['tax_dom'] ?? 0) - $sumAmount, 2);
            if ($diffAmount !== 0.0) {
                $t = $lastAmountIdx ?? $lastIdx;
                $rows[$t]['vat_amount_dom'] = round((float) $rows[$t]['vat_amount_dom'] + $diffAmount, 2);
            }
        }

        // 4. Row home-currency totals from the final reconciled parts
        foreach ($rows as &$row) {
            if ((int) ($row['row_kind'] ?? 1) !== 1) {
                continue;
            }
            $row['vat_total_dom'] = round(
                (float) ($row['vat_base_dom'] ?? 0) + (float) ($row['vat_amount_dom'] ?? 0),
                2,
            );
        }
        unset($row);
    }

    /**
     * Normalized group key matching buildVatRecapitulation's grouping —
     * pct via float cast so '21.00' (DB) and 21.0 (recap) compare equal.
     */
    private function vatGroupKey(mixed $vatCode, mixed $vatPct): string
    {
        return (string) $vatCode . '|' . (string) (float) $vatPct;
    }

    // ── Number assignment ───────────────────────────────────────────────────

    protected function processStateTransition(array &$data, ?array $originalData): void
    {
        $newState = (int) ($data['docState'] ?? 10);
        $oldState = (int) ($originalData['docState'] ?? $newState);

        if ($oldState === 10 && $newState === 20) {
            $this->assignDocumentNumber($data);
            return;
        }
        if ($oldState === 20 && $newState === 10) {
            $this->releaseDocumentNumber($data, $originalData);
            return;
        }
    }

    /**
     * Import mode: store the document's own number + sequence verbatim and sync
     * the series counter to the highest used sequence. Replaces
     * assignDocumentNumber for migrated documents.
     *
     * Counter sync uses GREATEST so it is:
     *   - idempotent (re-importing the same doc never lowers the counter),
     *   - order-independent (importing 7, then 3, leaves counter at 7),
     *   - hole-tolerant (deleted source docs leave gaps; counter still ends at
     *     the true maximum so the next new doc continues correctly).
     *
     * The counter key (number_series, fiscal_year) is computed identically to
     * assignDocumentNumber — same reset_scope handling, same NULL-safe `<=>`
     * match — otherwise the sync would miss and the next new doc would not
     * continue from the imported sequence.
     *
     * @param array<string, mixed> $importNumber {docNumber: string, sequenceNumber: int}
     */
    protected function applyImportNumber(array &$data, array $importNumber): void
    {
        $docNumber = (string) ($importNumber['docNumber'] ?? '');
        $sequence  = (int) ($importNumber['sequenceNumber'] ?? 0);

        if ($docNumber === '' || $sequence <= 0) {
            // Defensive: malformed import payload — fall back to normal
            // assignment rather than persisting an empty/placeholder number.
            $this->processStateTransition($data, null);
            return;
        }

        $data['doc_number']      = $docNumber;
        $data['sequence_number'] = $sequence;

        $seriesId = (int) ($data['number_series'] ?? 0);
        if ($seriesId === 0 || $this->db === null) {
            return;
        }

        // Mirror assignDocumentNumber's counter key: per (number_series,
        // fiscal_year) for reset_scope = 'fiscal_year', else fiscal_year = NULL.
        $resetScope = $this->numberSeriesResetScope($seriesId);
        $fyId = ($resetScope === 'fiscal_year')
            ? ($data['fiscal_year'] ?? null)   // already resolved by resolveAccountingPeriods()
            : null;

        // Two-step, NULL-safe (matches assignDocumentNumber): INSERT IGNORE the
        // counter row, then bump it via GREATEST. A single ON DUPLICATE KEY
        // UPDATE would not fire for fiscal_year = NULL rows (UNIQUE treats NULL
        // as distinct in MariaDB).
        $this->executeSql(
            'INSERT IGNORE INTO [docs_core_number_counters]
                ([number_series], [fiscal_year], [last_assigned])
             VALUES (%i, %iN, 0)',
            $seriesId, $fyId,
        );
        $this->executeSql(
            'UPDATE [docs_core_number_counters]
             SET [last_assigned] = GREATEST([last_assigned], %i)
             WHERE [number_series] = %i AND [fiscal_year] <=> %iN',
            $sequence, $seriesId, $fyId,
        );
    }

    /**
     * Read a number series' reset_scope (default 'fiscal_year'). Used by
     * applyImportNumber to compute the same counter key as
     * assignDocumentNumber.
     */
    protected function numberSeriesResetScope(int $seriesId): string
    {
        if ($this->db === null || $seriesId === 0) {
            return 'fiscal_year';
        }
        $row = $this->db->fetch(
            'SELECT [reset_scope] FROM [docs_core_number_series] WHERE [id] = %i',
            $seriesId,
        );
        return $row !== null ? (string) ($row['reset_scope'] ?? 'fiscal_year') : 'fiscal_year';
    }

    protected function assignDocumentNumber(array &$data): void
    {
        if ($this->db === null) {
            throw new \LogicException('No DB connection available');
        }
        $seriesId = (int) ($data['number_series'] ?? 0);
        if ($seriesId === 0) {
            throw new \LogicException('Cannot assign number — number_series missing');
        }

        $seriesRow = $this->db->fetch(
            'SELECT * FROM [docs_core_number_series] WHERE [id] = %i',
            $seriesId,
        );
        if ($seriesRow === null) {
            throw new \LogicException("Number series id={$seriesId} not found");
        }
        $series = $seriesRow->toArray();

        $resetScope = (string) ($series['reset_scope'] ?? 'fiscal_year');
        $fyId = ($resetScope === 'fiscal_year')
            ? $this->resolveFiscalYearId((string) ($data['accounting_date'] ?? ''))
            : null;

        $this->db->begin();
        try {
            // Idempotent counter init
            $this->executeSql(
                'INSERT IGNORE INTO [docs_core_number_counters]
                 ([number_series], [fiscal_year], [last_assigned])
                 VALUES (%i, %iN, 0)',
                $seriesId, $fyId,
            );

            // Lock + read counter (NULL-safe equality for fiscal_year)
            $row = $this->db->fetch(
                'SELECT [last_assigned] FROM [docs_core_number_counters]
                 WHERE [number_series] = %i AND [fiscal_year] <=> %iN
                 FOR UPDATE',
                $seriesId, $fyId,
            );
            $current = (int) ($row['last_assigned'] ?? 0);
            $newSeq = $current + 1;

            $this->executeSql(
                'UPDATE [docs_core_number_counters]
                 SET [last_assigned] = %i
                 WHERE [number_series] = %i AND [fiscal_year] <=> %iN',
                $newSeq, $seriesId, $fyId,
            );

            $data['sequence_number'] = $newSeq;
            $data['fiscal_year']     = $fyId;
            $data['doc_number']      = $this->resolvePattern(
                (string) $series['doc_number_pattern'],
                $data,
                $series,
            );

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    protected function releaseDocumentNumber(array &$data, ?array $originalData): void
    {
        if ($this->db === null || $originalData === null) {
            throw new \LogicException('Cannot release number without original data');
        }

        $seriesId = (int) ($originalData['number_series'] ?? 0);
        $fyId     = $originalData['fiscal_year'] ?? null;
        $sequence = (int) ($originalData['sequence_number'] ?? 0);

        if ($seriesId === 0 || $sequence === 0) {
            $data['sequence_number'] = null;
            $data['doc_number']      = '';
            return;
        }

        $maxRow = $this->db->fetch(
            'SELECT MAX([sequence_number]) AS [max_seq]
             FROM [docs_core_heads]
             WHERE [number_series] = %i AND [fiscal_year] <=> %iN',
            $seriesId, $fyId,
        );
        $maxSeq = (int) ($maxRow['max_seq'] ?? 0);

        if ($maxSeq !== $sequence) {
            throw new \DomainException(
                "Doklad #{$sequence} není poslední v řadě (poslední je #{$maxSeq}). "
                . "Vrácení do Konceptu by vytvořilo díru v sekvenci.",
            );
        }

        $this->db->begin();
        try {
            $this->executeSql(
                'UPDATE [docs_core_number_counters]
                 SET [last_assigned] = [last_assigned] - 1
                 WHERE [number_series] = %i AND [fiscal_year] <=> %iN AND [last_assigned] = %i',
                $seriesId, $fyId, $sequence,
            );

            $data['sequence_number'] = null;
            $data['fiscal_year']     = null;
            $data['doc_number']      = !empty($data['id'])
                ? '!' . str_pad((string) $data['id'], 10, '0', STR_PAD_LEFT)
                : '';
            $data['supplier_snapshot'] = null;
            $data['customer_snapshot'] = null;

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $series
     */
    protected function resolvePattern(string $pattern, array $data, array $series): string
    {
        $resolved = preg_replace_callback(
            '/%(D|C|y|Y|3|4|5|6)/',
            function (array $m) use ($data, $series): string {
                return match ($m[1]) {
                    'D' => $this->getDocIdCode((string) ($data['doc_type'] ?? '')),
                    'C' => (string) ($series['doc_number_code'] ?? ''),
                    'y' => substr($this->getFiscalYearLabel($data), -2),
                    'Y' => $this->getFiscalYearLabel($data),
                    '3' => str_pad((string) ($data['sequence_number'] ?? 0), 3, '0', STR_PAD_LEFT),
                    '4' => str_pad((string) ($data['sequence_number'] ?? 0), 4, '0', STR_PAD_LEFT),
                    '5' => str_pad((string) ($data['sequence_number'] ?? 0), 5, '0', STR_PAD_LEFT),
                    '6' => str_pad((string) ($data['sequence_number'] ?? 0), 6, '0', STR_PAD_LEFT),
                    default => $m[0],
                };
            },
            $pattern,
        );
        return $resolved ?? $pattern;
    }

    private function getDocIdCode(string $docType): string
    {
        if ($docType === '' || $this->config === null) {
            return '';
        }
        $cfg = $this->config->cfgItem('docs.core.docTypes');
        return is_array($cfg) && isset($cfg[$docType]['doc_id_code'])
            ? (string) $cfg[$docType]['doc_id_code']
            : '';
    }

    /**
     * @param array<string, mixed> $data
     */
    private function getFiscalYearLabel(array $data): string
    {
        if (empty($data['fiscal_year']) || $this->db === null) {
            if (!empty($data['accounting_date'])) {
                return substr((string) $data['accounting_date'], 0, 4);
            }
            return date('Y');
        }
        $row = $this->db->fetch(
            'SELECT [doc_number_prefix], [name] FROM [economy_codebooks_fiscal_years] WHERE [id] = %i',
            (int) $data['fiscal_year'],
        );
        if ($row === null) {
            return date('Y');
        }
        $name = (string) ($row['name'] ?? '');
        if (preg_match('/^(\d{4})/', $name, $matches)) {
            return $matches[1];
        }
        return date('Y');
    }

    // ── Snapshots ───────────────────────────────────────────────────────────

    protected function maintainSnapshots(array &$data, ?array $originalData): void
    {
        $newState = (int) ($data['docState'] ?? 10);
        if (!in_array($newState, self::SNAPSHOT_STATES, true)) {
            return;
        }

        $partnerChanged = ($data['partner'] ?? null) !== ($originalData['partner'] ?? null);
        $needsBuild = empty($data['supplier_snapshot'])
                    || empty($data['customer_snapshot'])
                    || $partnerChanged;

        if (!$needsBuild) {
            return;
        }

        $this->buildSnapshots($data);
    }

    protected function buildSnapshots(array &$data): void
    {
        $docTypeKey = (string) ($data['doc_type'] ?? '');
        $docTypes = $this->config?->cfgItem('docs.core.docTypes') ?? [];
        if (!is_array($docTypes) || !isset($docTypes[$docTypeKey]['trade_dir'])) {
            return;
        }
        $tradeDir = (int) $docTypes[$docTypeKey]['trade_dir'];

        $partnerSnap = $this->buildPersonSnapshot(
            personId:  (int) ($data['partner'] ?? 0),
            addressId: $data['partner_address'] ?? null,
            bankAccountId: null,
            vatRegistrationId: null,
        );

        $own = $this->ownCompanyResolver();
        $ownPersonId = $own->getOwnPersonId();
        if ($ownPersonId === null) {
            throw new \DomainException(
                'Není nastavena vlastní firma (base_persons_persons.is_own = 1).',
            );
        }
        $ownHqAddress = $own->getOwnHeadquartersAddress();

        $ownSnap = $this->buildPersonSnapshot(
            personId:  $ownPersonId,
            addressId: $ownHqAddress !== null ? (int) $ownHqAddress['id'] : null,
            bankAccountId: $data['bank_account'] ?? null,
            vatRegistrationId: $data['vat_registration'] ?? null,
        );

        // Snapshots must hit the database as JSON strings, not PHP arrays.
        // The columns are typed `json` in JSONC (= LONGTEXT in MariaDB), and
        // dibi has no automatic array→JSON serialization for that type — if
        // we pass a 2-D array, dibi treats it as a multi-row insert payload
        // and produces broken SQL. DocsHeadsForm::decodeSnapshot reverses
        // this on read.
        if ($tradeDir === 1) {
            // Output (issued invoice) — we are supplier
            $data['supplier_snapshot'] = $this->encodeSnapshot($ownSnap);
            $data['customer_snapshot'] = $this->encodeSnapshot($partnerSnap);
        } else {
            // Input (received invoice) — we are customer
            $data['supplier_snapshot'] = $this->encodeSnapshot($partnerSnap);
            $data['customer_snapshot'] = $this->encodeSnapshot($ownSnap);
        }
    }

    /**
     * Encode a snapshot array as JSON string for storage in a `json` column.
     * Returns null for empty snapshots so the column ends up NULL rather than
     * an empty JSON object string.
     *
     * @param array<string, mixed> $snap
     */
    private function encodeSnapshot(array $snap): ?string
    {
        if ($snap === []) {
            return null;
        }
        $json = json_encode($snap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $json === false ? null : $json;
    }

    /**
     * Thin delegation to the shared PersonSnapshotBuilder — kept as a method
     * so existing subclasses and tests overriding/calling it keep working.
     *
     * @return array<string, mixed>
     */
    protected function buildPersonSnapshot(
        int $personId,
        mixed $addressId,
        mixed $bankAccountId,
        mixed $vatRegistrationId,
    ): array {
        if ($this->db === null || $personId === 0) {
            return [];
        }
        return $this->personSnapshotBuilder()->build(
            $personId,
            $addressId,
            $bankAccountId,
            $vatRegistrationId,
        );
    }

    // ── Other defaults ──────────────────────────────────────────────────────

    protected function applyVariableSymbolDefault(array &$data): void
    {
        if (!empty($data['variable_symbol'])) {
            return;
        }
        if (!empty($data['sequence_number'])) {
            $data['variable_symbol'] = (string) $data['sequence_number'];
        }
    }

    /**
     * Thin wrapper around Dibi\Connection::query() to make it overridable in
     * tests (Connection::query() is `final` so PHPUnit cannot mock it).
     */
    protected function executeSql(mixed ...$args): void
    {
        $this->db?->query(...$args);
    }

    // ── Lazy service factories ──────────────────────────────────────────────

    protected function vatRateResolver(): VatRateResolver
    {
        if ($this->vatRateResolver === null) {
            if ($this->config === null) {
                throw new \LogicException('VatRateResolver requires ConfigRuntime injection');
            }
            $this->vatRateResolver = new VatRateResolver($this->config);
        }
        return $this->vatRateResolver;
    }

    protected function ownCompanyResolver(): OwnCompanyResolver
    {
        if ($this->ownCompanyResolver === null) {
            if ($this->db === null) {
                throw new \LogicException('OwnCompanyResolver requires Dibi connection');
            }
            $this->ownCompanyResolver = new OwnCompanyResolver($this->db);
        }
        return $this->ownCompanyResolver;
    }

    protected function personSnapshotBuilder(): PersonSnapshotBuilder
    {
        if ($this->personSnapshotBuilder === null) {
            if ($this->db === null) {
                throw new \LogicException('PersonSnapshotBuilder requires Dibi connection');
            }
            $this->personSnapshotBuilder = new PersonSnapshotBuilder($this->db);
        }
        return $this->personSnapshotBuilder;
    }
}
