<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Resolve;

use Dibi\Connection;

/**
 * Resolves a canonical Address object to a `base_persons_addresses.id`.
 *
 * Per docs/exchange-format-persons.md §8.2, the match-key priority is:
 *
 *   1. `placeRegId` filled → `(person, place_reg_type, place_reg_id)` exact
 *      → matched with `authoritativeRefresh = true`. Provozovna (IČP) and
 *      Zařízení (IČZ) addresses come from registries — the registry is
 *      the source of truth, so the applier overwrites fields even under
 *      `mergeStrategy = mergeAdd`.
 *
 *   2. `registryCode` filled → `(person, address_type, registry_code)` exact
 *      → matched with `authoritativeRefresh = false`. Sídla / doručovací
 *      addresses standardized via RÚIAN ADM.
 *
 *   3. `isStandardized = false` → `(person, address_type, displayLine)` exact
 *      → matched with `authoritativeRefresh = false`. Foreign / parcel
 *      addresses fall back to whole-line equality.
 *
 *   4. No match → `canCreate` with a payload ready for insert into
 *      `base_persons_addresses`.
 *
 * When called with `personId = null` (header is canCreate — there is no
 * person row yet to attach addresses to), the resolver short-circuits to
 * `canCreate` without DB probes.
 *
 * Address-table probes filter `docState IN (10, 40, 80)` — archived (70)
 * and deleted (90) addresses are not matched. `world_divisions` is
 * reference data without docState (see {@see lookupDivisionId()}).
 *
 * `divisionCode → world_divisions.id` lookup lives in
 * {@see lookupDivisionId()} — a public method PersonResolver also uses
 * to emit a `division_unknown` warning when the code is supplied but
 * unmapped. Phase 1 does one round-trip per address; batch lookup is a
 * Phase 3 follow-up.
 */
class AddressResolver
{
    private const ACTIVE_STATES = [10, 40, 80];

    public function __construct(
        private readonly Connection $db,
    ) {}

    /**
     * @param array<string, mixed> $address
     */
    public function resolve(array $address, ?int $personId): ResolveResult
    {
        // Header is canCreate — no DB row to match against yet.
        if ($personId === null) {
            return ResolveResult::canCreate($this->buildCreatePayload($address, null));
        }

        $placeRegType = $this->normalize($address['placeRegType'] ?? null);
        $placeRegId = $this->normalize($address['placeRegId'] ?? null);
        if ($placeRegType !== null && $placeRegId !== null) {
            $row = $this->fetchByPlaceReg($personId, $placeRegType, $placeRegId);
            if ($row !== null) {
                return ResolveResult::matched(
                    (int) $row['id'],
                    'placeRegId',
                    authoritativeRefresh: true,
                );
            }
        }

        $registryCode = $this->normalize($address['registryCode'] ?? null);
        $addressType = $this->intOrNull($address['addressType'] ?? null);
        if ($registryCode !== null && $addressType !== null) {
            $row = $this->fetchByRegistryCode($personId, $addressType, $registryCode);
            if ($row !== null) {
                return ResolveResult::matched((int) $row['id'], 'registryCode');
            }
        }

        $isStandardized = ($address['isStandardized'] ?? null) === true;
        $displayLine = $this->normalize($address['displayLine'] ?? null);
        if (!$isStandardized && $addressType !== null && $displayLine !== null) {
            $row = $this->fetchByDisplayLine($personId, $addressType, $displayLine);
            if ($row !== null) {
                return ResolveResult::matched((int) $row['id'], 'displayLine');
            }
        }

        return ResolveResult::canCreate($this->buildCreatePayload($address, $personId));
    }

    /**
     * Map a canonical `divisionCode` (ZÚJ kód / RÚIAN obec code) to a
     * `world_divisions.id`. Returns null when the code is empty or
     * unknown. Caller emits a warning issue on the latter.
     *
     * `world_divisions` is reference data without docState — validity
     * is expressed via `valid_from`/`valid_to`. For now we accept any
     * matching code; historic-validity filtering is a follow-up if/when
     * the registry actually emits codes for retired divisions.
     */
    public function lookupDivisionId(?string $code): ?int
    {
        $code = $this->normalize($code);
        if ($code === null) {
            return null;
        }
        $row = $this->db->fetch(
            'SELECT [id] FROM [world_divisions] WHERE [code] = %s LIMIT 1',
            $code,
        );
        return $row !== null ? (int) $row['id'] : null;
    }

    private function fetchByPlaceReg(int $personId, string $placeRegType, string $placeRegId): ?array
    {
        $row = $this->db->fetch(
            'SELECT [id] FROM [base_persons_addresses]
             WHERE [person] = %i AND [place_reg_type] = %s AND [place_reg_id] = %s
               AND [docState] IN (%i, %i, %i)
             LIMIT 1',
            $personId, $placeRegType, $placeRegId,
            self::ACTIVE_STATES[0], self::ACTIVE_STATES[1], self::ACTIVE_STATES[2],
        );
        return $row !== null ? $row->toArray() : null;
    }

    private function fetchByRegistryCode(int $personId, int $addressType, string $registryCode): ?array
    {
        $row = $this->db->fetch(
            'SELECT [id] FROM [base_persons_addresses]
             WHERE [person] = %i AND [address_type] = %i AND [registry_code] = %s
               AND [docState] IN (%i, %i, %i)
             LIMIT 1',
            $personId, $addressType, $registryCode,
            self::ACTIVE_STATES[0], self::ACTIVE_STATES[1], self::ACTIVE_STATES[2],
        );
        return $row !== null ? $row->toArray() : null;
    }

    private function fetchByDisplayLine(int $personId, int $addressType, string $displayLine): ?array
    {
        $row = $this->db->fetch(
            'SELECT [id] FROM [base_persons_addresses]
             WHERE [person] = %i AND [address_type] = %i AND [display_line] = %s
               AND [docState] IN (%i, %i, %i)
             LIMIT 1',
            $personId, $addressType, $displayLine,
            self::ACTIVE_STATES[0], self::ACTIVE_STATES[1], self::ACTIVE_STATES[2],
        );
        return $row !== null ? $row->toArray() : null;
    }

    /**
     * Build the row payload for `base_persons_addresses` insert. `person` is
     * filled when known; for header-create the applier patches it in after
     * the person row gets its id. `division` is resolved here so the
     * payload is fully wired — PersonResolver emits a warning when the
     * supplied `divisionCode` does not map to any row.
     *
     * @param array<string, mixed> $address
     * @return array<string, mixed>
     */
    private function buildCreatePayload(array $address, ?int $personId): array
    {
        $country = $address['country'] ?? null;
        if (is_string($country)) {
            $country = strtolower(trim($country));
            if ($country === '') $country = null;
        }

        return [
            'person'             => $personId,
            'address_type'       => $this->intOrNull($address['addressType'] ?? null),
            'name'               => $this->normalize($address['name'] ?? null),
            'place_reg_type'     => $this->normalize($address['placeRegType'] ?? null),
            'place_reg_id'       => $this->normalize($address['placeRegId'] ?? null),
            'is_standardized'    => ($address['isStandardized'] ?? null) === true ? 1 : 0,
            'street'             => $this->normalize($address['street'] ?? null),
            'house_number'       => $this->normalize($address['houseNumber'] ?? null),
            'orientation_number' => $this->normalize($address['orientationNumber'] ?? null),
            'city'               => $this->normalize($address['city'] ?? null),
            'city_part'          => $this->normalize($address['cityPart'] ?? null),
            'district'           => $this->normalize($address['district'] ?? null),
            'zip'                => $this->normalize($address['zip'] ?? null),
            'country'            => $country,
            'registry_code'      => $this->normalize($address['registryCode'] ?? null),
            'division'           => $this->lookupDivisionId(
                is_string($address['divisionCode'] ?? null) ? $address['divisionCode'] : null,
            ),
            'latitude'           => $this->floatOrNull($address['latitude'] ?? null),
            'longitude'          => $this->floatOrNull($address['longitude'] ?? null),
            'manual_gps'         => ($address['manualGps'] ?? null) === true ? 1 : 0,
            'display_line'       => $this->normalize($address['displayLine'] ?? null),
            'display_block'      => $this->normalize($address['displayBlock'] ?? null),
            'order_pos'          => $this->intOrNull($address['orderPos'] ?? null) ?? 0,
            'valid_from'         => $this->normalize($address['validFrom'] ?? null),
            'valid_to'           => $this->normalize($address['validTo'] ?? null),
            'note'               => $this->normalize($address['note'] ?? null),
        ];
    }

    private function normalize(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $trimmed = trim($value);
        return $trimmed === '' ? null : $trimmed;
    }

    private function intOrNull(mixed $value): ?int
    {
        if (is_int($value)) return $value;
        if (is_string($value) && ctype_digit($value)) return (int) $value;
        return null;
    }

    private function floatOrNull(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) return (float) $value;
        if (is_string($value) && is_numeric($value)) return (float) $value;
        return null;
    }
}
