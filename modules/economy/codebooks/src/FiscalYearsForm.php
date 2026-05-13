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

        $basic = $this->tab('basic', $this->defaultGeneralTabLabel())
            ->section()
                ->col()
                    ->input('name', required: true)
                    ->input('doc_number_prefix', required: true)
                    ->input('currency', required: true, placeholder: 'czk')
                    ->checkbox('locked')
                    ->date('date_begin', required: true)
                    ->date('date_end', required: true)
            ->build();

        $months = $this->subtableTab(
            'months',
            'Měsíce',
            'economy_codebooks_fiscal_months',
            'fiscal_year',
            formId: 'economy.codebooks.fiscal_months',
            sort: 'date_begin:asc',
        );

        return new FormDefinition(
            table: $this->table,
            title: 'Fiskální rok',
            titleNew: 'Nový fiskální rok',
            tabs: [$basic, $months],
            fullSize: true,
        );
    }
}
