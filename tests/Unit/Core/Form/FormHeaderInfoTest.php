<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Form;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Form\FormHeaderInfo;

class FormHeaderInfoTest extends TestCase
{
    public function testConstructorAndToArray(): void
    {
        $info = new FormHeaderInfo(
            title: 'Beta Software, a.s.',
            info: [
                ['label' => 'IČO', 'value' => '68253848'],
                ['label' => 'Kód osoby', 'value' => 'TEST-0098'],
            ],
        );

        $this->assertSame('Beta Software, a.s.', $info->title);
        $this->assertCount(2, $info->info);
        $this->assertNull($info->icon);
        $this->assertSame([], $info->summary);

        $arr = $info->toArray();
        $this->assertSame('Beta Software, a.s.', $arr['title']);
        $this->assertSame([
            ['label' => 'IČO', 'value' => '68253848'],
            ['label' => 'Kód osoby', 'value' => 'TEST-0098'],
        ], $arr['info']);
        $this->assertNull($arr['icon']);
        $this->assertSame([], $arr['summary']);
    }

    public function testInfoDefaultsToEmptyArray(): void
    {
        $info = new FormHeaderInfo(title: 'Standalone title');

        $this->assertSame([], $info->info);
        $this->assertSame([], $info->toArray()['info']);
    }

    public function testIconIsPropagated(): void
    {
        $info = new FormHeaderInfo(
            title: 'Beta Software, a.s.',
            icon: 'company',
        );

        $this->assertSame('company', $info->icon);
        $this->assertSame('company', $info->toArray()['icon']);
    }

    public function testSummaryIsPropagated(): void
    {
        $info = new FormHeaderInfo(
            title: 'Beta Software, a.s.',
            icon: 'invoice-in',
            summary: [
                ['label' => 'Bez DPH', 'value' => '10 000,00'],
                ['label' => 'DPH',     'value' => '2 100,00'],
                ['label' => 'Celkem',  'value' => '12 100,00 CZK'],
            ],
        );

        $this->assertCount(3, $info->summary);
        $this->assertSame(
            [
                ['label' => 'Bez DPH', 'value' => '10 000,00'],
                ['label' => 'DPH',     'value' => '2 100,00'],
                ['label' => 'Celkem',  'value' => '12 100,00 CZK'],
            ],
            $info->toArray()['summary'],
        );
    }

    public function testToArrayShape(): void
    {
        // Drátový formát musí obsahovat všechny čtyři klíče i v default stavu;
        // frontend na nich staví defenzivně (chybějící klíč → undefined).
        $arr = (new FormHeaderInfo(title: 'X'))->toArray();
        $this->assertSame(['title', 'info', 'icon', 'summary'], array_keys($arr));
    }
}
