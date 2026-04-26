<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Mail;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Core\Mail\AIAnalyzerProvisioner;

/**
 * AIAnalyzerProvisioner musí být idempotentní (každý ensure*() je ok volat
 * opakovaně) a respektovat jediné default omezení per DS.
 */
class AIAnalyzerProvisionerTest extends TestCase
{
    public function testProvisionsAllOnFreshDs(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        // 4× fetchRow: user (null), backend (null), backend default (null),
        //              profile (null), profile default (null)
        $db->method('fetchRow')->willReturnOnConsecutiveCalls(
            null, // user lookup
            null, // backend by backend_id
            null, // backend any default
            null, // profile by profile_id
            null, // profile any default
        );
        $db->method('insertRow')->willReturnOnConsecutiveCalls(
            42, // user id
            17, // backend id
            33, // profile id
        );

        $provisioner = new AIAnalyzerProvisioner($db);
        $result = $provisioner->provision();

        $this->assertSame(['id' => 42, 'created' => true], $result['user']);
        $this->assertSame(['id' => 17, 'created' => true], $result['backend']);
        $this->assertSame(['id' => 33, 'created' => true], $result['profile']);
    }

    public function testIsIdempotentWhenAllExist(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturnOnConsecutiveCalls(
            ['id' => 1],   // user
            ['id' => 2],   // backend by backend_id
            ['id' => 3],   // profile by profile_id
        );
        $db->expects($this->never())->method('insertRow');

        $provisioner = new AIAnalyzerProvisioner($db);
        $result = $provisioner->provision();

        $this->assertSame(['id' => 1, 'created' => false], $result['user']);
        $this->assertSame(['id' => 2, 'created' => false], $result['backend']);
        $this->assertSame(['id' => 3, 'created' => false], $result['profile']);
    }

    public function testSkipsBackendWhenAnotherIsDefault(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturnOnConsecutiveCalls(
            ['id' => 1],                                     // user exists
            null,                                            // backend 'default' missing
            ['id' => 99, 'backend_id' => 'claude-opus'],     // ALE jiný backend je default
            null,                                            // profile lookup
            null,                                            // profile any default
        );
        $db->method('insertRow')->willReturn(50); // pro profile

        $provisioner = new AIAnalyzerProvisioner($db);
        $result = $provisioner->provision();

        $this->assertSame(99, $result['backend']['id']);
        $this->assertFalse($result['backend']['created']);
        $this->assertArrayHasKey('skipped_reason', $result['backend']);
        $this->assertStringContainsString('claude-opus', $result['backend']['skipped_reason']);
        // profile by měl odkazovat na existující default backend (id=99)
        $this->assertTrue($result['profile']['created']);
    }

    public function testSkipsProfileWhenAnotherIsDefault(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturnOnConsecutiveCalls(
            ['id' => 1],                                                // user
            ['id' => 17],                                               // backend default exists
            null,                                                       // profile 'czech_invoices' missing
            ['id' => 88, 'profile_id' => 'english_invoices'],           // ALE jiný profil je default
        );
        $db->expects($this->never())->method('insertRow');

        $provisioner = new AIAnalyzerProvisioner($db);
        $result = $provisioner->provision();

        $this->assertSame(88, $result['profile']['id']);
        $this->assertFalse($result['profile']['created']);
        $this->assertArrayHasKey('skipped_reason', $result['profile']);
        $this->assertStringContainsString('english_invoices', $result['profile']['skipped_reason']);
    }

    public function testProvisionedBackendHasNoApiKeyAndIsInactive(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn(null);

        $insertedRows = [];
        $db->method('insertRow')->willReturnCallback(
            function (string $table, array $data) use (&$insertedRows): int {
                $insertedRows[$table] = $data;
                return count($insertedRows);
            },
        );

        $provisioner = new AIAnalyzerProvisioner($db);
        $provisioner->provision();

        $this->assertArrayHasKey('core_mail_ai_backends', $insertedRows);
        $backend = $insertedRows['core_mail_ai_backends'];
        $this->assertNull($backend['api_key']);
        $this->assertSame(0, $backend['is_active']);
        $this->assertSame(1, $backend['is_default']);
        $this->assertSame('anthropic', $backend['provider']);
    }

    public function testProvisionedProfileLoadsTemplateAndJsonEncodes(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn(null);

        $insertedRows = [];
        $db->method('insertRow')->willReturnCallback(
            function (string $table, array $data) use (&$insertedRows): int {
                $insertedRows[$table] = $data;
                return count($insertedRows);
            },
        );

        $provisioner = new AIAnalyzerProvisioner($db);
        $provisioner->provision();

        $this->assertArrayHasKey('core_mail_ai_profiles', $insertedRows);
        $profile = $insertedRows['core_mail_ai_profiles'];

        $this->assertSame('czech_invoices', $profile['profile_id']);
        $this->assertSame('cs', $profile['language']);
        $this->assertSame('v1.0.0', $profile['prompt_version']);

        // JSON pole musí být validní serializace
        $supportedTypes = json_decode($profile['supported_doc_types'], true);
        $this->assertIsArray($supportedTypes);
        $this->assertContains('invoiceReceived', $supportedTypes);

        $thresholds = json_decode($profile['confidence_thresholds'], true);
        $this->assertSame(0.9, $thresholds['ready']);
        $this->assertSame(0.6, $thresholds['review']);

        $schema = json_decode($profile['output_schema'], true);
        $this->assertIsArray($schema);
        $this->assertSame('object', $schema['type']);
    }
}
