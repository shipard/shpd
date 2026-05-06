<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\Core;

use Shipard\Core\Document\DocStateConfig;
use Shipard\Core\Viewer\TableViewer;

class NumberSeriesViewer extends TableViewer
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

    public function selectRows(?string $search, array $filters, int $pageNumber): array
    {
        $sql = 'SELECT `id`, `doc_type`, `name`, `doc_number_code`, `doc_number_pattern`,'
            . ' `reset_scope`, `valid_from`, `valid_to`, `notice`, `docState`, `docStateMain`'
            . ' FROM `' . $this->table . '`';

        $conditions = [];
        $params = [];

        $viewGroup = 'active';
        foreach ($filters as $filter) {
            if ($filter['id'] === 'viewGroup') {
                $viewGroup = (string) $filter['value'];
            }
        }

        if ($viewGroup !== 'all') {
            [$vgSql, $vgParams] = $this->buildViewGroupFilter($this->docStatesCfgItem, $viewGroup);
            if ($vgSql !== '') {
                $conditions[] = $vgSql;
                $params = array_merge($params, $vgParams);
            }
        }

        if ($search !== null && $search !== '') {
            [$searchSql, $searchParams] = $this->buildSearchCondition(['name', 'doc_number_code'], $search);
            if ($searchSql !== '') {
                $conditions[] = $searchSql;
                $params = array_merge($params, $searchParams);
            }
        }

        if ($conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        $sql .= ' ORDER BY `docStateMain` ASC, `doc_type` ASC, `name` ASC, `id` ASC';

        [$offset, $limit] = $this->buildPaginationLimit($pageNumber);
        $sql .= ' LIMIT ' . $offset . ', ' . $limit;

        return $this->db->fetchAll($sql, ...$params);
    }

    public function renderRow(array $rowData): array
    {
        $docTypeLabel = $this->resolveDocTypeLabel((string) ($rowData['doc_type'] ?? ''));

        $row = [
            'id' => (int) $rowData['id'],
            't1' => $rowData['name'] ?? '',
            'i1' => $rowData['doc_number_code'] ?? null,
        ];

        $t2 = [];
        if ($docTypeLabel !== '') {
            $t2[] = ['text' => $docTypeLabel];
        }
        if (!empty($rowData['doc_number_pattern'])) {
            $t2[] = ['text' => (string) $rowData['doc_number_pattern'], 'class' => 'muted'];
        }

        $validFrom = $this->formatDate($rowData['valid_from'] ?? null);
        $validTo   = $this->formatDate($rowData['valid_to']   ?? null);
        if ($validFrom !== null && $validTo !== null) {
            $t2[] = ['text' => $validFrom . ' – ' . $validTo, 'class' => 'muted'];
        } elseif ($validFrom !== null) {
            $t2[] = ['text' => 'od ' . $validFrom, 'class' => 'muted'];
        } elseif ($validTo !== null) {
            $t2[] = ['text' => 'do ' . $validTo, 'class' => 'muted'];
        }

        $docState = (int) ($rowData['docState'] ?? 10);
        $cfg = DocStateConfig::fromCfgItem($this->config?->cfgItem($this->docStatesCfgItem));
        $stateData = $cfg->getState($docState);
        $stateStyle = $stateData['stateStyle'] ?? 'concept';

        if ($docState !== 10) {
            $t2[] = [
                'text'  => $stateData['stateName'] ?? '',
                'class' => self::STATE_SPAN_CLASS[$stateStyle] ?? 'muted',
            ];
        }

        $row['t2'] = $t2 !== [] ? $t2 : null;

        if (!empty($rowData['notice'])) {
            $row['t3'] = (string) $rowData['notice'];
        }

        $row['stateStyle'] = $stateStyle;

        return $row;
    }

    public function renderDetail(int $recordId): array
    {
        $record = $this->db->fetchRow(
            'SELECT * FROM `' . $this->table . '` WHERE `id` = %i',
            $recordId,
        );

        if ($record === null) {
            return ['tabs' => []];
        }

        return [
            'tabs' => [
                [
                    'id'      => 'overview',
                    'label'   => $this->defaultOverviewLabel(),
                    'content' => $this->buildOverviewContent($record),
                ],
            ],
        ];
    }

    private function buildOverviewContent(array $record): array
    {
        $identityItems = [];
        $this->addItem($identityItems, 'Název', $record['name'] ?? null);
        $this->addItem(
            $identityItems,
            'Typ dokladu',
            $this->resolveDocTypeLabel((string) ($record['doc_type'] ?? '')),
        );
        $this->addItem($identityItems, 'Poznámka', $record['notice'] ?? null);

        $numberingItems = [];
        $this->addItem($numberingItems, 'Kód řady (%C)', $record['doc_number_code'] ?? null);
        $this->addItem($numberingItems, 'Vzorec čísla dokladu', $record['doc_number_pattern'] ?? null);
        $this->addItem(
            $numberingItems,
            'Restart počítadla',
            $this->resolveResetScopeLabel((string) ($record['reset_scope'] ?? '')),
        );

        $validityItems = [];
        $this->addItem($validityItems, 'Platnost od', $this->formatDate($record['valid_from'] ?? null));
        $this->addItem(
            $validityItems,
            'Platnost do',
            $this->formatDate($record['valid_to'] ?? null) ?? 'bez konce',
        );

        $groups = [];
        if ($identityItems !== []) {
            $groups[] = ['title' => 'Identifikace', 'items' => $identityItems];
        }
        if ($numberingItems !== []) {
            $groups[] = ['title' => 'Číslování', 'items' => $numberingItems];
        }
        if ($validityItems !== []) {
            $groups[] = ['title' => 'Platnost', 'items' => $validityItems];
        }

        return ['type' => 'properties', 'groups' => $groups];
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

    private function resolveResetScopeLabel(string $key): string
    {
        if ($key === '' || $this->config === null) {
            return '';
        }
        $cfg = $this->config->cfgItem('docs.core.resetScopes');
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

    private function formatDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        return (string) $value;
    }
}
