<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Vat;

use Shipard\Core\Form\FormDefinition;
use Shipard\Core\Form\TableForm;

/**
 * Editační formulář koeficientu odpočtu DPH (#59 D13). Registrace jako
 * select (registrací je pár, jen stav V pořádku), koeficienty jako desetinné
 * číslo 0,00–1,00 (0,80 = 80 %) — hodnota ve formuláři = hodnota v DB.
 */
class DeductionCoefficientsForm extends TableForm
{
    public function buildFormDefinition(array $data, bool $isNew): FormDefinition
    {
        $registrationOptions = $this->resolveRegistrationOptions();
        if ($isNew) {
            if (empty($data['vat_registration']) && count($registrationOptions) === 1) {
                $data['vat_registration'] = $registrationOptions[0]['value'];
            }
            if (empty($data['year'])) {
                $data['year'] = (int) date('Y');
            }
        }

        $basic = $this->tab('basic', $this->defaultGeneralTabLabel())
            ->section()
                ->col()
                    ->select('vat_registration', options: $registrationOptions, required: true)
                    ->number('year', required: true, hint: 'Kalendářní rok — vypořádací období je vždy kalendářní rok (§ 76 ZDPH).')
                    ->separator('Koeficient')
                    ->number('coefficient_provisional', hint: 'Desetinné číslo 0,00–1,00 na celá procenta (0,80 = 80 %). Prázdné = platí vypořádací koeficient minulého roku, jinak 1,00.')
                    ->number('coefficient_settled', hint: 'Vypořádací koeficient roku po ročním vypořádání; je implicitním zálohovým koeficientem příštího roku. Prázdné = nevypořádáno.')
                    ->separator('Ostatní')
                    ->input('note', hint: 'Odkud hodnota je — rozhodnutí správce daně, výpočet, odhad.')
            ->build();

        return new FormDefinition(
            table: $this->table,
            title: 'Koeficient odpočtu DPH',
            titleNew: 'Nový koeficient odpočtu DPH',
            tabs: [$basic],
        );
    }

    /** @return list<array{value: int, label: string}> Registrace ve stavu V pořádku. */
    private function resolveRegistrationOptions(): array
    {
        if ($this->db === null) {
            return [];
        }
        $rows = $this->db->fetchAll(
            'SELECT `id`, `name`, `vat_id` FROM `economy_codebooks_vat_registrations`'
            . ' WHERE `docState` = 40 ORDER BY `name`, `id`',
        );
        $options = [];
        foreach ($rows as $row) {
            $label = (string) $row['name'];
            if (!empty($row['vat_id'])) {
                $label .= ' (' . $row['vat_id'] . ')';
            }
            $options[] = ['value' => (int) $row['id'], 'label' => $label];
        }
        return $options;
    }
}
