<?php

declare(strict_types=1);

namespace Shipard\Module\Hosting\Core;

use Shipard\Core\Form\FormDefinition;
use Shipard\Core\Form\TableForm;

class AiAnalyzersForm extends TableForm
{
    public function buildFormDefinition(array $data, bool $isNew): FormDefinition
    {
        $basic = $this->tab('basic', 'Základní údaje')
            ->section()
                ->col()
                    ->input('name', required: true)
                    ->textarea('note')
            ->build();

        return new FormDefinition(
            table: $this->table,
            title: 'AI analyzer',
            titleNew: 'Nový AI analyzer',
            tabs: [$basic, $this->attachmentsTab()],
        );
    }
}
