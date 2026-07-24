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
 * Detail panel (§5.3): hlavička (předmět · odesílatel · schránka · doručeno
 * + badges stavu a primárního typu) nad taby, taby Obsah (tělo, přílohy,
 * technické údaje) / Analýzy / Extrahované dokumenty / Originál.
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
            . ' m.`analysis_state`, m.`is_bulk`,'
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
        // + badge stavu AI analýzy (hodnota 0 = Bez analýzy se nezobrazuje)
        $primaryType = (string) ($rowData['primary_type'] ?? 'other');
        $typeLabel = $this->resolvePrimaryTypeLabel($primaryType);
        $i2 = [[
            'text'  => $typeLabel,
            'class' => self::PRIMARY_TYPE_SPAN_CLASS[$primaryType] ?? 'muted',
        ]];
        $analysisBadge = $this->buildAnalysisBadge((int) ($rowData['analysis_state'] ?? 0));
        if ($analysisBadge !== null) {
            $i2[] = [
                'text'  => $analysisBadge['label'],
                'class' => self::STATE_SPAN_CLASS[$analysisBadge['style']] ?? 'muted',
            ];
        }
        if (!empty($rowData['is_bulk'])) {
            $i2[] = ['text' => 'hromadná', 'class' => 'muted'];
        }
        $row['i2'] = $i2;

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

        $header = $this->buildDetailHeader($record);

        $tabs = [];

        // Tab 1 — Obsah
        $tabs[] = [
            'id'      => 'content',
            'label'   => $this->detailTabLabel('core.mail.viewerDetailLabels', 'content', 'Content'),
            'content' => $this->buildContentTab($record),
        ];

        // Tab 2 — Analýzy
        $tabs[] = [
            'id'      => 'analyses',
            'label'   => $this->detailTabLabel('core.mail.viewerDetailLabels', 'analyses', 'Analyses'),
            'content' => $this->buildAnalysesTab((int) $record['id']),
        ];

        // Tab 3 — Extrahované dokumenty (Fáze 3a)
        $tabs[] = [
            'id'      => 'extracted',
            'label'   => $this->detailTabLabel('core.mail.viewerDetailLabels', 'extractedDocuments', 'Extracted documents'),
            'content' => $this->buildExtractedDocumentsTab((int) $record['id']),
        ];

        // Tab 4 — Originál (raw .eml)
        $tabs[] = [
            'id'      => 'raw',
            'label'   => $this->detailTabLabel('core.mail.viewerDetailLabels', 'original', 'Original'),
            'content' => $this->buildRawSourceTab($record),
        ];

        return [
            'title'    => $header['title'],
            'subtitle' => $header['subtitle'],
            'badges'   => $header['badges'],
            // Stejny klic jako viewers[].icon v module.jsonc - jeden vyznam,
            // jedna ikona pro radek vieweru i hlavicku detailu.
            'icon'     => 'mail',
            'tabs'     => $tabs,
        ];
    }

    public function getToolbarActions(?array $selectedRow): array
    {
        // Start from the base — gives localized create/edit. Then we override
        // create's label with the mail-specific "New message" / "Nová zpráva"
        // and append reanalyze when the message is in an analyzable state.
        $actions = parent::getToolbarActions($selectedRow);

        $mailDefs = ($this->config?->cfgItem('core.mail.viewerDefaults') ?? [])['toolbarActions'] ?? [];

        if (isset($mailDefs['create']) && isset($actions[0]) && $actions[0]['id'] === 'create') {
            $actions[0]['label']   = $mailDefs['create']['name']    ?? $actions[0]['label'];
            $actions[0]['variant'] = $mailDefs['create']['variant'] ?? $actions[0]['variant'];
        }

        if ($selectedRow === null) {
            return $actions;
        }

        // "Zařadit do Spisovny" — ruční dispozice zprávy do base.registry,
        // viditelná mimo Koš (docState != 90). Obsluha ve Viewer.svelte
        // (POST /_registry/from-message/{ndx} → FormDialog nad novým Konceptem).
        if ((int) ($selectedRow['docState'] ?? 0) !== 90) {
            $fileDef = $mailDefs['fileToRegistry'] ?? ['name' => 'File to registry', 'variant' => 'secondary'];
            $actions[] = [
                'id'      => 'fileToRegistry',
                'label'   => $fileDef['name'] ?? 'File to registry',
                'variant' => $fileDef['variant'] ?? 'secondary',
                'meta'    => [
                    'messageNdx' => (int) $selectedRow['id'],
                ],
            ];
        }

        // "Znova analyzovat" je viditelné jen když analysis_state ∈ {30, 70}
        // (Analyzováno / Analýza selhala) a zpráva není v Archivu/Koši —
        // zrcadlí validaci AnalysisController::reanalyze.
        $analysisState = (int) ($selectedRow['analysis_state'] ?? 0);
        $docState = (int) ($selectedRow['docState'] ?? 0);
        if (($analysisState !== 30 && $analysisState !== 70) || $docState === 80 || $docState === 90) {
            return $actions;
        }

        // Inject seznam aktivních profilů → frontend dropdown bez další API
        // round-trip. Spec §5.3 chce dropdown s profily, ne číselné ID.
        $profiles = $this->db->fetchAll(
            'SELECT `id`, `profile_id`, `name` FROM `core_mail_ai_profiles`'
            . ' WHERE `is_active` = %i ORDER BY `is_default` DESC, `name` ASC',
            1,
        );
        $profileOptions = [];
        foreach ($profiles as $p) {
            $profileOptions[] = [
                'ndx' => (int) $p['id'],
                'profile_id' => (string) $p['profile_id'],
                'name' => (string) $p['name'],
            ];
        }

        $reanalyzeDef = $mailDefs['reanalyze'] ?? ['name' => 'Reanalyze', 'variant' => 'secondary'];
        $actions[] = [
            'id'      => 'reanalyze',
            'label'   => $reanalyzeDef['name']    ?? 'Reanalyze',
            'variant' => $reanalyzeDef['variant'] ?? 'secondary',
            'meta' => [
                'messageNdx' => (int) $selectedRow['id'],
                'profiles' => $profileOptions,
            ],
        ];

        return $actions;
    }

    // -------------------------------------------------------------------------
    // Private — detail tabs
    // -------------------------------------------------------------------------

    /**
     * Hlavička detailu nad taby — předmět jako title, odesílatel · schránka ·
     * doručeno jako subtitle, badges se stavem a primárním typem. Renderuje
     * generický header v ViewerDetail (detail.title/subtitle/badges).
     *
     * @return array{title: string, subtitle: ?string, badges: array<int, array{label: string, style: string}>}
     */
    private function buildDetailHeader(array $record): array
    {
        $subject = trim((string) ($record['subject'] ?? ''));

        $senderName  = trim((string) ($record['sender_name'] ?? ''));
        $senderEmail = trim((string) ($record['sender_email'] ?? ''));
        $sender = match (true) {
            $senderName !== '' && $senderEmail !== '' => $senderName . ' <' . $senderEmail . '>',
            $senderName !== ''                        => $senderName,
            default                                   => $senderEmail,
        };

        $subtitleParts = [];
        if ($sender !== '') {
            $subtitleParts[] = $sender;
        }
        $mailbox = $this->formatMailbox($record);
        if ($mailbox !== '') {
            $subtitleParts[] = $mailbox;
        }
        $received = $this->formatDateTime($record['received_at'] ?? null);
        if ($received !== null) {
            $subtitleParts[] = $received;
        }

        $badges = [];
        $stateBadge = $this->buildStateBadge((int) ($record['docState'] ?? 10));
        if ($stateBadge !== null) {
            $badges[] = $stateBadge;
        }

        $primaryType = (string) ($record['primary_type'] ?? 'other');
        $typeStyle = self::PRIMARY_TYPE_SPAN_CLASS[$primaryType] ?? 'muted';
        $badges[] = [
            'label' => $this->resolvePrimaryTypeLabel($primaryType),
            // Detail badge nemá variantu `muted` — mapujeme na `neutral`.
            'style' => $typeStyle === 'muted' ? 'neutral' : $typeStyle,
        ];

        $analysisBadge = $this->buildAnalysisBadge((int) ($record['analysis_state'] ?? 0));
        if ($analysisBadge !== null) {
            $badges[] = [
                'label' => $analysisBadge['label'],
                'style' => $analysisBadge['style'] === 'archive' ? 'neutral' : $analysisBadge['style'],
            ];
        }

        return [
            'title'    => $subject !== '' ? $subject : '(bez předmětu)',
            'subtitle' => $subtitleParts !== [] ? implode(' · ', $subtitleParts) : null,
            'badges'   => $badges,
        ];
    }

    /**
     * Badge stavu AI analýzy z cfgItem `core.mail.analysisStates`.
     * Hodnota 0 (Bez analýzy) se nezobrazuje → null.
     *
     * @return array{label: string, style: string}|null
     */
    private function buildAnalysisBadge(int $analysisState): ?array
    {
        if ($analysisState === 0 || $this->config === null) {
            return null;
        }

        $cfg = $this->config->cfgItem('core.mail.analysisStates');
        if (!is_array($cfg) || !isset($cfg[(string) $analysisState])) {
            return null;
        }
        $entry = $cfg[(string) $analysisState];

        return [
            'label' => (string) ($entry['name'] ?? $analysisState),
            'style' => (string) ($entry['stateStyle'] ?? 'concept'),
        ];
    }

    /** Badge stavu zprávy (label + stateStyle) z docState configu. */
    private function buildStateBadge(int $docState): ?array
    {
        if ($this->config === null || $this->docStatesCfgItem === null) {
            return null;
        }

        $cfg = DocStateConfig::fromCfgItem($this->config->cfgItem($this->docStatesCfgItem));
        $stateData = $cfg->getState($docState);
        $label = (string) ($stateData['stateName'] ?? '');
        if ($label === '') {
            return null;
        }

        return [
            'label' => $label,
            'style' => (string) ($stateData['stateStyle'] ?? 'concept'),
        ];
    }

    private function buildContentTab(array $record): array
    {
        // Předmět, odesílatel, schránka, doručeno, typ a stav jsou v hlavičce
        // detailu nad taby (buildDetailHeader). Tady zůstávají jen technické
        // identifikátory zprávy.
        $techItems = [];
        $this->addItem($techItems, 'Kód zprávy', $record['message_id'] ?? null);
        if (!empty($record['external_message_id'])) {
            $this->addItem($techItems, 'Message-ID', (string) $record['external_message_id']);
        }

        $bodyHtml = (string) ($record['body_html'] ?? '');
        $bodyPlain = (string) ($record['body_plain'] ?? '');

        // Tělo: preferujeme HTML, fallback na plain. HTML je nedůvěryhodný
        // vstup (e-mail) — frontend ho renderuje v sandboxovaném iframe
        // (SandboxedHtml.svelte), do DB se ukládá raw (api-contract §7).
        $bodyContent = null;
        if ($bodyHtml !== '') {
            $bodyContent = ['type' => 'untrusted-html', 'html' => $bodyHtml];
        } elseif ($bodyPlain !== '') {
            $bodyContent = [
                'type' => 'html',
                'html' => '<pre style="white-space: pre-wrap; font-family: inherit;">'
                    . htmlspecialchars($bodyPlain, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                    . '</pre>',
            ];
        }

        $attachments = $this->fetchContentAttachments($record);

        // Skládáme bloky: tělo, přílohy a technické údaje jen pokud existují.
        // Tělo bez headingu — s hlavičkou nad taby začíná obsah rovnou textem.
        $blocks = [];
        if ($bodyContent !== null) {
            $blocks[] = $bodyContent;
        }
        if ($attachments !== []) {
            $blocks[] = [
                'type' => 'heading',
                'text' => $this->detailTabLabel('core.mail.viewerDetailLabels', 'attachments', 'Attachments'),
            ];
            $blocks[] = ['type' => 'attachment-grid', 'attachments' => $attachments];
        }
        if ($techItems !== []) {
            $blocks[] = [
                'type'   => 'properties',
                'groups' => [['title' => 'Technické údaje', 'items' => $techItems]],
            ];
        }

        if ($blocks === []) {
            return ['type' => 'html', 'html' => '<p class="muted">Zpráva nemá žádný obsah.</p>'];
        }
        if (count($blocks) === 1) {
            return $blocks[0];
        }

        return ['type' => 'composite', 'blocks' => $blocks];
    }

    /**
     * Obsahové přílohy zprávy pro blok `attachment-grid` (AttachmentGrid).
     * Vylučuje raw .eml (raw_source_attachment); velikost posíláme v bajtech,
     * formátuje frontend (formatFileSize v api/attachments.js).
     *
     * @return array<int, array{id: int, name: string, mime_type: string, file_size: int}>
     */
    private function fetchContentAttachments(array $record): array
    {
        $rawId = isset($record['raw_source_attachment']) && $record['raw_source_attachment'] !== null
            ? (int) $record['raw_source_attachment']
            : null;

        // Seznam obsahových příloh = core_attachments_files.table_id = 303 AND record_id = msg.id
        // s vyloučením raw .eml (raw_source_attachment_ndx)
        $sql = 'SELECT `id`, `name`, `file_name`, `file_size`, `mime_type`'
            . ' FROM `core_attachments_files`'
            . ' WHERE `table_id` = %i AND `record_id` = %i AND `is_deleted` = 0';
        $params = [303, (int) $record['id']];

        if ($rawId !== null) {
            $sql .= ' AND `id` != %i';
            $params[] = $rawId;
        }

        $sql .= ' ORDER BY `att_order` ASC, `name` ASC';

        $files = $this->db->fetchAll($sql, ...$params);
        $out = [];
        foreach ($files as $f) {
            $out[] = [
                'id'        => (int) $f['id'],
                'name'      => (string) ($f['name'] ?? $f['file_name']),
                'mime_type' => (string) ($f['mime_type'] ?? ''),
                'file_size' => (int) ($f['file_size'] ?? 0),
            ];
        }

        return $out;
    }

    private function buildAnalysesTab(int $messageId): array
    {
        $analyses = $this->db->fetchAll(
            'SELECT `id`, `analyzed_at`, `status`, `model_name`, `model_version`, `prompt_version`,'
            . ' `confidence`, `cost_usd`, `duration_ms`, `extracted_document_count`, `error_message`'
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
            $confidence = $a['confidence'] !== null ? number_format((float) $a['confidence'], 3) : '—';
            $cost = $a['cost_usd'] !== null ? '$' . number_format((float) $a['cost_usd'], 4) : '—';
            $duration = $a['duration_ms'] !== null
                ? number_format((int) $a['duration_ms'] / 1000, 1) . ' s'
                : '—';

            $rows[] = [
                'analyzed_at' => $this->formatDateTime($a['analyzed_at'] ?? null),
                'status'      => $statusLabels[(int) ($a['status'] ?? 1)] ?? '—',
                'model'       => trim(($a['model_name'] ?? '') . ' ' . ($a['model_version'] ?? '')),
                'prompt'      => $a['prompt_version'] ?? '',
                'confidence'  => $confidence,
                'extracted'   => (string) ($a['extracted_document_count'] ?? 0),
                'cost'        => $cost,
                'duration'    => $duration,
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
                ['id' => 'extracted',   'label' => 'Doc.'],
                ['id' => 'cost',        'label' => 'Cena'],
                ['id' => 'duration',    'label' => 'Trvání'],
            ],
            'rows' => $rows,
        ];
    }

    /**
     * Spec §5.2 — Tab "Extrahované dokumenty". Vrací custom content type
     * `extracted-documents`, který frontend renderuje s badge typu, status
     * badge (barva + ikona), confidence a per-row akce "Použít" / "Zamítnout".
     *
     * Status mapping na barvy/ikony viz config/extractedDocStates.jsonc.
     */
    private function buildExtractedDocumentsTab(int $messageId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT `id`, `doc_type`, `source_attachments`, `confidence`, `status`,'
            . ' `extracted_json`, `rejected_reason`, `applied_at`, `created`'
            . ' FROM `core_mail_extracted_documents`'
            . ' WHERE `message` = %i'
            . ' ORDER BY `created` DESC, `id` DESC',
            $messageId,
        );

        if ($rows === []) {
            return [
                'type' => 'html',
                'html' => '<p class="muted">Pro tuto zprávu zatím nebyly extrahovány žádné dokumenty.</p>',
            ];
        }

        $stateMap = $this->loadExtractedDocStates();
        $typeMap = $this->loadExtractedDocTypes();

        $documents = [];
        foreach ($rows as $r) {
            $statusKey = (int) $r['status'];
            $state = $stateMap[$statusKey] ?? null;
            $docType = (string) $r['doc_type'];
            $typeMeta = $typeMap[$docType] ?? null;

            $sourceNdxs = [];
            if (!empty($r['source_attachments'])) {
                $decoded = json_decode((string) $r['source_attachments'], true);
                if (is_array($decoded)) {
                    $sourceNdxs = array_values(array_map('intval', $decoded));
                }
            }

            $summary = $this->summarizeExtractedJson($r['extracted_json'] ?? null);

            $documents[] = [
                'ndx' => (int) $r['id'],
                'doc_type' => $docType,
                'doc_type_label' => $typeMeta['name'] ?? $docType,
                'confidence' => $r['confidence'] !== null
                    ? round((float) $r['confidence'], 3)
                    : null,
                'status' => $statusKey,
                'status_label' => $state['name'] ?? (string) $statusKey,
                'status_style' => $state['stateStyle'] ?? 'concept',
                'status_icon' => $state['icon'] ?? null,
                'source_attachment_ndxs' => $sourceNdxs,
                'summary' => $summary,
                'extracted_json' => $r['extracted_json'] ?? null,
                'applied_at' => $this->formatDateTime($r['applied_at'] ?? null),
                'rejected_reason' => $r['rejected_reason'] ?? null,
                'can_apply' => in_array($statusKey, [10, 20, 30], true),
                'can_reject' => in_array($statusKey, [10, 20, 30], true),
            ];
        }

        return [
            'type' => 'extracted-documents',
            'documents' => $documents,
        ];
    }

    /**
     * @return array<int, array{name: string, stateStyle: string, icon: ?string}>
     */
    private function loadExtractedDocStates(): array
    {
        if ($this->config === null) {
            return [];
        }
        $cfg = $this->config->cfgItem('core.mail.extractedDocStates');
        if ($cfg === null) {
            return [];
        }
        $out = [];
        foreach ($cfg as $key => $entry) {
            $out[(int) $key] = [
                'name' => (string) ($entry['name'] ?? $key),
                'stateStyle' => (string) ($entry['stateStyle'] ?? 'concept'),
                'icon' => $entry['icon'] ?? null,
            ];
        }
        return $out;
    }

    /**
     * @return array<string, array{name: string}>
     */
    private function loadExtractedDocTypes(): array
    {
        if ($this->config === null) {
            return [];
        }
        $cfg = $this->config->cfgItem('core.mail.extractedDocTypes');
        if ($cfg === null) {
            return [];
        }
        $out = [];
        foreach ($cfg as $key => $entry) {
            $out[(string) $key] = ['name' => (string) ($entry['name'] ?? $key)];
        }
        return $out;
    }

    /**
     * Krátké shrnutí extrahovaného JSON pro list view ("Faktura č. X, 12 500 Kč, dodavatel Y").
     */
    private function summarizeExtractedJson(mixed $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            return null;
        }
        // Common path z faktury default profilu
        $fields = $decoded['fields'] ?? $decoded;
        if (!is_array($fields)) {
            return null;
        }
        $parts = [];
        if (!empty($fields['invoice_number'])) {
            $parts[] = 'č. ' . (string) $fields['invoice_number'];
        }
        if (!empty($fields['total_amount'])) {
            $currency = (string) ($fields['currency'] ?? 'Kč');
            $parts[] = number_format((float) $fields['total_amount'], 2, ',', ' ') . ' ' . $currency;
        }
        $supplier = $fields['supplier']['name'] ?? null;
        if (is_string($supplier) && $supplier !== '') {
            $parts[] = $supplier;
        }
        return $parts === [] ? null : implode(', ', $parts);
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
