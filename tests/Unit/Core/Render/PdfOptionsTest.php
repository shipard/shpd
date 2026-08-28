<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Render;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Render\PdfOptions;
use Shipard\Core\Render\RenderProfile;

class PdfOptionsTest extends TestCase
{
    public function testDefaults(): void
    {
        $options = new PdfOptions();

        $this->assertSame('A4', $options->paperFormat);
        $this->assertSame('portrait', $options->orientation);
        $this->assertNull($options->marginTop);
        $this->assertFalse($options->printBackground);
    }

    public function testInvalidPaperFormatThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/paperFormat/');

        new PdfOptions(paperFormat: 'B5');
    }

    public function testInvalidOrientationThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/orientation/');

        new PdfOptions(orientation: 'diagonal');
    }

    public function testWithDefaultsFillsOnlyMissingMargins(): void
    {
        $options = new PdfOptions(marginLeft: '2cm');

        $filled = $options->withDefaults(RenderProfile::Report);

        $this->assertSame('2cm', $filled->marginLeft);
        $this->assertSame('1.6cm', $filled->marginTop);
        $this->assertSame('1.6cm', $filled->marginBottom);
        $this->assertSame('1.6cm', $filled->marginRight);
    }
}
