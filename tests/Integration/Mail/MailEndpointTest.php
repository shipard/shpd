<?php

declare(strict_types=1);

namespace Shipard\Tests\Integration\Mail;

use Shipard\Api\AuthContext;
use Shipard\Api\Controller\MailController;
use Shipard\Api\DocumentLoader;
use Shipard\Api\Request;
use Shipard\Tests\Integration\IntegrationTestCase;

/**
 * End-to-end pokrytí `POST /_mail/incoming`. Testy volají MailController přímo
 * (se skutečnou DB + file storage z provisioned DS) — HTTP vrstva (router, auth
 * middleware) je pokrytá unit testy.
 *
 * Testy si po sobě uklízí: test prefix `IT:` v subjectu umožňuje rychlý cleanup.
 */
class MailEndpointTest extends IntegrationTestCase
{
    private const TEST_SUBJECT_PREFIX = 'IT:';
    private const TEST_SENDER = 'integration-test@example.com';

    private int $routerUserId;
    private int $defaultMailboxId;
    private \Shipard\Core\Document\DocumentRegistry $documentRegistry;

    /** @var list<int> Vytvořené message IDs pro teardown */
    private array $createdMessageIds = [];
    /** @var list<string> Vytvořené idempotency klíče pro teardown */
    private array $createdIdempotencyKeys = [];

    protected function setUp(): void
    {
        parent::setUp();

        $modulesBasePath = dirname(__DIR__, 3) . '/modules';
        $this->documentRegistry = DocumentLoader::load($this->dsConfig, $modulesBasePath);

        $user = $this->db->fetchRow('SELECT id FROM core_system_users WHERE login = %s', '_mail_router');
        if ($user === null) {
            $this->markTestSkipped('DS is missing _mail_router user — run bin/shpd-ds mail-router-bootstrap.');
        }
        $this->routerUserId = (int) $user['id'];

        $mailbox = $this->db->fetchRow('SELECT id FROM core_mail_mailboxes WHERE is_default = %i LIMIT 1', 1);
        if ($mailbox === null) {
            $this->markTestSkipped('DS is missing default mailbox — run bin/shpd-ds mail-router-bootstrap.');
        }
        $this->defaultMailboxId = (int) $mailbox['id'];

        $_POST = [];
        $_FILES = [];
    }

    protected function tearDown(): void
    {
        foreach ($this->createdIdempotencyKeys as $key) {
            $this->db->execute('DELETE FROM core_mail_incoming_idempotency WHERE idempotency_key = %s', $key);
        }
        foreach ($this->createdMessageIds as $id) {
            // File storage lives under $dsPath (temp dir) — rmTree in parent teardown handles it.
            $this->db->execute('DELETE FROM core_attachments_files WHERE table_id = %i AND record_id = %i', 303, $id);
            $this->db->execute('DELETE FROM core_mail_incoming_messages WHERE id = %i', $id);
        }

        $_POST = [];
        $_FILES = [];

        parent::tearDown();
    }

    public function testHappyPathCreatesMessageWithAttachments(): void
    {
        [$rawSource, $att1, $att2] = $this->prepareFiles();

        $_POST = [
            'mailbox' => 'default',
            'received_at' => '2026-04-18T14:32:00+02:00',
            'sender_email' => self::TEST_SENDER,
            'sender_name' => 'Integration Test',
            'subject' => self::TEST_SUBJECT_PREFIX . ' happy path',
            'body_plain' => 'Hello from the mail-router.',
            'external_message_id' => '<happy-' . uniqid() . '@example.com>',
            'source_type' => '2',
        ];
        $_FILES = [
            'raw_source' => $this->fakeFile($rawSource, 'message.eml'),
            'attachments' => $this->fakeFileArray([$att1, $att2], ['invoice.pdf', 'logo.png']),
        ];

        $response = $this->invokeController();
        $this->assertResponseStatus(201, $response);

        $payload = $response->getPayload();
        $this->assertTrue($payload['success']);
        $this->assertFalse($payload['data']['idempotent_replay']);

        $ndx = (int) $payload['data']['ndx'];
        $this->createdMessageIds[] = $ndx;
        $this->assertGreaterThan(0, $ndx);
        $this->assertStringStartsWith('MSG-', (string) $payload['data']['message_id']);

        $row = $this->db->fetchRow('SELECT * FROM core_mail_incoming_messages WHERE id = %i', $ndx);
        $this->assertNotNull($row);
        $this->assertSame($this->defaultMailboxId, (int) $row['mailbox']);
        $this->assertSame(self::TEST_SENDER, $row['sender_email']);
        $this->assertNotNull($row['raw_source_attachment']);

        $attachments = $this->db->fetchAll(
            'SELECT name FROM core_attachments_files WHERE table_id = %i AND record_id = %i ORDER BY id',
            303,
            $ndx,
        );
        $this->assertCount(3, $attachments, 'raw_source + 2 user attachments');
    }

    public function testIdempotentReplayReturnsSameResponse(): void
    {
        [$rawSource] = $this->prepareFiles();
        $idempotencyKey = hash('sha256', 'integration-test-replay-' . uniqid());
        $this->createdIdempotencyKeys[] = $idempotencyKey;

        $basePost = [
            'mailbox' => 'default',
            'received_at' => '2026-04-18T14:32:00+02:00',
            'sender_email' => self::TEST_SENDER,
            'subject' => self::TEST_SUBJECT_PREFIX . ' replay',
            'source_type' => '2',
        ];

        $_POST = $basePost;
        $_FILES = ['raw_source' => $this->fakeFile($rawSource, 'first.eml')];

        $first = $this->invokeController(['X-Idempotency-Key' => $idempotencyKey]);
        $this->assertResponseStatus(201, $first);
        $this->assertFalse($first->getPayload()['data']['idempotent_replay']);
        $ndx = (int) $first->getPayload()['data']['ndx'];
        $this->createdMessageIds[] = $ndx;

        // Druhý pokus se stejným klíčem
        [$rawSource2] = $this->prepareFiles();
        $_POST = $basePost;
        $_FILES = ['raw_source' => $this->fakeFile($rawSource2, 'second.eml')];

        $second = $this->invokeController(['X-Idempotency-Key' => $idempotencyKey]);
        $this->assertResponseStatus(201, $second);
        $this->assertTrue($second->getPayload()['data']['idempotent_replay']);
        $this->assertSame($ndx, (int) $second->getPayload()['data']['ndx']);
    }

    public function testUnknownMailboxReturns422(): void
    {
        [$rawSource] = $this->prepareFiles();
        $_POST = [
            'mailbox' => 'nonexistent-' . uniqid(),
            'received_at' => '2026-04-18T14:32:00+02:00',
            'sender_email' => self::TEST_SENDER,
            'subject' => self::TEST_SUBJECT_PREFIX . ' unknown mbx',
        ];
        $_FILES = ['raw_source' => $this->fakeFile($rawSource, 'unknown.eml')];

        $response = $this->invokeController();
        $this->assertResponseStatus(422, $response);
        $this->assertSame('VALIDATION_ERROR', $response->getPayload()['error']['code']);
    }

    public function testMissingSenderEmailReturns422(): void
    {
        [$rawSource] = $this->prepareFiles();
        $_POST = [
            'mailbox' => 'default',
            'received_at' => '2026-04-18T14:32:00+02:00',
            // sender_email záměrně chybí
            'subject' => self::TEST_SUBJECT_PREFIX . ' no sender',
        ];
        $_FILES = ['raw_source' => $this->fakeFile($rawSource, 'no-sender.eml')];

        $response = $this->invokeController();
        $this->assertResponseStatus(422, $response);
        $this->assertSame('sender_email', $response->getPayload()['error']['details'][0]['field']);
    }

    public function testAnonymousAuthReturns401(): void
    {
        [$rawSource] = $this->prepareFiles();
        $_POST = [
            'mailbox' => 'default',
            'received_at' => '2026-04-18T14:32:00+02:00',
            'sender_email' => self::TEST_SENDER,
            'subject' => self::TEST_SUBJECT_PREFIX . ' anon',
        ];
        $_FILES = ['raw_source' => $this->fakeFile($rawSource, 'anon.eml')];

        $response = $this->invokeController([], AuthContext::anonymous());
        $this->assertResponseStatus(401, $response);
    }

    public function testEmptyMailboxUsesDefault(): void
    {
        [$rawSource] = $this->prepareFiles();
        $_POST = [
            'mailbox' => '',
            'received_at' => '2026-04-18T14:32:00+02:00',
            'sender_email' => self::TEST_SENDER,
            'subject' => self::TEST_SUBJECT_PREFIX . ' default fallback',
            'source_type' => '2',
        ];
        $_FILES = ['raw_source' => $this->fakeFile($rawSource, 'default.eml')];

        $response = $this->invokeController();
        $this->assertResponseStatus(201, $response);
        $ndx = (int) $response->getPayload()['data']['ndx'];
        $this->createdMessageIds[] = $ndx;

        $row = $this->db->fetchRow('SELECT mailbox FROM core_mail_incoming_messages WHERE id = %i', $ndx);
        $this->assertSame($this->defaultMailboxId, (int) $row['mailbox']);
    }

    /** @return array{0: string, 1: string, 2: string} paths to temp files */
    private function prepareFiles(): array
    {
        $dir = sys_get_temp_dir();
        $raw = tempnam($dir, 'shpd_raw_');
        file_put_contents($raw, "From: test@example.com\r\nSubject: IT\r\n\r\nHello");

        $att1 = tempnam($dir, 'shpd_att_');
        file_put_contents($att1, '%PDF-1.4 fake');

        $att2 = tempnam($dir, 'shpd_att_');
        file_put_contents($att2, "\x89PNG\r\n\x1a\n fake");

        return [$raw, $att1, $att2];
    }

    private function fakeFile(string $tmpPath, string $originalName): array
    {
        return [
            'name' => $originalName,
            'tmp_name' => $tmpPath,
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($tmpPath) ?: 0,
            'type' => 'application/octet-stream',
        ];
    }

    /**
     * @param list<string> $paths
     * @param list<string> $names
     */
    private function fakeFileArray(array $paths, array $names): array
    {
        return [
            'name' => $names,
            'tmp_name' => $paths,
            'error' => array_fill(0, count($paths), UPLOAD_ERR_OK),
            'size' => array_map(static fn(string $p): int => filesize($p) ?: 0, $paths),
            'type' => array_fill(0, count($paths), 'application/octet-stream'),
        ];
    }

    private function invokeController(array $headers = [], ?AuthContext $auth = null): \Shipard\Api\Response
    {
        $ctrl = new MailController($this->db, $this->dsPath, $this->tables, $this->documentRegistry);
        $auth ??= new AuthContext(true, $this->routerUserId, 'api_key', 'shpd_ak_test');
        $request = $this->buildRequest($headers);
        return $ctrl->receiveIncoming($auth, $request);
    }

    private function buildRequest(array $headers): Request
    {
        $server = ['HTTP_HOST' => 'test.local', 'REMOTE_ADDR' => '127.0.0.1'];
        foreach ($headers as $name => $value) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }
        return Request::fromArray('POST', '/_mail/incoming', [], '', $server);
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
