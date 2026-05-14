<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Resolve;

use Dibi\Connection;

/**
 * Maps a free-form unit token from canonical (`"h"`, `"ks"`, `"kWh"`) to
 * a `core_units.id`. Strategy:
 *
 *   1. Normalize the input (lowercase, strip whitespace).
 *   2. Apply the alias map ({@see ALIASES}) to translate canonical / local
 *      synonyms onto the Shipard `system_code` we ship in unitsSeed.
 *   3. Look that `system_code` up in `core_units` (active rows only).
 *   4. Fall back to a case-insensitive lookup on `core_units.shortcut`
 *      (matches user-defined units).
 *
 * No `canCreate` — units are a closed system codebook in Shipard, the
 * user adds them via the Settings viewer, not implicitly via apply.
 */
class UnitResolver
{
    /** Active doc states for codebook lookups (Koncept, V pořádku, V opravě). */
    private const ACTIVE_STATES = [10, 40, 80];

    /**
     * Canonical / locale unit token → Shipard `system_code`.
     *
     * Keys are lowercase; UnitResolver lowercases input before lookup.
     */
    private const ALIASES = [
        // ISO / canonical
        'h'    => 'hr',
        'pc'   => 'pcs',
        'piece' => 'pcs',
        'pcs'  => 'pcs',
        'kg'   => 'kg',
        'g'    => 'g',
        't'    => 't',
        'm'    => 'm',
        'km'   => 'km',
        'm2'   => 'm2',
        'm^2'  => 'm2',
        'sqm'  => 'm2',
        'm3'   => 'm3',
        'm^3'  => 'm3',
        'l'    => 'l',
        'ltr'  => 'l',
        'kwh'  => 'kwh',
        'mwh'  => 'mwh',
        'gj'   => 'gj',

        // Czech shortcuts
        'ks'   => 'pcs',
        'hod'  => 'hr',
        'den'  => 'day',
        'měs'  => 'mnth',
        'mes'  => 'mnth',
        'rok'  => 'year',

        // Time fractions
        '30min' => 'hr_2',
        '15min' => 'hr_4',
    ];

    public function __construct(
        private readonly Connection $db,
    ) {}

    public function resolve(?string $unit): ResolveResult
    {
        if ($unit === null) {
            return ResolveResult::notFound();
        }
        $token = trim($unit);
        if ($token === '') {
            return ResolveResult::notFound();
        }

        $lower = mb_strtolower($token);
        $systemCode = self::ALIASES[$lower] ?? null;

        // First try resolving via system_code (either via alias or directly).
        $probe = $systemCode ?? $lower;
        $row = $this->db->fetch(
            'SELECT [id] FROM [core_units]
             WHERE [system_code] = %s AND [docState] IN (%i, %i, %i)
             LIMIT 1',
            $probe,
            self::ACTIVE_STATES[0], self::ACTIVE_STATES[1], self::ACTIVE_STATES[2],
        );
        if ($row !== null) {
            return ResolveResult::matched(
                (int) $row['id'],
                $systemCode !== null ? 'alias' : 'systemCode',
            );
        }

        // Fall back to shortcut — case-insensitive, picks the first match.
        $row = $this->db->fetch(
            'SELECT [id] FROM [core_units]
             WHERE LOWER([shortcut]) = %s AND [docState] IN (%i, %i, %i)
             LIMIT 1',
            $lower,
            self::ACTIVE_STATES[0], self::ACTIVE_STATES[1], self::ACTIVE_STATES[2],
        );
        if ($row !== null) {
            return ResolveResult::matched((int) $row['id'], 'shortcut');
        }

        return ResolveResult::notFound();
    }
}
