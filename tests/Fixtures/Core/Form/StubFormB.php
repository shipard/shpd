<?php

declare(strict_types=1);

namespace Shipard\Tests\Fixtures\Core\Form;

use Shipard\Core\Form\FormDefinition;
use Shipard\Core\Form\TableForm;

class StubFormB extends TableForm
{
    public function buildFormDefinition(array $data, bool $isNew): FormDefinition
    {
        return new FormDefinition(
            table: $this->table,
            title: 'B',
            titleNew: 'New B',
            tabs: [],
        );
    }
}
