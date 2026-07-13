<?php

declare(strict_types=1);

namespace Shipard\Tests\Integration\Mail;

use Shipard\Api\AuthContext;
use Shipard\Api\Controller\MailController;
use Shipard\Api\DocumentLoader;
use Shipard\Api\Request;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Tests\Integration\IntegrationTestCase;

/**
 * End-to-end pokrytí `POST /_mail/import`. Testy volají MailController přímo
 * (se skutečnou DB z provisioned DS) — HTTP vrstva (router, auth middleware)
 * je pokrytá unit testy.
 *
 * Na rozdíl od `/_mail/incoming`: JSON tělo (žádný .eml/přílohy), libovolný
 * api_key uživatel, default docState 40 (Hotovo, analysis_state 0) a možnost
 * nastavit sender_person / primary_type / source_type / target_* / analysis_state.
 *
 * Testy si po sobě uklízí přes test prefix `IT:` v subjectu.
 */
class MailImportEndpointTest extends IntegrationTestCase
{
    private const TEST_SUBJECT_PREFIX = 'IT:';
    private const TEST_SENDER = 'import-test@example.com';

    private int $importerUserId;
    private int $defaultMailboxId;
    private \Shipard\Core\Document\DocumentRegistry $documentRegistry;
    private ?ConfigRuntime $config = null;

    /** @var list<int> */
    private array $createdMessageIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $modulePathResolver = new ModulePathResolver([dirname(__DIR__, 3) . '/modules']);
        $this->documentRegistry = DocumentLoader::load($this->dsConfig, $modulePathResolver);

        // Jakýkoli api_key uživatel projde; vezmeme libovolného existujícího.
        $user = $this->db->fetchRow('SELECT id FROM core_system_users ORDER BY id LIMIT 1');
        if ($user === null) {
            $this->markTestSkipped('DS has no users.');
        }
        $this->importerUserId = (int) $user['id'];

        $mailbox = $this->db->fetchRow('SELECT id FROM core_mail_mailboxes WHERE is_default = %i LIMIT 1', 1);
        if ($mailbox === null) {
            $this->markTestSkipped('DS is missing default mailbox — run bin/shpd-ds mail-router-bootstrap.');
        }
        $this->defaultMailboxId = (int) $mailbox['id'];

        try {
            $this->config = ConfigRuntime::load($this->realDsPath, 'cs');
        } catch (\Throwable) {
            $this->config = null; // controller degraduje na pevnou mainState mapu
        }
    }

    protected function onTearDown(): void
    {
        foreach ($this->createdMessageIds as $id) {
            $this->db->execute('DELETE FROM core_mail_incoming_messages WHERE id = %i', $id);
        }
    }

    public function testImportCreatesProcessedMessageWithGeneratedId(): void
    {
        $response = $this->invoke([
            'mailbox' => 'default',
            'subject' => self::TEST_SUBJECT_PREFIX . ' minimal',
            'sender_email' => self::TEST_SENDER,
            'received_at' => '2026-04-18T14:32:00+02:00',
        ]);

        $this->assertResponseStatus(201, $response);
        $payload = $response->getPayload();
        $this->assertTrue($payload['success']);

        $ndx = (int) $payload['data']['ndx'];
        $this->createdMessageIds[] = $ndx;
        $this->assertGreaterThan(0, $ndx);
        $this->assertStringStartsWith('MSG-', (string) $payload['data']['message_id']);

        $row = $this->db->fetchRow('SELECT * FROM core_mail_incoming_messages WHERE id = %i', $ndx);
        $this->assertNotNull($row);
        $this->assertSame($this->defaultMailboxId, (int) $row['mailbox']);
        $this->assertSame(self::TEST_SENDER, $row['sender_email']);
        $this->assertSame(40, (int) $row['docState'], 'default docState = 40 (Hotovo)');
        $this->assertSame(3, (int) $row['docStateMain']);
        $this->assertSame(0, (int) $row['analysis_state'], 'import v Hotovo se neanalyzuje');
        $this->assertSame(1, (int) $row['source_type'], 'default source_type = 1');
        $this->assertSame($this->importerUserId, (int) $row['created_by']);
    }

    public function testImportNormalizesSenderEmail(): void
    {
        $response = $this->invoke([
            'mailbox' => 'default',
            'subject' => self::TEST_SUBJECT_PREFIX . ' normalize',
            'sender_email' => '  Mixed.Case@Example.COM  ',
            'received_at' => '2026-04-18T14:32:00+02:00',
        ]);

        $this->assertResponseStatus(201, $response);
        $ndx = (int) $response->getPayload()['data']['ndx'];
        $this->createdMessageIds[] = $ndx;

        $row = $this->db->fetchRow('SELECT sender_email FROM core_mail_incoming_messages WHERE id = %i', $ndx);
        $this->assertSame('mixed.case@example.com', $row['sender_email']);
    }

    public function testImportWithTargetLinkAndOptionalFields(): void
    {
        $response = $this->invoke([
            'mailbox' => 'default',
            'subject' => self::TEST_SUBJECT_PREFIX . ' target link',
            'sender_email' => self::TEST_SENDER,
            'received_at' => '2026-04-18T14:32:00+02:00',
            'primary_type' => 'order',
            'source_type' => 3,
            'target_table_id' => 'docs_core_heads',
            'target_row' => 4242,
        ]);

        $this->assertResponseStatus(201, $response);
        $ndx = (int) $response->getPayload()['data']['ndx'];
        $this->createdMessageIds[] = $ndx;

        $row = $this->db->fetchRow('SELECT * FROM core_mail_incoming_messages WHERE id = %i', $ndx);
        $this->assertSame('order', $row['primary_type'], 'tělo má přednost před default primary_type');
        $this->assertSame(3, (int) $row['source_type'], 'tělo má přednost před default source_type');
        $this->assertSame('docs_core_heads', $row['target_table_id']);
        $this->assertSame(4242, (int) $row['target_row']);
    }

    public function testImportWithDocState10(): void
    {
        $response = $this->invoke([
            'mailbox' => 'default',
            'subject' => self::TEST_SUBJECT_PREFIX . ' new state',
            'sender_email' => self::TEST_SENDER,
            'received_at' => '2026-04-18T14:32:00+02:00',
            'docState' => 10,
        ]);

        $this->assertResponseStatus(201, $response);
        $ndx = (int) $response->getPayload()['data']['ndx'];
        $this->createdMessageIds[] = $ndx;

        $row = $this->db->fetchRow('SELECT docState, docStateMain FROM core_mail_incoming_messages WHERE id = %i', $ndx);
        $this->assertSame(10, (int) $row['docState']);
        $this->assertSame(1, (int) $row['docStateMain']);
    }

    public function testImportEmptyMailboxUsesDefault(): void
    {
        $response = $this->invoke([
            'mailbox' => '',
            'subject' => self::TEST_SUBJECT_PREFIX . ' default fallback',
            'sender_email' => self::TEST_SENDER,
            'received_at' => '2026-04-18T14:32:00+02:00',
        ]);

        $this->assertResponseStatus(201, $response);
        $ndx = (int) $response->getPayload()['data']['ndx'];
        $this->createdMessageIds[] = $ndx;

        $row = $this->db->fetchRow('SELECT mailbox FROM core_mail_incoming_messages WHERE id = %i', $ndx);
        $this->assertSame($this->defaultMailboxId, (int) $row['mailbox']);
    }

    public function testImportUnknownMailboxReturns422(): void
    {
        $response = $this->invoke([
            'mailbox' => 'nonexistent-' . uniqid(),
            'subject' => self::TEST_SUBJECT_PREFIX . ' unknown mbx',
            'sender_email' => self::TEST_SENDER,
            'received_at' => '2026-04-18T14:32:00+02:00',
        ]);

        $this->assertResponseStatus(422, $response);
        $this->assertSame('VALIDATION_ERROR', $response->getPayload()['error']['code']);
    }

    public function testImportInvalidSenderEmailReturns422(): void
    {
        $response = $this->invoke([
            'mailbox' => 'default',
            'subject' => self::TEST_SUBJECT_PREFIX . ' bad sender',
            'sender_email' => 'not-an-email',
            'received_at' => '2026-04-18T14:32:00+02:00',
        ]);

        $this->assertResponseStatus(422, $response);
        $this->assertSame('sender_email', $response->getPayload()['error']['details'][0]['field']);
    }

    private function invoke(array $body, ?AuthContext $auth = null): \Shipard\Api\Response
    {
        $ctrl = new MailController($this->db, $this->dsPath, $this->tables, $this->documentRegistry, $this->config);
        $auth ??= new AuthContext(true, $this->importerUserId, 'api_key', 'shpd_ak_importer');
        $request = Request::fromArray(
            'POST',
            '/_mail/import',
            [],
            (string) json_encode($body),
            ['HTTP_HOST' => 'test.local', 'REMOTE_ADDR' => '127.0.0.1'],
        );
        return $ctrl->importMessage($auth, $request);
    }

    private function assertResponseStatus(int $expected, \Shipard\Api\Response $response): void
    {
        $ref = new \ReflectionClass($response);
        $prop = $ref->getProperty('status');
        $actual = (int) $prop->getValue($response);
        if ($actual !== $expected) {
            $payload = $response->getPayload();
            $msg = is_array($payload) && isset($payload['error']['message'])
                ? $payload['error']['message']
                : json_encode($payload);
            $this->assertSame($expected, $actual, "Unexpected status with payload: {$msg}");
        } else {
            $this->assertSame($expected, $actual);
        }
    }
}
