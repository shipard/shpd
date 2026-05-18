<?php

declare(strict_types=1);

namespace Shipard\Module\Base\Persons;

use Shipard\Core\Form\Lookup\LookupItem;
use Shipard\Core\Form\Lookup\TableLookup;

/**
 * Lookup pro adresy partnerů. Search smysluplný jen v rámci jednoho
 * partnera — `filter[person]` je povinný, jinak je výsledek prázdný.
 *
 * Aktivní záznamy: validity window (`valid_from` <= dnes <= `valid_to`).
 */
class AddressesLookup extends TableLookup
{
    public function getAllowedFilterKeys(): array
    {
        return ['person'];
    }

    public function search(string $q, array $filter, int $limit): array
    {
        if ($this->db === null) {
            return [];
        }
        $personId = isset($filter['person']) ? (int) $filter['person'] : 0;
        if ($personId <= 0) {
            return [];
        }
        $rows = $this->db->fetchAll(
            'SELECT `id`, `display_line` FROM `base_persons_addresses`'
            . ' WHERE `person` = %i'
            . ' AND (`valid_from` IS NULL OR `valid_from` <= CURDATE())'
            . ' AND (`valid_to`   IS NULL OR `valid_to`   >= CURDATE())'
            . ' ORDER BY `order_pos` ASC, `id` ASC'
            . ' LIMIT %i',
            $personId, $limit,
        );
        return array_map(fn(array $r) => self::buildItem($r), $rows);
    }

    public function resolve(array $ids): array
    {
        if ($this->db === null || $ids === []) {
            return [];
        }
        $intIds = array_values(array_filter(array_map('intval', $ids), fn($v) => $v > 0));
        if ($intIds === []) {
            return [];
        }
        $rows = $this->db->fetchAll(
            'SELECT `id`, `display_line` FROM `base_persons_addresses`'
            . ' WHERE `id` IN %in',
            $intIds,
        );
        return array_map(fn(array $r) => self::buildItem($r), $rows);
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function buildItem(array $row): LookupItem
    {
        $primary = trim((string) ($row['display_line'] ?? '')) ?: ('#' . (string) $row['id']);
        return new LookupItem(id: (int) $row['id'], primary: $primary);
    }
}
