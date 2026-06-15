<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Codebooks;

use Shipard\Core\Form\Lookup\LookupItem;
use Shipard\Core\Form\Lookup\TableLookup;

/**
 * Lookup pro bankovní spojení firmy (economy_codebooks_bank_accounts).
 * Hledá v kódu, názvu, čísle účtu a IBANu; display: `kód — název`.
 *
 * Používá ho form bankovního výpisu (economy.bank) a do budoucna i další
 * místa, která vybírají vlastní bankovní účet.
 */
class BankAccountsLookup extends TableLookup
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

        $sql = 'SELECT `id`, `code`, `name` FROM `economy_codebooks_bank_accounts`'
            . ' WHERE `docState` IN (10, 40, 80)';
        $args = [];

        if ($q !== '') {
            $like = '%' . $q . '%';
            $sql .= ' AND (`code` LIKE %s OR `name` LIKE %s OR `account_number` LIKE %s OR `iban` LIKE %s)';
            $args[] = $like;
            $args[] = $like;
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
            'SELECT `id`, `code`, `name` FROM `economy_codebooks_bank_accounts` WHERE `id` IN %in',
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

        return new LookupItem(
            id: (int) $row['id'],
            primary: $primary,
            secondary: null,
        );
    }
}
