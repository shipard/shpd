<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Base\Registry;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Base\Registry\ExtractedTextFiller;
use Shipard\Module\Core\Attachments\AttachmentService;
use Shipard\Module\Core\Attachments\TextExtractor;

class ExtractedTextFillerTest extends TestCase
{
    /** @var array<int, mixed>|null zachycený payload updatu extracted_text */
    private ?array $updatePayload = null;
    private int $updateCalls = 0;

    /**
     * @param array<int, array<string, mixed>> $attachmentRows
     * @param array<int, ?string> $extractedTexts per příloha (v pořadí)
     */
    private function makeFiller(array $attachmentRows, array $extractedTexts): ExtractedTextFiller
    {
        $this->updatePayload = null;
        $this->updateCalls = 0;

        $fluent = $this->createMock(\Dibi\Fluent::class);
        $fluent->method('__call')->willReturnCallback(fn () => $fluent);
        $fluent->method('execute')->willReturnCallback(function () {
            $this->updateCalls++;
            return null;
        });

        $dibi = $this->createMock(\Dibi\Connection::class);
        $dibi->method('update')->willReturnCallback(
            function (string $table, array $payload) use ($fluent) {
                $this->updatePayload = [$table, $payload];
                return $fluent;
            },
        );

        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturn($attachmentRows);
        $db->method('getDibiConnection')->willReturn($dibi);

        $attachments = $this->createMock(AttachmentService::class);
        $attachments->method('getFilePath')->willReturn('/tmp/x');

        $extractor = $this->createMock(TextExtractor::class);
        $extractor->method('extract')->willReturnOnConsecutiveCalls(...$extractedTexts);

        return new ExtractedTextFiller($db, $attachments, $extractor);
    }

    /** @return array<string, mixed> */
    private function attRow(string $name): array
    {
        return ['file_path' => 'att/ab', 'file_name' => $name, 'mime_type' => 'application/pdf'];
    }

    public function testConcatenatesWithBlankLineSeparator(): void
    {
        $filler = $this->makeFiller([$this->attRow('a.pdf'), $this->attRow('b.pdf')], ['Alpha', 'Beta']);
        $result = $filler->fill(5);

        $this->assertSame(['chars' => 11, 'attachments' => 2], $result);
        [$table, $payload] = $this->updatePayload;
        $this->assertSame('base_registry_documents', $table);
        $this->assertSame("Alpha\n\nBeta", $payload['extracted_text']);
    }

    public function testCapsJoinedTextAtMaxLength(): void
    {
        $chunk = str_repeat('x', 300_000);
        $filler = $this->makeFiller([$this->attRow('a.pdf'), $this->attRow('b.pdf')], [$chunk, $chunk]);
        $result = $filler->fill(5);

        $this->assertSame(TextExtractor::MAX_LENGTH, $result['chars']);
        $this->assertSame(TextExtractor::MAX_LENGTH, mb_strlen($this->updatePayload[1]['extracted_text']));
    }

    public function testSkipsAttachmentsWithFailedExtraction(): void
    {
        $filler = $this->makeFiller([$this->attRow('scan.pdf'), $this->attRow('b.pdf')], [null, 'Beta']);
        $result = $filler->fill(5);

        $this->assertSame(['chars' => 4, 'attachments' => 2], $result);
        $this->assertSame('Beta', $this->updatePayload[1]['extracted_text']);
    }

    public function testNoExtractableTextLeavesColumnUntouched(): void
    {
        $filler = $this->makeFiller([$this->attRow('scan.pdf')], [null]);
        $result = $filler->fill(5);

        $this->assertSame(['chars' => 0, 'attachments' => 1], $result);
        $this->assertSame(0, $this->updateCalls, 'best-effort: neúspěšná extrakce nesmí smazat stávající text');
    }

    public function testNoAttachmentsByDefaultLeavesColumnUntouched(): void
    {
        $filler = $this->makeFiller([], []);
        $result = $filler->fill(5);

        $this->assertSame(['chars' => 0, 'attachments' => 0], $result);
        $this->assertSame(0, $this->updateCalls);
    }

    public function testNoAttachmentsWithClearFlagClearsColumn(): void
    {
        $filler = $this->makeFiller([], []);
        $result = $filler->fill(5, clearWhenNoAttachments: true);

        $this->assertSame(['chars' => 0, 'attachments' => 0], $result);
        $this->assertSame(1, $this->updateCalls);
        $this->assertNull($this->updatePayload[1]['extracted_text']);
    }

    public function testSwallowsExceptions(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willThrowException(new \RuntimeException('DB gone'));

        $filler = new ExtractedTextFiller(
            $db,
            $this->createMock(AttachmentService::class),
            $this->createMock(TextExtractor::class),
        );

        $this->assertSame(['chars' => 0, 'attachments' => 0], $filler->fill(5));
    }
}
