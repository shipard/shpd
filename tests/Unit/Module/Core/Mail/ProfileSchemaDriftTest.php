<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Mail;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Utils\JsoncParser;

/**
 * The AI profile's `output_schema.documents.items.extracted_json` is a
 * oneOf of two inline schema copies:
 *   [0] `modules/core/exchange/schemas/shpd.docs.document.v1.json`
 *   [1] `modules/base/registry/schemas/shpd.registry.document.v1.json`
 * Analyzers receive `output_schema` over the wire (`/claim` response)
 * and don't resolve `$ref` across files, so we keep both canonical
 * schemas inlined. This test catches drift when one is updated and the
 * other isn't.
 *
 * Repair: copy the canonical file content into the corresponding
 * `extracted_json.oneOf[...]` branch of the profile JSONC.
 */
class ProfileSchemaDriftTest extends TestCase
{
    /** @return array{0: array<string, mixed>, 1: string} [profile, modulesRoot] */
    private function loadProfile(): array
    {
        $modulesRoot = dirname(__DIR__, 5) . '/modules';
        $profile = JsoncParser::parseFile(
            $modulesRoot . '/core/mail/profiles/default_czech_invoices.jsonc',
        );
        $this->assertIsArray($profile, 'profile JSONC must parse');
        return [$profile, $modulesRoot];
    }

    /** @return array<int, mixed> */
    private function extractedJsonOneOf(array $profile): array
    {
        $extractedJson = $profile['output_schema']['properties']['documents']['items']['properties']['extracted_json'] ?? null;
        $this->assertIsArray($extractedJson, 'profile output_schema.documents.items.properties.extracted_json missing');
        $this->assertArrayHasKey('oneOf', $extractedJson, 'extracted_json must be a oneOf of [docs, registry] embeds');
        return $extractedJson['oneOf'];
    }

    public function testProfileDocsSchemaMatchesCanonical(): void
    {
        [$profile, $modulesRoot] = $this->loadProfile();

        $canonical = json_decode(
            (string) file_get_contents($modulesRoot . '/core/exchange/schemas/shpd.docs.document.v1.json'),
            true,
        );
        $this->assertIsArray($canonical, 'docs canonical schema must be valid JSON');

        $this->assertSame(
            $canonical,
            $this->extractedJsonOneOf($profile)[0] ?? null,
            "Drift between docs canonical schema and profile's `extracted_json.oneOf[0]`. "
                . "Copy shpd.docs.document.v1.json content into the profile.",
        );
    }

    public function testProfileRegistrySchemaMatchesCanonical(): void
    {
        [$profile, $modulesRoot] = $this->loadProfile();

        $canonical = json_decode(
            (string) file_get_contents($modulesRoot . '/base/registry/schemas/shpd.registry.document.v1.json'),
            true,
        );
        $this->assertIsArray($canonical, 'registry canonical schema must be valid JSON');

        $this->assertSame(
            $canonical,
            $this->extractedJsonOneOf($profile)[1] ?? null,
            "Drift between registry canonical schema and profile's `extracted_json.oneOf[1]`. "
                . "Copy shpd.registry.document.v1.json content into the profile.",
        );
    }

    public function testProfileMetadata(): void
    {
        [$profile] = $this->loadProfile();

        $this->assertSame('czech_invoices', $profile['profile_id']);
        $this->assertSame('v3.2.0', $profile['prompt_version']);
        $this->assertContains('invoiceReceived', $profile['supported_doc_types']);
        foreach (['contract', 'insurance', 'quotation', 'certificate', 'official'] as $registryType) {
            $this->assertContains($registryType, $profile['supported_doc_types']);
        }
        $this->assertSame(0.9, $profile['confidence_thresholds']['ready']);
    }

    public function testPromptEnumeratesKindFieldsExactly(): void
    {
        // Prompt vyjmenovává přesné názvy kindFields per druh — nesoulad
        // s docKinds znamená tiché prázdno v metadatech. Strojová pojistka
        // nad textem promptu.
        [$profile, $modulesRoot] = $this->loadProfile();
        $prompt = (string) $profile['prompt_template'];

        $docKinds = JsoncParser::parseFile($modulesRoot . '/base/registry/config/docKinds.jsonc');
        foreach (['contract', 'insurance', 'quotation', 'certificate', 'official'] as $kind) {
            $expected = sprintf(
                '- %s: %s',
                $kind,
                implode(', ', array_map(static fn(string $f): string => "\"{$f}\"", $docKinds[$kind]['fields'])),
            );
            $this->assertStringContainsString(
                $expected,
                $prompt,
                "Prompt must enumerate kindFields of '{$kind}' exactly as in docKinds.jsonc",
            );
        }

        $this->assertStringContainsString('"v3.2.0"', $prompt, 'prompt must pin its own version');
        $this->assertStringNotContainsString('v3.1.0', $prompt, 'stale prompt version reference');
    }
}
