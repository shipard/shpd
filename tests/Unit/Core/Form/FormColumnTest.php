<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Form;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Form\FormColumn;
use Shipard\Core\Form\FormElement;

class FormColumnTest extends TestCase
{
    public function testEmptyColumnSerializesEmptyElements(): void
    {
        $col = new FormColumn([]);
        $this->assertSame(['elements' => []], $col->toArray());
    }

    public function testToArraySerializesElements(): void
    {
        $col = new FormColumn([
            new FormElement(type: 'input', column: 'name', label: 'Name'),
            new FormElement(type: 'separator', label: 'Section'),
        ]);
        $arr = $col->toArray();

        $this->assertCount(2, $arr['elements']);
        $this->assertSame('name', $arr['elements'][0]['column']);
        $this->assertSame('separator', $arr['elements'][1]['type']);
    }
}
