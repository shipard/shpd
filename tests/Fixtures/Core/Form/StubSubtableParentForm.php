<?php

declare(strict_types=1);

namespace Shipard\Tests\Fixtures\Core\Form;

use Shipard\Core\Form\FormDefinition;
use Shipard\Core\Form\TableForm;

/**
 * Rodičovský form se sub-tabulkou `items` (dětská tabulka `child_tbl`,
 * FK `parent`, sort `name:desc`) — pro testy endpointu /subtable a default
 * rendereru. Žádný override renderSubtable() → jede default z TableForm.
 */
class StubSubtableParentForm extends TableForm
{
    public function buildFormDefinition(array $data, bool $isNew): FormDefinition
    {
        $basic = $this->tab('basic', 'Basic')
            ->section()
                ->col()
                    ->input('name')
            ->build();

        return new FormDefinition(
            table: $this->table,
            title: 'Parent',
            titleNew: 'New parent',
            tabs: [
                $basic,
                $this->subtableTab('items', 'Items', 'child_tbl', 'parent', sort: 'name:desc'),
                $this->subtableTab('badsort', 'Bad sort', 'child_tbl', 'parent', sort: 'nope:asc'),
            ],
        );
    }
}
