<?php

declare(strict_types=1);

namespace Shipard\Module\Hosting\Core;

use Shipard\Core\Form\FormDefinition;
use Shipard\Core\Form\TableForm;

class MailRoutersForm extends TableForm
{
    public function buildFormDefinition(array $data, bool $isNew): FormDefinition
    {
        $basic = $this->tab('basic', 'Základní údaje')
            ->section()
                ->col()
                    ->input('name', required: true)
                    ->input('domains', required: true, hint: 'Čárkami oddělené mail domény (např. shipard.email)')
                    ->textarea('note')
            ->build();

        return new FormDefinition(
            table: $this->table,
            title: 'Mail-router',
            titleNew: 'Nový mail-router',
            tabs: [$basic, $this->attachmentsTab()],
        );
    }
}
