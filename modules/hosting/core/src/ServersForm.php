<?php

declare(strict_types=1);

namespace Shipard\Module\Hosting\Core;

use Shipard\Core\Form\FormDefinition;
use Shipard\Core\Form\TableForm;

class ServersForm extends TableForm
{
    public function buildFormDefinition(array $data, bool $isNew): FormDefinition
    {
        $basic = $this->tab('basic', 'Základní údaje')
            ->section()
                ->col()
                    ->input('name', required: true)
                    ->input('fqdn', required: true)
                    ->checkbox('can_provision')
                    ->checkbox('provision_default')
                    ->textarea('note')
            ->build();

        return new FormDefinition(
            table: $this->table,
            title: 'Server',
            titleNew: 'Nový server',
            tabs: [$basic, $this->attachmentsTab()],
        );
    }
}
