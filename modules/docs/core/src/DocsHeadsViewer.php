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

        $identityItems = [];
        $this->addItem($identityItems, 'Číslo dokladu', $record['doc_number'] ?? null);
        $this->addItem($identityItems, 'Typ dokladu', $this->resolveDocTypeLabel((string) ($record['doc_type'] ?? '')));
        $this->addItem($identityItems, 'Text', $record['doc_text'] ?? null);
        $this->addItem($identityItems, 'Partner', $record['partner_name'] ?? null);

        $dateItems = [];
        $this->addItem($dateItems, 'Datum vystavení', $this->formatDate($record['issue_date'] ?? null));
        $this->addItem($dateItems, 'Datum splatnosti', $this->formatDate($record['due_date'] ?? null));
        $this->addItem($dateItems, 'Účetní datum', $this->formatDate($record['accounting_date'] ?? null));
        $this->addItem($dateItems, 'DUZP', $this->formatDate($record['vat_duzp'] ?? null));

        $totalsItems = [];
        $currency = strtoupper((string) ($record['doc_currency'] ?? ''));
        $this->addItem($totalsItems, 'Základ', $this->formatMoneyWith($record['total_base'] ?? null, $currency));
        $this->addItem($totalsItems, 'DPH', $this->formatMoneyWith($record['total_vat'] ?? null, $currency));
        $this->addItem($totalsItems, 'Celkem', $this->formatMoneyWith($record['total_amount'] ?? null, $currency));

        $stateItems = [];
        $cfg = DocStateConfig::fromCfgItem($this->config?->cfgItem($this->docStatesCfgItem ?? ''));
        $stateData = $cfg->getState((int) ($record['docState'] ?? 10));
        $this->addItem($stateItems, 'Stav', $stateData['stateName'] ?? null);

        $groups = [];
        if ($identityItems !== []) {
            $groups[] = ['title' => 'Identifikace', 'items' => $identityItems];
        }
        if ($dateItems !== []) {
            $groups[] = ['title' => 'Datumy', 'items' => $dateItems];
        }
        if ($totalsItems !== []) {
            $groups[] = ['title' => 'Součty', 'items' => $totalsItems];
        }
        if ($stateItems !== []) {
            $groups[] = ['title' => 'Stav', 'items' => $stateItems];
        }

        $tabs = [[
            'id'      => 'overview',
            'label'   => $this->defaultOverviewLabel(),
            'content' => ['type' => 'properties', 'groups' => $groups],
        ]];

        // Tab "Přílohy" — přílohy zpráv došlé pošty, ze kterých doklad vznikl.
        // Zobrazí se jen když existuje aspoň jedna navázaná zpráva s přílohou.
        $attachmentGroups = $this->sourceAttachmentGroups($recordId);
        if ($attachmentGroups !== []) {
            $count = array_sum(array_map(
                static fn (array $g): int => count($g['attachments']),
                $attachmentGroups,
            ));
            $tabs[] = [
                'id'      => 'sourceAttachments',
                'label'   => $this->detailTabLabel('docs.core.viewerDetailLabels', 'sourceAttachments', 'Attachments')
                    . ' (' . $count . ')',
                'content' => [
                    'type'           => 'attachments',
                    'groups'         => $attachmentGroups,
                    'sourceViewerId' => 'core.mail.incoming',
                ],
            ];
        }

        return ['tabs' => $tabs];
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
     * @return list<array{message_id:string, received_at:?string, message_ndx:int, attachments:list<array{id:int, name:string, mime_type:string, file_size:int}>}>
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
                'message_id'  => (string) $msg['message_id'],
                'received_at' => $this->formatDate($msg['received_at'] ?? null),
                'message_ndx' => (int) $msg['id'],
                'attachments' => $attachments,
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

    /** @param array<int, array{label: string, value: string}> $items */
    private function addItem(array &$items, string $label, mixed $value): void
    {
        if ($value !== null && $value !== '') {
            $items[] = ['label' => $label, 'value' => (string) $value];
        }
    }

    private function formatMoneyWith(mixed $amount, string $currency): ?string
    {
        if ($amount === null || $amount === '') {
            return null;
        }
        $formatted = number_format((float) $amount, 2, ',', ' ');
        return $currency !== '' ? $formatted . ' ' . $currency : $formatted;
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
