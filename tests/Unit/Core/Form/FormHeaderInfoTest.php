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

        $arr = $info->toArray();
        $this->assertSame('Beta Software, a.s.', $arr['title']);
        $this->assertSame([
            ['label' => 'IČO', 'value' => '68253848'],
            ['label' => 'Kód osoby', 'value' => 'TEST-0098'],
        ], $arr['info']);
    }

    public function testInfoDefaultsToEmptyArray(): void
    {
        $info = new FormHeaderInfo(title: 'Standalone title');

        $this->assertSame([], $info->info);
        $this->assertSame([], $info->toArray()['info']);
    }
}
