<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Accounting;

/**
 * Dohledání analytického účtu dle masky — sdílený mezi účtovacími engine
 * (doklady i bankovní transakce), aby princip „účet se nikde nezadává,
 * vzniká z masky předpisu" měl jediný zdroj pravdy.
 *
 * Maska → první aktivní analytický účet (account_level = 4) dle čísla
 * vzestupně (602 najde 602000 dřív než 602100), platný k účetnímu datu.
 * Archivní účet (70) je jen fallback — bere se, až když masce nevyhovuje
 * žádný aktivní účet (historické doklady na zrušené účty); smazaný (90)
 * se nedohledá nikdy. Konvence LINKABLE_STATES viz StatementImportService.
 * Per-instance cache; pro per-run izolaci stačí novou instanci na běh.
 */
final class AccountMaskResolver
{
    private const LINKABLE_STATES = [10, 40, 70, 80];

    /** @var array<string, array{id: int, number: string}|null> mask|date → resolved account */
    private array $cache = [];

    public function __construct(
        private readonly \Dibi\Connection $db,
    ) {}

    /**
     * @return array{id: int, number: string}|null
     */
    public function resolve(string $mask, string $accountingDate): ?array
    {
        $cacheKey = $mask . '|' . $accountingDate;
        if (array_key_exists($cacheKey, $this->cache)) {
            return $this->cache[$cacheKey];
        }

        $row = $this->db->fetch(
            'SELECT [id], [number] FROM [economy_accounting_accounts]
             WHERE [number] LIKE %like~ AND [account_level] = 4
               AND [docState] IN %in
               AND ([valid_from] IS NULL OR [valid_from] <= %s)
               AND ([valid_to] IS NULL OR [valid_to] >= %s)
             ORDER BY [docState] = 70, [number]
             LIMIT 1',
            $mask,
            self::LINKABLE_STATES,
            $accountingDate,
            $accountingDate,
        );

        $result = $row !== null
            ? ['id' => (int) $row['id'], 'number' => (string) $row['number']]
            : null;
        return $this->cache[$cacheKey] = $result;
    }
}
