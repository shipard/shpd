<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Codebooks;

use Shipard\Core\Form\Lookup\LookupItem;
use Shipard\Core\Form\Lookup\TableLookup;

/**
 * Lookup pro pokladny (economy_codebooks_cash_desks). Hledá v kódu a názvu;
 * display: `kód — název`, sekundárně měna.
 *
 * Používá ho formulář číselné řady (vazba řady na pokladnu) a hlavička
 * dokladu (pokladna hotově placené faktury).
 */
class CashDesksLookup extends TableLookup
{
    public function getAllowedFilterKeys(): array
    {
        return [];
    }

    public function search(string $q, array $filter, int $limit): array
    {
        if ($this->db === null) {
            return [];
        }
        $q = trim($q);

        $sql = 'SELECT `id`, `code`, `name`, `currency` FROM `economy_codebooks_cash_desks`'
            . ' WHERE `docState` IN (10, 40, 80)';
        $args = [];

        if ($q !== '') {
            $like = '%' . $q . '%';
            $sql .= ' AND (`code` LIKE %s OR `name` LIKE %s)';
            $args[] = $like;
            $args[] = $like;
        }
        $sql .= ' ORDER BY `sort_order` ASC, `name` ASC LIMIT %i';
        $args[] = $limit;

        $rows = $this->db->fetchAll($sql, ...$args);
        return array_map(fn($r) => $this->buildItem($r), $rows);
    }

    public function resolve(array $ids): array
    {
        if ($this->db === null || $ids === []) {
            return [];
        }
        $intIds = array_filter(array_map('intval', $ids), fn($v) => $v > 0);
        if ($intIds === []) {
            return [];
        }
        $rows = $this->db->fetchAll(
            'SELECT `id`, `code`, `name`, `currency` FROM `economy_codebooks_cash_desks` WHERE `id` IN %in',
            array_values($intIds),
        );
        return array_map(fn($r) => $this->buildItem($r), $rows);
    }

    /** @param array<string, mixed> $row */
    private function buildItem(array $row): LookupItem
    {
        $code = trim((string) ($row['code'] ?? ''));
        $name = trim((string) ($row['name'] ?? ''));
        $primary = $code !== '' && $name !== ''
            ? "{$code} — {$name}"
            : ($name !== '' ? $name : ('#' . $row['id']));
        $currency = strtoupper(trim((string) ($row['currency'] ?? '')));

        return new LookupItem(
            id: (int) $row['id'],
            primary: $primary,
            secondary: $currency !== '' ? $currency : null,
        );
    }
}
