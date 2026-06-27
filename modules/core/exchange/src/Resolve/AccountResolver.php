<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Resolve;

use Dibi\Connection;

/**
 * Resolves a chart-of-accounts number (string) to an
 * `economy_accounting_accounts.id`.
 *
 * Used by accounting-document import (acc.record rows): the canonical row
 * carries the account `number` verbatim from the legacy system; we look up
 * the active account row by exact number. No fuzzy matching, no userAction —
 * the number is authoritative. Not found → null (caller emits a warning and
 * the row reaches the journal as an error line, never blocking the import).
 *
 * Per-run cache keyed by number — a document repeats the same accounts across
 * rows (518100 / 321100 / …).
 */
class AccountResolver
{
    private const ACTIVE_STATES = [10, 40, 80];

    /** @var array<string, ?int> */
    private array $cache = [];

    public function __construct(
        private readonly Connection $db,
    ) {}

    public function resolve(string $number): ?int
    {
        $number = trim($number);
        if ($number === '') {
            return null;
        }
        if (array_key_exists($number, $this->cache)) {
            return $this->cache[$number];
        }

        $row = $this->db->fetch(
            'SELECT [id] FROM [economy_accounting_accounts]
             WHERE [number] = %s AND [docState] IN (%i, %i, %i)
             ORDER BY [id] LIMIT 1',
            $number,
            self::ACTIVE_STATES[0], self::ACTIVE_STATES[1], self::ACTIVE_STATES[2],
        );

        return $this->cache[$number] = ($row !== null ? (int) $row['id'] : null);
    }
}
