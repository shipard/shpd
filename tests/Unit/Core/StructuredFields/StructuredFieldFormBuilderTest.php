<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\StructuredFields;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Form\FormElement;
use Shipard\Core\StructuredFields\StructuredFieldFormBuilder;
use Shipard\Core\StructuredFields\StructuredSchema;

class StructuredFieldFormBuilderTest extends TestCase
{
    private function schema(): StructuredSchema
    {
        return StructuredSchema::fromArray('test.profile', [
            'version' => '2026',
            'groups'  => [
                ['id' => 'office', 'name' => 'Finanční úřad'],
                ['id' => 'contact', 'name' => 'Kontakt'],
            ],
            'fields' => [
                ['id' => 'note', 'type' => 'text', 'name' => 'Poznámka'],
                [
                    'id' => 'c_ufo', 'type' => 'enumString', 'length' => 5,
                    'cfgItem' => 'test.taxOffices', 'group' => 'office', 'name' => 'Finanční úřad',
                    'required' => true,
                ],
                [
                    'id' => 'email', 'type' => 'varchar', 'length' => 255, 'inputType' => 'email',
                    'group' => 'contact', 'name' => 'E-mail', 'hint' => 'Pro portál',
                ],
                ['id' => 'born', 'type' => 'date', 'group' => 'contact', 'name' => 'Narození'],
                ['id' => 'count', 'type' => 'int', 'group' => 'contact', 'name' => 'Počet', 'readOnly' => true],
                ['id' => 'ratio', 'type' => 'numeric', 'precision' => 5, 'scale' => 2,
                    'group' => 'contact', 'name' => 'Podíl'],
                ['id' => 'signed', 'type' => 'boolean', 'group' => 'contact', 'name' => 'Podepsáno'],
                ['id' => 'kind', 'type' => 'enumInt', 'cfgItem' => 'test.kinds',
                    'group' => 'contact', 'name' => 'Druh'],
            ],
        ]);
    }

    private function builder(bool $withConfig = true): StructuredFieldFormBuilder
    {
        if (!$withConfig) {
            return new StructuredFieldFormBuilder(null);
        }
        $items = [
            'test.taxOffices' => ['451' => ['name' => 'Praha'], '464' => ['name' => 'Zlínský kraj']],
            'test.kinds'      => ['1' => ['name' => 'Jeden'], '2' => ['name' => 'Dva']],
        ];
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnCallback(fn(string $id) => $items[$id] ?? null);
        return new StructuredFieldFormBuilder($config);
    }

    /** @return list<FormElement> */
    private function elements(bool $groupSeparators = true): array
    {
        return $this->builder()->elements($this->schema(), 'filing_profile', $groupSeparators);
    }

    public function testGroupSeparatorsPrecedeTheirFields(): void
    {
        $shape = array_map(
            fn(FormElement $el) => $el->type . ':' . ($el->column ?? $el->label ?? ''),
            $this->elements(),
        );

        $this->assertSame([
            'input:filing_profile.note',
            'separator:Finanční úřad',
            'select:filing_profile.c_ufo',
            'separator:Kontakt',
            'input:filing_profile.email',
            'input:filing_profile.born',
            'input:filing_profile.count',
            'input:filing_profile.ratio',
            'input:filing_profile.signed',
            'select:filing_profile.kind',
        ], $shape);
    }

    public function testGroupSeparatorsCanBeSuppressed(): void
    {
        foreach ($this->elements(false) as $el) {
            $this->assertNotSame('separator', $el->type);
        }
    }

    public function testEnumBecomesSelectWithCfgItemOptions(): void
    {
        $el = $this->elementFor('filing_profile.c_ufo');

        $this->assertSame('select', $el->type);
        $this->assertSame('Finanční úřad', $el->label);
        $this->assertTrue($el->required);
        $this->assertSame(
            [['value' => '451', 'label' => 'Praha'], ['value' => '464', 'label' => 'Zlínský kraj']],
            $el->options,
        );
    }

    public function testEnumIntOptionsAreInts(): void
    {
        $options = $this->elementFor('filing_profile.kind')->options ?? [];

        $this->assertSame([['value' => 1, 'label' => 'Jeden'], ['value' => 2, 'label' => 'Dva']], $options);
    }

    public function testInputTypesDerivedFromFieldType(): void
    {
        $this->assertSame('textarea', $this->elementFor('filing_profile.note')->inputType);
        $this->assertSame('date', $this->elementFor('filing_profile.born')->inputType);
        $this->assertSame('number', $this->elementFor('filing_profile.count')->inputType);
        $this->assertSame('number', $this->elementFor('filing_profile.ratio')->inputType);
        $this->assertSame('checkbox', $this->elementFor('filing_profile.signed')->inputType);
    }

    public function testDeclaredInputTypeWinsAndHintPassesThrough(): void
    {
        $el = $this->elementFor('filing_profile.email');

        $this->assertSame('email', $el->inputType);
        $this->assertSame('Pro portál', $el->hint);
        $this->assertFalse($el->required);
    }

    public function testReadOnlyIsPropagated(): void
    {
        $this->assertTrue($this->elementFor('filing_profile.count')->readOnly);
        $this->assertFalse($this->elementFor('filing_profile.email')->readOnly);
    }

    public function testWithoutConfigSelectsHaveNoOptions(): void
    {
        $elements = $this->builder(false)->elements($this->schema(), 'filing_profile');

        foreach ($elements as $el) {
            if ($el->column === 'filing_profile.c_ufo') {
                $this->assertSame([], $el->options);
                return;
            }
        }
        $this->fail('c_ufo element not found');
    }

    private function elementFor(string $column): FormElement
    {
        foreach ($this->elements() as $el) {
            if ($el->column === $column) {
                return $el;
            }
        }
        $this->fail("element {$column} not found");
    }
}
