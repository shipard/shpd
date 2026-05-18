<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Alerts;

use Shipard\Core\Alerts\AlertReconciler;
use Shipard\Core\Viewer\TableViewer;

/**
 * Viewer pro `core_alerts_alerts`. Žádný tab bar (alerty nepoužívají
 * docStates) — místo toho filter `alert_state` s hodnotami:
 *   open       — Active + Snoozed   (default)
 *   active     — jen Active
 *   snoozed    — jen Snoozed
 *   resolved   — jen Resolved
 *   dismissed  — jen Dismissed
 *   all        — všechno
 *
 * Default sort: severity DESC, last_seen_at DESC — kritické nahoře a v rámci
 * závažnosti nejnovější napřed.
 */
class AlertsViewer extends TableViewer
{
    protected ?string $docStatesCfgItem = null;   // alerts NEjsou doc-state

    /** Mapping severity (int z DB) na CSS class span pro t2 badge. */
    private const SEVERITY_SPAN_CLASS = [
        10 => 'primary',    // info
        20 => 'warning',    // warning
        30 => 'danger',     // error
    ];

    /** Severity int → vizuální label fallback (kdyby cfgItem nedoběhl). */
    private const SEVERITY_LABEL_FALLBACK = [
        10 => 'Info',
        20 => 'Warning',
        30 => 'Error',
    ];

    /** Mapping alert_state na stateStyle (CSS proužek řádku z doc-state palety). */
    private const STATE_TO_STYLE = [
        AlertReconciler::STATE_ACTIVE    => 'concept',    // žlutá — potřebuje pozornost
        AlertReconciler::STATE_SNOOZED   => 'edit',       // fialová — odloženo
        AlertReconciler::STATE_RESOLVED  => 'done',       // zelená — vyřešeno
        AlertReconciler::STATE_DISMISSED => 'archive',    // šedá — zamítnuto
    ];

    private const STATE_LABEL_FALLBACK = [
        AlertReconciler::STATE_ACTIVE    => 'Active',
        AlertReconciler::STATE_SNOOZED   => 'Snoozed',
        AlertReconciler::STATE_RESOLVED  => 'Resolved',
        AlertReconciler::STATE_DISMISSED => 'Dismissed',
    ];

    public function selectRows(?string $search, array $filters, int $pageNumber): array
    {
        $sql = 'SELECT `id`, `check_id`, `finding_key`, `title`, `message`, `severity`,'
            . ' `alert_state`, `snoozed_until`, `first_seen_at`, `last_seen_at`, `seen_count`,'
            . ' `subject_table_id`, `subject_row_id`'
            . ' FROM `' . $this->table . '`';

        $conditions = [];
        $params     = [];

        $alertStateFilter = 'open';
        foreach ($filters as $f) {
            if (($f['id'] ?? null) === 'alert_state' && is_string($f['value'] ?? null) && $f['value'] !== '') {
                $alertStateFilter = (string) $f['value'];
            }
        }

        $stateCondition = $this->resolveStateFilter($alertStateFilter);
        if ($stateCondition !== null) {
            $conditions[] = $stateCondition[0];
            $params       = array_merge($params, $stateCondition[1]);
        }

        if ($search !== null && $search !== '') {
            [$searchSql, $searchParams] = $this->buildSearchCondition(
                ['title', 'message', 'check_id'],
                $search,
            );
            if ($searchSql !== '') {
                $conditions[] = $searchSql;
                $params       = array_merge($params, $searchParams);
            }
        }

        if ($conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        $sql .= ' ORDER BY `severity` DESC, `last_seen_at` DESC, `id` DESC';

        [$offset, $limit] = $this->buildPaginationLimit($pageNumber);
        $sql .= ' LIMIT ' . $offset . ', ' . $limit;

        return $this->db->fetchAll($sql, ...$params);
    }

    public function renderRow(array $rowData): array
    {
        $severity   = (int) ($rowData['severity'] ?? 20);
        $alertState = (int) ($rowData['alert_state'] ?? AlertReconciler::STATE_ACTIVE);

        $severityLabel = $this->severityLabel($severity);
        $stateLabel    = $this->stateLabel($alertState);

        $row = [
            'id'   => (int) $rowData['id'],
            't1'   => $rowData['title'] ?? '',
            'i1'   => null,
        ];

        // Severity badge first, then state if non-Active, then check_id (raw)
        $t2 = [
            ['text' => $severityLabel, 'class' => self::SEVERITY_SPAN_CLASS[$severity] ?? 'muted'],
        ];

        if ($alertState !== AlertReconciler::STATE_ACTIVE) {
            $t2[] = ['text' => $stateLabel, 'class' => 'muted'];
        }

        $t2[] = ['text' => (string) ($rowData['check_id'] ?? ''), 'class' => 'muted'];

        $row['t2'] = $t2;

        // Line 3: relative last_seen + seen_count if >1
        $parts = [];
        if (!empty($rowData['last_seen_at'])) {
            $parts[] = 'Naposled ' . $this->formatRelative((string) $rowData['last_seen_at']);
        }
        $sc = (int) ($rowData['seen_count'] ?? 1);
        if ($sc > 1) {
            $parts[] = $sc . '×';
        }
        if ($alertState === AlertReconciler::STATE_SNOOZED && !empty($rowData['snoozed_until'])) {
            $parts[] = 'odloženo do ' . substr((string) $rowData['snoozed_until'], 0, 16);
        }
        $row['t3'] = $parts !== [] ? implode(' • ', $parts) : null;

        $row['stateStyle'] = self::STATE_TO_STYLE[$alertState] ?? 'concept';

        return $row;
    }

    public function renderDetail(int $recordId): array
    {
        $row = $this->db->fetchRow(
            'SELECT * FROM `' . $this->table . '` WHERE `id` = %i',
            $recordId,
        );
        if ($row === null) {
            return ['tabs' => []];
        }

        $severity   = (int) ($row['severity'] ?? 20);
        $alertState = (int) ($row['alert_state'] ?? AlertReconciler::STATE_ACTIVE);

        // Overview
        $overviewItems = [
            ['label' => 'Titulek',     'value' => (string) ($row['title'] ?? '')],
            ['label' => 'Závažnost',   'value' => $this->severityLabel($severity)],
            ['label' => 'Stav',        'value' => $this->stateLabel($alertState)],
            ['label' => 'Kontrola',    'value' => (string) ($row['check_id'] ?? '')],
            ['label' => 'Klíč nálezu', 'value' => (string) ($row['finding_key'] ?? '')],
            ['label' => 'Poprvé spatřeno',  'value' => (string) ($row['first_seen_at'] ?? '')],
            ['label' => 'Naposled spatřeno','value' => (string) ($row['last_seen_at'] ?? '')],
            ['label' => 'Počet pozorování', 'value' => (string) ((int) ($row['seen_count'] ?? 1))],
        ];
        if ($alertState === AlertReconciler::STATE_SNOOZED && !empty($row['snoozed_until'])) {
            $overviewItems[] = ['label' => 'Odloženo do', 'value' => (string) $row['snoozed_until']];
        }
        if ($alertState === AlertReconciler::STATE_DISMISSED && !empty($row['dismissed_at'])) {
            $overviewItems[] = ['label' => 'Zamítnuto',   'value' => (string) $row['dismissed_at']];
        }
        if ($alertState === AlertReconciler::STATE_RESOLVED && !empty($row['resolved_at'])) {
            $overviewItems[] = ['label' => 'Vyřešeno',    'value' => (string) $row['resolved_at']];
        }

        $overviewGroups = [
            ['title' => 'Identifikace a stav', 'items' => $overviewItems],
        ];

        if (!empty($row['message'])) {
            $overviewGroups[] = [
                'title' => 'Zpráva',
                'items' => [['label' => '', 'value' => (string) $row['message']]],
            ];
        }

        $tabs = [
            [
                'id'      => 'overview',
                'label'   => $this->detailTabLabel('core.alerts.viewerDetailLabels', 'overview', 'Overview'),
                'content' => ['type' => 'properties', 'groups' => $overviewGroups],
            ],
        ];

        // Context tab — raw JSON view of context + actions
        $contextHtml = '';
        if (!empty($row['actions'])) {
            $decoded = is_string($row['actions']) ? json_decode($row['actions'], true) : null;
            if (is_array($decoded)) {
                $contextHtml .= '<h4>Akce</h4><pre>'
                    . htmlspecialchars(json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8')
                    . '</pre>';
            }
        }
        if (!empty($row['context'])) {
            $decoded = is_string($row['context']) ? json_decode($row['context'], true) : null;
            if (is_array($decoded)) {
                $contextHtml .= '<h4>Kontext</h4><pre>'
                    . htmlspecialchars(json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8')
                    . '</pre>';
            }
        }
        if ($contextHtml !== '') {
            $tabs[] = [
                'id'      => 'context',
                'label'   => $this->detailTabLabel('core.alerts.viewerDetailLabels', 'context', 'Context'),
                'content' => ['type' => 'html', 'html' => $contextHtml],
            ];
        }

        return ['tabs' => $tabs];
    }

    public function getFilters(): array
    {
        return [[
            'id'      => 'alert_state',
            'label'   => 'Stav',
            'type'    => 'enum',
            'default' => 'open',
            'options' => [
                ['value' => 'open',      'label' => 'Otevřené'],
                ['value' => 'active',    'label' => 'Aktivní'],
                ['value' => 'snoozed',   'label' => 'Odložené'],
                ['value' => 'resolved',  'label' => 'Vyřešené'],
                ['value' => 'dismissed', 'label' => 'Zamítnuté'],
                ['value' => 'all',       'label' => 'Vše'],
            ],
        ]];
    }

    /**
     * Alerts viewer nedědí default `Add`/`Open` toolbar — alerty se nevytvářejí
     * ručně, jen přes reconciler. Nabídneme `Run due` (POST na CLI/API).
     */
    public function getToolbarActions(?array $selectedRow): array
    {
        $defs    = ($this->config?->cfgItem('core.alerts.viewerDefaults') ?? [])['toolbarActions'] ?? [];
        $runDef  = $defs['runDue'] ?? ['name' => 'Run due checks', 'variant' => 'primary'];

        $actions = [[
            'id'      => 'runDue',
            'label'   => $runDef['name'] ?? 'Run due checks',
            'variant' => $runDef['variant'] ?? 'primary',
        ]];

        return $actions;
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /**
     * @return array{0:string,1:array}|null  [sql_fragment, params] or null = no condition
     */
    private function resolveStateFilter(string $value): ?array
    {
        return match ($value) {
            'all'       => null,
            'active'    => ['`alert_state` = %i', [AlertReconciler::STATE_ACTIVE]],
            'snoozed'   => ['`alert_state` = %i', [AlertReconciler::STATE_SNOOZED]],
            'resolved'  => ['`alert_state` = %i', [AlertReconciler::STATE_RESOLVED]],
            'dismissed' => ['`alert_state` = %i', [AlertReconciler::STATE_DISMISSED]],
            'open'      => [
                '`alert_state` IN %in',
                [[AlertReconciler::STATE_ACTIVE, AlertReconciler::STATE_SNOOZED]],
            ],
            default     => null,
        };
    }

    private function severityLabel(int $severity): string
    {
        $cfg = $this->config?->cfgItem('core.alerts.severities') ?? [];
        return (string) ($cfg[(string) $severity]['name'] ?? self::SEVERITY_LABEL_FALLBACK[$severity] ?? 'Severity ' . $severity);
    }

    private function stateLabel(int $alertState): string
    {
        $cfg = $this->config?->cfgItem('core.alerts.alertStates') ?? [];
        return (string) ($cfg[(string) $alertState]['name'] ?? self::STATE_LABEL_FALLBACK[$alertState] ?? 'State ' . $alertState);
    }

    private function formatRelative(string $datetime): string
    {
        $ts = strtotime($datetime);
        if ($ts === false) {
            return $datetime;
        }
        $diff = time() - $ts;
        if ($diff < 60)     return 'před chvílí';
        if ($diff < 3600)   return 'před ' . (int) ($diff / 60) . ' min';
        if ($diff < 86400)  return 'před ' . (int) ($diff / 3600) . ' h';
        if ($diff < 86400 * 7) return 'před ' . (int) ($diff / 86400) . ' dny';
        return substr($datetime, 0, 10);
    }
}
