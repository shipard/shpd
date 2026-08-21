<?php

declare(strict_types=1);

namespace Shipard\Core\Reports;

use Shipard\Core\Database\DataSourceConnection;

final class DbFiscalPeriodProvider implements FiscalPeriodProvider
{
    private const DOC_STATE_DELETED = 90;

    public function __construct(private readonly DataSourceConnection $db) {}

    public function findYearByName(string $name): ?array
    {
        $row = $this->db->fetchRow(
            'SELECT [id], [name] FROM [economy_codebooks_fiscal_years]'
            . ' WHERE [name] = %s AND [docState] != %i LIMIT 1',
            $name, self::DOC_STATE_DELETED,
        );
        return $row !== null ? ['id' => (int) $row['id'], 'name' => (string) $row['name']] : null;
    }

    public function monthsOfYear(int $fiscalYearId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT [id], [period_type] FROM [economy_codebooks_fiscal_months]'
            . ' WHERE [fiscal_year] = %i ORDER BY [date_begin], [id]',
            $fiscalYearId,
        );
        $out = [];
        foreach ($rows as $row) {
            $out[] = ['id' => (int) $row['id'], 'periodType' => (int) $row['period_type']];
        }
        return $out;
    }
}
