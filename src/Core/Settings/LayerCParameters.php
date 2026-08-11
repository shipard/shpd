<?php

declare(strict_types=1);

namespace Shipard\Core\Settings;

/**
 * Parametry vrstvy C (docs/ds-setup.md §5.2, D2) — jediné místo pravdy.
 *
 * Sdílí ho `[TODO]` výpis nerozhodnutých parametrů v ds-upgrade, whitelist
 * a validace hodnot v `ds-setting` a (Fáze 3) setup checky. Navazující
 * tasky sem přidávají další klíče (`economy.vatPayer`,
 * `economy.homeCurrency`) — nikam jinam.
 *
 * Absence klíče v core_system_settings = nerozhodnuto; provisionery pak
 * neseedují (D6). Žádný fallback na main.json (D9).
 */
final class LayerCParameters
{
    /**
     * klíč => [
     *   module:  modul, který parametr čte — [TODO] výpis se gate-uje na
     *            jeho aktivitu (DS bez ekonomiky nemá hlásit osnovu),
     *   example: ukázková hodnota do [TODO] výpisu,
     * ]
     */
    public const SPECS = [
        'economy.accountChart' => [
            'module'  => 'economy.accounting',
            'example' => 'default',
        ],
        'economy.fiscalYearStartMonth' => [
            'module'  => 'economy.codebooks',
            'example' => '1',
        ],
    ];

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::SPECS);
    }

    /**
     * Zvaliduje syrovou CLI hodnotu parametru vrstvy C a vrátí hodnotu
     * k uložení (správného typu). Neznámé klíče sem nepatří — volající
     * je má odfiltrovat whitelistem.
     *
     * @throws \InvalidArgumentException s výčtem povolených hodnot
     */
    public static function validate(string $key, string $raw): string|int
    {
        switch ($key) {
            case 'economy.accountChart':
                $allowed = ['default', 'npo', 'none'];
                if (!in_array($raw, $allowed, true)) {
                    throw new \InvalidArgumentException(
                        "Invalid value '{$raw}' for {$key}. Allowed: " . implode(', ', $allowed),
                    );
                }
                return $raw;

            case 'economy.fiscalYearStartMonth':
                if (!ctype_digit($raw) || (int) $raw < 1 || (int) $raw > 12) {
                    throw new \InvalidArgumentException(
                        "Invalid value '{$raw}' for {$key}. Allowed: integer 1-12 (1 = January)",
                    );
                }
                return (int) $raw;
        }

        throw new \InvalidArgumentException("Unknown layer C parameter: {$key}");
    }
}
