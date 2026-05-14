<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Mail;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Utils\JsoncParser;

/**
 * The AI profile's `output_schema.documents.items.fields` is an inline
 * copy of `modules/core/exchange/schemas/shpd.docs.document.v1.json`.
 * Analyzers receive `output_schema` over the wire (`/claim` response)
 * and don't resolve `$ref` across files, so we keep the canonical schema
 * inlined. This test catches drift when one is updated and the other
 * isn't.
 *
 * Repair: regenerate the profile with /tmp/build_profile.php or copy
 * `shpd.docs.document.v1.json` content into the `fields` value of the
 * profile JSONC.
 */
class ProfileSchemaDriftTest extends TestCase
{
    public function testProfileFieldsSchemaMatchesCanonical(): void
    {
        $modulesRoot = dirname(__DIR__, 5) . '/modules';

        $canonical = json_decode(
            (string) file_get_contents($modulesRoot . '/core/exchange/schemas/shpd.docs.document.v1.json'),
            true,
        );

        $profile = JsoncParser::parseFile(
            $modulesRoot . '/core/mail/profiles/default_czech_invoices.jsonc',
        );

        $this->assertIsArray($canonical, 'canonical schema must be valid JSON');
        $this->assertIsArray($profile, 'profile JSONC must parse');

        $fields = $profile['output_schema']['properties']['documents']['items']['properties']['fields'] ?? null;
        $this->assertIsArray($fields, 'profile output_schema.documents.items.properties.fields missing');

        $this->assertSame(
            $canonical,
            $fields,
            "Drift between canonical schema and profile's inline `fields`. "
                . "Regenerate the profile from shpd.docs.document.v1.json (see /tmp/build_profile.php).",
        );
    }

    public function testProfileMetadata(): void
    {
        $modulesRoot = dirname(__DIR__, 5) . '/modules';
        $profile = JsoncParser::parseFile(
            $modulesRoot . '/core/mail/profiles/default_czech_invoices.jsonc',
        );

        $this->assertSame('czech_invoices', $profile['profile_id']);
        $this->assertSame('v2.0.0', $profile['prompt_version']);
        $this->assertContains('invoiceReceived', $profile['supported_doc_types']);
        $this->assertSame(0.9, $profile['confidence_thresholds']['ready']);
    }
}
