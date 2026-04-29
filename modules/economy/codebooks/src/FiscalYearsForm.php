<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Codebooks;

use Shipard\Core\Form\FormDefinition;
use Shipard\Core\Form\TableForm;

class FiscalYearsForm extends TableForm
{
    public function buildFormDefinition(array $data, bool $isNew): FormDefinition
    {
        if ($isNew && empty($data['currency'])) {
            $data['currency'] = 'czk';
        }

        $basic = $this->tab('basic', 'Obecné')
            ->addInput('name', cols: 1, required: true)
            ->addInput('doc_number_prefix', cols: 1, required: true)
            ->addInput('currency', cols: 1, required: true, placeholder: 'czk')
            ->addCheckbox('locked', cols: 1)
            ->addDate('date_begin', cols: 1, required: true)
            ->addDate('date_end', cols: 1, required: true)
            ->build();

        $months = $this->tab('months', 'Měsíce')
            ->addSubtable(
                'economy_codebooks_fiscal_months',
                'fiscal_year',
                formId: 'economy.codebooks.fiscal_months',
                sort: 'date_begin:asc',
            )
            ->build();

        return new FormDefinition(
            table: $this->table,
            title: 'Fiskální rok',
            titleNew: 'Nový fiskální rok',
            tabs: [$basic, $months],
            fullSize: true,
        );
    }
}
