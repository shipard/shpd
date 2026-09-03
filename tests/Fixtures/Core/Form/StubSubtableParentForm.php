<?php

declare(strict_types=1);

namespace Shipard\Tests\Fixtures\Core\Form;

use Shipard\Core\Form\FormDefinition;
use Shipard\Core\Form\TableForm;

/**
 * Rodičovský form se sub-tabulkami nad `child_tbl` (FK `parent`): `items`
 * (sort `name:desc`), `ordered` (orderColumn `order_pos` — šipky přesunu),
 * plus záměrně vadné `badsort` / `badorder` — pro testy endpointů /subtable
 * a /move a default rendereru. Žádný override renderSubtable() → default.
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
                $this->subtableTab('ordered', 'Ordered', 'child_tbl', 'parent', orderColumn: 'order_pos'),
                $this->subtableTab('badorder', 'Bad order', 'child_tbl', 'parent', orderColumn: 'nope'),
            ],
        );
    }
}
