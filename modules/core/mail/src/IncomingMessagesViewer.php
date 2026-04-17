<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail;

use Shipard\Core\Document\DocStateConfig;
use Shipard\Core\Viewer\TableViewer;

/**
 * Viewer došlých zpráv (core_mail_incoming_messages).
 *
 * Layout řádku podle spec §5.1:
 *   t1 — subject (orientováno vlevo, bold)
 *   i1 — received_at relativní („před 2 h", „včera 14:32", „12. 3.")
 *   t2 — sender_name ?? sender_email
 *   i2 — badge primárního typu (barva dle cfgItem)
 *   t3 — [mailbox.name] + první řádek body_plain (preview)
 *
 * Detail panel (§5.3): Obsah / Přílohy / Analýzy / Originál.
 */
class IncomingMessagesViewer extends TableViewer
{
    protected ?string $docStatesCfgItem = 'core.mail.docStatesIncoming';

    /** Mapování docState → span class (tamtéž jako v PersonsViewer). */
    private const STATE_SPAN_CLASS = [
        'concept'   => 'warning',
        'confirmed' => 'primary',
        'done'      => 'success',
        'edit'      => 'warning',
        'archive'   => 'muted',
        'trash'     => 'muted',
        'cancelled' => 'danger',
    ];

    /** Barevné hinty pro badge primárního typu — klíč = cfgItem key. */
    private const PRIMARY_TYPE_SPAN_CLASS = [
        'invoiceReceived' => 'primary',
        'other'           => 'muted',
        'creditNote'      => 'warning',
        'order'           => 'success',
        'quotation'       => 'primary',
        'statement'       => 'muted',
        'complaint'       => 'danger',
    ];

    public function selectRows(?string $search, array $filters, int $pageNumber): array
    {
        $sql = 'SELECT m.`id`, m.`message_id`, m.`subject`, m.`sender_email`, m.`sender_name`,'
            . ' m.`primary_type`, m.`received_at`, m.`body_plain`, m.`docState`, m.`docStateMain`,'
            . ' m.`mailbox`, mb.`name` AS mailbox_name, mb.`mailbox_id` AS mailbox_code'
            . ' FROM `' . $this->table . '` m'
            . ' LEFT JOIN `core_mail_mailboxes` mb ON mb.`id` = m.`mailbox`';

        $conditions = [];
        $params     = [];

        // viewGroup filter (Active / Archive / Trash)
        $viewGroup = 'active';
        foreach ($filters as $filter) {
            if ($filter['id'] === 'viewGroup') {
                $viewGroup = (string) $filter['value'];
            }
        }

        if ($viewGroup !== 'all' && $this->docStatesCfgItem !== null && $this->config !== null) {
            $cfg = DocStateConfig::fromCfgItem($this->config->cfgItem($this->docStatesCfgItem));
            $states = $cfg->getViewGroupStates($viewGroup);
            if ($states !== []) {
                $placeholders = implode(', ', array_fill(0, count($states), '%i'));
                $conditions[] = 'm.`docState` IN (' . $placeholders . ')';
                $params = array_merge($params, $states);
            } elseif ($viewGroup !== 'active') {
                // Prázdná skupina → vrátíme 0 řádků (nikoli všechno)
                $conditions[] = '1=0';
            }
        }

        // Fulltext search — subject, sender_email, sender_name, body_plain
        if ($search !== null && $search !== '') {
            $term = '%' . $search . '%';
            $conditions[] = '(m.`subject` LIKE %s OR m.`sender_email` LIKE %s'
                . ' OR m.`sender_name` LIKE %s OR m.`body_plain` LIKE %s)';
            $params[] = $term;
            $params[] = $term;
            $params[] = $term;
            $params[] = $term;
        }

        if ($conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        // Řazení: docStateMain (Nová nahoře), pak chronologicky newest-first
        $sql .= ' ORDER BY m.`docStateMain` ASC, m.`received_at` DESC, m.`id` DESC';

        [$offset, $limit] = $this->buildPaginationLimit($pageNumber);
        $sql .= ' LIMIT ' . $offset . ', ' . $limit;

        return $this->db->fetchAll($sql, ...$params);
    }

    public function renderRow(array $rowData): array
    {
        $docState  = (int) ($rowData['docState'] ?? 10);
        $stateStyle = $this->resolveStateStyle($docState);

        $row = [
            'id'         => (int) $rowData['id'],
            't1'         => (string) ($rowData['subject'] ?? ''),
            'i1'         => $this->formatRelativeDate($rowData['received_at'] ?? null),
            'stateStyle' => $stateStyle,
        ];

        // t2: sender_name preferované, jinak sender_email
        $senderName = trim((string) ($rowData['sender_name'] ?? ''));
        $senderEmail = trim((string) ($rowData['sender_email'] ?? ''));
        $row['t2'] = $senderName !== '' ? $senderName : ($senderEmail !== '' ? $senderEmail : null);

        // i2: primární typ (badge s lokalizovaným jménem a barvou dle typu)
        $primaryType = (string) ($rowData['primary_type'] ?? 'other');
        $typeLabel = $this->resolvePrimaryTypeLabel($primaryType);
        $row['i2'] = [
            'text'  => $typeLabel,
            'class' => self::PRIMARY_TYPE_SPAN_CLASS[$primaryType] ?? 'muted',
        ];

        // t3: [mailbox.name] + první řádek body_plain
        $mailboxName = trim((string) ($rowData['mailbox_name'] ?? ''));
        $bodyPreview = $this->firstBodyLine($rowData['body_plain'] ?? null, 100);
        $t3Parts = [];
        if ($mailboxName !== '') {
            $t3Parts[] = ['text' => '[' . $mailboxName . ']', 'class' => 'muted'];
        }
        if ($bodyPreview !== '') {
            $t3Parts[] = ['text' => $bodyPreview];
        }
        $row['t3'] = $t3Parts !== [] ? $t3Parts : null;

        return $row;
    }

    public function renderDetail(int $recordId): array
    {
        $record = $this->db->fetchRow(
            'SELECT m.*, mb.`name` AS mailbox_name, mb.`mailbox_id` AS mailbox_code'
            . ' FROM `' . $this->table . '` m'
            . ' LEFT JOIN `core_mail_mailboxes` mb ON mb.`id` = m.`mailbox`'
            . ' WHERE m.`id` = %i',
            $recordId,
        );

        if ($record === null) {
            return ['tabs' => []];
        }

        $tabs = [];

        // Tab 1 — Obsah
        $tabs[] = [
            'id'      => 'content',
            'label'   => 'Obsah',
            'content' => $this->buildContentTab($record),
        ];

        // Tab 2 — Přílohy (obsahové, s vyloučením raw .eml)
        $tabs[] = [
            'id'      => 'attachments',
            'label'   => 'Přílohy',
            'content' => $this->buildAttachmentsTab($record),
        ];

        // Tab 3 — Analýzy
        $tabs[] = [
            'id'      => 'analyses',
            'label'   => 'Analýzy',
            'content' => $this->buildAnalysesTab((int) $record['id']),
        ];

        // Tab 4 — Originál (raw .eml)
        $tabs[] = [
            'id'      => 'raw',
            'label'   => 'Originál',
            'content' => $this->buildRawSourceTab($record),
        ];

        return ['tabs' => $tabs];
    }

    public function getToolbarActions(?array $selectedRow): array
    {
        $actions = [
            ['id' => 'create', 'label' => 'Nová zpráva', 'variant' => 'primary'],
        ];

        if ($selectedRow !== null) {
            array_splice($actions, 1, 0, [
                ['id' => 'edit', 'label' => 'Otevřít', 'variant' => 'secondary'],
            ]);
        }

        return $actions;
    }

    // -------------------------------------------------------------------------
    // Private — detail tabs
    // -------------------------------------------------------------------------

    private function buildContentTab(array $record): array
    {
        $headerItems = [];
        $this->addItem($headerItems, 'Kód zprávy', $record['message_id'] ?? null);
        $this->addItem($headerItems, 'Schránka', $this->formatMailbox($record));
        $this->addItem($headerItems, 'Doručeno', $this->formatDateTime($record['received_at'] ?? null));
        $this->addItem($headerItems, 'Primární typ', $this->resolvePrimaryTypeLabel((string) ($record['primary_type'] ?? 'other')));

        $senderItems = [];
        $this->addItem($senderItems, 'E-mail', $record['sender_email'] ?? null);
        $this->addItem($senderItems, 'Jméno', $record['sender_name'] ?? null);
        if (!empty($record['external_message_id'])) {
            $this->addItem($senderItems, 'Message-ID', (string) $record['external_message_id']);
        }

        $subject = (string) ($record['subject'] ?? '');
        $bodyHtml = (string) ($record['body_html'] ?? '');
        $bodyPlain = (string) ($record['body_plain'] ?? '');

        $groups = [];
        if ($headerItems !== []) {
            $groups[] = ['title' => 'Hlavička', 'items' => $headerItems];
        }
        if ($senderItems !== []) {
            $groups[] = ['title' => 'Odesílatel', 'items' => $senderItems];
        }
        if ($subject !== '') {
            $groups[] = ['title' => 'Předmět', 'items' => [['label' => '', 'value' => $subject]]];
        }

        // Tělo: preferujeme HTML, fallback na plain. Frontend ho renderuje sanitovaně.
        $bodyContent = null;
        if ($bodyHtml !== '') {
            $bodyContent = ['type' => 'html', 'html' => $bodyHtml];
        } elseif ($bodyPlain !== '') {
            $bodyContent = [
                'type' => 'html',
                'html' => '<pre style="white-space: pre-wrap; font-family: inherit;">'
                    . htmlspecialchars($bodyPlain, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                    . '</pre>',
            ];
        }

        if ($bodyContent === null) {
            return ['type' => 'properties', 'groups' => $groups];
        }

        // Kombinovaný obsah: properties + body — vrátíme seznam bloků.
        return [
            'type' => 'composite',
            'blocks' => [
                ['type' => 'properties', 'groups' => $groups],
                ['type' => 'heading', 'text' => 'Tělo zprávy'],
                $bodyContent,
            ],
        ];
    }

    private function buildAttachmentsTab(array $record): array
    {
        $rawId = isset($record['raw_source_attachment']) && $record['raw_source_attachment'] !== null
            ? (int) $record['raw_source_attachment']
            : null;

        // Seznam obsahových příloh = core_attachments_files.table_id = 303 AND record_id = msg.id
        // s vyloučením raw .eml (raw_source_attachment_ndx)
        $sql = 'SELECT `id`, `name`, `file_name`, `file_size`, `mime_type`, `created`'
            . ' FROM `core_attachments_files`'
            . ' WHERE `table_id` = %i AND `record_id` = %i AND `is_deleted` = 0';
        $params = [303, (int) $record['id']];

        if ($rawId !== null) {
            $sql .= ' AND `id` != %i';
            $params[] = $rawId;
        }

        $sql .= ' ORDER BY `att_order` ASC, `name` ASC';

        $files = $this->db->fetchAll($sql, ...$params);
        $rows = [];
        foreach ($files as $f) {
            $rows[] = [
                'name'      => $f['name'] ?? $f['file_name'],
                'mime_type' => $f['mime_type'] ?? '',
                'size'      => $this->formatFileSize((int) ($f['file_size'] ?? 0)),
                'created'   => $this->formatDateTime($f['created'] ?? null),
            ];
        }

        if ($rows === []) {
            return ['type' => 'html', 'html' => '<p class="muted">Zpráva nemá žádné obsahové přílohy.</p>'];
        }

        return [
            'type'    => 'table',
            'columns' => [
                ['id' => 'name',      'label' => 'Název'],
                ['id' => 'mime_type', 'label' => 'Typ'],
                ['id' => 'size',      'label' => 'Velikost'],
                ['id' => 'created',   'label' => 'Nahráno'],
            ],
            'rows' => $rows,
        ];
    }

    private function buildAnalysesTab(int $messageId): array
    {
        $analyses = $this->db->fetchAll(
            'SELECT `id`, `analyzed_at`, `status`, `model_name`, `model_version`, `prompt_version`, `confidence`'
            . ' FROM `core_mail_message_analyses`'
            . ' WHERE `message` = %i'
            . ' ORDER BY `analyzed_at` DESC',
            $messageId,
        );

        if ($analyses === []) {
            return ['type' => 'html', 'html' => '<p class="muted">Pro tuto zprávu zatím neexistuje žádná AI analýza.</p>'];
        }

        $statusLabels = [1 => 'Probíhá', 2 => 'Úspěch', 3 => 'Selhala'];
        $rows = [];
        foreach ($analyses as $a) {
            $rows[] = [
                'analyzed_at' => $this->formatDateTime($a['analyzed_at'] ?? null),
                'status'      => $statusLabels[(int) ($a['status'] ?? 1)] ?? '—',
                'model'       => trim(($a['model_name'] ?? '') . ' ' . ($a['model_version'] ?? '')),
                'prompt'      => $a['prompt_version'] ?? '',
                'confidence'  => $a['confidence'] !== null ? (string) $a['confidence'] : '—',
            ];
        }

        return [
            'type'    => 'table',
            'columns' => [
                ['id' => 'analyzed_at', 'label' => 'Čas'],
                ['id' => 'status',      'label' => 'Stav'],
                ['id' => 'model',       'label' => 'Model'],
                ['id' => 'prompt',      'label' => 'Prompt'],
                ['id' => 'confidence',  'label' => 'Jistota'],
            ],
            'rows' => $rows,
        ];
    }

    private function buildRawSourceTab(array $record): array
    {
        $rawId = isset($record['raw_source_attachment']) && $record['raw_source_attachment'] !== null
            ? (int) $record['raw_source_attachment']
            : null;

        if ($rawId === null) {
            return [
                'type' => 'html',
                'html' => '<p class="muted">Originální <code>.eml</code> není k dispozici (zpráva pořízena ručně).</p>',
            ];
        }

        $raw = $this->db->fetchRow(
            'SELECT `id`, `name`, `file_name`, `file_size`, `mime_type`, `created`'
            . ' FROM `core_attachments_files` WHERE `id` = %i AND `is_deleted` = 0',
            $rawId,
        );

        if ($raw === null) {
            return ['type' => 'html', 'html' => '<p class="muted">Originál byl smazán nebo není dostupný.</p>'];
        }

        return [
            'type'   => 'properties',
            'groups' => [[
                'title' => 'Originální .eml',
                'items' => [
                    ['label' => 'Název',     'value' => (string) ($raw['name'] ?? $raw['file_name'])],
                    ['label' => 'Velikost',  'value' => $this->formatFileSize((int) ($raw['file_size'] ?? 0))],
                    ['label' => 'MIME',      'value' => (string) ($raw['mime_type'] ?? '')],
                    ['label' => 'Uloženo',   'value' => $this->formatDateTime($raw['created'] ?? null)],
                ],
            ]],
        ];
    }

    // -------------------------------------------------------------------------
    // Private — formátovací helpery
    // -------------------------------------------------------------------------

    private function resolveStateStyle(int $docState): string
    {
        if ($this->config === null || $this->docStatesCfgItem === null) {
            return 'concept';
        }

        $cfg = DocStateConfig::fromCfgItem($this->config->cfgItem($this->docStatesCfgItem));
        $stateData = $cfg->getState($docState);
        return $stateData['stateStyle'] ?? 'concept';
    }

    private function resolvePrimaryTypeLabel(string $key): string
    {
        if ($this->config !== null) {
            $types = $this->config->cfgItem('core.mail.primaryTypes');
            if (is_array($types) && isset($types[$key]['name'])) {
                return (string) $types[$key]['name'];
            }
        }

        // Fallback bez configu
        return match ($key) {
            'invoiceReceived' => 'Přijatá faktura',
            'other'           => 'Ostatní',
            'creditNote'      => 'Dobropis',
            'order'           => 'Objednávka',
            'quotation'       => 'Nabídka',
            'statement'       => 'Výpis / Saldo',
            'complaint'       => 'Reklamace',
            default           => $key,
        };
    }

    private function formatMailbox(array $record): string
    {
        $name = trim((string) ($record['mailbox_name'] ?? ''));
        $code = trim((string) ($record['mailbox_code'] ?? ''));
        if ($name !== '' && $code !== '') {
            return $name . ' (' . $code . ')';
        }
        return $name !== '' ? $name : $code;
    }

    private function formatRelativeDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $ts = $this->parseDateTime($value);
        if ($ts === null) {
            return null;
        }

        $diff = time() - $ts;

        if ($diff < 60) {
            return 'právě teď';
        }
        if ($diff < 3600) {
            $mins = (int) floor($diff / 60);
            return 'před ' . $mins . ' min';
        }
        if ($diff < 86400) {
            $hrs = (int) floor($diff / 3600);
            return 'před ' . $hrs . ' h';
        }
        if ($diff < 2 * 86400) {
            return 'včera ' . date('H:i', $ts);
        }
        if ($diff < 7 * 86400) {
            $days = (int) floor($diff / 86400);
            return 'před ' . $days . ' d';
        }

        // Starší než týden → datum
        return date('j. n.', $ts);
    }

    private function formatDateTime(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $ts = $this->parseDateTime($value);
        return $ts !== null ? date('j. n. Y H:i', $ts) : null;
    }

    private function parseDateTime(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->getTimestamp();
        }
        if (is_string($value)) {
            $ts = strtotime($value);
            return $ts !== false ? $ts : null;
        }
        return null;
    }

    private function firstBodyLine(mixed $body, int $maxLen): string
    {
        if (!is_string($body) || $body === '') {
            return '';
        }
        $lines = preg_split('/\R/', trim($body), 2);
        $first = is_array($lines) && $lines !== [] ? (string) $lines[0] : '';
        $first = trim($first);
        if (mb_strlen($first) > $maxLen) {
            $first = mb_substr($first, 0, $maxLen - 1) . '…';
        }
        return $first;
    }

    private function formatFileSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1024 * 1024) {
            return number_format($bytes / 1024, 1, ',', ' ') . ' kB';
        }
        return number_format($bytes / (1024 * 1024), 1, ',', ' ') . ' MB';
    }

    /** @param array<int, array{label: string, value: string}> $items */
    private function addItem(array &$items, string $label, mixed $value): void
    {
        if ($value !== null && $value !== '') {
            $items[] = ['label' => $label, 'value' => (string) $value];
        }
    }
}
