<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Mail;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Core\Exchange\Enrich\RowHistoryEnricher;
use Shipard\Module\Core\Exchange\Resolve\PartyResolver;
use Shipard\Module\Core\Exchange\Resolve\ResolveResult;
use Shipard\Module\Core\Exchange\Schema\SchemaLoader;
use Shipard\Module\Core\Exchange\Schema\SchemaValidator;
use Shipard\Module\Core\Mail\ExtractedDocumentDocument;
use Shipard\Module\Core\Mail\ExtractedDocumentStatusResolver;
use Shipard\Module\Core\Mail\IsdocImportService;

/**
 * Deterministický ISDOC import (tasks/mail-isdoc-import.md, krok 3).
 * Reálný IsdocReader + SchemaValidator nad fixtures, mockovaná DB —
 * ověřuje se orchestrace: co se insertne/updatne a kdy se větev vzdá.
 */
class IsdocImportServiceTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../../../../Fixtures/Exchange/isdoc';
    private const MESSAGE_NDX = 42;

    private string $tmpDir;

    /** @var list<array{0: string, 1: array<string, mixed>}> */
    private array $inserts = [];

    /** @var list<array{0: string, 1: array<string, mixed>}> */
    private array $updates = [];

    protected function setUp(): void
    {
        $this->inserts = [];
        $this->updates = [];
        $this->tmpDir = sys_get_temp_dir() . '/shpd_isdocimp_' . bin2hex(random_bytes(6));
        mkdir($this->tmpDir . '/att/m', 0777, true);
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->tmpDir . '/att/m/*') as $file) {
            @unlink((string) $file);
        }
        @rmdir($this->tmpDir . '/att/m');
        @rmdir($this->tmpDir . '/att');
        @rmdir($this->tmpDir);
    }

    // ── Testovací infrastruktura ────────────────────────────────────────────

    /**
     * @return array<string, mixed> Návrat AttachmentService::upload (výřez).
     */
    private function storedAttachment(int $id, string $displayName, string $contents, string $mime): array
    {
        $storedName = "stored_{$id}_" . $displayName;
        file_put_contents($this->tmpDir . '/att/m/' . $storedName, $contents);
        return [
            'id' => $id,
            'name' => $displayName,
            'file_name' => $storedName,
            'file_path' => 'm',
            'mime_type' => $mime,
        ];
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(self::FIXTURES . '/' . $name);
    }

    private function fluent(): \Dibi\Fluent
    {
        $fluent = $this->createMock(\Dibi\Fluent::class);
        $fluent->method('__call')->willReturnSelf();
        return $fluent;
    }

    /**
     * @param array<string, mixed>|null $messageRow Řádek vrácený guard SELECT … FOR UPDATE.
     */
    private function makeDibi(?array $messageRow): \Dibi\Connection&\PHPUnit\Framework\MockObject\MockObject
    {
        $dibi = $this->createMock(\Dibi\Connection::class);
        $dibi->method('fetch')->willReturn($messageRow !== null ? new \Dibi\Row($messageRow) : null);
        $dibi->method('getInsertId')->willReturn(909);
        $dibi->method('insert')->willReturnCallback(function (string $table, array $data): \Dibi\Fluent {
            $this->inserts[] = [$table, $data];
            return $this->fluent();
        });
        $dibi->method('update')->willReturnCallback(function (string $table, array $data): \Dibi\Fluent {
            $this->updates[] = [$table, $data];
            return $this->fluent();
        });
        return $dibi;
    }

    private function makeDb(\Dibi\Connection $dibi): DataSourceConnection
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('getDibiConnection')->willReturn($dibi);
        $db->method('fetchRow')->willReturn(null); // žádný AI profil → default thresholds
        return $db;
    }

    private function service(DataSourceConnection $db, ?RowHistoryEnricher $enricher = null): IsdocImportService
    {
        return new IsdocImportService(
            $db,
            new SchemaValidator(SchemaLoader::default()),
            $enricher,
            new ExtractedDocumentStatusResolver($db),
            $this->tmpDir,
        );
    }

    /**
     * Reálný enricher nad mockovanou DB (final třída) — partner vždy matched.
     *
     * @param list<array<string, mixed>> $history
     */
    private function enricher(array $history): RowHistoryEnricher
    {
        $dibi = $this->createMock(\Dibi\Connection::class);
        $dibi->method('fetchAll')->willReturn(array_map(
            static fn(array $row) => new \Dibi\Row($row),
            $history,
        ));
        $party = $this->createMock(PartyResolver::class);
        $party->method('resolve')->willReturn(ResolveResult::matched(77, 'companyId'));
        return new RowHistoryEnricher($dibi, $party);
    }

    /**
     * @return array<string, mixed> Výchozí message row pro guard SELECT.
     */
    private function messageRow(array $overrides = []): array
    {
        return array_merge([
            'analysis_state' => 10,
            'docState' => 10,
            'primary_type_source' => 'mailbox',
            'created_by' => 5,
        ], $overrides);
    }

    // ── Detekce kandidátů ───────────────────────────────────────────────────

    public function testDetectionPositive(): void
    {
        $this->assertTrue(IsdocImportService::isPotentialIsdocAttachment(
            ['name' => 'FAKTURA.ISDOC', 'mime_type' => 'application/octet-stream'],
        ));
        $this->assertTrue(IsdocImportService::isPotentialIsdocAttachment(
            ['name' => 'balik.IsdocX', 'mime_type' => 'application/zip'],
        ));
        $this->assertTrue(IsdocImportService::isPotentialIsdocAttachment(
            ['name' => 'data.xml', 'mime_type' => 'text/xml'],
        ));
        $this->assertTrue(IsdocImportService::isPotentialIsdocAttachment(
            ['name' => 'data.xml', 'mime_type' => 'application/xml'],
        ));
    }

    public function testDetectionNegative(): void
    {
        $this->assertFalse(IsdocImportService::isPotentialIsdocAttachment(
            ['name' => 'faktura.pdf', 'mime_type' => 'application/pdf'],
        ));
        $this->assertFalse(IsdocImportService::isPotentialIsdocAttachment(
            ['name' => 'message.eml', 'mime_type' => 'message/rfc822'],
        ));
        $this->assertFalse(IsdocImportService::isPotentialIsdocAttachment([]));
    }

    // ── Úspěšný import ──────────────────────────────────────────────────────

    public function testSuccessfulImportWritesAnalysisExtractedAndStates(): void
    {
        $files = [
            $this->storedAttachment(500, 'faktura.pdf', '%PDF-fake', 'application/pdf'),
            $this->storedAttachment(501, 'faktura.isdoc', $this->fixture('invoice_min.isdoc'), 'application/xml'),
        ];

        $dibi = $this->makeDibi($this->messageRow());
        $dibi->expects($this->once())->method('begin');
        $dibi->expects($this->once())->method('commit');
        $dibi->expects($this->never())->method('rollback');

        $result = $this->service($this->makeDb($dibi))->tryImport(self::MESSAGE_NDX, $files);

        $this->assertTrue($result);
        $this->assertCount(2, $this->inserts);

        [$analysesTable, $analysis] = $this->inserts[0];
        $this->assertSame('core_mail_message_analyses', $analysesTable);
        $this->assertSame(self::MESSAGE_NDX, $analysis['message']);
        $this->assertSame(2, $analysis['status']);
        $this->assertSame('isdoc', $analysis['model_name']);
        $this->assertSame('6.0.1', $analysis['model_version']);
        $this->assertSame('isdoc', $analysis['prompt_version']);
        $this->assertSame(1.0, $analysis['confidence']);
        $this->assertNull($analysis['profile']);
        $this->assertNull($analysis['backend']);
        $this->assertArrayNotHasKey('cost_usd', $analysis);
        $this->assertArrayNotHasKey('tokens_input', $analysis);
        $this->assertSame(1, $analysis['extracted_document_count']);
        $this->assertSame(5, $analysis['created_by']);

        [$extractedTable, $extracted] = $this->inserts[1];
        $this->assertSame('core_mail_extracted_documents', $extractedTable);
        $this->assertSame(self::MESSAGE_NDX, $extracted['message']);
        $this->assertSame(909, $extracted['analysis']);
        $this->assertSame('invoiceReceived', $extracted['doc_type']);
        $this->assertSame('[501]', $extracted['source_attachments']);
        $this->assertSame(1.0, $extracted['confidence']);
        // Bez enrichmentu nemá řádek ourCode → strop D7 sráží na pending_review
        $this->assertSame(ExtractedDocumentDocument::STATUS_PENDING_REVIEW, $extracted['status']);

        $canonical = json_decode((string) $extracted['extracted_json'], true);
        $this->assertSame('isdoc', $canonical['source']['kind']);
        $this->assertSame(self::MESSAGE_NDX, $canonical['source']['mailMessage']);
        $this->assertSame('FV-2026-0042', $canonical['docNumber']);
        $this->assertSame('att:501', $canonical['attachments'][0]['ref']);
        $this->assertSame('faktura.isdoc', $canonical['attachments'][0]['filename']);
        $this->assertSame('structured', $canonical['attachments'][0]['kind']);

        // 1) analysis_state → 30, 2) primary_type isdoc, 3) docState 10 → 20
        $this->assertCount(3, $this->updates);
        $this->assertSame('core_mail_incoming_messages', $this->updates[0][0]);
        $this->assertSame(30, $this->updates[0][1]['analysis_state']);
        $this->assertSame(0, $this->updates[0][1]['needs_reanalysis']);
        $this->assertSame(
            ['primary_type' => 'invoiceReceived', 'primary_type_source' => 'isdoc'],
            $this->updates[1][1],
        );
        $this->assertSame(['docState' => 20, 'docStateMain' => 2], $this->updates[2][1]);
    }

    public function testEnrichmentFillsOurCodeAndKeepsReadyToApply(): void
    {
        $files = [
            $this->storedAttachment(501, 'faktura.isdoc', $this->fixture('invoice_min.isdoc'), 'application/xml'),
        ];
        $enricher = $this->enricher([[
            'description' => 'Testovací služba',
            'vat_code' => 'cz-110',
            'doc_head' => 1234,
            'doc_number' => 'FPB-0001',
            'item_code' => 'SRV-TEST',
            'account_number' => null,
        ]]);

        $dibi = $this->makeDibi($this->messageRow());
        $result = $this->service($this->makeDb($dibi), $enricher)->tryImport(self::MESSAGE_NDX, $files);

        $this->assertTrue($result);
        $extracted = $this->inserts[1][1];
        $this->assertSame(ExtractedDocumentDocument::STATUS_READY_TO_APPLY, $extracted['status']);

        $canonical = json_decode((string) $extracted['extracted_json'], true);
        $this->assertSame('SRV-TEST', $canonical['rows'][0]['item']['ourCode']);
        $this->assertSame('cz-110', $canonical['rows'][0]['vat']['code']);
    }

    public function testCreditNoteProducesCreditNoteExtracted(): void
    {
        $files = [
            $this->storedAttachment(502, 'dobropis.isdoc', $this->fixture('credit_note.isdoc'), 'application/xml'),
        ];

        $dibi = $this->makeDibi($this->messageRow());
        $result = $this->service($this->makeDb($dibi))->tryImport(self::MESSAGE_NDX, $files);

        $this->assertTrue($result);
        $this->assertSame('creditNote', $this->inserts[1][1]['doc_type']);
    }

    // ── Guard a ochrana ručních zásahů ──────────────────────────────────────

    public function testGuardSkipsWhenAnalyzerClaimedMeanwhile(): void
    {
        $files = [
            $this->storedAttachment(501, 'faktura.isdoc', $this->fixture('invoice_min.isdoc'), 'application/xml'),
        ];

        $dibi = $this->makeDibi($this->messageRow(['analysis_state' => 20]));
        $dibi->expects($this->once())->method('begin');
        $dibi->expects($this->once())->method('rollback');
        $dibi->expects($this->never())->method('commit');

        $result = $this->service($this->makeDb($dibi))->tryImport(self::MESSAGE_NDX, $files);

        $this->assertFalse($result);
        $this->assertSame([], $this->inserts);
        $this->assertSame([], $this->updates);
    }

    public function testGuardSkipsWhenMessageMissing(): void
    {
        $files = [
            $this->storedAttachment(501, 'faktura.isdoc', $this->fixture('invoice_min.isdoc'), 'application/xml'),
        ];

        $dibi = $this->makeDibi(null);
        $dibi->expects($this->once())->method('rollback');

        $this->assertFalse($this->service($this->makeDb($dibi))->tryImport(self::MESSAGE_NDX, $files));
        $this->assertSame([], $this->inserts);
    }

    public function testUserPrimaryTypeSourceIsNotOverwritten(): void
    {
        $files = [
            $this->storedAttachment(501, 'faktura.isdoc', $this->fixture('invoice_min.isdoc'), 'application/xml'),
        ];

        $dibi = $this->makeDibi($this->messageRow(['primary_type_source' => 'user']));
        $result = $this->service($this->makeDb($dibi))->tryImport(self::MESSAGE_NDX, $files);

        $this->assertTrue($result);
        foreach ($this->updates as [, $data]) {
            $this->assertArrayNotHasKey('primary_type', $data);
        }
    }

    public function testDocStateOutsideNewIsLeftAlone(): void
    {
        $files = [
            $this->storedAttachment(501, 'faktura.isdoc', $this->fixture('invoice_min.isdoc'), 'application/xml'),
        ];

        $dibi = $this->makeDibi($this->messageRow(['docState' => 40]));
        $result = $this->service($this->makeDb($dibi))->tryImport(self::MESSAGE_NDX, $files);

        $this->assertTrue($result);
        foreach ($this->updates as [, $data]) {
            $this->assertArrayNotHasKey('docState', $data);
        }
    }

    // ── Vzdání se větve → AI fronta beze změny ──────────────────────────────

    public function testNoCandidateDoesNothing(): void
    {
        $files = [
            $this->storedAttachment(500, 'faktura.pdf', '%PDF-fake', 'application/pdf'),
        ];

        $dibi = $this->makeDibi($this->messageRow());
        $dibi->expects($this->never())->method('begin');

        $this->assertFalse($this->service($this->makeDb($dibi))->tryImport(self::MESSAGE_NDX, $files));
        $this->assertSame([], $this->inserts);
        $this->assertSame([], $this->updates);
    }

    public function testMalformedIsdocAbortsWholeBranch(): void
    {
        $files = [
            $this->storedAttachment(501, 'ok.isdoc', $this->fixture('invoice_min.isdoc'), 'application/xml'),
            $this->storedAttachment(502, 'vadna.isdoc', '<Invoice xmlns="http://isdoc.cz/n"><ID>1', 'application/xml'),
        ];

        $dibi = $this->makeDibi($this->messageRow());
        $dibi->expects($this->never())->method('begin');

        $this->assertFalse($this->service($this->makeDb($dibi))->tryImport(self::MESSAGE_NDX, $files));
        $this->assertSame([], $this->inserts);
    }

    public function testUnsupportedDocumentTypeAbortsWholeBranch(): void
    {
        $files = [
            $this->storedAttachment(501, 'zaloha.isdoc', $this->fixture('doctype4.isdoc'), 'application/xml'),
        ];

        $dibi = $this->makeDibi($this->messageRow());
        $dibi->expects($this->never())->method('begin');

        $this->assertFalse($this->service($this->makeDb($dibi))->tryImport(self::MESSAGE_NDX, $files));
    }

    public function testForeignXmlAttachmentIsSilentlySkipped(): void
    {
        // XML bez ISDOC přípony s cizím rootem není kandidát — bez importu,
        // ale taky bez logovaného selhání větve.
        $files = [
            $this->storedAttachment(503, 'objednavka.xml', $this->fixture('foreign_root.xml'), 'text/xml'),
        ];

        $dibi = $this->makeDibi($this->messageRow());
        $dibi->expects($this->never())->method('begin');

        $this->assertFalse($this->service($this->makeDb($dibi))->tryImport(self::MESSAGE_NDX, $files));
    }

    public function testForeignXmlNextToValidIsdocDoesNotAbort(): void
    {
        $files = [
            $this->storedAttachment(503, 'objednavka.xml', $this->fixture('foreign_root.xml'), 'text/xml'),
            $this->storedAttachment(501, 'faktura.isdoc', $this->fixture('invoice_min.isdoc'), 'application/xml'),
        ];

        $dibi = $this->makeDibi($this->messageRow());
        $result = $this->service($this->makeDb($dibi))->tryImport(self::MESSAGE_NDX, $files);

        $this->assertTrue($result);
        $this->assertSame('[501]', $this->inserts[1][1]['source_attachments']);
    }

    public function testTwoIsdocAttachmentsProduceTwoExtractedDocuments(): void
    {
        $files = [
            $this->storedAttachment(501, 'faktura.isdoc', $this->fixture('invoice_min.isdoc'), 'application/xml'),
            $this->storedAttachment(502, 'dobropis.isdoc', $this->fixture('credit_note.isdoc'), 'application/xml'),
        ];

        $dibi = $this->makeDibi($this->messageRow());
        $result = $this->service($this->makeDb($dibi))->tryImport(self::MESSAGE_NDX, $files);

        $this->assertTrue($result);
        $this->assertCount(3, $this->inserts); // 1× analysis + 2× extracted
        $this->assertSame(2, $this->inserts[0][1]['extracted_document_count']);
        $this->assertSame('invoiceReceived', $this->inserts[1][1]['doc_type']);
        $this->assertSame('creditNote', $this->inserts[2][1]['doc_type']);
    }

    public function testExceptionDuringWriteRollsBackAndReturnsFalse(): void
    {
        $files = [
            $this->storedAttachment(501, 'faktura.isdoc', $this->fixture('invoice_min.isdoc'), 'application/xml'),
        ];

        $dibi = $this->createMock(\Dibi\Connection::class);
        $dibi->method('fetch')->willReturn(new \Dibi\Row($this->messageRow()));
        $dibi->method('insert')->willThrowException(new \RuntimeException('DB down'));
        $dibi->expects($this->once())->method('rollback');

        $this->assertFalse($this->service($this->makeDb($dibi))->tryImport(self::MESSAGE_NDX, $files));
    }
}
