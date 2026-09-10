<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\StructuredFields;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\StructuredFields\StructuredSchema;

class StructuredSchemaTest extends TestCase
{
    /** @param array<string, mixed> $items */
    private function config(array $items): ConfigRuntime
    {
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnCallback(fn(string $id) => $items[$id] ?? null);
        return $config;
    }

    /** Lokalizované schéma (jak vyleze z ConfigCompiler pro jeden jazyk). */
    private function localized(): array
    {
        return [
            'version' => '2026',
            'groups'  => [
                ['id' => 'office', 'name' => 'Finanční úřad'],
                ['id' => 'contact', 'name' => 'Kontakt'],
                ['id' => 'empty', 'name' => 'Bez polí'],
            ],
            'fields' => [
                ['id' => 'note', 'type' => 'text', 'name' => 'Poznámka'],
                [
                    'id' => 'c_ufo', 'type' => 'enumString', 'length' => 5,
                    'cfgItem' => 'world.cz.taxOffices', 'group' => 'office',
                    'name' => 'Finanční úřad', 'required' => true,
                ],
                [
                    'id' => 'email', 'type' => 'varchar', 'length' => 255,
                    'group' => 'contact', 'name' => 'E-mail', 'inputType' => 'email',
                    'hint' => 'Pro daňový portál',
                ],
            ],
        ];
    }

    public function testKeyCombinesCfgItemAndVersion(): void
    {
        $schema = StructuredSchema::fromArray('economy.vat.filingProfileCz', $this->localized());
        $this->assertSame('economy.vat.filingProfileCz/2026', $schema->key());
    }

    public function testFieldsAreIndexedById(): void
    {
        $schema = StructuredSchema::fromArray('test.schema', $this->localized());

        $this->assertSame(['note', 'c_ufo', 'email'], array_keys($schema->fields));
        $field = $schema->field('c_ufo');
        $this->assertNotNull($field);
        $this->assertSame('enumString', $field->type);
        $this->assertSame(5, $field->length);
        $this->assertSame('world.cz.taxOffices', $field->cfgItem);
        $this->assertTrue($field->required);
        $this->assertTrue($field->isEnum());
        $this->assertNull($schema->field('nope'));
    }

    public function testFieldDefaultsAreOptional(): void
    {
        $schema = StructuredSchema::fromArray('test.schema', $this->localized());
        $note = $schema->field('note');

        $this->assertNotNull($note);
        $this->assertNull($note->group);
        $this->assertFalse($note->required);
        $this->assertFalse($note->readOnly);
        $this->assertNull($note->default);
        $this->assertNull($note->hint);
        $this->assertSame('Pro daňový portál', $schema->field('email')?->hint);
        $this->assertSame('email', $schema->field('email')?->inputType);
    }

    public function testFieldsByGroupPutsUngroupedFirstAndSkipsEmptyGroups(): void
    {
        $schema = StructuredSchema::fromArray('test.schema', $this->localized());
        $blocks = $schema->fieldsByGroup();

        $this->assertCount(3, $blocks);
        $this->assertNull($blocks[0]['group']);
        $this->assertNull($blocks[0]['title']);
        $this->assertSame(['note'], array_map(fn($f) => $f->id, $blocks[0]['fields']));

        $this->assertSame('office', $blocks[1]['group']);
        $this->assertSame('Finanční úřad', $blocks[1]['title']);
        $this->assertSame(['c_ufo'], array_map(fn($f) => $f->id, $blocks[1]['fields']));

        $this->assertSame('contact', $blocks[2]['group']);
        $this->assertSame(['email'], array_map(fn($f) => $f->id, $blocks[2]['fields']));
    }

    public function testFieldWithUnknownGroupFallsBackToUngrouped(): void
    {
        $schema = StructuredSchema::fromArray('test.schema', [
            'version' => '1',
            'groups'  => [['id' => 'a', 'name' => 'A']],
            'fields'  => [['id' => 'x', 'type' => 'text', 'name' => 'X', 'group' => 'ghost']],
        ]);

        $blocks = $schema->fieldsByGroup();
        $this->assertCount(1, $blocks);
        $this->assertNull($blocks[0]['group']);
    }

    public function testFromCfgItemReadsCompiledConfig(): void
    {
        $config = $this->config(['economy.vat.filingProfileCz' => $this->localized()]);

        $schema = StructuredSchema::fromCfgItem($config, 'economy.vat.filingProfileCz');
        $this->assertNotNull($schema);
        $this->assertSame('2026', $schema->version);
    }

    public function testFromCfgItemReturnsNullWhenMissing(): void
    {
        $this->assertNull(StructuredSchema::fromCfgItem($this->config([]), 'nope'));
        $this->assertNull(StructuredSchema::fromCfgItem(null, 'economy.vat.filingProfileCz'));
    }

    public function testMissingVersionThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        StructuredSchema::fromArray('test.schema', [
            'fields' => [['id' => 'a', 'type' => 'text', 'name' => 'A']],
        ]);
    }

    public function testNoFieldsThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        StructuredSchema::fromArray('test.schema', ['version' => '1', 'fields' => []]);
    }

    #[DataProvider('schemaKeyProvider')]
    public function testCfgItemFromKey(mixed $key, ?string $expected): void
    {
        $this->assertSame($expected, StructuredSchema::cfgItemFromKey($key));
    }

    /** @return array<string, array{mixed, ?string}> */
    public static function schemaKeyProvider(): array
    {
        return [
            'key with version'    => ['economy.vat.filingProfileCz/2026', 'economy.vat.filingProfileCz'],
            'key without version' => ['economy.vat.filingProfileCz', 'economy.vat.filingProfileCz'],
            'empty string'        => ['', null],
            'null'                => [null, null],
            'non-string'          => [42, null],
            'only slash'          => ['/2026', null],
        ];
    }

    public function testVirtualColumnUsesDotSeparator(): void
    {
        $this->assertSame('filing_profile.c_ufo', StructuredSchema::virtualColumn('filing_profile', 'c_ufo'));
        $this->assertSame('.', StructuredSchema::PATH_SEPARATOR);
    }
}
