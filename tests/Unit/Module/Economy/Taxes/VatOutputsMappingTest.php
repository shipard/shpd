<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Taxes;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Module\Economy\Taxes\VatOutputsMapping;

class VatOutputsMappingTest extends TestCase
{
    private const CONFIG = __DIR__
        . '/../../../../../modules/economy/taxes/config/vat-reports-cz.jsonc';

    private function mapping(): VatOutputsMapping
    {
        return new VatOutputsMapping(JsoncParser::parseFile(self::CONFIG));
    }

    public function testResolvesKnownCodes(): void
    {
        $mapping = $this->mapping();

        $this->assertSame(['row' => 40, 'col' => 'full'], $mapping->dp3('cz-110'));
        $this->assertSame(['group' => 'B2B3'], $mapping->kh('cz-110'));
        $this->assertNull($mapping->sh('cz-110'));

        $this->assertSame(['group' => 'B1', 'kodPredPl' => 4], $mapping->kh('cz-115'));
        $this->assertSame(['group' => 'B1', 'kodPredPl' => 5], $mapping->kh('cz-117'));
        $this->assertSame(['row' => 40, 'col' => 'reduced'], $mapping->dp3('cz-118'));
        $this->assertSame(['kod' => 0], $mapping->sh('cz-201'));
        $this->assertSame(['kod' => 3], $mapping->sh('cz-202'));

        // Explicitní vyloučení: záznam existuje, všechny výstupy null.
        $this->assertSame(['dp3' => null, 'kh' => null, 'sh' => null], $mapping->forCode('cz-112'));
    }

    public function testUnknownCodeThrows(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage("Kód DPH 'cz-999'");
        $this->mapping()->forCode('cz-999');
    }

    public function testDp3RowLabels(): void
    {
        $mapping = $this->mapping();
        $this->assertNotNull($mapping->dp3RowLabel(64));
        $this->assertNotNull($mapping->dp3RowLabel(1));
        $this->assertNull($mapping->dp3RowLabel(99));
    }

    public function testMissingVatOutputsSectionThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new VatOutputsMapping(['dp3Rows' => []]);
    }
}
