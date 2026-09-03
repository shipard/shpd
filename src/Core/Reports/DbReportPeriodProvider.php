<?php

declare(strict_types=1);

namespace Shipard\Core\Reports;

use Shipard\Core\Database\DataSourceConnection;

final class DbReportPeriodProvider implements ReportPeriodProvider
{
    private const DOC_STATE_DELETED = 90;

    public function __construct(private readonly DataSourceConnection $db) {}

    public function findPeriod(int $id): ?array
    {
        $row = $this->db->fetchRow(
            'SELECT [p].[id], [p].[vat_registration], [p].[report_type], [p].[name], [p].[date_begin],'
            . ' [p].[date_end], [p].[locked], [p].[docState], [r].[name] AS [registration_name]'
            . ' FROM [economy_vat_report_periods] [p]'
            . ' LEFT JOIN [economy_codebooks_vat_registrations] [r] ON [r].[id] = [p].[vat_registration]'
            . ' WHERE [p].[id] = %i AND [p].[docState] != %i LIMIT 1',
            $id, self::DOC_STATE_DELETED,
        );
        if ($row === null) {
            return null;
        }
        return $this->periodShape($row) + [
            'registrationId'   => (int) $row['vat_registration'],
            'registrationName' => (string) ($row['registration_name'] ?? ''),
        ];
    }

    public function registrationsWithPeriods(): array
    {
        $registrations = $this->db->fetchAll(
            'SELECT [id], [name], [vat_id]'
            . ' FROM [economy_codebooks_vat_registrations]'
            . ' WHERE [docState] != %i ORDER BY [name], [id]',
            self::DOC_STATE_DELETED,
        );
        if ($registrations === []) {
            return [];
        }

        // Instance všech registrací jedním dotazem, seskupení v PHP (žádné N+1).
        $periodRows = $this->db->fetchAll(
            'SELECT [id], [vat_registration], [report_type], [name], [date_begin], [date_end], [locked], [docState]'
            . ' FROM [economy_vat_report_periods]'
            . ' WHERE [docState] != %i ORDER BY [vat_registration], [date_begin], [report_type], [id]',
            self::DOC_STATE_DELETED,
        );
        $periodsByRegistration = [];
        foreach ($periodRows as $row) {
            $periodsByRegistration[(int) $row['vat_registration']][] = $this->periodShape($row);
        }

        $out = [];
        foreach ($registrations as $row) {
            $id = (int) $row['id'];
            $out[] = [
                'id'      => $id,
                'name'    => (string) $row['name'],
                'vatId'   => $row['vat_id'] !== null ? (string) $row['vat_id'] : null,
                'periods' => $periodsByRegistration[$id] ?? [],
            ];
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: int, type: string, name: string, dateBegin: string, dateEnd: string, locked: bool, docState: int}
     */
    private function periodShape(array $row): array
    {
        return [
            'id'        => (int) $row['id'],
            'type'      => (string) $row['report_type'],
            'name'      => (string) $row['name'],
            'dateBegin' => $this->isoDate($row['date_begin']),
            'dateEnd'   => $this->isoDate($row['date_end']),
            'locked'    => (bool) $row['locked'],
            'docState'  => (int) $row['docState'],
        ];
    }

    /** Dibi vrací date sloupce jako DateTime — normalizace na ISO string. */
    private function isoDate(mixed $value): string
    {
        return $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : (string) $value;
    }
}
