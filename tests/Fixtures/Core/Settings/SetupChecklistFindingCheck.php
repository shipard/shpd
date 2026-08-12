<?php

declare(strict_types=1);

namespace Shipard\Tests\Fixtures\Core\Settings;

use Shipard\Core\Alerts\AlertCheck;
use Shipard\Core\Alerts\AlertFinding;

/** Fixture: singleton check, který vždy vrátí jeden nález. */
final class SetupChecklistFindingCheck extends AlertCheck
{
    public function run(): array
    {
        return [
            new AlertFinding(
                findingKey: '',
                title: 'Fixture finding',
                severity: 'warning',
            ),
        ];
    }
}
