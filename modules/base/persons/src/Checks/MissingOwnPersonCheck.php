<?php

declare(strict_types=1);

namespace Shipard\Module\Base\Persons\Checks;

use Shipard\Core\Alerts\AlertCheck;
use Shipard\Core\Alerts\AlertFinding;

/**
 * Detekuje, že v DS chybí "vlastní Osoba" — právní subjekt (firma / živnostník),
 * pod jehož hlavičkou DS funguje. Po vytvoření čerstvého DS žádná taková
 * osoba není a uživatel by si ji měl založit, jinak se účetní doklady neutáhnou.
 *
 * Detekce: počet aktivních (`docState IN (10, 40)` = Koncept / V pořádku)
 * osob s `is_own = TRUE`. Pokud je výsledek 0 → singleton alert.
 *
 * Spec: tasks/alerts-01.md §11.
 */
final class MissingOwnPersonCheck extends AlertCheck
{
    /** docState 10 = Koncept, 40 = V pořádku — to jsou "aktivní" osoby */
    private const ACTIVE_DOC_STATES = [10, 40];

    public function run(): array
    {
        $count = (int) $this->db->fetchSingle(
            'SELECT COUNT(*) FROM base_persons_persons'
            . ' WHERE is_own = %i AND docState IN %in',
            1,
            self::ACTIVE_DOC_STATES,
        );

        if ($count > 0) {
            return [];
        }

        $isCs = $this->language === 'cs';

        $title   = $isCs ? 'Chybí vlastní Osoba' : 'Own Person is missing';
        $message = $isCs
            ? 'Po vytvoření zdroje dat je třeba nastavit vlastní právní subjekt'
              . ' (firmu nebo živnostníka), pod jehož hlavičkou systém funguje.'
            : 'After creating a data source, you need to set up the own legal'
              . ' entity that the system runs on behalf of.';

        $actionLabel = $isCs ? 'Přidat vlastní Osobu' : 'Add own Person';

        return [
            new AlertFinding(
                findingKey: '',     // singleton check — buď je problém, nebo není
                title: $title,
                message: $message,
                severity: 'warning',
                actions: [
                    [
                        'id'      => 'create_own_person',
                        'label'   => $actionLabel,
                        'kind'    => 'open_form',
                        'target'  => [
                            'table'  => 'base_persons_persons',
                            'mode'   => 'create',
                            'preset' => ['is_own' => true],
                        ],
                        'primary' => true,
                    ],
                ],
            ),
        ];
    }
}
