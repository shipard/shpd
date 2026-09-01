<?php

declare(strict_types=1);

namespace Shipard\Core\Reports;

use Shipard\Core\Database\DataSourceConnection;

final class DbVatPeriodProvider implements VatPeriodProvider
{
    private const DOC_STATE_DELETED = 90;

    public function __construct(private readonly DataSourceConnection $db) {}

    public function findRegistration(int $id): ?array
    {
        $row = $this->db->fetchRow(
            'SELECT [id], [name] FROM [economy_codebooks_vat_registrations]'
            . ' WHERE [id] = %i AND [docState] != %i LIMIT 1',
            $id, self::DOC_STATE_DELETED,
        );
        return $row !== null ? ['id' => (int) $row['id'], 'name' => (string) $row['name']] : null;
    }

    public function periodsOfRegistration(int $registrationId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT [id], [name], [date_begin], [date_end], [locked]'
            . ' FROM [economy_codebooks_vat_periods]'
            . ' WHERE [vat_registration] = %i AND [docState] != %i'
            . ' ORDER BY [date_begin], [id]',
            $registrationId, self::DOC_STATE_DELETED,
        );
        $out = [];
        foreach ($rows as $row) {
            $out[] = $this->periodShape($row);
        }
        return $out;
    }

    public function registrationsWithPeriods(): array
    {
        $registrations = $this->db->fetchAll(
            'SELECT [id], [name], [vat_id], [tax_period_kind], [report_period_kind]'
            . ' FROM [economy_codebooks_vat_registrations]'
            . ' WHERE [docState] != %i ORDER BY [name], [id]',
            self::DOC_STATE_DELETED,
        );
        if ($registrations === []) {
            return [];
        }

        // Období všech registrací jedním dotazem, seskupení v PHP (žádné N+1).
        $periodRows = $this->db->fetchAll(
            'SELECT [id], [vat_registration], [name], [date_begin], [date_end], [locked]'
            . ' FROM [economy_codebooks_vat_periods]'
            . ' WHERE [docState] != %i ORDER BY [vat_registration], [date_begin], [id]',
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
                'id'               => $id,
                'name'             => (string) $row['name'],
                'vatId'            => $row['vat_id'] !== null ? (string) $row['vat_id'] : null,
                'taxPeriodKind'    => (int) $row['tax_period_kind'],
                'reportPeriodKind' => (int) $row['report_period_kind'],
                'periods'          => $periodsByRegistration[$id] ?? [],
            ];
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: int, name: string, dateBegin: string, dateEnd: string, locked: bool}
     */
    private function periodShape(array $row): array
    {
        return [
            'id'        => (int) $row['id'],
            'name'      => (string) $row['name'],
            'dateBegin' => $this->isoDate($row['date_begin']),
            'dateEnd'   => $this->isoDate($row['date_end']),
            'locked'    => (bool) $row['locked'],
        ];
    }

    /** Dibi vrací date sloupce jako DateTime — normalizace na ISO string. */
    private function isoDate(mixed $value): string
    {
        return $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : (string) $value;
    }
}
