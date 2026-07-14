<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Base\Registry;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Utils\JsoncParser;

/**
 * Strojová kontrola shody názvů mezi `base.registry.docKinds` (`fields`)
 * a `kindFields` větvemi schématu `shpd.registry.document.v1.json`.
 * Analyzer plní `kindFields` přesně podle output schématu — přejmenované
 * nebo chybějící pole znamená tiché prázdno v metadatech, žádnou chybu.
 *
 * Oprava při failu: dogenerovat/opravit properties příslušné if/then
 * větve schématu tak, aby přesně odpovídaly `fields` daného druhu
 * v `modules/base/registry/config/docKinds.jsonc` (a naopak).
 */
class RegistrySchemaDriftTest extends TestCase
{
    private const REGISTRY_DOC_TYPES = ['contract', 'insurance', 'quotation', 'certificate', 'official'];

    /** @return array<string, mixed> */
    private function loadSchema(): array
    {
        $schema = json_decode(
            (string) file_get_contents(
                $this->modulesRoot() . '/base/registry/schemas/shpd.registry.document.v1.json',
            ),
            true,
        );
        $this->assertIsArray($schema, 'registry schema must be valid JSON');
        return $schema;
    }

    private function modulesRoot(): string
    {
        return dirname(__DIR__, 5) . '/modules';
    }

    public function testKindFieldsMatchDocKinds(): void
    {
        $schema = $this->loadSchema();
        $docKinds = JsoncParser::parseFile(
            $this->modulesRoot() . '/base/registry/config/docKinds.jsonc',
        );
        $this->assertIsArray($docKinds, 'docKinds JSONC must parse');

        $branches = [];
        foreach ($schema['allOf'] ?? [] as $branch) {
            $docType = $branch['if']['properties']['docType']['const'] ?? null;
            $props = $branch['then']['properties']['kindFields']['properties'] ?? null;
            $this->assertIsString($docType, 'each allOf branch must discriminate on docType const');
            $this->assertIsArray($props, "kindFields properties missing for docType '{$docType}'");
            $this->assertFalse(
                $branch['then']['properties']['kindFields']['additionalProperties'] ?? true,
                "kindFields of '{$docType}' must set additionalProperties: false",
            );
            $branches[$docType] = array_keys($props);
        }

        foreach (self::REGISTRY_DOC_TYPES as $docType) {
            $this->assertArrayHasKey($docType, $branches, "schema has no kindFields branch for '{$docType}'");
            $this->assertArrayHasKey($docType, $docKinds, "docKinds has no kind '{$docType}'");
            $this->assertSame(
                $docKinds[$docType]['fields'],
                $branches[$docType],
                "Drift between docKinds['{$docType}'].fields and schema kindFields properties. "
                    . 'Field names must match exactly (order included) — mismatch means silently empty metadata.',
            );
        }

        $this->assertSame(
            self::REGISTRY_DOC_TYPES,
            array_keys($branches),
            'schema allOf branches must cover exactly the registry doc types',
        );
    }

    public function testDocTypeEnumMatchesRegistryTargets(): void
    {
        $schema = $this->loadSchema();
        $this->assertSame(
            self::REGISTRY_DOC_TYPES,
            $schema['properties']['docType']['enum'] ?? null,
            'schema docType enum must list exactly the registry doc types',
        );

        $extractedTypes = JsoncParser::parseFile(
            $this->modulesRoot() . '/core/mail/config/extractedDocTypes.jsonc',
        );
        $registryTypes = array_keys(array_filter(
            $extractedTypes,
            static fn(array $type): bool => ($type['target'] ?? 'docs') === 'registry',
        ));
        $this->assertSame(
            self::REGISTRY_DOC_TYPES,
            $registryTypes,
            'extractedDocTypes with target=registry must match the schema docType enum',
        );

        $docKinds = JsoncParser::parseFile(
            $this->modulesRoot() . '/base/registry/config/docKinds.jsonc',
        );
        foreach ($registryTypes as $docType) {
            $docKind = $extractedTypes[$docType]['docKind'] ?? null;
            $this->assertIsString($docKind, "extractedDocTypes['{$docType}'] must declare docKind");
            $this->assertArrayHasKey($docKind, $docKinds, "docKind '{$docKind}' missing in docKinds");
        }
    }

    public function testExtractedDocTypesPairWithPrimaryTypes(): void
    {
        $extractedTypes = JsoncParser::parseFile(
            $this->modulesRoot() . '/core/mail/config/extractedDocTypes.jsonc',
        );
        $primaryTypes = JsoncParser::parseFile(
            $this->modulesRoot() . '/core/mail/config/primaryTypes.jsonc',
        );

        foreach (array_keys($extractedTypes) as $key) {
            $this->assertArrayHasKey(
                $key,
                $primaryTypes,
                "extractedDocTypes key '{$key}' has no primaryTypes counterpart — "
                    . 'the key pairing (no translation table) must hold',
            );
        }
    }
}
