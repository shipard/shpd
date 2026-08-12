<?php

declare(strict_types=1);

namespace Shipard\Tests\Fixtures\Core\Settings;

use Shipard\Core\Alerts\AlertCheck;

/** Fixture: rozbitý check — run() vždy vyhodí výjimku (fail-open testy). */
final class SetupChecklistBrokenCheck extends AlertCheck
{
    public function run(): array
    {
        throw new \RuntimeException('boom');
    }
}
