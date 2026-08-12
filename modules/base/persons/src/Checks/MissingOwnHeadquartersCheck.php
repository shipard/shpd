<?php

declare(strict_types=1);

namespace Shipard\Module\Base\Persons\Checks;

use Shipard\Core\Alerts\AlertCheck;
use Shipard\Core\Alerts\AlertFinding;

/**
 * Detekuje vlastní Osobu bez adresy sídla (`address_type = 1`). Bez sídla
 * vyjde snapshot vlastní firmy na dokladu neúplný a doklady se tisknou
 * s prázdnou adresou.
 *
 * Mlčí, když žádná aktivní vlastní Osoba neexistuje — tehdy svítí
 * `base.persons.missing_own_person` a dvě položky o tomtéž jsou šum
 * (ds-setup.md §5.3, podmíněnost).
 *
 * Spec: tasks/ds-setup-05-setup-checks.md.
 */
final class MissingOwnHeadquartersCheck extends AlertCheck
{
    /** docState 10 = Koncept, 40 = V pořádku — to jsou "aktivní" záznamy */
    private const ACTIVE_DOC_STATES = [10, 40];

    /** address_type 1 = Sídlo (cfgItem base.persons.addressTypes) */
    private const ADDRESS_TYPE_HEADQUARTERS = 1;

    public function run(): array
    {
        $ownPersonIds = array_map(
            static fn(array $row): int => (int) $row['id'],
            $this->db->fetchAll(
                'SELECT id FROM base_persons_persons'
                    . ' WHERE is_own = %i AND docState IN %in',
                1,
                self::ACTIVE_DOC_STATES,
            ),
        );

        if ($ownPersonIds === []) {
            return [];
        }

        $count = (int) $this->db->fetchSingle(
            'SELECT COUNT(*) FROM base_persons_addresses'
                . ' WHERE person IN %in AND address_type = %i AND docState IN %in',
            $ownPersonIds,
            self::ADDRESS_TYPE_HEADQUARTERS,
            self::ACTIVE_DOC_STATES,
        );

        if ($count > 0) {
            return [];
        }

        $isCs = $this->language === 'cs';

        $title   = $isCs ? 'Vlastní Osoba nemá adresu sídla' : 'Own Person has no registered office address';
        $message = $isCs
            ? 'Doplň vlastní Osobě adresu typu Sídlo — tiskne se na doklady'
            . ' a bez ní vyjde hlavička dokladu neúplná.'
            : 'Add a Registered office address to the own Person — it is printed'
            . ' on documents and the document header is incomplete without it.';

        $actionLabel = $isCs ? 'Doplnit sídlo' : 'Add registered office';

        return [
            new AlertFinding(
                findingKey: '',     // singleton check — buď je problém, nebo není
                title: $title,
                message: $message,
                severity: 'warning',
                actions: [
                    [
                        'id'      => 'edit_own_person',
                        'label'   => $actionLabel,
                        'kind'    => 'open_form',
                        'target'  => [
                            'table' => 'base_persons_persons',
                            'mode'  => 'edit',
                            // PersonDocument připouští právě jednu vlastní
                            // Osobu; kdyby jich (přechodně) bylo víc, otevře
                            // se první.
                            'id'    => $ownPersonIds[0],
                        ],
                        'primary' => true,
                    ],
                ],
            ),
        ];
    }
}
