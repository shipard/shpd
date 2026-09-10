<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\StructuredFields;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shipard\Core\StructuredFields\StructuredSchemaValidator;

class StructuredSchemaValidatorTest extends TestCase
{
    /** Surové schéma (jako z .jsonc, s :lang variantami) — základ pro mutace. */
    private function validRaw(): array
    {
        return [
            'version' => '2026',
            'groups'  => [
                ['id' => 'office', 'name' => 'Tax office', 'name:cs' => 'Finanční úřad'],
                ['id' => 'contact', 'name' => 'Contact', 'name:cs' => 'Kontakt'],
            ],
            'fields' => [
                [
                    'id' => 'c_ufo', 'type' => 'enumString', 'length' => 5,
                    'cfgItem' => 'world.cz.taxOffices', 'group' => 'office',
                    'name' => 'Tax office', 'name:cs' => 'Finanční úřad', 'required' => true,
                ],
                [
                    'id' => 'email', 'type' => 'varchar', 'length' => 255,
                    'inputType' => 'email', 'group' => 'contact',
                    'name' => 'E-mail', 'hint' => 'For the tax portal', 'hint:cs' => 'Pro daňový portál',
                ],
                ['id' => 'note', 'type' => 'text', 'name' => 'Note'],
                ['id' => 'amount', 'type' => 'numeric', 'precision' => 12, 'scale' => 2, 'name' => 'Amount'],
                ['id' => 'signed', 'type' => 'boolean', 'name' => 'Signed', 'default' => false],
                ['id' => 'born', 'type' => 'date', 'name' => 'Born', 'readOnly' => true],
                ['id' => 'kind', 'type' => 'enumInt', 'cfgItem' => 'economy.vat.kinds', 'name' => 'Kind'],
            ],
        ];
    }

    public function testValidSchemaPasses(): void
    {
        StructuredSchemaValidator::validate('economy.vat.filingProfileCz', $this->validRaw());
        $this->expectNotToPerformAssertions();
    }

    public function testSchemaWithoutGroupsPasses(): void
    {
        StructuredSchemaValidator::validate('test.schema', [
            'version' => '1',
            'fields'  => [['id' => 'a', 'type' => 'text', 'name' => 'A']],
        ]);
        $this->expectNotToPerformAssertions();
    }

    /** @return array<string, array{array, string}> */
    public static function invalidSchemaProvider(): array
    {
        return [
            'unknown top-level key' => [
                ['version' => '1', 'layout' => [], 'fields' => [['id' => 'a', 'type' => 'text', 'name' => 'A']]],
                'layout: unknown key',
            ],
            'missing version' => [
                ['fields' => [['id' => 'a', 'type' => 'text', 'name' => 'A']]],
                'version: must be a string',
            ],
            'version with slash' => [
                ['version' => '2026/1', 'fields' => [['id' => 'a', 'type' => 'text', 'name' => 'A']]],
                "version: invalid value '2026/1'",
            ],
            'no fields' => [
                ['version' => '1', 'fields' => []],
                'fields: must be a non-empty list',
            ],
            'unknown field key' => [
                ['version' => '1', 'fields' => [['id' => 'a', 'type' => 'text', 'name' => 'A', 'requred' => true]]],
                'fields[0].requred: unknown key',
            ],
            'localized variant of non-localizable key' => [
                ['version' => '1', 'fields' => [['id' => 'a', 'type' => 'text', 'name' => 'A', 'type:cs' => 'text']]],
                'fields[0].type:cs',
            ],
            'unknown type' => [
                ['version' => '1', 'fields' => [['id' => 'a', 'type' => 'enumSting', 'name' => 'A']]],
                "fields[0].type: invalid type 'enumSting'",
            ],
            'varchar without length' => [
                ['version' => '1', 'fields' => [['id' => 'a', 'type' => 'varchar', 'name' => 'A']]],
                "fields[0].length: type 'varchar' requires",
            ],
            'length on text' => [
                ['version' => '1', 'fields' => [['id' => 'a', 'type' => 'text', 'name' => 'A', 'length' => 10]]],
                "fields[0].length: type 'text' does not take",
            ],
            'enumString without cfgItem' => [
                ['version' => '1', 'fields' => [['id' => 'a', 'type' => 'enumString', 'length' => 2, 'name' => 'A']]],
                "fields[0].cfgItem: type 'enumString' requires",
            ],
            'cfgItem on int' => [
                ['version' => '1', 'fields' => [['id' => 'a', 'type' => 'int', 'name' => 'A', 'cfgItem' => 'x.y']]],
                "fields[0].cfgItem: type 'int' does not take",
            ],
            'numeric without scale' => [
                ['version' => '1', 'fields' => [['id' => 'a', 'type' => 'numeric', 'precision' => 8, 'name' => 'A']]],
                "fields[0].scale: type 'numeric' requires",
            ],
            'scale over precision' => [
                ['version' => '1', 'fields' => [
                    ['id' => 'a', 'type' => 'numeric', 'precision' => 2, 'scale' => 4, 'name' => 'A'],
                ]],
                'fields[0].scale: scale must not exceed precision',
            ],
            'duplicate field id' => [
                ['version' => '1', 'fields' => [
                    ['id' => 'a', 'type' => 'text', 'name' => 'A'],
                    ['id' => 'a', 'type' => 'int', 'name' => 'A2'],
                ]],
                "fields[1].id: duplicate field id 'a'",
            ],
            'field in unknown group' => [
                ['version' => '1', 'groups' => [['id' => 'x', 'name' => 'X']], 'fields' => [
                    ['id' => 'a', 'type' => 'text', 'name' => 'A', 'group' => 'y'],
                ]],
                "fields[0].group: unknown group 'y'",
            ],
            'field id with dot' => [
                ['version' => '1', 'fields' => [['id' => 'a.b', 'type' => 'text', 'name' => 'A']]],
                'fields[0].id: must match',
            ],
            'field without bare name' => [
                ['version' => '1', 'fields' => [['id' => 'a', 'type' => 'text', 'name:cs' => 'A']]],
                'fields[0].name: missing bare `name`',
            ],
            'duplicate group id' => [
                ['version' => '1', 'groups' => [
                    ['id' => 'x', 'name' => 'X'],
                    ['id' => 'x', 'name' => 'X2'],
                ], 'fields' => [['id' => 'a', 'type' => 'text', 'name' => 'A']]],
                "groups[1].id: duplicate group id 'x'",
            ],
            'group without bare name' => [
                ['version' => '1', 'groups' => [['id' => 'x', 'name:cs' => 'X']],
                    'fields' => [['id' => 'a', 'type' => 'text', 'name' => 'A']]],
                'groups[0].name: missing bare `name`',
            ],
            'unknown group key' => [
                ['version' => '1', 'groups' => [['id' => 'x', 'name' => 'X', 'icon' => 'user']],
                    'fields' => [['id' => 'a', 'type' => 'text', 'name' => 'A']]],
                'groups[0].icon: unknown key',
            ],
            'bad inputType' => [
                ['version' => '1', 'fields' => [
                    ['id' => 'a', 'type' => 'text', 'name' => 'A', 'inputType' => 'richtext'],
                ]],
                "fields[0].inputType: invalid inputType 'richtext'",
            ],
            'password inputType is refused' => [
                ['version' => '1', 'fields' => [
                    ['id' => 'a', 'type' => 'varchar', 'length' => 50, 'name' => 'A', 'inputType' => 'password'],
                ]],
                "fields[0].inputType: invalid inputType 'password'",
            ],
            'non-bool required' => [
                ['version' => '1', 'fields' => [['id' => 'a', 'type' => 'text', 'name' => 'A', 'required' => 'yes']]],
                'fields[0].required: must be a bool',
            ],
            'non-scalar default' => [
                ['version' => '1', 'fields' => [['id' => 'a', 'type' => 'text', 'name' => 'A', 'default' => ['x']]]],
                'fields[0].default: must be a scalar',
            ],
        ];
    }

    #[DataProvider('invalidSchemaProvider')]
    public function testInvalidSchemaFailsWithPath(array $raw, string $expectedFragment): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches(
            '/' . preg_quote("Structured schema 'test.schema' → ", '/') . '/',
        );
        try {
            StructuredSchemaValidator::validate('test.schema', $raw);
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString($expectedFragment, $e->getMessage());
            throw $e;
        }
    }
}
