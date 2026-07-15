<?php

declare(strict_types=1);

namespace Shipard\Tests\Integration\Mail;

use Shipard\Api\AuthContext;
use Shipard\Api\Controller\SenderRulesController;
use Shipard\Api\DocumentLoader;
use Shipard\Api\Request;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Document\DocumentRegistry;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Tests\Integration\IntegrationTestCase;

/**
 * Endpointy Fáze 3 (šum): confirm/reject návrhu pravidla a undo denního
 * auto-archivu (obnova docState + analysis_state + audit sloupců).
 */
class SenderRulesEndpointTest extends IntegrationTestCase
{
    private const TEST_SENDER = 'rules-endpoint-test@example.com';

    private SenderRulesController $controller;
    private DocumentRegistry $documentRegistry;
    private ConfigRuntime $configRuntime;
    private int $mailboxId = 0;

    /** @var list<int> */
    private array $createdRuleIds = [];
    /** @var list<int> */
    private array $createdMessageIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $resolver = new ModulePathResolver([dirname(__DIR__, 3) . '/modules']);
        $this->documentRegistry = DocumentLoader::load($this->dsConfig, $resolver);
        $this->configRuntime = ConfigRuntime::load($this->realDsPath, 'cs');

        if ($this->configRuntime->cfgItem('core.mail.senderRulePatternKinds') === null) {
            $this->markTestSkipped('compiled config missing sender rule cfgItems — run ds-upgrade.');
        }

        $mailbox = $this->db->fetchRow('SELECT id FROM core_mail_mailboxes ORDER BY is_default DESC, id LIMIT 1');
        if ($mailbox === null) {
            $this->markTestSkipped('DS has no mailbox — run mail-router-bootstrap.');
        }
        $this->mailboxId = (int) $mailbox['id'];

        $this->controller = new SenderRulesController(
            $this->db,
            $this->tables,
            $this->documentRegistry,
            $this->configRuntime,
            $this->dsConfig,
        );
    }

    protected function onTearDown(): void
    {
        foreach ($this->createdMessageIds as $id) {
            $this->db->execute('DELETE FROM core_mail_incoming_messages WHERE id = %i', $id);
        }
        foreach ($this->createdRuleIds as $id) {
            $this->db->execute('DELETE FROM core_mail_sender_rules WHERE id = %i', $id);
        }
    }

    // --- confirm / reject -------------------------------------------------

    public function testConfirmMovesDraftToConfirmed(): void
    {
        $ruleId = $this->insertRule(10);

        $response = $this->controller->confirmRule($this->auth(), $ruleId);
        $this->assertResponseStatus(200, $response);

        $row = $this->db->fetchRow('SELECT docState, docStateMain FROM core_mail_sender_rules WHERE id = %i', $ruleId);
        $this->assertSame(40, (int) $row['docState']);
        $this->assertSame(3, (int) $row['docStateMain']);
    }

    public function testRejectMovesDraftToTrash(): void
    {
        $ruleId = $this->insertRule(10);

        $response = $this->controller->rejectRule($this->auth(), $ruleId);
        $this->assertResponseStatus(200, $response);

        $row = $this->db->fetchRow('SELECT docState FROM core_mail_sender_rules WHERE id = %i', $ruleId);
        $this->assertSame(90, (int) $row['docState']);
    }

    public function testConfirmNonDraftReturns409(): void
    {
        $ruleId = $this->insertRule(40);

        $response = $this->controller->confirmRule($this->auth(), $ruleId);

        $this->assertResponseStatus(409, $response);
        $this->assertSame('INVALID_STATE', $response->getPayload()['error']['code']);
    }

    public function testConfirmMissingRuleReturns404(): void
    {
        $response = $this->controller->confirmRule($this->auth(), 999999999);

        $this->assertResponseStatus(404, $response);
    }

    public function testAnonymousReturns401(): void
    {
        $response = $this->controller->confirmRule(AuthContext::anonymous(), 1);

        $this->assertResponseStatus(401, $response);
    }

    // --- undo auto-archive --------------------------------------------------

    public function testUndoRestoresTodaysAutoArchivedMessages(): void
    {
        $ruleId = $this->insertRule(40);
        $m1 = $this->insertAutoArchivedMessage($ruleId, date('Y-m-d') . ' 08:00:00');
        $m2 = $this->insertAutoArchivedMessage($ruleId, date('Y-m-d') . ' 09:30:00');
        // Starší auto-archiv nesmí být dotčen.
        $mOld = $this->insertAutoArchivedMessage($ruleId, date('Y-m-d', strtotime('-5 days')) . ' 10:00:00');

        $response = $this->controller->undoAutoArchive($this->auth(), $this->request([]));
        $this->assertResponseStatus(200, $response);
        $this->assertSame(2, $response->getPayload()['data']['restored']);

        foreach ([$m1, $m2] as $id) {
            $row = $this->db->fetchRow(
                'SELECT docState, docStateMain, analysis_state, auto_disposed_by, auto_disposed_at'
                . ' FROM core_mail_incoming_messages WHERE id = %i',
                $id,
            );
            $this->assertSame(10, (int) $row['docState']);
            $this->assertSame(1, (int) $row['docStateMain']);
            $this->assertNull($row['auto_disposed_by']);
            $this->assertNull($row['auto_disposed_at']);
            // Re-queue analýzy: 10 s aktivním AI profilem, jinak 0.
            $expected = $this->db->fetchRow('SELECT id FROM core_mail_ai_profiles WHERE is_active = 1 LIMIT 1') !== null ? 10 : 0;
            $this->assertSame($expected, (int) $row['analysis_state']);
        }

        $old = $this->db->fetchRow('SELECT docState, auto_disposed_by FROM core_mail_incoming_messages WHERE id = %i', $mOld);
        $this->assertSame(80, (int) $old['docState']);
        $this->assertNotNull($old['auto_disposed_by']);
    }

    public function testRepeatedUndoRestoresNothing(): void
    {
        $ruleId = $this->insertRule(40);
        $this->insertAutoArchivedMessage($ruleId, date('Y-m-d') . ' 08:00:00');

        $first = $this->controller->undoAutoArchive($this->auth(), $this->request([]));
        $this->assertSame(1, $first->getPayload()['data']['restored']);

        $second = $this->controller->undoAutoArchive($this->auth(), $this->request([]));
        $this->assertSame(0, $second->getPayload()['data']['restored']);
    }

    public function testUndoRejectsOldDate(): void
    {
        $response = $this->controller->undoAutoArchive(
            $this->auth(),
            $this->request(['date' => date('Y-m-d', strtotime('-3 days'))]),
        );

        $this->assertResponseStatus(422, $response);
    }

    // --- helpers -------------------------------------------------------------

    private function auth(): AuthContext
    {
        return new AuthContext(true, 1, 'session', 'test-token');
    }

    private function request(array $body): Request
    {
        return Request::fromArray('POST', '/_mail/auto-archive/undo', [], (string) json_encode($body), [
            'HTTP_HOST' => 'test.local',
            'REMOTE_ADDR' => '127.0.0.1',
            'CONTENT_TYPE' => 'application/json',
        ]);
    }

    private function insertRule(int $docState): int
    {
        $now = date('Y-m-d H:i:s');
        $dibi = $this->db->getDibiConnection();
        $dibi->insert('core_mail_sender_rules', [
            'pattern_kind' => 'email',
            'pattern' => self::TEST_SENDER,
            'disposition' => 'archive',
            'origin' => 'suggested',
            'hit_count' => 0,
            'notice' => 'IT: endpoint test',
            'created' => $now,
            'modified' => $now,
            'docState' => $docState,
            'docStateMain' => [10 => 1, 40 => 3, 90 => 5][$docState] ?? 1,
        ])->execute();

        $id = (int) $dibi->getInsertId();
        $this->createdRuleIds[] = $id;
        return $id;
    }

    private function insertAutoArchivedMessage(int $ruleId, string $disposedAt): int
    {
        $now = date('Y-m-d H:i:s');
        $dibi = $this->db->getDibiConnection();
        $dibi->insert('core_mail_incoming_messages', [
            'message_id' => 'MSG-IT-' . uniqid(),
            'mailbox' => $this->mailboxId,
            'subject' => 'IT-SR: auto-archived',
            'sender_email' => self::TEST_SENDER,
            'received_at' => $disposedAt,
            'source_type' => 2,
            'analysis_state' => 0,
            'auto_disposed_by' => $ruleId,
            'auto_disposed_at' => $disposedAt,
            'docState' => 80,
            'docStateMain' => 4,
            'created' => $now,
            'modified' => $now,
        ])->execute();

        $id = (int) $dibi->getInsertId();
        $this->createdMessageIds[] = $id;
        return $id;
    }

    private function assertResponseStatus(int $expected, \Shipard\Api\Response $response): void
    {
        $ref = new \ReflectionClass($response);
        $prop = $ref->getProperty('status');
        $actual = (int) $prop->getValue($response);
        $payload = $response->getPayload();
        $this->assertSame($expected, $actual, 'Unexpected status with payload: ' . json_encode($payload));
    }
}
