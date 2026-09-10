<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Mail;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Core\Mail\MessageTargetWriter;
use Shipard\Module\Core\Mail\MessageTitleComposer;

/**
 * Fakta z cílového dokladu (tasks/mail-import-partner-title.md D2–D4)
 * a jejich backfill jen do NULL sloupců (D8, P7).
 */
final class MessageTargetWriterTest extends TestCase
{
    private const DOC_ID = 4711;
    private const MESSAGE_NDX = 42;

    /** @var list<array{sql: string, args: list<mixed>}> */
    private array $executed = [];

    protected function setUp(): void
    {
        $this->executed = [];
    }

    // ── infrastruktura ──────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function docRow(): array
    {
        return [
            'doc_type' => 'invni',
            'doc_number' => '2019-0123',
            'total_amount' => '13105.00',
            'doc_currency' => 'CZK',
            'partner' => 55,
            'partner_full_name' => 'Dodavatel s.r.o.',
        ];
    }

    private function config(): ConfigRuntime
    {
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnMap([
            ['docs.core.docTypes', ['invni' => ['name' => 'Faktura přijatá']]],
        ]);
        return $config;
    }

    /**
     * Connection, která na `docs_core_heads` vrátí `$doc`, na
     * `core_mail_incoming_messages` `$message`, a zaznamenává `execute()`.
     *
     * @param array<string, mixed>|null $doc
     * @param array<string, mixed>|null $message
     */
    private function db(?array $doc, ?array $message = null): DataSourceConnection
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturnCallback(
            static function (mixed ...$args) use ($doc, $message): ?array {
                $tables = array_filter($args, 'is_string');
                return in_array('docs_core_heads', $tables, true) ? $doc : $message;
            },
        );
        $db->method('execute')->willReturnCallback(function (mixed ...$args): void {
            $this->executed[] = ['sql' => (string) $args[0], 'args' => array_slice($args, 1)];
        });
        return $db;
    }

    /** @param array<string, mixed>|null $doc */
    private function writer(?array $doc, ?array $message = null): MessageTargetWriter
    {
        return new MessageTargetWriter($this->db($doc, $message), new MessageTitleComposer($this->config()));
    }

    // ── factsFor ────────────────────────────────────────────────────────────

    public function testFactsForDocsTarget(): void
    {
        $this->assertSame([
            'partner_person' => 55,
            'partner_name' => 'Dodavatel s.r.o.',
            'ai_title' => 'Faktura přijatá 2019-0123 — Dodavatel s.r.o., 13 105 CZK',
        ], $this->writer($this->docRow())->factsFor('docs_core_heads', self::DOC_ID));
    }

    public function testFactsForNonDocsTargetsGiveNulls(): void
    {
        $empty = ['partner_person' => null, 'partner_name' => null, 'ai_title' => null];
        $writer = $this->writer($this->docRow());

        // Spisovna: import ji nikdy nevytváří — přeskočí se, není to chyba.
        $this->assertSame($empty, $writer->factsFor('base_registry_documents', self::DOC_ID));
        $this->assertSame($empty, $writer->factsFor(null, self::DOC_ID));
        $this->assertSame($empty, $writer->factsFor('', self::DOC_ID));
        $this->assertSame($empty, $writer->factsFor('docs_core_heads', 0));
        $this->assertSame($empty, $writer->factsFor('docs_core_heads', null));
        // P4: název tabulky je vstup zvenčí.
        $this->assertSame($empty, $writer->factsFor('docs_core_heads; DROP TABLE x', self::DOC_ID));
    }

    public function testFactsForMissingDocumentGivesNulls(): void
    {
        $this->assertSame([
            'partner_person' => null, 'partner_name' => null, 'ai_title' => null,
        ], $this->writer(null)->factsFor('docs_core_heads', self::DOC_ID));
    }

    public function testFactsForDocumentWithoutPartnerStillHasTitle(): void
    {
        $doc = $this->docRow();
        $doc['partner'] = null;
        $doc['partner_full_name'] = null;

        $this->assertSame([
            'partner_person' => null,
            'partner_name' => null,
            'ai_title' => 'Faktura přijatá 2019-0123 — 13 105 CZK',
        ], $this->writer($doc)->factsFor('docs_core_heads', self::DOC_ID));
    }

    public function testFactsForSurvivesDbFailure(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willThrowException(new \RuntimeException('DB down'));
        $writer = new MessageTargetWriter($db, new MessageTitleComposer(null));

        $this->assertSame([
            'partner_person' => null, 'partner_name' => null, 'ai_title' => null,
        ], $writer->factsFor('docs_core_heads', self::DOC_ID));
    }

    // ── backfill ────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function messageRow(array $overrides = []): array
    {
        return $overrides + [
            'id' => self::MESSAGE_NDX,
            'target_table_id' => 'docs_core_heads',
            'target_row' => self::DOC_ID,
            'partner_person' => null,
            'partner_name' => null,
            'ai_title' => null,
        ];
    }

    public function testBackfillFillsAllEmptyColumns(): void
    {
        $this->assertTrue($this->writer($this->docRow())->backfillRow($this->messageRow()));
        $this->assertCount(1, $this->executed);
        $this->assertSame([
            'partner_person' => 55,
            'partner_name' => 'Dodavatel s.r.o.',
            'ai_title' => 'Faktura přijatá 2019-0123 — Dodavatel s.r.o., 13 105 CZK',
        ], $this->executed[0]['args'][1]);
        $this->assertSame(self::MESSAGE_NDX, $this->executed[0]['args'][2]);
    }

    public function testBackfillKeepsManuallyChosenPartnerAndExistingTitle(): void
    {
        $message = $this->messageRow(['partner_person' => 99, 'ai_title' => 'Ruční titulek']);

        $this->assertTrue($this->writer($this->docRow())->backfillRow($message));
        $this->assertSame(['partner_name' => 'Dodavatel s.r.o.'], $this->executed[0]['args'][1]);
    }

    public function testBackfillIsIdempotent(): void
    {
        $filled = $this->messageRow([
            'partner_person' => 55,
            'partner_name' => 'Dodavatel s.r.o.',
            'ai_title' => 'Faktura přijatá 2019-0123 — Dodavatel s.r.o., 13 105 CZK',
        ]);

        $this->assertFalse($this->writer($this->docRow())->backfillRow($filled));
        $this->assertSame([], $this->executed);
    }

    public function testBackfillSkipsMessagesWithoutTarget(): void
    {
        $writer = $this->writer($this->docRow());

        $this->assertFalse($writer->backfillRow($this->messageRow(['target_row' => null, 'target_table_id' => null])));
        $this->assertFalse($writer->backfillRow($this->messageRow(['id' => 0])));
        $this->assertSame([], $this->executed);
    }

    public function testBackfillByIdLoadsMessageRow(): void
    {
        $writer = $this->writer($this->docRow(), $this->messageRow());

        $this->assertTrue($writer->backfill(self::MESSAGE_NDX));
        $this->assertCount(1, $this->executed);
    }

    public function testBackfillByIdOnMissingMessageDoesNothing(): void
    {
        $this->assertFalse($this->writer($this->docRow(), null)->backfill(self::MESSAGE_NDX));
        $this->assertSame([], $this->executed);
    }
}
