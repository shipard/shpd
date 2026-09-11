<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\Core;

use PHPUnit\Framework\TestCase;
use Shipard\Tests\Fixtures\Module\Docs\Core\TestableDocsHeadsDocument;

/**
 * DocDocument × zámek období (#55 D24/D26): import mód (`_importNumber`)
 * providery obchází, běžný zápis ne. Vynucení samo dělá TableGateway
 * (TableGatewayTest), tady jen rozhodnutí o výjimce.
 */
class DocDocumentLockTest extends TestCase
{
    public function testImportModeIsLockExempt(): void
    {
        $doc = new TestableDocsHeadsDocument();

        $this->assertTrue($doc->isLockExempt([
            'id' => 5,
            '_importNumber' => ['docNumber' => 'FV-2026-001', 'sequenceNumber' => 1],
        ]));
    }

    public function testRegularSaveIsNotExempt(): void
    {
        $doc = new TestableDocsHeadsDocument();

        $this->assertFalse($doc->isLockExempt(['id' => 5, 'docState' => 40]));
        // Marker musí být pole (tvar applieru) — cizí hodnota není import.
        $this->assertFalse($doc->isLockExempt(['id' => 5, '_importNumber' => true]));
    }
}
