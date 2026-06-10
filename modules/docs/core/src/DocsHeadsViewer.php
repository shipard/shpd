<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\Core;

use Shipard\Core\Document\DocStateConfig;
use Shipard\Core\Viewer\TableViewer;

/**
 * Generic viewer for all documents in docs_core_heads (Phase 3).
 *
 * Phase 6 will add per-type viewers (issued / received invoices) with a
 * bottom tab bar of number series; this generic viewer remains as the
 * "all documents" cross-type entry point.
 */
class DocsHeadsViewer extends TableViewer
{
    protected ?string $docStatesCfgItem = 'docs.core.docStates';

    /** tableId tabulky docs_core_heads — pro vlastní přílohy dokladu. */
    private const TABLE_ID_HEADS = 401;

    private ?PersonSnapshotBuilder $personSnapshotBuilder = null;
    private ?OwnCompanyResolver $ownCompanyResolver = null;

    private const STATE_SPAN_CLASS = [
        'concept'   => 'warning',
        'confirmed' => 'primary',
        'edit'      => 'warning',
        'done'      => 'success',
        'cancelled' => 'danger',
        'archive'   => 'muted',
        'trash'     => 'muted',
    ];

    /**
     * When set, this viewer is scoped to a single doc_type (e.g. 'invni' for
     * received invoices). Drives the implicit doc_type filter in selectRows(),
     * the bottom number-series tab list (getNumberSeries), and the default
     * doc_type for newly created records (getNewRecordDefaults).
     *
     * Generic viewers (cross-type "all documents") leave this null.
     */
    protected ?string $scopedDocType = null;

    public function selectRows(?string $search, array $filters, int $pageNumber): array
    {
        $sql = 'SELECT h.`id`, h.`doc_type`, h.`doc_number`, h.`doc_text`,'
            . ' h.`docState`, h.`docStateMain`,'
            . ' h.`issue_date`, h.`due_date`,'
            . ' h.`total_amount`, h.`doc_currency`,'
            . ' p.`full_name` AS partner_name'
            . ' FROM `' . $this->table . '` h'
            . ' LEFT JOIN `base_persons_persons` p ON p.`id` = h.`partner`';

        $conditions = [];
        $params = [];

        $viewGroup = 'active';
        $docTypeFilter = $this->scopedDocType;
        $numberSeriesFilter = null;
        foreach ($filters as $filter) {
            $id = $filter['id'] ?? null;
            if ($id === 'viewGroup') {
                $viewGroup = (string) $filter['value'];
            } elseif ($id === '_doc_type') {
                // Explicit override (e.g. a cross-type viewer pinning a type manually).
                $docTypeFilter = (string) $filter['value'];
            } elseif ($id === 'number_series') {
                $numberSeriesFilter = (int) $filter['value'];
            }
        }

        if ($viewGroup !== 'all') {
            [$vgSql, $vgParams] = $this->buildViewGroupFilter($this->docStatesCfgItem ?? '', $viewGroup);
            if ($vgSql !== '') {
                $conditions[] = 'h.' . $vgSql;
                $params = array_merge($params, $vgParams);
            }
        }

        if ($docTypeFilter !== null && $docTypeFilter !== '') {
            $conditions[] = 'h.`doc_type` = %s';
            $params[] = $docTypeFilter;
        }

        if ($numberSeriesFilter !== null && $numberSeriesFilter > 0) {
            $conditions[] = 'h.`number_series` = %i';
            $params[] = $numberSeriesFilter;
        }

        if ($search !== null && $search !== '') {
            $term = '%' . $search . '%';
            $conditions[] = '(h.`doc_number` LIKE %s OR h.`doc_text` LIKE %s OR p.`full_name` LIKE %s)';
            $params[] = $term;
            $params[] = $term;
            $params[] = $term;
        }

        if ($conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        $sql .= ' ORDER BY h.`docStateMain` ASC, h.`doc_number` DESC, h.`id` DESC';

        [$offset, $limit] = $this->buildPaginationLimit($pageNumber);
        $sql .= ' LIMIT ' . $offset . ', ' . $limit;

        return $this->db->fetchAll($sql, ...$params);
    }

    /**
     * Bottom-tab number series for this viewer.
     *
     * Returns only series in "V pořádku" state (docState = 40):
     *  - Koncept (10) — series not yet in use for filing documents.
     *  - Archivovaná (70) — past series, not shown (its documents are thus
     *    not visible in the default view — a deliberate choice).
     *  - Smazaná (90) — gone.
     *
     * Empty for cross-type viewers ($scopedDocType === null) — the generic
     * DocsHeadsViewer (and any subclass that doesn't pin a type) shows no tabs.
     *
     * Note: wider than DocsHeadsFormBase::resolveNumberSeriesOptions() (which
     * uses docState IN (10, 40, 80)) on purpose — that's "which series may a
     * new document be filed into", a different question than "which series are
     * worth showing as a tab".
     *
     * @return list<array{id: int, name: string}>
     */
    public function getNumberSeries(): array
    {
        if ($this->scopedDocType === null) {
            return [];
        }
        $rows = $this->db->fetchAll(
            'SELECT `id`, `name` FROM `docs_core_number_series`'
            . ' WHERE `doc_type` = %s AND `docState` = 40'
            . ' ORDER BY `name` ASC',
            $this->scopedDocType,
        );
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id'   => (int) $row['id'],
                'name' => (string) $row['name'],
            ];
        }
        return $out;
    }

    public function getNewRecordDefaults(): array
    {
        return $this->scopedDocType !== null
            ? ['doc_type' => $this->scopedDocType]
            : [];
    }

    public function renderRow(array $rowData): array
    {
        $docState = (int) ($rowData['docState'] ?? 10);
        $stateStyle = $this->resolveStateStyle($docState);

        $partnerName = trim((string) ($rowData['partner_name'] ?? ''));
        $docText = trim((string) ($rowData['doc_text'] ?? ''));

        $title = $partnerName !== '' ? $partnerName : ($docText !== '' ? $docText : '—');

        $row = [
            'id'         => (int) $rowData['id'],
            't1'         => $title,
            'i1'         => $rowData['doc_number'] !== '' ? $rowData['doc_number'] : null,
            'stateStyle' => $stateStyle,
        ];

        $t2 = [];
        $docTypeLabel = $this->resolveDocTypeLabel((string) ($rowData['doc_type'] ?? ''));
        if ($docTypeLabel !== '') {
            $t2[] = ['text' => $docTypeLabel, 'class' => 'muted'];
        }
        $issueDate = $this->formatDate($rowData['issue_date'] ?? null);
        if ($issueDate !== null) {
            $t2[] = ['text' => $issueDate];
        }
        $dueDate = $this->formatDate($rowData['due_date'] ?? null);
        if ($dueDate !== null) {
            $t2[] = ['text' => 'splat. ' . $dueDate, 'class' => 'muted'];
        }
        if ($docState !== 10 && $docState !== 40) {
            $cfg = DocStateConfig::fromCfgItem($this->config?->cfgItem($this->docStatesCfgItem ?? ''));
            $stateData = $cfg->getState($docState);
            $stateName = (string) ($stateData['stateName'] ?? '');
            if ($stateName !== '') {
                $t2[] = [
                    'text'  => $stateName,
                    'class' => self::STATE_SPAN_CLASS[$stateStyle] ?? 'muted',
                ];
            }
        }
        $row['t2'] = $t2 !== [] ? $t2 : null;

        $totalAmount = $rowData['total_amount'] ?? null;
        if ($totalAmount !== null && $totalAmount !== '' && (float) $totalAmount !== 0.0) {
            $currency = strtoupper((string) ($rowData['doc_currency'] ?? ''));
            $formatted = number_format((float) $totalAmount, 2, ',', ' ');
            if ($currency !== '') {
                $formatted .= ' ' . $currency;
            }
            $row['i2'] = ['text' => $formatted, 'class' => 'amount'];
        }

        if ($partnerName !== '' && $docText !== '') {
            $row['t3'] = $docText;
        }

        return $row;
    }

    /**
     * Detail dokladu jako "textová faktura" — jediný tab `overview` s content
     * typem `document`: hlavička se stavem, strany Dodavatel/Odběratel, meta
     * mřížka, řádky, DPH rekapitulace, součty a náhledy příloh (mailové
     * zdrojové skupiny + vlastní přílohy dokladu) na konci.
     *
     * Server formátuje hodnoty (datumy `j. n. Y`, částky `1 234,56`);
     * frontend (DocumentDetail.svelte) jen skládá layout.
     */
    public function renderDetail(int $recordId): array
    {
        $record = $this->db->fetchRow(
            'SELECT h.*, p.`full_name` AS partner_name'
            . ' FROM `' . $this->table . '` h'
            . ' LEFT JOIN `base_persons_persons` p ON p.`id` = h.`partner`'
            . ' WHERE h.`id` = %i',
            $recordId,
        );

        if ($record === null) {
            return ['tabs' => []];
        }

        [$supplier, $customer] = $this->buildDetailParties($record);

        $content = [
            'type'      => 'document',
            'header'    => $this->buildDetailHeader($record),
            'supplier'  => $supplier,
            'customer'  => $customer,
            'meta'      => $this->buildDetailMeta($record),
            'rows'      => $this->buildDetailRows($recordId),
            'vat_recap' => $this->buildDetailVatRecap($recordId),
            'totals'    => $this->buildDetailTotals($record),
        ];

        $attachmentGroups = $this->detailAttachmentGroups($recordId);
        if ($attachmentGroups !== []) {
            $content['attachments'] = ['groups' => $attachmentGroups];
        }

        return ['tabs' => [[
            'id'      => 'overview',
            'label'   => $this->defaultOverviewLabel(),
            'content' => $content,
        ]]];
    }

    /** @return array<string, mixed> */
    private function buildDetailHeader(array $record): array
    {
        $cfg = DocStateConfig::fromCfgItem($this->config?->cfgItem($this->docStatesCfgItem ?? ''));
        $stateData = $cfg->getState((int) ($record['docState'] ?? 10));
        $docText = trim((string) ($record['doc_text'] ?? ''));

        return [
            'docTypeLabel' => $this->resolveDocTypeLabel((string) ($record['doc_type'] ?? '')),
            'docNumber'    => (string) ($record['doc_number'] ?? ''),
            'docText'      => $docText !== '' ? $docText : null,
            'state'        => [
                'name'  => (string) ($stateData['stateName'] ?? ''),
                'style' => (string) ($stateData['stateStyle'] ?? 'concept'),
            ],
        ];
    }

    /**
     * Strany dokladu. Uložené snapshoty (zmrazené při Potvrzení) mají
     * přednost; u Konceptu se strany skládají živě přes PersonSnapshotBuilder
     * + OwnCompanyResolver. Chybějící vlastní firma → strana je null, detail
     * nesmí spadnout na nenakonfigurovaném DS.
     *
     * @return array{0: ?array<string, mixed>, 1: ?array<string, mixed>}  [supplier, customer]
     */
    private function buildDetailParties(array $record): array
    {
        $supplier = $this->decodeSnapshot($record['supplier_snapshot'] ?? null);
        $customer = $this->decodeSnapshot($record['customer_snapshot'] ?? null);
        if ($supplier !== null && $customer !== null) {
            return [$supplier, $customer];
        }

        // Stejné mapování jako DocDocument::buildSnapshots():
        // trade_dir 1 = my jsme dodavatel, jinak my odběratel.
        $docTypes = $this->config?->cfgItem('docs.core.docTypes');
        $docTypeKey = (string) ($record['doc_type'] ?? '');
        $tradeDir = is_array($docTypes)
            ? (int) ($docTypes[$docTypeKey]['trade_dir'] ?? 2)
            : 2;

        $partner = null;
        $partnerId = (int) ($record['partner'] ?? 0);
        if ($partnerId > 0) {
            $snap = $this->personSnapshotBuilder()->build(
                $partnerId,
                $record['partner_address'] ?? null,
                null,
                null,
            );
            $partner = $snap !== [] ? $snap : null;
        }

        $own = null;
        $ownPersonId = $this->ownCompanyResolver()->getOwnPersonId();
        if ($ownPersonId !== null) {
            $hqAddress = $this->ownCompanyResolver()->getOwnHeadquartersAddress();
            $snap = $this->personSnapshotBuilder()->build(
                $ownPersonId,
                $hqAddress !== null ? (int) $hqAddress['id'] : null,
                $record['bank_account'] ?? null,
                $record['vat_registration'] ?? null,
            );
            $own = $snap !== [] ? $snap : null;
        }

        $liveSupplier = $tradeDir === 1 ? $own : $partner;
        $liveCustomer = $tradeDir === 1 ? $partner : $own;

        return [$supplier ?? $liveSupplier, $customer ?? $liveCustomer];
    }

    /** @return array<string, mixed>|null */
    private function decodeSnapshot(mixed $raw): ?array
    {
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) && $decoded !== [] ? $decoded : null;
    }

    /** @return array<string, ?string> */
    private function buildDetailMeta(array $record): array
    {
        $currency = strtoupper((string) ($record['doc_currency'] ?? ''));
        $rate = $record['exchange_rate'] ?? null;
        $hasRate = $this->isForeignCurrency($record) && $rate !== null && (float) $rate > 0;

        return [
            'issue_date'      => $this->formatDate($record['issue_date'] ?? null),
            'due_date'        => $this->formatDate($record['due_date'] ?? null),
            'accounting_date' => $this->formatDate($record['accounting_date'] ?? null),
            'vat_duzp'        => $this->formatDate($record['vat_duzp'] ?? null),
            'currency'        => $currency !== '' ? $currency : null,
            'exchange_rate'   => $hasRate ? number_format((float) $rate, 3, ',', ' ') : null,
            'payment_method'  => $this->resolvePaymentMethodLabel($record['payment_method'] ?? null),
            'variable_symbol' => $this->nullableString($record['variable_symbol'] ?? null),
            'specific_symbol' => $this->nullableString($record['specific_symbol'] ?? null),
            'constant_symbol' => $this->nullableString($record['constant_symbol'] ?? null),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function buildDetailRows(int $recordId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT r.`row_kind`, r.`order_pos`, r.`description`, r.`quantity`,'
            . ' r.`unit_price`, r.`total_price`, r.`vat_pct`,'
            . ' u.`shortcut` AS unit_shortcut'
            . ' FROM `docs_core_rows` r'
            . ' LEFT JOIN `core_units` u ON u.`id` = r.`unit`'
            . ' WHERE r.`doc_head` = %i'
            . ' ORDER BY r.`order_pos` ASC, r.`id` ASC',
            $recordId,
        );

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'order_pos'   => (int) ($row['order_pos'] ?? 0),
                'kind'        => (int) ($row['row_kind'] ?? 1),
                'description' => (string) ($row['description'] ?? ''),
                'quantity'    => $this->formatTrimmedNumber($row['quantity'] ?? null, 4),
                'unit'        => $this->nullableString($row['unit_shortcut'] ?? null),
                'unit_price'  => $this->formatMoney($row['unit_price'] ?? null),
                'vat_pct'     => $this->formatTrimmedNumber($row['vat_pct'] ?? null, 2),
                'total_price' => $this->formatMoney($row['total_price'] ?? null),
            ];
        }
        return $out;
    }

    /** @return list<array<string, ?string>> */
    private function buildDetailVatRecap(int $recordId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT `vat_pct`, `base`, `tax`, `total`'
            . ' FROM `docs_core_vat_recap`'
            . ' WHERE `doc_head` = %i'
            . ' ORDER BY `order_pos` ASC, `id` ASC',
            $recordId,
        );

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'vat_pct' => $this->formatTrimmedNumber($row['vat_pct'] ?? null, 2),
                'base'    => $this->formatMoney($row['base'] ?? null),
                'tax'     => $this->formatMoney($row['tax'] ?? null),
                'total'   => $this->formatMoney($row['total'] ?? null),
            ];
        }
        return $out;
    }

    /** @return array<string, mixed> */
    private function buildDetailTotals(array $record): array
    {
        $rounding = (float) ($record['total_rounding'] ?? 0);

        $totals = [
            'currency' => strtoupper((string) ($record['doc_currency'] ?? '')),
            'base'     => $this->formatMoney($record['total_base'] ?? 0),
            'vat'      => $this->formatMoney($record['total_vat'] ?? 0),
            'amount'   => $this->formatMoney($record['total_amount'] ?? 0),
            'rounding' => $rounding !== 0.0 ? $this->formatMoney($rounding) : null,
            'dom'      => null,
        ];

        if ($this->isForeignCurrency($record)) {
            $totals['dom'] = [
                'currency' => strtoupper((string) ($record['home_currency'] ?? '')),
                'base'     => $this->formatMoney($record['total_base_dom'] ?? 0),
                'vat'      => $this->formatMoney($record['total_vat_dom'] ?? 0),
                'amount'   => $this->formatMoney($record['total_amount_dom'] ?? 0),
            ];
        }

        return $totals;
    }

    /**
     * Skupiny příloh pro konec detailu: mailové zdrojové skupiny první
     * (kind 'mail'), pak vlastní přílohy dokladu (kind 'doc', vynechá se
     * když žádné nejsou).
     *
     * @return list<array<string, mixed>>
     */
    private function detailAttachmentGroups(int $recordId): array
    {
        $groups = $this->sourceAttachmentGroups($recordId);

        $files = $this->db->fetchAll(
            'SELECT `id`, `name`, `file_name`, `file_size`, `mime_type`'
            . ' FROM `core_attachments_files`'
            . ' WHERE `table_id` = %i AND `record_id` = %i AND `is_deleted` = 0'
            . ' ORDER BY `att_order` ASC, `name` ASC',
            self::TABLE_ID_HEADS,
            $recordId,
        );
        if ($files !== []) {
            $attachments = [];
            foreach ($files as $f) {
                $attachments[] = [
                    'id'        => (int) $f['id'],
                    'name'      => (string) ($f['name'] ?? $f['file_name']),
                    'mime_type' => (string) ($f['mime_type'] ?? ''),
                    'file_size' => (int) ($f['file_size'] ?? 0),
                ];
            }
            $groups[] = [
                'kind'        => 'doc',
                'attachments' => $attachments,
            ];
        }

        return $groups;
    }

    /**
     * Zprávy došlé pošty, ze kterých tento doklad vznikl (vazba přes
     * message.target_table_id + target_row; index idx_target). Trash (90)
     * vynechán. Shodná konvence pro importní i nativní AI flow.
     *
     * @return list<array{id:int, message_id:string, received_at:?string, raw_source_attachment:?int}>
     */
    private function sourceMessages(int $docId): array
    {
        return $this->db->fetchAll(
            'SELECT `id`, `message_id`, `received_at`, `raw_source_attachment`'
            . ' FROM `core_mail_incoming_messages`'
            . ' WHERE `target_table_id` = %s AND `target_row` = %i AND `docState` != %i'
            . ' ORDER BY `received_at` ASC, `id` ASC',
            'docs_core_heads', $docId, 90,
        );
    }

    /**
     * Přílohy zdrojových zpráv seskupené per zpráva. Skupiny bez obsahových
     * příloh se vynechávají. Raw .eml originál (raw_source_attachment) je
     * vyloučen — patří zprávě zvlášť a není to obsahová příloha.
     *
     * Přílohy zprávy = core_attachments_files s table_id = 303 (tableId
     * core_mail_incoming_messages), record_id = message.id.
     *
     * @return list<array{kind:string, sourceViewerId:string, message_id:string, received_at:?string, message_ndx:int, attachments:list<array{id:int, name:string, mime_type:string, file_size:int}>}>
     */
    private function sourceAttachmentGroups(int $docId): array
    {
        $groups = [];
        foreach ($this->sourceMessages($docId) as $msg) {
            $rawId = $msg['raw_source_attachment'] !== null ? (int) $msg['raw_source_attachment'] : null;

            $sql = 'SELECT `id`, `name`, `file_name`, `file_size`, `mime_type`'
                . ' FROM `core_attachments_files`'
                . ' WHERE `table_id` = %i AND `record_id` = %i AND `is_deleted` = 0';
            $params = [303, (int) $msg['id']];
            if ($rawId !== null) {
                $sql .= ' AND `id` != %i';
                $params[] = $rawId;
            }
            $sql .= ' ORDER BY `att_order` ASC, `name` ASC';

            $files = $this->db->fetchAll($sql, ...$params);
            if ($files === []) {
                continue;
            }

            $attachments = [];
            foreach ($files as $f) {
                $attachments[] = [
                    'id'        => (int) $f['id'],
                    'name'      => (string) ($f['name'] ?? $f['file_name']),
                    'mime_type' => (string) ($f['mime_type'] ?? ''),
                    'file_size' => (int) ($f['file_size'] ?? 0),
                ];
            }

            $groups[] = [
                'kind'           => 'mail',
                'sourceViewerId' => 'core.mail.incoming',
                'message_id'     => (string) $msg['message_id'],
                'received_at'    => $this->formatDate($msg['received_at'] ?? null),
                'message_ndx'    => (int) $msg['id'],
                'attachments'    => $attachments,
            ];
        }

        return $groups;
    }

    private function resolveStateStyle(int $docState): string
    {
        if ($this->config === null || $this->docStatesCfgItem === null) {
            return 'concept';
        }
        $cfg = DocStateConfig::fromCfgItem($this->config->cfgItem($this->docStatesCfgItem));
        return $cfg->getState($docState)['stateStyle'] ?? 'concept';
    }

    private function resolveDocTypeLabel(string $key): string
    {
        if ($key === '' || $this->config === null) {
            return '';
        }
        $cfg = $this->config->cfgItem('docs.core.docTypes');
        if (!is_array($cfg) || !isset($cfg[$key]['name'])) {
            return $key;
        }
        return (string) $cfg[$key]['name'];
    }

    private function resolvePaymentMethodLabel(mixed $value): ?string
    {
        if ($value === null || $value === '' || $this->config === null) {
            return null;
        }
        $cfg = $this->config->cfgItem('docs.core.paymentMethods');
        $key = (string) (int) $value;
        if (!is_array($cfg) || !isset($cfg[$key]['name'])) {
            return null;
        }
        return (string) $cfg[$key]['name'];
    }

    private function isForeignCurrency(array $record): bool
    {
        $doc = strtolower((string) ($record['doc_currency'] ?? ''));
        $home = strtolower((string) ($record['home_currency'] ?? ''));
        return $doc !== '' && $home !== '' && $doc !== $home;
    }

    private function formatMoney(mixed $amount): ?string
    {
        if ($amount === null || $amount === '') {
            return null;
        }
        return number_format((float) $amount, 2, ',', ' ');
    }

    /**
     * Číslo s ořezanými koncovými nulami v desetinné části
     * (množství "10,0000" → "10", sazba "21,00" → "21").
     */
    private function formatTrimmedNumber(mixed $value, int $decimals): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $formatted = number_format((float) $value, $decimals, ',', ' ');
        if (str_contains($formatted, ',')) {
            $formatted = rtrim(rtrim($formatted, '0'), ',');
        }
        return $formatted;
    }

    private function nullableString(mixed $value): ?string
    {
        $str = trim((string) ($value ?? ''));
        return $str !== '' ? $str : null;
    }

    // ── Lazy service factories (protected → overridable v testech) ─────────

    protected function personSnapshotBuilder(): PersonSnapshotBuilder
    {
        if ($this->personSnapshotBuilder === null) {
            $this->personSnapshotBuilder = new PersonSnapshotBuilder($this->db->getDibiConnection());
        }
        return $this->personSnapshotBuilder;
    }

    protected function ownCompanyResolver(): OwnCompanyResolver
    {
        if ($this->ownCompanyResolver === null) {
            $this->ownCompanyResolver = new OwnCompanyResolver($this->db->getDibiConnection());
        }
        return $this->ownCompanyResolver;
    }

    private function formatDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('j. n. Y');
        }
        $ts = is_string($value) ? strtotime($value) : false;
        return $ts !== false ? date('j. n. Y', $ts) : null;
    }
}
