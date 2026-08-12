<?php

declare(strict_types=1);

namespace Shipard\Tests\Fixtures\Core\Settings;

use Shipard\Core\Alerts\AlertCheck;

/** Fixture: check, u kterého je vše v pořádku — vrací prázdné pole. */
final class SetupChecklistEmptyCheck extends AlertCheck
{
    public function run(): array
    {
        return [];
    }
}
