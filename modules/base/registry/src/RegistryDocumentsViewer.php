<?php

declare(strict_types=1);

namespace Shipard\Module\Base\Registry;

use Shipard\Core\Document\DocStateConfig;
use Shipard\Core\Viewer\TableViewer;

class RegistryDocumentsViewer extends TableViewer
{
    protected ?string $docStatesCfgItem = 'core.system.docStatesArchive';

    public function selectRows(?string $search, array $filters, int $pageNumber): array
    {
        $sql = 'SELECT d.`id`, d.`title`, d.`doc_kind`, d.`binder`, d.`ref_number`,'
            . ' d.`valid_to`, d.`ai_summary`, d.`docState`, d.`docStateMain`,'
            . ' p.`full_name` AS `partner_name`, b.`name` AS `binder_name`'
            . ' FROM `' . $this->table . '` d'
            . ' LEFT JOIN `base_persons_persons` p ON p.`id` = d.`partner`'
            . ' LEFT JOIN `base_registry_binders` b ON b.`id` = d.`binder`';

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
                $conditions[] = 'd.' . $vgSql;
                $params = array_merge($params, $vgParams);
            }
        }

        if ($search !== null && $search !== '') {
            [$searchSql, $searchParams] = $this->buildSearchCondition(
                ['title', 'ref_number', 'ai_summary'],
                $search,
            );
            if ($searchSql !== '') {
                $conditions[] = $searchSql;
                $params = array_merge($params, $searchParams);
            }
        }

        if ($conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        $sql .= ' ORDER BY d.`id` DESC';

        [$offset, $limit] = $this->buildPaginationLimit($pageNumber);
        $sql .= ' LIMIT ' . $offset . ', ' . $limit;

        return $this->db->fetchAll($sql, ...$params);
    }

    public function renderRow(array $rowData): array
    {
        $row = [
            'id'         => (int) $rowData['id'],
            't1'         => (string) ($rowData['title'] ?? ''),
            'stateStyle' => $this->resolveStateStyle((int) ($rowData['docState'] ?? 10)),
        ];

        $validTo = $rowData['valid_to'] ?? null;
        $row['i1'] = $validTo !== null
            ? (string) ($validTo instanceof \DateTimeInterface ? $validTo->format('Y-m-d') : $validTo)
            : null;

        $partner = (string) ($rowData['partner_name'] ?? '');
        $refNumber = (string) ($rowData['ref_number'] ?? '');
        $row['t2'] = $partner !== '' ? $partner : ($refNumber !== '' ? $refNumber : null);

        $row['i2'] = $this->buildKindBadge($rowData['doc_kind'] ?? null);

        $t3 = [];
        $binderName = (string) ($rowData['binder_name'] ?? '');
        if ($binderName !== '') {
            $t3[] = ['text' => '[' . $binderName . ']', 'class' => 'muted'];
        }
        $summary = (string) ($rowData['ai_summary'] ?? '');
        if ($summary !== '') {
            $firstLine = strtok($summary, "\n");
            $t3[] = ['text' => $firstLine !== false ? $firstLine : $summary];
        }
        $row['t3'] = $t3 !== [] ? $t3 : null;

        return $row;
    }

    private function buildKindBadge(mixed $kind): ?array
    {
        $kind = (string) ($kind ?? '');
        if ($kind === '') {
            return null;
        }
        $label = $kind;
        if ($this->config !== null) {
            $cfg = $this->config->cfgItem('base.registry.docKinds');
            if (is_array($cfg) && isset($cfg[$kind]['name'])) {
                $label = (string) $cfg[$kind]['name'];
            }
        }
        return [['text' => $label, 'class' => 'primary']];
    }

    private function resolveStateStyle(int $docState): string
    {
        if ($this->config === null || $this->docStatesCfgItem === null) {
            return 'concept';
        }
        $cfg = DocStateConfig::fromCfgItem($this->config->cfgItem($this->docStatesCfgItem));
        return $cfg->getState($docState)['stateStyle'] ?? 'concept';
    }
}
