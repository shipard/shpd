<?php

declare(strict_types=1);

namespace Shipard\Tests\Fixtures\Module\Docs\Core;

use Shipard\Module\Docs\Core\DocsHeadsForm;

/**
 * Test-only subclass exposing protected form hooks as public, so unit tests
 * can drive them in isolation without resorting to reflection.
 */
class TestableDocsHeadsForm extends DocsHeadsForm
{
    public function applyClientDefaultsPub(array &$data, bool $isNew): void
    {
        $this->applyClientDefaults($data, $isNew);
    }
}
