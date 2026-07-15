<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Mail;

use Dibi\Connection;
use Dibi\Row;
use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Mail\SenderRuleSuggestionHandler;

/**
 * Testable handler — Connection::query je final, executeSql se přepisuje
 * subclassingem (vzor TestableSupplierCodeCaptureHandler).
 */
class TestableSenderRuleSuggestionHandler extends SenderRuleSuggestionHandler
{
    /** @var list<array> */
    public array $sqlCalls = [];

    protected function executeSql(mixed ...$args): void
    {
        $this->sqlCalls[] = $args;
    }
}

class SenderRuleSuggestionHandlerTest extends TestCase
{
    /**
     * @param array<string, mixed>|null $message  Řádek zprávy (sender_email, auto_disposed_by)
     * @param int $manualCount                    COUNT ručních odklizení
     * @param bool $liveRuleExists                Existuje živé pravidlo pro e-mail/doménu
     */
    private function handler(
        ?array $message,
        int $manualCount = 0,
        bool $liveRuleExists = false,
    ): TestableSenderRuleSuggestionHandler {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturnCallback(
            static function (string $sql) use ($message, $manualCount, $liveRuleExists): ?Row {
                if (str_contains($sql, 'COUNT(*)')) {
                    return new Row(['cnt' => $manualCount]);
                }
                if (str_contains($sql, 'core_mail_sender_rules')) {
                    return $liveRuleExists ? new Row(['id' => 99]) : null;
                }
                return $message !== null ? new Row($message) : null;
            },
        );

        $handler = new TestableSenderRuleSuggestionHandler();
        $handler->setDb($db);
        return $handler;
    }

    public function testThresholdReachedInsertsDraftSuggestion(): void
    {
        $handler = $this->handler(
            ['sender_email' => 'News@Example.com', 'auto_disposed_by' => null],
            manualCount: 3,
        );

        $handler->onStateChanged('core_mail_incoming_messages', ['id' => 5], 10, 90);

        $this->assertCount(1, $handler->sqlCalls);
        $data = $handler->sqlCalls[0][1];
        $this->assertSame('news@example.com', $data['pattern']);
        $this->assertSame('email', $data['pattern_kind']);
        $this->assertSame('archive', $data['disposition']);
        $this->assertSame('suggested', $data['origin']);
        $this->assertSame(10, $data['docState']);
        $this->assertNotEmpty($data['notice']);
        $this->assertArrayHasKey('created', $data);
    }

    public function testBelowThresholdDoesNothing(): void
    {
        $handler = $this->handler(
            ['sender_email' => 'news@example.com', 'auto_disposed_by' => null],
            manualCount: 2,
        );

        $handler->onStateChanged('core_mail_incoming_messages', ['id' => 5], 10, 80);

        $this->assertSame([], $handler->sqlCalls);
    }

    public function testExistingLiveRuleBlocksDuplicateSuggestion(): void
    {
        $handler = $this->handler(
            ['sender_email' => 'news@example.com', 'auto_disposed_by' => null],
            manualCount: 5,
            liveRuleExists: true,
        );

        $handler->onStateChanged('core_mail_incoming_messages', ['id' => 5], 10, 90);

        $this->assertSame([], $handler->sqlCalls);
    }

    public function testAutoDisposedMessageIsIgnored(): void
    {
        $handler = $this->handler(
            ['sender_email' => 'news@example.com', 'auto_disposed_by' => 3],
            manualCount: 10,
        );

        $handler->onStateChanged('core_mail_incoming_messages', ['id' => 5], 10, 80);

        $this->assertSame([], $handler->sqlCalls);
    }

    public function testEmptySenderEmailIsIgnored(): void
    {
        $handler = $this->handler(
            ['sender_email' => '', 'auto_disposed_by' => null],
            manualCount: 10,
        );

        $handler->onStateChanged('core_mail_incoming_messages', ['id' => 5], 10, 90);

        $this->assertSame([], $handler->sqlCalls);
    }

    public function testTransitionsOutsideDisposalAreIgnored(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->never())->method('fetch');

        $handler = new TestableSenderRuleSuggestionHandler();
        $handler->setDb($db);

        $handler->onStateChanged('core_mail_incoming_messages', ['id' => 5], 10, 20);
        $handler->onStateChanged('core_mail_incoming_messages', ['id' => 5], 80, 10);
        $handler->onStateChanged('core_mail_incoming_messages', ['id' => 5], 20, 40);

        $this->assertSame([], $handler->sqlCalls);
    }

    public function testMissingMessageRowDoesNothing(): void
    {
        $handler = $this->handler(null, manualCount: 10);

        $handler->onStateChanged('core_mail_incoming_messages', ['id' => 5], 10, 90);

        $this->assertSame([], $handler->sqlCalls);
    }
}
