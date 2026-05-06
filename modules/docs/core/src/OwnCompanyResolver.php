<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\Core;

use Shipard\Core\Database\DataSourceConnection;

/**
 * Helper for looking up the "own company" — the base_persons_persons row
 * with is_own = 1. Used by document snapshot building (Phase 2) and
 * validation checks at Confirm transition.
 */
final class OwnCompanyResolver
{
    /** Address type 1 = "Sídlo" / Registered office (see base.persons addressTypes). */
    private const ADDRESS_TYPE_HEADQUARTERS = 1;

    public function __construct(
        private readonly DataSourceConnection $db,
    ) {}

    /**
     * @return int|null  ID of the own person, or null if none configured
     */
    public function getOwnPersonId(): ?int
    {
        $row = $this->db->fetchRow(
            'SELECT id FROM base_persons_persons
             WHERE is_own = 1 AND docState IN (%i, %i, %i)
             LIMIT 1',
            10, 40, 80,
        );
        return $row !== null ? (int) $row['id'] : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getOwnPersonData(): ?array
    {
        $id = $this->getOwnPersonId();
        if ($id === null) {
            return null;
        }
        return $this->db->fetchRow(
            'SELECT * FROM base_persons_persons WHERE id = %i',
            $id,
        );
    }

    /**
     * Find the headquarters address (address_type = 1) of the own person.
     *
     * @return array<string, mixed>|null
     */
    public function getOwnHeadquartersAddress(): ?array
    {
        $personId = $this->getOwnPersonId();
        if ($personId === null) {
            return null;
        }
        return $this->db->fetchRow(
            'SELECT * FROM base_persons_addresses
             WHERE person = %i AND address_type = %i
             LIMIT 1',
            $personId,
            self::ADDRESS_TYPE_HEADQUARTERS,
        );
    }
}
