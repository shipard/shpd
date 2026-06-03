<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\Core;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Docs\Core\DocsHeadsViewer;

/**
 * Tab "Přílohy" v detailu dokladu — agregace příloh zpráv došlé pošty,
 * ze kterých doklad vznikl (vazba message.target_table_id + target_row).
 */
class DocsHeadsViewerSourceAttachmentsTest extends TestCase
{
    /**
     * @param list<array<string, mixed>> $messages
     * @param array<int, list<array<string, mixed>>> $filesByMessage  message.id → soubory
     * @param list<array<string, mixed>> $attachmentQueries  zachycené dotazy na core_attachments_files (out)
     */
    private function makeViewer(
        array $messages,
        array $filesByMessage,
        array &$attachmentQueries = [],
    ): DocsHeadsViewer {
        $db = $this->createMock(DataSourceConnection::class);

        $db->method('fetchRow')->willReturn([
            'id'        => 7,
            'doc_type'  => 'invni',
            'doc_number' => 'FP-1',
            'docState'  => 40,
        ]);

        $db->method('fetchAll')->willReturnCallback(
            function (string $sql, ...$params) use ($messages, $filesByMessage, &$attachmentQueries): array {
                if (str_contains($sql, 'core_mail_incoming_messages')) {
                    return $messages;
                }
                if (str_contains($sql, 'core_attachments_files')) {
                    $attachmentQueries[] = ['sql' => $sql, 'params' => $params];
                    $recordId = (int) ($params[1] ?? 0); // (table_id=303, record_id, [rawId])
                    return $filesByMessage[$recordId] ?? [];
                }
                return [];
            },
        );

        return new DocsHeadsViewer($db, 'docs_core_heads');
    }

    private function findTab(array $detail, string $id): ?array
    {
        foreach ($detail['tabs'] ?? [] as $tab) {
            if (($tab['id'] ?? null) === $id) {
                return $tab;
            }
        }
        return null;
    }

    public function testNoSourceMessagesYieldsNoAttachmentsTab(): void
    {
        $viewer = $this->makeViewer([], []);
        $detail = $viewer->renderDetail(7);

        $this->assertNotNull($this->findTab($detail, 'overview'));
        $this->assertNull($this->findTab($detail, 'sourceAttachments'));
    }

    public function testMessageWithoutAttachmentsIsSkipped(): void
    {
        $messages = [
            ['id' => 11, 'message_id' => 'MSG-A', 'received_at' => '2026-06-01 10:00:00', 'raw_source_attachment' => null],
        ];
        $viewer = $this->makeViewer($messages, [11 => []]);
        $detail = $viewer->renderDetail(7);

        $this->assertNull($this->findTab($detail, 'sourceAttachments'));
    }

    public function testAggregatesAttachmentsGroupedPerMessageWithCount(): void
    {
        $messages = [
            ['id' => 11, 'message_id' => 'MSG-A', 'received_at' => '2026-06-01 10:00:00', 'raw_source_attachment' => null],
            ['id' => 12, 'message_id' => 'MSG-B', 'received_at' => '2026-06-02 09:00:00', 'raw_source_attachment' => null],
        ];
        $filesByMessage = [
            11 => [
                ['id' => 101, 'name' => 'faktura.pdf', 'file_name' => 'faktura.pdf', 'file_size' => 2048, 'mime_type' => 'application/pdf'],
            ],
            12 => [
                ['id' => 201, 'name' => 'sken.jpg', 'file_name' => 'sken.jpg', 'file_size' => 1024, 'mime_type' => 'image/jpeg'],
                ['id' => 202, 'name' => 'priloha.pdf', 'file_name' => 'priloha.pdf', 'file_size' => 512, 'mime_type' => 'application/pdf'],
            ],
        ];

        $viewer = $this->makeViewer($messages, $filesByMessage);
        $detail = $viewer->renderDetail(7);

        $tab = $this->findTab($detail, 'sourceAttachments');
        $this->assertNotNull($tab);

        // Label nese celkový počet příloh (1 + 2 = 3).
        $this->assertStringContainsString('(3)', $tab['label']);

        $content = $tab['content'];
        $this->assertSame('attachments', $content['type']);
        $this->assertSame('core.mail.incoming', $content['sourceViewerId']);
        $this->assertCount(2, $content['groups']);

        $this->assertSame('MSG-A', $content['groups'][0]['message_id']);
        $this->assertSame(11, $content['groups'][0]['message_ndx']);
        $this->assertCount(1, $content['groups'][0]['attachments']);
        $this->assertSame(
            ['id' => 101, 'name' => 'faktura.pdf', 'mime_type' => 'application/pdf', 'file_size' => 2048],
            $content['groups'][0]['attachments'][0],
        );

        $this->assertSame('MSG-B', $content['groups'][1]['message_id']);
        $this->assertCount(2, $content['groups'][1]['attachments']);
    }

    public function testRawEmlIsExcludedFromAttachmentQuery(): void
    {
        $messages = [
            ['id' => 11, 'message_id' => 'MSG-A', 'received_at' => '2026-06-01 10:00:00', 'raw_source_attachment' => 99],
        ];
        // Po vyloučení rawId zbude jedna obsahová příloha.
        $filesByMessage = [
            11 => [
                ['id' => 101, 'name' => 'faktura.pdf', 'file_name' => 'faktura.pdf', 'file_size' => 2048, 'mime_type' => 'application/pdf'],
            ],
        ];

        $attachmentQueries = [];
        $viewer = $this->makeViewer($messages, $filesByMessage, $attachmentQueries);
        $viewer->renderDetail(7);

        $this->assertCount(1, $attachmentQueries);
        $this->assertStringContainsString('AND `id` != %i', $attachmentQueries[0]['sql']);
        $this->assertContains(99, $attachmentQueries[0]['params']);
    }

    public function testMessageWithOnlyRawEmlIsSkipped(): void
    {
        // Zpráva má jen .eml originál → po vyloučení 0 obsahových příloh → skupina vynechána.
        $messages = [
            ['id' => 11, 'message_id' => 'MSG-A', 'received_at' => '2026-06-01 10:00:00', 'raw_source_attachment' => 99],
        ];
        $viewer = $this->makeViewer($messages, [11 => []]);
        $detail = $viewer->renderDetail(7);

        $this->assertNull($this->findTab($detail, 'sourceAttachments'));
    }
}
