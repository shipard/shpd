<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Mail\Feed;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Feed\FeedContext;
use Shipard\Module\Core\Mail\Feed\MailSuggestionsSource;

/**
 * Unit testy pro MailSuggestionsSource.
 *
 * Pokrývají:
 *   - stavy 10/20/30 → správný kind, stateStyle a sada akcí
 *   - zpráva analysis_state=70 → urgent karta + reanalyze/open_viewer
 *     (degradace na review při primary_type=other)
 *   - karta „Není faktura" (kind info, akce trash/archive/open_viewer)
 *   - titulek z cfgItem doc typu + partner z canonical, podtitulek
 *     (částka · jistota · e-mail)
 *   - prázdný vstup → []
 */
final class MailSuggestionsSourceTest extends TestCase
{
    /**
     * Sestaví FeedContext s DB mockem, který routuje tři SELECTy zdroje
     * podle tvaru SQL: notInvoice (NOT EXISTS subquery), suggestion
     * (JOIN na extracted_documents), error (zbytek — messages tabulka).
     *
     * @param list<array<string,mixed>> $suggestionRows
     * @param list<array<string,mixed>> $errorRows
     * @param list<array<string,mixed>> $notInvoiceRows
     */
    private function context(
        array $suggestionRows,
        array $errorRows = [],
        ?ConfigRuntime $config = null,
        string $lang = 'cs',
        array $notInvoiceRows = [],
    ): FeedContext {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturnCallback(
            static function (string $sql) use ($suggestionRows, $errorRows, $notInvoiceRows): array {
                if (str_contains($sql, 'NOT EXISTS')) {
                    return $notInvoiceRows;
                }
                return str_contains($sql, 'extracted_documents') ? $suggestionRows : $errorRows;
            },
        );
        return new FeedContext($db, $config, $lang, 30);
    }

    private function docTypesConfig(): ConfigRuntime
    {
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnCallback(
            static fn(string $id): mixed => $id === 'core.mail.extractedDocTypes'
                ? ['invoiceReceived' => ['name' => 'Přijatá faktura']]
                : null,
        );
        return $config;
    }

    /** @return array<string,mixed> */
    private function suggestionRow(int $status, int $ndx = 1): array
    {
        return [
            'extracted_ndx'  => $ndx,
            'message_ndx'    => 100 + $ndx,
            'doc_type'       => 'invoiceReceived',
            'confidence'     => 0.94,
            'status'         => $status,
            'subject'        => 'Faktura 2026000123',
            'sender_name'    => 'ČEZ a.s.',
            'received_at'    => '2026-06-28 10:00:00',
            'extracted_json' => json_encode([
                'selfParty' => 'customer',
                'supplier'  => ['name' => 'ČEZ a.s.'],
                'currency'  => 'CZK',
                'totals'    => ['totalAmount' => 12500.00],
            ]),
        ];
    }

    public function testStatus10ReadyWithApplyReviewReject(): void
    {
        $src = new MailSuggestionsSource();
        $cards = $src->collectCards($this->context([$this->suggestionRow(10)], [], $this->docTypesConfig()));

        $this->assertCount(1, $cards);
        $card = $cards[0];
        $this->assertSame('mail_extracted:1', $card['id']);
        $this->assertSame('mail', $card['source']);
        $this->assertSame('ready', $card['kind']);
        $this->assertSame('done', $card['stateStyle']);
        $this->assertSame('Přijatá faktura — ČEZ a.s.', $card['title']);
        $this->assertStringContainsString('12 500,00 CZK', $card['subtitle']);
        $this->assertStringContainsString('jistota 94 %', $card['subtitle']);
        $this->assertStringContainsString('Faktura 2026000123', $card['subtitle']);
        $this->assertSame('2026-06-28T10:00:00+00:00', $card['timestamp']);

        $actionIds = array_column($card['actions'], 'id');
        $this->assertSame(['apply', 'review', 'reject'], $actionIds);
        $this->assertTrue($card['actions'][0]['primary']);
        $this->assertSame('apply_extracted', $card['actions'][0]['kind']);
        $this->assertSame(['extractedNdx' => 1], $card['actions'][0]['target']);
        $this->assertSame(1, $card['context']['extractedNdx']);
        $this->assertSame(101, $card['context']['messageNdx']);
    }

    public function testStatus20ReviewWithoutApply(): void
    {
        $src = new MailSuggestionsSource();
        $cards = $src->collectCards($this->context([$this->suggestionRow(20)], [], $this->docTypesConfig()));

        $card = $cards[0];
        $this->assertSame('review', $card['kind']);
        $this->assertSame('confirmed', $card['stateStyle']);
        $actionIds = array_column($card['actions'], 'id');
        $this->assertSame(['review', 'reject'], $actionIds);
        $this->assertTrue($card['actions'][0]['primary']);
    }

    public function testStatus30ReviewEditStyle(): void
    {
        $src = new MailSuggestionsSource();
        $cards = $src->collectCards($this->context([$this->suggestionRow(30)], [], $this->docTypesConfig()));

        $card = $cards[0];
        $this->assertSame('review', $card['kind']);
        $this->assertSame('edit', $card['stateStyle']);
        $this->assertSame(['review', 'reject'], array_column($card['actions'], 'id'));
    }

    public function testMessageAiErrorProducesUrgentCard(): void
    {
        $errorRow = [
            'message_ndx' => 555,
            'subject'     => 'Nečitelná faktura',
            'sender_name' => 'Dodavatel s.r.o.',
            'received_at' => '2026-06-27 09:00:00',
        ];
        $src = new MailSuggestionsSource();
        $cards = $src->collectCards($this->context([], [$errorRow]));

        $this->assertCount(1, $cards);
        $card = $cards[0];
        $this->assertSame('mail_message:555', $card['id']);
        $this->assertSame('urgent', $card['kind']);
        $this->assertSame('error', $card['stateStyle']);
        $this->assertStringContainsString('Nečitelná faktura', $card['subtitle']);

        $actions = $card['actions'];
        $this->assertSame('reanalyze', $actions[0]['id']);
        $this->assertSame('reanalyze', $actions[0]['kind']);
        $this->assertSame(['messageNdx' => 555], $actions[0]['target']);
        $this->assertSame('open_viewer', $actions[1]['kind']);
        $this->assertSame('core.mail.incoming', $actions[1]['target']['viewerId']);
        $this->assertSame(555, $actions[1]['target']['recordId']);
    }

    public function testErrorCardDegradesToReviewForOtherPrimaryType(): void
    {
        $errorRow = [
            'message_ndx'  => 556,
            'subject'      => 'Newsletter',
            'sender_name'  => 'Marketing s.r.o.',
            'received_at'  => '2026-06-27 09:00:00',
            'primary_type' => 'other', // klasifikace stihla proběhnout dřív
        ];
        $src = new MailSuggestionsSource();
        $cards = $src->collectCards($this->context([], [$errorRow]));

        $this->assertCount(1, $cards);
        $this->assertSame('review', $cards[0]['kind']);
        $this->assertSame('error', $cards[0]['stateStyle']);
    }

    public function testNotInvoiceCardWithTrashArchiveActions(): void
    {
        $row = [
            'message_ndx'  => 777,
            'subject'      => 'Nabídka spolupráce',
            'sender_name'  => 'Obchodník a.s.',
            'sender_email' => 'obchod@example.com',
            'received_at'  => '2026-06-29 08:00:00',
            'primary_type' => 'other',
        ];
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnCallback(
            static fn(string $id): mixed => $id === 'core.mail.primaryTypes'
                ? ['other' => ['name' => 'Ostatní']]
                : null,
        );
        $src = new MailSuggestionsSource();
        $cards = $src->collectCards($this->context([], [], $config, 'cs', [$row]));

        $this->assertCount(1, $cards);
        $card = $cards[0];
        $this->assertSame('mail_notinvoice:777', $card['id']);
        $this->assertSame('info', $card['kind']);
        $this->assertSame('archive', $card['stateStyle']);
        $this->assertSame('Není faktura — Ostatní', $card['title']);
        $this->assertStringContainsString('Nabídka spolupráce', $card['subtitle']);
        $this->assertStringContainsString('Obchodník a.s.', $card['subtitle']);
        $this->assertSame(777, $card['context']['messageNdx']);

        $actions = $card['actions'];
        $this->assertSame(['trash', 'archive', 'openMail'], array_column($actions, 'id'));
        $this->assertSame('trash_message', $actions[0]['kind']);
        $this->assertTrue($actions[0]['primary']);
        $this->assertSame(['messageNdx' => 777], $actions[0]['target']);
        $this->assertSame('archive_message', $actions[1]['kind']);
        $this->assertSame('open_viewer', $actions[2]['kind']);
        $this->assertSame('core.mail.incoming', $actions[2]['target']['viewerId']);
    }

    public function testSuggestionQueryExcludesOtherDocType(): void
    {
        // Pojistka — WHERE musí filtrovat doc_type='other' už v SQL.
        $captured = null;
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturnCallback(
            static function (string $sql) use (&$captured): array {
                if (str_contains($sql, 'JOIN')) {
                    $captured = $sql;
                }
                return [];
            },
        );
        (new MailSuggestionsSource())->collectCards(new FeedContext($db, null, 'cs', 30));

        $this->assertNotNull($captured);
        $this->assertStringContainsString("`doc_type` != 'other'", $captured);
    }

    public function testEmptyInputReturnsNoCards(): void
    {
        $src = new MailSuggestionsSource();
        $this->assertSame([], $src->collectCards($this->context([], [])));
    }

    public function testDocTypeFallbackWhenConfigMissing(): void
    {
        $src = new MailSuggestionsSource();
        // Bez configu → title padne na holý doc_type key.
        $cards = $src->collectCards($this->context([$this->suggestionRow(10)], [], null));
        $this->assertStringStartsWith('invoiceReceived — ', $cards[0]['title']);
    }

    public function testCounterpartyFollowsSelfParty(): void
    {
        $row = $this->suggestionRow(10);
        $row['extracted_json'] = json_encode([
            'selfParty' => 'supplier',                 // my jsme dodavatel → protistrana customer
            'supplier'  => ['name' => 'Naše firma'],
            'customer'  => ['name' => 'Odběratel a.s.'],
        ]);
        $src = new MailSuggestionsSource();
        $cards = $src->collectCards($this->context([$row], [], $this->docTypesConfig()));
        $this->assertStringEndsWith('— Odběratel a.s.', $cards[0]['title']);
    }
}
