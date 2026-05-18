<?php

declare(strict_types=1);

namespace Shipard\Core\Alerts;

/**
 * Parsuje zjednodušený duration formát používaný v `alertChecks[].interval`
 * v `module.jsonc`.
 *
 * Akceptované suffixy: `s` sekundy, `m` minuty, `h` hodiny, `d` dny.
 * Číselná část musí být kladné celé číslo. Žádné desetinné, žádné znaménko,
 * žádné kombinace ("1h30m" není podporováno — buď stačí jeden suffix, nebo
 * je třeba vyjádřit hodnotu v menších jednotkách).
 */
final class IntervalParser
{
    /**
     * @return int Délka v sekundách (vždy > 0).
     * @throws \InvalidArgumentException Pokud řetězec nepasuje na formát.
     */
    public static function parse(string $duration): int
    {
        if (!preg_match('/^(\d+)([smhd])$/', $duration, $m)) {
            throw new \InvalidArgumentException(
                "Invalid interval format: '{$duration}'. Expected <number><s|m|h|d>, e.g. '1h', '30m', '7d'.",
            );
        }

        $n = (int) $m[1];
        if ($n <= 0) {
            throw new \InvalidArgumentException(
                "Interval must be positive: '{$duration}'.",
            );
        }

        return match ($m[2]) {
            's' => $n,
            'm' => $n * 60,
            'h' => $n * 3600,
            'd' => $n * 86400,
        };
    }
}
