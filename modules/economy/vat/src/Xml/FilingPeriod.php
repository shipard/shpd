<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Vat\Xml;

/**
 * Zdaňovací období podání pro větu D (issue #55, X3): rok a měsíc nebo
 * čtvrtletí, případně `zdobd_od` / `zdobd_do` u částečného období.
 *
 * Odvozuje se **z rozsahu instance tvrzení**, ne z periodicity registrace:
 * instance je zdroj pravdy o tom, za co se podává, a rozsah přežije i změnu
 * periodicity uprostřed roku. Rozsah, který přesně pokrývá kalendářní měsíc
 * (čtvrtletí), je celé období; cokoli užšího je částečné a doplní se
 * `zdobd_*` (§ vznik a zánik plátcovství uprostřed období).
 *
 * Rozsah přes víc měsíců i čtvrtletí zůstane bez měsíce a čtvrtletí —
 * takové podání validace odmítne, protože bez nich ho úřad nezpracuje.
 */
final class FilingPeriod
{
    private function __construct(
        public readonly int $year,
        public readonly ?int $month,
        public readonly ?int $quarter,
        /** Jen u částečného období; jinak `null`. */
        public readonly ?string $from,
        public readonly ?string $to,
    ) {}

    public static function fromRange(string $begin, string $end): self
    {
        $from = self::date($begin);
        $to   = self::date($end);

        $year = (int) $from->format('Y');
        if ((int) $to->format('Y') !== $year) {
            return new self($year, null, null, $begin, $end);
        }

        $month   = (int) $from->format('n');
        $quarter = intdiv($month - 1, 3) + 1;

        if ($month === (int) $to->format('n')) {
            $full = self::isMonthStart($from) && self::isMonthEnd($to);
            return new self($year, $month, null, $full ? null : $begin, $full ? null : $end);
        }
        if ($quarter === intdiv((int) $to->format('n') - 1, 3) + 1) {
            $full = self::isQuarterStart($from) && self::isQuarterEnd($to);
            return new self($year, null, $quarter, $full ? null : $begin, $full ? null : $end);
        }

        return new self($year, null, null, $begin, $end);
    }

    /** Období, které úřad umí zpracovat, musí mít měsíc nebo čtvrtletí. */
    public function isComplete(): bool
    {
        return $this->month !== null || $this->quarter !== null;
    }

    private static function date(string $value): \DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', substr($value, 0, 10));
        if ($date === false) {
            throw new \InvalidArgumentException("Neplatné datum období '{$value}'");
        }
        return $date;
    }

    private static function isMonthStart(\DateTimeImmutable $date): bool
    {
        return $date->format('j') === '1';
    }

    private static function isMonthEnd(\DateTimeImmutable $date): bool
    {
        return $date->format('j') === $date->format('t');
    }

    private static function isQuarterStart(\DateTimeImmutable $date): bool
    {
        return self::isMonthStart($date) && in_array((int) $date->format('n'), [1, 4, 7, 10], true);
    }

    private static function isQuarterEnd(\DateTimeImmutable $date): bool
    {
        return self::isMonthEnd($date) && in_array((int) $date->format('n'), [3, 6, 9, 12], true);
    }
}
