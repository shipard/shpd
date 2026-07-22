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
 * the account row by exact number. No fuzzy matching, no userAction —
 * the number is authoritative. Not found → null (caller emits a warning and
 * the row reaches the journal as an error line, never blocking the import).
 *
 * Unlike the other fresh-resolve probes (which stay ACTIVE_STATES), this one
 * accepts LINKABLE_STATES: the number is a historic reference, and posting a
 * 2015 document to a since-archived loan account is legitimate. Only deleted
 * (90) is out. When the same number exists in several states, a non-archived
 * row wins.
 *
 * Per-run cache keyed by number — a document repeats the same accounts across
 * rows (518100 / 321100 / …).
 */
class AccountResolver
{
    private const LINKABLE_STATES = [10, 40, 70, 80];

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
             WHERE [number] = %s AND [docState] IN %in
             ORDER BY [docState] = 70, [id] LIMIT 1',
            $number,
            self::LINKABLE_STATES,
        );

        return $this->cache[$number] = ($row !== null ? (int) $row['id'] : null);
    }
}
