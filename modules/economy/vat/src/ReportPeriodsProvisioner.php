<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Vat;

use Shipard\Core\Database\DataSourceConnection;

/**
 * Generátor instancí tvrzení (`economy_vat_report_periods`, issue #55 D9):
 *
 * - **seed / cron** (`ensureForRegistration`, `ensureAll`): pro aktivní
 *   registrace zajistí instance všech tří typů pokrývající dnešek a zítřek,
 *   stav V pořádku (40). Dopředu nic negeneruje — historii dodá import.
 * - **on-demand** (`covering` s `createMissing`): chybí-li při uložení
 *   dokladu instance pro datum, založí koncept (10); alert
 *   `economy.vat.draft_report_periods` ho pak nabídne ke kontrole.
 *
 * Kandidát = kalendářní jednotka dle periodicity registrace pro daný typ
 * (`tax_period_kind` / `cs_period_kind` / `rs_period_kind`; D10 — periodicity
 * jsou už jen defaulty generátoru), oříznutá do platnosti registrace
 * a o sousední existující instance, aby nikdy nevznikl překryv. Datum mimo
 * platnost registrace → žádná instance (null).
 */
final class ReportPeriodsProvisioner implements ReportPeriodLookup
{
    public const KIND_MONTHLY = 1;
    public const KIND_QUARTERLY = 2;

    public const TYPES = ['return', 'cs', 'rs'];

    private const KIND_COLUMN_BY_TYPE = [
        'return' => 'tax_period_kind',
        'cs'     => 'cs_period_kind',
        'rs'     => 'rs_period_kind',
    ];

    private const ACTIVE_REGISTRATION_STATES = [10, 40, 80];
    private const DOC_STATE_DELETED = 90;
    private const MAIN_STATE_BY_STATE = [10 => 1, 40 => 3];

    private bool $createMissing = false;
    private int $createdState = 10;

    /** @var list<int> id instancí založených touto instancí provisioneru */
    private array $created = [];

    /** @var array<int, ?array<string, mixed>> cache registrací */
    private array $registrations = [];

    public function __construct(private readonly DataSourceConnection $db) {}

    /** On-demand režim: chybějící instanci založit v daném stavu. */
    public function setCreateMissing(bool $create, int $docState = 10): void
    {
        $this->createMissing = $create;
        $this->createdState = $docState;
    }

    /** @return list<int> */
    public function createdInstances(): array
    {
        return $this->created;
    }

    public function covering(int $registrationId, string $type, string $date): ?array
    {
        $found = $this->find($registrationId, $type, $date);
        if ($found !== null || !$this->createMissing) {
            return $found;
        }
        return $this->create($registrationId, $type, $date, $this->createdState);
    }

    /** @return ?array{id: int, date_begin: string, date_end: string} */
    public function find(int $registrationId, string $type, string $date): ?array
    {
        $row = $this->db->fetchRow(
            'SELECT [id], [date_begin], [date_end] FROM [economy_vat_report_periods]'
            . ' WHERE [vat_registration] = %i AND [report_type] = %s AND [docState] != %i'
            . ' AND [date_begin] <= %d AND [date_end] >= %d'
            . ' ORDER BY [date_begin] DESC, [id] DESC LIMIT 1',
            $registrationId, $type, self::DOC_STATE_DELETED, $date, $date,
        );
        return $row !== null ? $this->shape($row) : null;
    }

    /**
     * Založí instanci pokrývající datum dle periodicity registrace. Null =
     * datum mimo platnost registrace nebo neznámá registrace.
     *
     * @return ?array{id: int, date_begin: string, date_end: string}
     */
    public function create(int $registrationId, string $type, string $date, int $docState): ?array
    {
        $registration = $this->registration($registrationId);
        if ($registration === null || !in_array($type, self::TYPES, true)) {
            return null;
        }
        $kind = (int) ($registration[self::KIND_COLUMN_BY_TYPE[$type]] ?? self::KIND_MONTHLY);
        $candidate = self::candidateRange($kind, $date);

        $validFrom = VatPeriodAssigner::isoDate($registration['valid_from'] ?? null);
        $validTo   = VatPeriodAssigner::isoDate($registration['valid_to'] ?? null);
        if (($validFrom !== null && $date < $validFrom) || ($validTo !== null && $date > $validTo)) {
            return null;
        }
        $candidate = self::clampRange($candidate, $validFrom, $validTo, $this->neighbourBounds($registrationId, $type, $candidate, $date));

        $id = $this->db->insertRow('economy_vat_report_periods', [
            'vat_registration' => $registrationId,
            'report_type'      => $type,
            'name'             => $candidate['name'],
            'date_begin'       => $candidate['begin'],
            'date_end'         => $candidate['end'],
            'locked'           => 0,
            'docState'         => $docState,
            'docStateMain'     => self::MAIN_STATE_BY_STATE[$docState] ?? 1,
        ]);
        $this->created[] = $id;
        return ['id' => $id, 'date_begin' => $candidate['begin'], 'date_end' => $candidate['end']];
    }

    /**
     * Seed / cron pro jednu registraci: instance všech typů pokrývající
     * dnešek a zítřek, stav V pořádku.
     *
     * @return array{created: int, existing: int}
     */
    public function ensureForRegistration(int $registrationId, ?\DateTimeImmutable $today = null): array
    {
        $today ??= new \DateTimeImmutable('today');
        $dates = [$today->format('Y-m-d'), $today->modify('+1 day')->format('Y-m-d')];

        $created = 0;
        $existing = 0;
        foreach (self::TYPES as $type) {
            foreach ($dates as $date) {
                if ($this->find($registrationId, $type, $date) !== null) {
                    $existing++;
                    continue;
                }
                if ($this->create($registrationId, $type, $date, 40) !== null) {
                    $created++;
                }
            }
        }
        return ['created' => $created, 'existing' => $existing];
    }

    /**
     * Cron / ds-upgrade: všechny aktivní registrace.
     *
     * @return array{created: int, existing: int}
     */
    public function ensureAll(?\DateTimeImmutable $today = null): array
    {
        $rows = $this->db->fetchAll(
            'SELECT [id] FROM [economy_codebooks_vat_registrations] WHERE [docState] IN %in ORDER BY [id]',
            self::ACTIVE_REGISTRATION_STATES,
        );
        $created = 0;
        $existing = 0;
        foreach ($rows as $row) {
            $result = $this->ensureForRegistration((int) $row['id'], $today);
            $created += $result['created'];
            $existing += $result['existing'];
        }
        return ['created' => $created, 'existing' => $existing];
    }

    // ── Čistá logika kandidáta ──────────────────────────────────────────────

    /**
     * Kalendářní jednotka obsahující datum dle periodicity.
     *
     * @return array{begin: string, end: string, name: string}
     */
    public static function candidateRange(int $kind, string $date): array
    {
        $d = new \DateTimeImmutable($date);
        $year = (int) $d->format('Y');
        $month = (int) $d->format('n');
        if ($kind === self::KIND_QUARTERLY) {
            $q = intdiv($month - 1, 3) + 1;
            $begin = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, ($q - 1) * 3 + 1));
            return [
                'begin' => $begin->format('Y-m-d'),
                'end'   => $begin->modify('+3 months -1 day')->format('Y-m-d'),
                'name'  => sprintf('Q%d/%04d', $q, $year),
            ];
        }
        $begin = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
        return [
            'begin' => $begin->format('Y-m-d'),
            'end'   => $begin->modify('+1 month -1 day')->format('Y-m-d'),
            'name'  => sprintf('%02d/%04d', $month, $year),
        ];
    }

    /**
     * Oříznutí kandidáta do platnosti registrace a o sousední instance
     * (`prevEnd` = konec nejbližší instance před datem uvnitř kandidáta,
     * `nextBegin` = začátek nejbližší za ním).
     *
     * @param array{begin: string, end: string, name: string} $candidate
     * @param array{prevEnd: ?string, nextBegin: ?string} $neighbours
     * @return array{begin: string, end: string, name: string}
     */
    public static function clampRange(array $candidate, ?string $validFrom, ?string $validTo, array $neighbours): array
    {
        $begin = $candidate['begin'];
        $end = $candidate['end'];
        if ($validFrom !== null && $validFrom > $begin) {
            $begin = $validFrom;
        }
        if ($validTo !== null && $validTo < $end) {
            $end = $validTo;
        }
        if ($neighbours['prevEnd'] !== null) {
            $after = (new \DateTimeImmutable($neighbours['prevEnd']))->modify('+1 day')->format('Y-m-d');
            if ($after > $begin) {
                $begin = $after;
            }
        }
        if ($neighbours['nextBegin'] !== null) {
            $before = (new \DateTimeImmutable($neighbours['nextBegin']))->modify('-1 day')->format('Y-m-d');
            if ($before < $end) {
                $end = $before;
            }
        }
        return ['begin' => $begin, 'end' => $end, 'name' => $candidate['name']];
    }

    // ── DB ──────────────────────────────────────────────────────────────────

    /**
     * @param array{begin: string, end: string} $candidate
     * @return array{prevEnd: ?string, nextBegin: ?string}
     */
    private function neighbourBounds(int $registrationId, string $type, array $candidate, string $date): array
    {
        $rows = $this->db->fetchAll(
            'SELECT [date_begin], [date_end] FROM [economy_vat_report_periods]'
            . ' WHERE [vat_registration] = %i AND [report_type] = %s AND [docState] != %i'
            . ' AND [date_begin] <= %d AND [date_end] >= %d',
            $registrationId, $type, self::DOC_STATE_DELETED, $candidate['end'], $candidate['begin'],
        );
        $prevEnd = null;
        $nextBegin = null;
        foreach ($rows as $row) {
            $b = VatPeriodAssigner::isoDate($row['date_begin']);
            $e = VatPeriodAssigner::isoDate($row['date_end']);
            if ($e !== null && $e < $date && ($prevEnd === null || $e > $prevEnd)) {
                $prevEnd = $e;
            }
            if ($b !== null && $b > $date && ($nextBegin === null || $b < $nextBegin)) {
                $nextBegin = $b;
            }
        }
        return ['prevEnd' => $prevEnd, 'nextBegin' => $nextBegin];
    }

    /** @return ?array<string, mixed> */
    private function registration(int $id): ?array
    {
        if (!array_key_exists($id, $this->registrations)) {
            $this->registrations[$id] = $this->db->fetchRow(
                'SELECT [id], [tax_period_kind], [cs_period_kind], [rs_period_kind], [valid_from], [valid_to]'
                . ' FROM [economy_codebooks_vat_registrations] WHERE [id] = %i AND [docState] != %i',
                $id, self::DOC_STATE_DELETED,
            );
        }
        return $this->registrations[$id];
    }

    /** @param array<string, mixed> $row @return array{id: int, date_begin: string, date_end: string} */
    private function shape(array $row): array
    {
        return [
            'id'         => (int) $row['id'],
            'date_begin' => (string) VatPeriodAssigner::isoDate($row['date_begin']),
            'date_end'   => (string) VatPeriodAssigner::isoDate($row['date_end']),
        ];
    }
}
