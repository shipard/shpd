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
 *   - zpráva analysis_state=70 → urgent karta + reanalyze/open_form
 *     (degradace na review při primary_type=other)
 *   - karta „Není faktura" (kind info, akce trash/archive/open_form)
 *   - strukturovaná hlavička `headline` (partner/typ/částka) + `confidencePct`
 *     + `emailSubject` + `details`; fallback na title/subtitle bez partnera
 *   - přílohy karet: struktura, řazení, vyloučení raw .eml, filtr dle
 *     source_attachments + fallback, strop 3 + attachmentsTotal
 *   - prázdný vstup → []
 */
final class MailSuggestionsSourceTest extends TestCase
{
    /**
     * Sestaví FeedContext s DB mockem, který routuje SELECTy zdroje podle
     * tvaru SQL: batch příloh (core_attachments_files), notInvoice
     * (NOT EXISTS subquery), suggestion (JOIN na extracted_documents),
     * error (zbytek — messages tabulka).
     *
     * @param list<array<string,mixed>> $suggestionRows
     * @param list<array<string,mixed>> $errorRows
     * @param list<array<string,mixed>> $notInvoiceRows
     * @param list<array<string,mixed>> $attachmentRows
     */
    private function context(
        array $suggestionRows,
        array $errorRows = [],
        ?ConfigRuntime $config = null,
        string $lang = 'cs',
        array $notInvoiceRows = [],
        array $attachmentRows = [],
    ): FeedContext {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturnCallback(
            static function (string $sql) use ($suggestionRows, $errorRows, $notInvoiceRows, $attachmentRows): array {
                if (str_contains($sql, 'core_attachments_files')) {
                    return $attachmentRows;
                }
                if (str_contains($sql, 'NOT EXISTS')) {
                    return $notInvoiceRows;
                }
                return str_contains($sql, 'extracted_documents') ? $suggestionRows : $errorRows;
            },
        );
        return new FeedContext($db, $config, $lang, 30);
    }

    /** @return array<string,mixed> */
    private function attachmentRow(
        int $id,
        int $recordId,
        string $name,
        string $mime = 'application/pdf',
        int $size = 245760,
    ): array {
        return [
            'id'        => $id,
            'record_id' => $recordId,
            'name'      => $name,
            'file_name' => $name,
            'mime_type' => $mime,
            'file_size' => $size,
        ];
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
        $this->assertSame('invoices', $card['category']);
        $this->assertSame('Přijatá faktura — ČEZ a.s.', $card['title']);
        // Karta s partnerem nese strukturovanou hlavičku; subtitle se neposílá.
        $this->assertSame(
            ['partnerName' => 'ČEZ a.s.', 'typeLabel' => 'Přijatá faktura', 'amountText' => '12 500,00 CZK'],
            $card['headline'],
        );
        $this->assertSame(94, $card['confidencePct']);
        $this->assertSame('Faktura 2026000123', $card['emailSubject']);
        $this->assertArrayNotHasKey('subtitle', $card);
        // Default canonical nemá docNumber/dueDate/paymentReference → bez details.
        $this->assertArrayNotHasKey('details', $card);
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
        $this->assertSame('other', $card['category']);
        // Předmět jde strukturovaně; subtitle nese odesílatele (bez duplikace).
        $this->assertSame('Dodavatel s.r.o.', $card['subtitle']);
        $this->assertSame('Nečitelná faktura', $card['emailSubject']);
        $this->assertArrayNotHasKey('headline', $card);

        $actions = $card['actions'];
        $this->assertSame('reanalyze', $actions[0]['id']);
        $this->assertSame('reanalyze', $actions[0]['kind']);
        $this->assertSame(['messageNdx' => 555], $actions[0]['target']);
        $this->assertSame('open_form', $actions[1]['kind']);
        $this->assertSame('core_mail_incoming_messages', $actions[1]['target']['table']);
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
        $this->assertSame('other', $card['category']);
        $this->assertSame('Není faktura — Ostatní', $card['title']);
        // Subtitle jen odesílatel; předmět jde strukturovaně v emailSubject.
        $this->assertSame('Obchodník a.s.', $card['subtitle']);
        $this->assertSame('Nabídka spolupráce', $card['emailSubject']);
        $this->assertArrayNotHasKey('headline', $card);
        $this->assertSame(777, $card['context']['messageNdx']);

        $actions = $card['actions'];
        $this->assertSame(['trash', 'archive', 'openMail'], array_column($actions, 'id'));
        $this->assertSame('trash_message', $actions[0]['kind']);
        $this->assertTrue($actions[0]['primary']);
        $this->assertSame(['messageNdx' => 777], $actions[0]['target']);
        $this->assertSame('archive_message', $actions[1]['kind']);
        $this->assertSame('open_form', $actions[2]['kind']);
        $this->assertSame('core_mail_incoming_messages', $actions[2]['target']['table']);
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

    /** ConfigRuntime s registry targetem (insurance) + docKinds. */
    private function registryConfig(): ConfigRuntime
    {
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnCallback(
            static fn(string $id): mixed => match ($id) {
                'core.mail.extractedDocTypes' => [
                    'invoiceReceived' => ['name' => 'Přijatá faktura', 'target' => 'docs'],
                    'insurance'       => ['name' => 'Pojistná smlouva', 'target' => 'registry', 'docKind' => 'insurance'],
                ],
                'base.registry.docKinds' => [
                    'insurance' => [
                        'name'    => 'Pojistná smlouva',
                        'promote' => ['policyNumber' => 'ref_number', 'validFrom' => 'valid_from', 'validTo' => 'valid_to'],
                    ],
                ],
                default => null,
            },
        );
        return $config;
    }

    /** @return array<string,mixed> */
    private function registrySuggestionRow(int $status, int $ndx = 1): array
    {
        return [
            'extracted_ndx'  => $ndx,
            'message_ndx'    => 100 + $ndx,
            'doc_type'       => 'insurance',
            'confidence'     => 0.91,
            'status'         => $status,
            'subject'        => 'Pojistná smlouva 2026',
            'sender_name'    => 'Pojišťovna ABC',
            'received_at'    => '2026-06-28 10:00:00',
            'extracted_json' => json_encode([
                'schema'  => 'shpd.registry.document.v1',
                'docType' => 'insurance',
                'title'   => 'Pojistná smlouva — flotila vozidel',
                'party'   => ['name' => 'Pojišťovna ABC a.s.', 'companyId' => '12345678'],
                'kindFields' => ['policyNumber' => 'POJ-1', 'validTo' => '2026-12-31'],
                'binderSuggestion' => 'Pojištění',
            ]),
        ];
    }

    public function testRegistryCardHeadlineDetailsTargetAndApplyId(): void
    {
        $src = new MailSuggestionsSource();
        $cards = $src->collectCards($this->context([$this->registrySuggestionRow(10)], [], $this->registryConfig()));

        $this->assertCount(1, $cards);
        $card = $cards[0];
        // titulek: {docKind label} — {party.name} (fallback + použití mimo kartu)
        $this->assertSame('Pojistná smlouva — Pojišťovna ABC a.s.', $card['title']);
        // headline z party.name + docKind labelu, bez amountText
        $this->assertSame(
            ['partnerName' => 'Pojišťovna ABC a.s.', 'typeLabel' => 'Pojistná smlouva'],
            $card['headline'],
        );
        $this->assertSame(91, $card['confidencePct']);
        $this->assertSame('Pojistná smlouva 2026', $card['emailSubject']);
        $this->assertArrayNotHasKey('subtitle', $card);
        // details: jediný řádek „Platí do" (klíč přes inverzi promote)
        $this->assertSame([['label' => 'Platí do', 'value' => '31. 12. 2026']], $card['details']);
        $this->assertSame('registry', $card['category']);
        $this->assertSame('registry', $card['context']['target']);
        // apply akce má id apply_registry → FE label „Zařadit"; kind beze změny
        $this->assertSame('apply_registry', $card['actions'][0]['id']);
        $this->assertSame('apply_extracted', $card['actions'][0]['kind']);
        $this->assertTrue($card['actions'][0]['primary']);
    }

    public function testRegistryCardWithoutPartyUsesKindLabelOnly(): void
    {
        $row = $this->registrySuggestionRow(10);
        $canonical = json_decode((string) $row['extracted_json'], true);
        unset($canonical['party'], $canonical['kindFields']);
        $row['extracted_json'] = json_encode($canonical);

        $src = new MailSuggestionsSource();
        $cards = $src->collectCards($this->context([$row], [], $this->registryConfig()));

        // Bez party.name se headline neposílá → fallback title/subtitle.
        $this->assertSame('Pojistná smlouva', $cards[0]['title']);
        $this->assertArrayNotHasKey('headline', $cards[0]);
        $this->assertArrayNotHasKey('details', $cards[0]);
        $this->assertStringNotContainsString('platí do', $cards[0]['subtitle']);
    }

    public function testRegistryReviewStatusHasNoApplyAction(): void
    {
        $src = new MailSuggestionsSource();
        $cards = $src->collectCards($this->context([$this->registrySuggestionRow(20)], [], $this->registryConfig()));

        $ids = array_column($cards[0]['actions'], 'id');
        $this->assertSame(['review', 'reject'], $ids);
    }

    public function testDocsCardKeepsTargetDocsInContext(): void
    {
        $src = new MailSuggestionsSource();
        $cards = $src->collectCards($this->context([$this->suggestionRow(10)], [], $this->registryConfig()));

        $this->assertSame('docs', $cards[0]['context']['target']);
        $this->assertSame('apply', $cards[0]['actions'][0]['id']);
    }

    public function testNotInvoiceQueryFiltersPrimaryTypeOther(): void
    {
        // Registry primary typy (contract/insurance/…) na kartu „Není faktura"
        // nesmí spadnout — SQL filtruje primary_type='other' přímo.
        $captured = null;
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturnCallback(
            static function (string $sql) use (&$captured): array {
                if (str_contains($sql, 'NOT EXISTS')) {
                    $captured = $sql;
                }
                return [];
            },
        );
        (new MailSuggestionsSource())->collectCards(new FeedContext($db, null, 'cs', 30));

        $this->assertNotNull($captured);
        $this->assertStringContainsString("`primary_type` = 'other'", $captured);
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

    // ── Strukturovaná hlavička + details ─────────────────────────────────

    public function testDetailsRowsInFixedOrderFromFullCanonical(): void
    {
        $row = $this->suggestionRow(10);
        $canonical = json_decode((string) $row['extracted_json'], true);
        $canonical['docNumber'] = '2026000123';
        $canonical['dates']     = ['dueDate' => '2026-04-29'];
        $canonical['payment']   = ['paymentReference' => '2026000123'];
        $row['extracted_json'] = json_encode($canonical);

        $src = new MailSuggestionsSource();
        $cards = $src->collectCards($this->context([$row], [], $this->docTypesConfig()));

        $this->assertSame(
            [
                ['label' => 'Číslo dokladu', 'value' => '2026000123'],
                ['label' => 'Splatnost', 'value' => '29. 4. 2026'],
                ['label' => 'Variabilní symbol', 'value' => '2026000123'],
            ],
            $cards[0]['details'],
        );
    }

    public function testDetailsSkipMissingAndInvalidValues(): void
    {
        // docNumber chybí, dueDate nevalidní → oba řádky vynechané, zbyde VS.
        $row = $this->suggestionRow(10);
        $canonical = json_decode((string) $row['extracted_json'], true);
        $canonical['dates']   = ['dueDate' => 'not-a-date'];
        $canonical['payment'] = ['paymentReference' => '2026000123'];
        $row['extracted_json'] = json_encode($canonical);

        $src = new MailSuggestionsSource();
        $cards = $src->collectCards($this->context([$row], [], $this->docTypesConfig()));

        $this->assertSame(
            [['label' => 'Variabilní symbol', 'value' => '2026000123']],
            $cards[0]['details'],
        );
    }

    public function testCardWithoutPartnerFallsBackToTitleSubtitle(): void
    {
        $row = $this->suggestionRow(10);
        $canonical = json_decode((string) $row['extracted_json'], true);
        unset($canonical['supplier']);
        $row['extracted_json'] = json_encode($canonical);

        $src = new MailSuggestionsSource();
        $cards = $src->collectCards($this->context([$row], [], $this->docTypesConfig()));

        $card = $cards[0];
        $this->assertArrayNotHasKey('headline', $card);
        $this->assertSame('Přijatá faktura', $card['title']);
        // subtitle zůstává dnešní složený (částka · jistota · e-mail)
        $this->assertStringContainsString('12 500,00 CZK', $card['subtitle']);
        $this->assertStringContainsString('jistota 94 %', $card['subtitle']);
        $this->assertStringContainsString('Faktura 2026000123', $card['subtitle']);
        // emailSubject posílají všechny mail karty, i fallback
        $this->assertSame('Faktura 2026000123', $card['emailSubject']);
        $this->assertSame(94, $card['confidencePct']);
    }

    // ── Přílohy karet ────────────────────────────────────────────────────

    public function testCardCarriesAttachmentsWithStructureAndOrder(): void
    {
        // Zpráva 101 (suggestionRow ndx=1) se dvěma obsahovými přílohami;
        // pořadí z batch dotazu (att_order) se zachovává.
        $atts = [
            $this->attachmentRow(11, 101, 'Faktura.pdf'),
            $this->attachmentRow(12, 101, 'scan-001.jpg', 'image/jpeg', 102400),
        ];
        $src = new MailSuggestionsSource();
        $cards = $src->collectCards(
            $this->context([$this->suggestionRow(10)], [], $this->docTypesConfig(), 'cs', [], $atts),
        );

        $card = $cards[0];
        $this->assertSame(2, $card['attachmentsTotal']);
        $this->assertSame(
            [
                ['id' => 11, 'name' => 'Faktura.pdf', 'mime_type' => 'application/pdf', 'file_size' => 245760],
                ['id' => 12, 'name' => 'scan-001.jpg', 'mime_type' => 'image/jpeg', 'file_size' => 102400],
            ],
            $card['attachments'],
        );
    }

    public function testRawEmlAttachmentIsExcluded(): void
    {
        $row = [
            'message_ndx'           => 777,
            'subject'               => 'Nabídka spolupráce',
            'sender_name'           => 'Obchodník a.s.',
            'sender_email'          => 'obchod@example.com',
            'received_at'           => '2026-06-29 08:00:00',
            'primary_type'          => 'other',
            'raw_source_attachment' => 99,
        ];
        $atts = [
            $this->attachmentRow(5, 777, 'letak.pdf'),
            $this->attachmentRow(99, 777, 'message.eml', 'message/rfc822'),
        ];
        $src = new MailSuggestionsSource();
        $cards = $src->collectCards($this->context([], [], null, 'cs', [$row], $atts));

        $this->assertSame([5], array_column($cards[0]['attachments'], 'id'));
        $this->assertSame(1, $cards[0]['attachmentsTotal']);
    }

    public function testSuggestionAttachmentsFilteredBySourceAttachments(): void
    {
        $row = $this->suggestionRow(10);
        $row['source_attachments'] = json_encode([12]);
        $atts = [
            $this->attachmentRow(11, 101, 'priloha-a.pdf'),
            $this->attachmentRow(12, 101, 'faktura.pdf'),
            $this->attachmentRow(13, 101, 'priloha-c.pdf'),
        ];
        $src = new MailSuggestionsSource();
        $cards = $src->collectCards($this->context([$row], [], $this->docTypesConfig(), 'cs', [], $atts));

        $this->assertSame([12], array_column($cards[0]['attachments'], 'id'));
        $this->assertSame(1, $cards[0]['attachmentsTotal']);
    }

    public function testSuggestionAttachmentsFallBackOnInvalidSourceAttachments(): void
    {
        $atts = [
            $this->attachmentRow(11, 101, 'priloha-a.pdf'),
            $this->attachmentRow(12, 101, 'faktura.pdf'),
        ];

        foreach (['not-json', '[]', json_encode([999])] as $sourceAttachments) {
            $row = $this->suggestionRow(10);
            $row['source_attachments'] = $sourceAttachments;
            $src = new MailSuggestionsSource();
            $cards = $src->collectCards($this->context([$row], [], $this->docTypesConfig(), 'cs', [], $atts));

            // Nevalidní JSON / prázdné pole / žádný průnik → všechny obsahové přílohy.
            $this->assertSame([11, 12], array_column($cards[0]['attachments'], 'id'), "source_attachments={$sourceAttachments}");
        }
    }

    public function testAttachmentsCappedAtThreeWithTotal(): void
    {
        $atts = [];
        foreach ([21, 22, 23, 24, 25] as $i => $id) {
            $atts[] = $this->attachmentRow($id, 101, sprintf('priloha-%d.pdf', $i + 1));
        }
        $src = new MailSuggestionsSource();
        $cards = $src->collectCards(
            $this->context([$this->suggestionRow(10)], [], $this->docTypesConfig(), 'cs', [], $atts),
        );

        $this->assertSame([21, 22, 23], array_column($cards[0]['attachments'], 'id'));
        $this->assertSame(5, $cards[0]['attachmentsTotal']);
    }

    public function testCardWithoutAttachmentsHasNoAttachmentKeys(): void
    {
        $src = new MailSuggestionsSource();
        $cards = $src->collectCards($this->context([$this->suggestionRow(10)], [], $this->docTypesConfig()));

        $this->assertArrayNotHasKey('attachments', $cards[0]);
        $this->assertArrayNotHasKey('attachmentsTotal', $cards[0]);
    }

    public function testAttachmentBatchQueryNotRunWithoutCards(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturnCallback(
            function (string $sql): array {
                $this->assertStringNotContainsString('core_attachments_files', $sql);
                return [];
            },
        );
        $src = new MailSuggestionsSource();
        $this->assertSame([], $src->collectCards(new FeedContext($db, null, 'cs', 30)));
    }
}
