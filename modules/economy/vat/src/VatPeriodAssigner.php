<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Vat;

/**
 * Pravidlo zařazení dokladu do instancí tvrzení (issue #55, D8/D13) —
 * čistá logika nad daty hlavičky a řádky recapu, instance dodává
 * ReportPeriodLookup.
 *
 * - `vat_period` (přiznání): instance typu `return` téže registrace, jejíž
 *   rozsah obsahuje DUZP (`vat_duzp`) — dnešní pravidlo výběru data.
 * - `cs_period` / `rs_period`: jen má-li doklad aspoň jeden řádek recapu,
 *   jehož mapování (`economy.vat.reports.cz`) má `kh` resp. `sh` ≠ null.
 *   Instance typu `cs`/`rs`, jejíž rozsah obsahuje **clamped efektivní
 *   datum** = `COALESCE(vat_dppd, vat_duzp)` oříznuté do rozsahu instance
 *   přiznání dokladu (invarianta: sjednocení měsíčních KH = čtvrtletní
 *   přiznání, beze zbytku a bez průniku). Jinak NULL.
 * - Bez DUZP nebo registrace → všechno NULL. Bez mapování (chybí
 *   kompilovaný config) → `cs_period`/`rs_period` NULL, přiznání se
 *   přiřadí.
 */
final class VatPeriodAssigner
{
    public const TYPE_RETURN = 'return';
    public const TYPE_CS = 'cs';
    public const TYPE_RS = 'rs';

    public function __construct(
        private readonly ReportPeriodLookup $periods,
        private readonly ?VatOutputsMapping $mapping,
    ) {}

    /**
     * @param array<string, mixed> $head klíče vat_registration, vat_duzp, vat_dppd
     * @param list<array<string, mixed>> $recap řádky recapu (klíč vat_code)
     * @return array{vat_period: ?int, cs_period: ?int, rs_period: ?int}
     */
    public function compute(array $head, array $recap): array
    {
        $empty = ['vat_period' => null, 'cs_period' => null, 'rs_period' => null];

        $regId = (int) ($head['vat_registration'] ?? 0);
        $duzp = self::isoDate($head['vat_duzp'] ?? null);
        if ($regId <= 0 || $duzp === null) {
            return $empty;
        }

        $return = $this->periods->covering($regId, self::TYPE_RETURN, $duzp);
        $out = $empty;
        $out['vat_period'] = $return['id'] ?? null;

        $membership = $this->membership($recap);
        if (!$membership['cs'] && !$membership['rs']) {
            return $out;
        }

        $effective = self::effectiveDate(self::isoDate($head['vat_dppd'] ?? null), $duzp, $return);
        if ($membership['cs']) {
            $out['cs_period'] = $this->periods->covering($regId, self::TYPE_CS, $effective)['id'] ?? null;
        }
        if ($membership['rs']) {
            $out['rs_period'] = $this->periods->covering($regId, self::TYPE_RS, $effective)['id'] ?? null;
        }
        return $out;
    }

    /**
     * Clamped efektivní datum pro KH/SH: DPPD (fallback DUZP) oříznuté do
     * rozsahu instance přiznání; bez instance přiznání bez oříznutí.
     *
     * @param ?array{date_begin: string, date_end: string} $returnInstance
     */
    public static function effectiveDate(?string $dppd, string $duzp, ?array $returnInstance): string
    {
        $date = $dppd ?? $duzp;
        if ($returnInstance === null) {
            return $date;
        }
        $begin = self::isoDate($returnInstance['date_begin']) ?? $date;
        $end   = self::isoDate($returnInstance['date_end']) ?? $date;
        if ($date < $begin) {
            return $begin;
        }
        if ($date > $end) {
            return $end;
        }
        return $date;
    }

    /**
     * Členství dokladu v KH/SH dle mapování kódů recapu. Kód bez záznamu
     * v mapování se tiše přeskočí — uložení dokladu nesmí spadnout kvůli
     * díře v konfiguraci výstupů (tu hlásí report i test úplnosti).
     *
     * @param list<array<string, mixed>> $recap
     * @return array{cs: bool, rs: bool}
     */
    public function membership(array $recap): array
    {
        $out = ['cs' => false, 'rs' => false];
        if ($this->mapping === null) {
            return $out;
        }
        foreach ($recap as $row) {
            $code = (string) ($row['vat_code'] ?? '');
            if ($code === '') {
                continue;
            }
            try {
                $entry = $this->mapping->forCode($code);
            } catch (\DomainException) {
                continue;
            }
            if (($entry['kh'] ?? null) !== null) {
                $out['cs'] = true;
            }
            if (($entry['sh'] ?? null) !== null) {
                $out['rs'] = true;
            }
            if ($out['cs'] && $out['rs']) {
                break;
            }
        }
        return $out;
    }

    public static function isoDate(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        $string = trim((string) ($value ?? ''));
        return $string !== '' ? substr($string, 0, 10) : null;
    }
}
