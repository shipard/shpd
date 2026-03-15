<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\I18n;

use PHPUnit\Framework\TestCase;
use Shipard\Core\I18n\ConfigLocalizer;

class ConfigLocalizerTest extends TestCase
{
    public function testFlatStructure(): void
    {
        $data = [
            'id' => 'economy.docs',
            'name' => 'Docs',
            'name:cs' => 'Doklady',
            'name:en' => 'Documents',
        ];
        $result = ConfigLocalizer::localize($data, 'cs');
        $this->assertSame([
            'id' => 'economy.docs',
            'name' => 'Doklady',
        ], $result);
    }

    public function testNestedObjects(): void
    {
        $data = [
            'id' => 'core.system',
            'meta' => [
                'label' => 'System',
                'label:cs' => 'Systém',
                'label:en' => 'System',
            ],
        ];
        $result = ConfigLocalizer::localize($data, 'cs');
        $this->assertSame([
            'id' => 'core.system',
            'meta' => ['label' => 'Systém'],
        ], $result);
    }

    public function testArrayOfObjects(): void
    {
        $data = [
            'rates' => [
                ['name' => 'Standard', 'name:cs' => 'Základní', 'rate' => 21],
                ['name' => 'Reduced', 'name:cs' => 'Snížená', 'rate' => 12],
            ],
        ];
        $result = ConfigLocalizer::localize($data, 'cs');
        $this->assertSame([
            'rates' => [
                ['name' => 'Základní', 'rate' => 21],
                ['name' => 'Snížená', 'rate' => 12],
            ],
        ], $result);
    }

    public function testMixedFields(): void
    {
        $data = [
            'id' => 'economy.vat',
            'version' => 3,
            'name' => 'VAT',
            'name:cs' => 'DPH',
            'tags' => ['accounting', 'tax'],
        ];
        $result = ConfigLocalizer::localize($data, 'cs');
        $this->assertSame([
            'id' => 'economy.vat',
            'version' => 3,
            'name' => 'DPH',
            'tags' => ['accounting', 'tax'],
        ], $result);
    }

    public function testArrayWithoutTranslations(): void
    {
        $data = [
            'id' => 'economy.docs',
            'dependencies' => ['core.system', 'base.persons'],
        ];
        $result = ConfigLocalizer::localize($data, 'cs');
        $this->assertSame([
            'id' => 'economy.docs',
            'dependencies' => ['core.system', 'base.persons'],
        ], $result);
    }

    public function testFallbackAppliedInLocalize(): void
    {
        $data = [
            'name' => 'Documents',
            'name:en' => 'Documents EN',
        ];
        $result = ConfigLocalizer::localize($data, 'de');
        $this->assertSame(['name' => 'Documents EN'], $result);
    }
}
