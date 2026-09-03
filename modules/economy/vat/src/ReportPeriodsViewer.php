<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Vat;

use Shipard\Core\Document\DocStateConfig;
use Shipard\Core\Form\EnumOptionsHelper;
use Shipard\Core\Viewer\TableViewer;

/**
 * Viewer instancí daňových tvrzení (`economy_vat_report_periods`). Filtry:
 * typ tvrzení, registrace (jen při více než jedné), rok začátku období.
 */
class ReportPeriodsViewer extends TableViewer
{
    protected ?string $docStatesCfgItem = 'core.system.docStatesArchive';

    private const STATE_SPAN_CLASS = [
        'concept'   => 'warning',
        'confirmed' => 'primary',
        'done'      => 'success',
        'edit'      => 'warning',
        'archive'   => 'muted',
        'trash'     => 'muted',
        'cancelled' => 'danger',
    ];

    /** @var ?array<int, string> */
    private ?array $registrationNames = null;

    public function selectRows(?string $search, array $filters, int $pageNumber): array
    {
        $sql = 'SELECT `id`, `vat_registration`, `report_type`, `name`,'
            . ' `date_begin`, `date_end`, `locked`, `docState`, `docStateMain`'
            . ' FROM `' . $this->table . '` ';

        $conditions = [];
        $params = [];

        $viewGroup = 'active';
        foreach ($filters as $filter) {
            $value = $filter['value'] ?? null;
            switch ($filter['id'] ?? '') {
                case 'viewGroup':
                    $viewGroup = (string) $value;
                    break;
                case 'report_type':
                    if (is_string($value) && $value !== '' && $value !== 'all') {
                        $conditions[] = '`report_type` = %s';
                        $params[] = $value;
                    }
                    break;
                case 'vat_registration':
                    if (is_numeric($value) && (int) $value > 0) {
                        $conditions[] = '`vat_registration` = %i';
                        $params[] = (int) $value;
                    }
                    break;
                case 'year':
                    if (is_numeric($value) && (int) $value > 0) {
                        $conditions[] = 'YEAR(`date_begin`) = %i';
                        $params[] = (int) $value;
                    }
                    break;
            }
        }

        if ($viewGroup !== 'all') {
            [$vgSql, $vgParams] = $this->buildViewGroupFilter($this->docStatesCfgItem, $viewGroup);
            if ($vgSql !== '') {
                $conditions[] = str_replace('`docState', '`docState', $vgSql);
                $params = array_merge($params, $vgParams);
            }
        }

        if ($search !== null && $search !== '') {
            [$searchSql, $searchParams] = $this->buildSearchCondition(['name'], $search);
            if ($searchSql !== '') {
                $conditions[] = $searchSql;
                $params = array_merge($params, $searchParams);
            }
        }

        if ($conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        $sql .= ' ORDER BY `date_begin` DESC, `report_type` ASC, `id` DESC';

        [$offset, $limit] = $this->buildPaginationLimit($pageNumber);
        $sql .= ' LIMIT ' . $offset . ', ' . $limit;

        return $this->db->fetchAll($sql, ...$params);
    }

    public function renderRow(array $rowData): array
    {
        $typeLabels = $this->resolveTypeLabels();
        $type = (string) ($rowData['report_type'] ?? '');

        $row = [
            'id' => (int) $rowData['id'],
            't1' => (string) ($rowData['name'] ?? ''),
            'i1' => $typeLabels[$type] ?? $type,
        ];

        $t2 = [];
        $range = $this->formatDate($rowData['date_begin'] ?? null) . ' – ' . $this->formatDate($rowData['date_end'] ?? null);
        $t2[] = ['text' => $range];

        $registrations = $this->registrationNames();
        if (count($registrations) > 1) {
            $regId = (int) ($rowData['vat_registration'] ?? 0);
            $t2[] = ['text' => $registrations[$regId] ?? ('#' . $regId), 'class' => 'muted'];
        }

        if (!empty($rowData['locked'])) {
            $t2[] = ['text' => 'Uzamčeno', 'class' => 'warning', 'icon' => 'lock'];
        }

        $docState = (int) ($rowData['docState'] ?? 10);
        $cfg = DocStateConfig::fromCfgItem($this->config?->cfgItem($this->docStatesCfgItem));
        $stateData = $cfg->getState($docState);
        $stateStyle = $stateData['stateStyle'] ?? 'concept';
        if ($docState !== 40) {
            $t2[] = [
                'text'  => $stateData['stateName'] ?? '',
                'class' => self::STATE_SPAN_CLASS[$stateStyle] ?? 'muted',
            ];
        }

        $row['t2'] = $t2;
        $row['stateStyle'] = $stateStyle;
        return $row;
    }

    public function renderDetail(int $recordId): array
    {
        $record = $this->db->fetchRow('SELECT * FROM `' . $this->table . '` WHERE `id` = %i', $recordId);
        if ($record === null) {
            return ['tabs' => []];
        }

        $typeLabels = $this->resolveTypeLabels();
        $type = (string) ($record['report_type'] ?? '');
        $registrations = $this->registrationNames();

        $items = [
            ['label' => 'Název', 'value' => (string) ($record['name'] ?? '')],
            ['label' => 'Typ tvrzení', 'value' => $typeLabels[$type] ?? $type],
            ['label' => 'Registrace DPH', 'value' => $registrations[(int) ($record['vat_registration'] ?? 0)] ?? ''],
            ['label' => 'Začátek období', 'value' => $this->formatDate($record['date_begin'] ?? null)],
            ['label' => 'Konec období', 'value' => $this->formatDate($record['date_end'] ?? null)],
            ['label' => 'Uzamčeno', 'value' => !empty($record['locked']) ? 'Ano' : 'Ne'],
        ];

        $column = ReportPeriodDocument::HEAD_COLUMN_BY_TYPE[$type] ?? null;
        if ($column !== null) {
            $count = (int) $this->db->fetchSingle(
                'SELECT COUNT(*) FROM `docs_core_heads` WHERE %n = %i AND `docState` != 90',
                $column, $recordId,
            );
            $items[] = ['label' => 'Přiřazené doklady', 'value' => (string) $count];
        }

        return ['tabs' => [[
            'id'      => 'overview',
            'label'   => $this->defaultOverviewLabel(),
            'content' => ['type' => 'properties', 'groups' => [['title' => 'Tvrzení', 'items' => $items]]],
        ]]];
    }

    public function getFilters(): array
    {
        $typeOptions = [['value' => 'all', 'label' => 'Vše']];
        foreach ($this->resolveTypeLabels() as $key => $label) {
            $typeOptions[] = ['value' => $key, 'label' => $label];
        }

        $filters = [[
            'id'      => 'report_type',
            'label'   => 'Typ tvrzení',
            'type'    => 'enum',
            'default' => 'all',
            'options' => $typeOptions,
        ]];

        $registrations = $this->registrationNames();
        if (count($registrations) > 1) {
            $regOptions = [['value' => '0', 'label' => 'Vše']];
            foreach ($registrations as $id => $name) {
                $regOptions[] = ['value' => (string) $id, 'label' => $name];
            }
            $filters[] = [
                'id'      => 'vat_registration',
                'label'   => 'Registrace DPH',
                'type'    => 'enum',
                'default' => '0',
                'options' => $regOptions,
            ];
        }

        $years = $this->db->fetchAll(
            'SELECT DISTINCT YEAR(`date_begin`) AS y FROM `' . $this->table . '`'
            . ' WHERE `docState` != 90 ORDER BY y DESC',
        );
        if ($years !== []) {
            $yearOptions = [['value' => '0', 'label' => 'Vše']];
            foreach ($years as $row) {
                $yearOptions[] = ['value' => (string) $row['y'], 'label' => (string) $row['y']];
            }
            $filters[] = [
                'id'      => 'year',
                'label'   => 'Rok',
                'type'    => 'enum',
                'default' => '0',
                'options' => $yearOptions,
            ];
        }

        return $filters;
    }

    /** @return array<string, string> */
    private function resolveTypeLabels(): array
    {
        $cfgData = $this->config?->cfgItem('economy.vat.reportTypes');
        if (!is_array($cfgData)) {
            return [];
        }
        $labels = [];
        foreach (EnumOptionsHelper::fromCfgData($cfgData, 'enumString', 'economy.vat.reportTypes') as $opt) {
            $labels[(string) $opt['value']] = (string) $opt['label'];
        }
        return $labels;
    }

    /** @return array<int, string> id → název registrace (živé registrace) */
    private function registrationNames(): array
    {
        if ($this->registrationNames === null) {
            $this->registrationNames = [];
            $rows = $this->db->fetchAll(
                'SELECT `id`, `name` FROM `economy_codebooks_vat_registrations` WHERE `docState` != 90 ORDER BY `name`, `id`',
            );
            foreach ($rows as $row) {
                $this->registrationNames[(int) $row['id']] = (string) $row['name'];
            }
        }
        return $this->registrationNames;
    }

    private function formatDate(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        return substr((string) $value, 0, 10);
    }
}
