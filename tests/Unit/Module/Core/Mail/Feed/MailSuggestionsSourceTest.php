<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Mail\Feed;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Feed\FeedContext;
use Shipard\Module\Core\Mail\Feed\MailSuggestionsSource;

/**
 * Unit testy pro MailSuggestionsSource (message-centric, D10).
 *
 * Pokrývají:
 *   - confidence pásmo (AnalysisConfidenceResolver) → kind, stateStyle a sada
 *     akcí: ready → apply (primary) + review + reject; review/low → review
 *     (primary) + reject; strop D7 podle pokrytí řádků
 *   - zpráva analysis_state=70 → urgent karta + reanalyze/open_detail
 *     (degradace na review při primary_type=other)
 *   - otevřený návrh s ai_failed wrapperem (_validationError) → chybová
 *     karta mail_invalid s reanalyze
 *   - karta „Není faktura" (kind info, akce trash/archive/open_detail)
 *   - strukturovaná hlavička `headline` (partner/typ/částka) + `confidencePct`
 *     + `emailSubject` + `details` + `secondaryFindings`; fallback na
 *     title/subtitle bez partnera
 *   - přílohy karet: struktura, vyloučení raw .eml, strop 3 + attachmentsTotal
 *     (vždy všechny obsahové přílohy — source_attachments filtr zanikl)
 *   - prázdný vstup → []
 */
final class MailSuggestionsSourceTest extends TestCase
{
    /**
     * Sestaví FeedContext s DB mockem, který routuje SELECTy zdroje podle
     * tvaru SQL: batch příloh (core_attachments_files), suggestion (JOIN na
     * poslední úspěšnou analýzu), notInvoice (COALESCE(( subquery), error
     * (zbytek — messages tabulka). fetchRow (thresholds resolveru) vrací
     * default null → DEFAULT_THRESHOLDS, nebo řádky z $profileRows.
     *
     * @param list<array<string,mixed>> $suggestionRows
     * @param list<array<string,mixed>> $errorRows
     * @param list<array<string,mixed>> $notInvoiceRows
     * @param list<array<string,mixed>> $attachmentRows
     * @param list<array<string,mixed>|null> $profileRows po sobě jdoucí návraty fetchRow
     */
    private function context(
        array $suggestionRows,
        array $errorRows = [],
        ?ConfigRuntime $config = null,
        string $lang = 'cs',
        array $notInvoiceRows = [],
        array $attachmentRows = [],
        array $profileRows = [],
    ): FeedContext {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturnCallback(
            static function (mixed ...$args) use ($suggestionRows, $errorRows, $notInvoiceRows, $attachmentRows): array {
                $sql = (string) $args[0];
                if (str_contains($sql, 'core_attachments_files')) {
                    return $attachmentRows;
                }
                if (str_contains($sql, 'JOIN')) {
                    return $suggestionRows;
                }
                return str_contains($sql, 'COALESCE((') ? $notInvoiceRows : $errorRows;
            },
        );
        if ($profileRows !== []) {
            $db->method('fetchRow')->willReturnOnConsecutiveCalls(...$profileRows);
        } else {
            $db->method('fetchRow')->willReturn(null); // žádný AI profil → default thresholds
        }
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

    private function primaryTypesConfig(): ConfigRuntime
    {
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnCallback(
            static fn(string $id): mixed => $id === 'core.mail.primaryTypes'
                ? ['invoiceReceived' => ['name' => 'Přijatá faktura', 'target' => 'docs']]
                : null,
        );
        return $config;
    }

    /** @return array<string,mixed> */
    private function suggestionRow(float $confidence = 0.94, int $messageNdx = 101): array
    {
        return [
            'message_ndx'           => $messageNdx,
            'analysis_ndx'          => 900 + $messageNdx,
            'proposed_type'         => 'invoiceReceived',
            'confidence'            => $confidence,
            'profile'               => null,
            'subject'               => 'Faktura 2026000123',
            'sender_name'           => 'ČEZ a.s.',
            'received_at'           => '2026-06-28 10:00:00',
            'raw_source_attachment' => null,
            'analysis_json'         => null,
            'canonical_json'        => json_encode([
                'selfParty' => 'customer',
                'supplier'  => ['name' => 'ČEZ a.s.'],
                'currency'  => 'CZK',
                'totals'    => ['totalAmount' => 12500.00],
            ]),
        ];
    }

    // ── Confidence pásma → kind + akce ───────────────────────────────────

    public function testReadyBandProducesApplyReviewReject(): void
    {
        $src = new MailSuggestionsSource();
        $cards = $src->collectCards($this->context([$this->suggestionRow(0.94)], [], $this->primaryTypesConfig()));

        $this->assertCount(1, $cards);
        $card = $cards[0];
        $this->assertSame('mail_suggestion:101', $card['id']);
        $this->assertSame('mail', $card['source']);
        $this->assertSame('ready', $card['kind']);
        $this->assertSame('done', $card['stateStyle']);
        $this->assertSame('check', $card['icon']);
        $this->assertSame('invoices', $card['category']);
        $this->assertSame('Přijatá faktura — ČEZ a.s.', $card['title']);
        // Karta s partnerem nese strukturovanou hlavičku; subtitle se neposílá.
        $this->assertSame(
            ['partnerName' => 'ČEZ a.s.', 'typeLabel' => 'Přijatá faktura', 'amountText' => '12 500,00 CZK'],
            $card['headline'],
        );
        $this->assertSame(94, $card['confidencePct']);
        $this->assertSame('Faktura 2026000123', $card['emailSubject']);
        $this->assertSame('28. 6. 2026', $card['receivedDateText']);
        $this->assertArrayNotHasKey('subtitle', $card);
        // Default canonical nemá docNumber/dueDate/paymentReference → bez details.
        $this->assertArrayNotHasKey('details', $card);
        $this->assertSame('2026-06-28T10:00:00+00:00', $card['timestamp']);

        // Ready → jednoklikové apply (primary) + review + reject.
        $actionIds = array_column($card['actions'], 'id');
        $this->assertSame(['apply', 'review', 'reject'], $actionIds);
        $this->assertTrue($card['actions'][0]['primary']);
        $this->assertSame('apply_message', $card['actions'][0]['kind']);
        $this->assertSame(['messageNdx' => 101], $card['actions'][0]['target']);
        $this->assertSame('review_message', $card['actions'][1]['kind']);
        $this->assertSame('reject_message', $card['actions'][2]['kind']);

        $this->assertSame(101, $card['context']['messageNdx']);
        $this->assertSame(1001, $card['context']['analysisNdx']);
        $this->assertSame(0.94, $card['context']['confidence']);
        $this->assertSame('docs', $card['context']['target']);
    }

    public function testReviewBandWithoutApply(): void
    {
        $src = new MailSuggestionsSource();
        $cards = $src->collectCards($this->context([$this->suggestionRow(0.7)], [], $this->primaryTypesConfig()));

        $card = $cards[0];
        $this->assertSame('review', $card['kind']);
        $this->assertSame('confirmed', $card['stateStyle']);
        $actionIds = array_column($card['actions'], 'id');
        $this->assertSame(['review', 'reject'], $actionIds);
        $this->assertTrue($card['actions'][0]['primary']);
        $this->assertSame('review_message', $card['actions'][0]['kind']);
    }

    public function testLowBandReviewEditStyle(): void
    {
        $src = new MailSuggestionsSource();
        $cards = $src->collectCards($this->context([$this->suggestionRow(0.3)], [], $this->primaryTypesConfig()));

        $card = $cards[0];
        $this->assertSame('review', $card['kind']);
        $this->assertSame('edit', $card['stateStyle']);
        $this->assertSame('warning', $card['icon']);
        $this->assertSame(['review', 'reject'], array_column($card['actions'], 'id'));
    }

    public function testRowCoverageCapsReadyToReview(): void
    {
        // Vysoká confidence, ale item řádek bez ourCode → strop D7 sráží
        // pásmo na review — žádný jednoklik apply.
        $row = $this->suggestionRow(0.95);
        $canonical = json_decode((string) $row['canonical_json'], true);
        $canonical['rows'] = [['rowKind' => 'item', 'item' => ['name' => 'Elektřina']]];
        $row['canonical_json'] = json_encode($canonical);

        $src = new MailSuggestionsSource();
        $cards = $src->collectCards($this->context([$row], [], $this->primaryTypesConfig()));

        $this->assertSame('review', $cards[0]['kind']);
        $this->assertSame(['review', 'reject'], array_column($cards[0]['actions'], 'id'));
    }

    public function testProfileThresholdsAreUsedForBand(): void
    {
        // Běh s profilem 17 (ready práh 0.8) → 0.85 stačí na ready.
        $row = $this->suggestionRow(0.85);
        $row['profile'] = 17;

        $src = new MailSuggestionsSource();
        $cards = $src->collectCards($this->context(
            [$row], [], $this->primaryTypesConfig(), 'cs', [], [],
            [['confidence_thresholds' => '{"ready": 0.8, "review": 0.4}']],
        ));

        $this->assertSame('ready', $cards[0]['kind']);
        $this->assertSame('apply', $cards[0]['actions'][0]['id']);
    }

    // ── Chybové karty ────────────────────────────────────────────────────

    public function testInvalidAiOutputProducesUrgentReanalyzeCard(): void
    {
        // Otevřený návrh s forenzním wrapperem z /result → karta mail_invalid.
        $row = $this->suggestionRow();
        $row['canonical_json'] = json_encode(['_validationError' => ['issues' => ['x']]]);

        $src = new MailSuggestionsSource();
        $cards = $src->collectCards($this->context([$row], [], $this->primaryTypesConfig()));

        $this->assertCount(1, $cards);
        $card = $cards[0];
        $this->assertSame('mail_invalid:101', $card['id']);
        $this->assertSame('urgent', $card['kind']);
        $this->assertSame('error', $card['stateStyle']);
        $this->assertSame('Chyba analýzy e-mailu', $card['title']);
        $this->assertSame('ČEZ a.s.', $card['subtitle']);
        $this->assertSame(['messageNdx' => 101], $card['context']);

        $actions = $card['actions'];
        $this->assertSame('reanalyze', $actions[0]['kind']);
        $this->assertTrue($actions[0]['primary']);
        $this->assertSame(['messageNdx' => 101], $actions[0]['target']);
        $this->assertSame('open_detail', $actions[1]['kind']);
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
        $this->assertSame('27. 6. 2026', $card['receivedDateText']);
        $this->assertArrayNotHasKey('headline', $card);

        $actions = $card['actions'];
        $this->assertSame('reanalyze', $actions[0]['id']);
        $this->assertSame('reanalyze', $actions[0]['kind']);
        $this->assertSame(['messageNdx' => 555], $actions[0]['target']);
        $this->assertSame('open_detail', $actions[1]['kind']);
        $this->assertSame('core.mail.incoming', $actions[1]['target']['viewerId']);
        $this->assertSame(555, $actions[1]['target']['recordId']);
        $this->assertSame('content', $actions[1]['target']['tabId']);
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

    // ── Karta „Není faktura" ─────────────────────────────────────────────

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
        $this->assertSame('open_detail', $actions[2]['kind']);
        $this->assertSame('core.mail.incoming', $actions[2]['target']['viewerId']);
        $this->assertSame('content', $actions[2]['target']['tabId']);
    }

    // ── SQL pojistky ─────────────────────────────────────────────────────

    public function testSuggestionQueryExcludesOtherProposedType(): void
    {
        // Pojistka — WHERE musí filtrovat proposed_type='other' už v SQL
        // (prompt 'other' návrhy zakazuje, starší analýzy je mohly vytvořit).
        $captured = null;
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturnCallback(
            static function (mixed ...$args) use (&$captured): array {
                $sql = (string) $args[0];
                if (str_contains($sql, 'JOIN')) {
                    $captured = $sql;
                }
                return [];
            },
        );
        (new MailSuggestionsSource())->collectCards(new FeedContext($db, null, 'cs', 30));

        $this->assertNotNull($captured);
        $this->assertStringContainsString("COALESCE(`a`.`proposed_type`, 'other') != 'other'", $captured);
        $this->assertStringContainsString('`a`.`resolution` IS NULL', $captured);
        $this->assertStringContainsString('`a`.`canonical_json` IS NOT NULL', $captured);
    }

    public function testNotInvoiceQueryFiltersPrimaryTypeOtherWithoutOpenProposal(): void
    {
        // Registry primary typy na kartu „Není faktura" nesmí spadnout — SQL
        // filtruje primary_type='other'; otevřený návrh poslední analýzy
        // kartu potlačí přes COALESCE(derived flag)=0.
        $captured = null;
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturnCallback(
            static function (mixed ...$args) use (&$captured): array {
                $sql = (string) $args[0];
                if (str_contains($sql, 'COALESCE((')) {
                    $captured = $sql;
                }
                return [];
            },
        );
        (new MailSuggestionsSource())->collectCards(new FeedContext($db, null, 'cs', 30));

        $this->assertNotNull($captured);
        $this->assertStringContainsString("`primary_type` = 'other'", $captured);
        $this->assertStringContainsString('), 0) = 0', $captured);
    }

    // ── Registry target ──────────────────────────────────────────────────

    /** ConfigRuntime s registry targetem (insurance) + docKinds. */
    private function registryConfig(): ConfigRuntime
    {
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnCallback(
            static fn(string $id): mixed => match ($id) {
                'core.mail.primaryTypes' => [
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
    private function registrySuggestionRow(float $confidence = 0.91, int $messageNdx = 101): array
    {
        $row = $this->suggestionRow($confidence, $messageNdx);
        $row['proposed_type'] = 'insurance';
        $row['subject'] = 'Pojistná smlouva 2026';
        $row['sender_name'] = 'Pojišťovna ABC';
        $row['canonical_json'] = json_encode([
            'schema'  => 'shpd.registry.document.v1',
            'docType' => 'insurance',
            'title'   => 'Pojistná smlouva — flotila vozidel',
            'party'   => ['name' => 'Pojišťovna ABC a.s.', 'companyId' => '12345678'],
            'kindFields' => ['policyNumber' => 'POJ-1', 'validTo' => '2026-12-31'],
            'binderSuggestion' => 'Pojištění',
        ]);
        return $row;
    }

    public function testRegistryCardHeadlineDetailsTargetAndActions(): void
    {
        $src = new MailSuggestionsSource();
        $cards = $src->collectCards($this->context([$this->registrySuggestionRow()], [], $this->registryConfig()));

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
        // Action kinds jsou pro oba targety shodné — 0.91 ≥ 0.9 → ready → apply.
        $this->assertSame(['apply', 'review', 'reject'], array_column($card['actions'], 'id'));
        $this->assertSame('apply_message', $card['actions'][0]['kind']);
        $this->assertTrue($card['actions'][0]['primary']);
    }

    public function testRegistryCardWithoutPartyUsesKindLabelOnly(): void
    {
        $row = $this->registrySuggestionRow();
        $canonical = json_decode((string) $row['canonical_json'], true);
        unset($canonical['party'], $canonical['kindFields']);
        $row['canonical_json'] = json_encode($canonical);

        $src = new MailSuggestionsSource();
        $cards = $src->collectCards($this->context([$row], [], $this->registryConfig()));

        // Bez party.name se headline neposílá → fallback title/subtitle.
        $this->assertSame('Pojistná smlouva', $cards[0]['title']);
        $this->assertArrayNotHasKey('headline', $cards[0]);
        $this->assertArrayNotHasKey('details', $cards[0]);
        $this->assertStringNotContainsString('platí do', $cards[0]['subtitle']);
    }

    public function testRegistryReviewBandHasNoApplyAction(): void
    {
        $src = new MailSuggestionsSource();
        $cards = $src->collectCards($this->context([$this->registrySuggestionRow(0.7)], [], $this->registryConfig()));

        $ids = array_column($cards[0]['actions'], 'id');
        $this->assertSame(['review', 'reject'], $ids);
    }

    public function testDocsCardKeepsTargetDocsInContext(): void
    {
        $src = new MailSuggestionsSource();
        $cards = $src->collectCards($this->context([$this->suggestionRow()], [], $this->registryConfig()));

        $this->assertSame('docs', $cards[0]['context']['target']);
    }

    // ── Fallbacky ────────────────────────────────────────────────────────

    public function testEmptyInputReturnsNoCards(): void
    {
        $src = new MailSuggestionsSource();
        $this->assertSame([], $src->collectCards($this->context([], [])));
    }

    public function testDocTypeFallbackWhenConfigMissing(): void
    {
        $src = new MailSuggestionsSource();
        // Bez configu → title padne na holý proposed_type key.
        $cards = $src->collectCards($this->context([$this->suggestionRow()], [], null));
        $this->assertStringStartsWith('invoiceReceived — ', $cards[0]['title']);
    }

    public function testCounterpartyFollowsSelfParty(): void
    {
        $row = $this->suggestionRow();
        $row['canonical_json'] = json_encode([
            'selfParty' => 'supplier',                 // my jsme dodavatel → protistrana customer
            'supplier'  => ['name' => 'Naše firma'],
            'customer'  => ['name' => 'Odběratel a.s.'],
        ]);
        $src = new MailSuggestionsSource();
        $cards = $src->collectCards($this->context([$row], [], $this->primaryTypesConfig()));
        $this->assertStringEndsWith('— Odběratel a.s.', $cards[0]['title']);
    }

    // ── Strukturovaná hlavička + details + secondary findings ───────────

    public function testDetailsRowsInFixedOrderFromFullCanonical(): void
    {
        $row = $this->suggestionRow();
        $canonical = json_decode((string) $row['canonical_json'], true);
        $canonical['docNumber'] = '2026000123';
        $canonical['dates']     = ['dueDate' => '2026-04-29'];
        $canonical['payment']   = ['paymentReference' => '2026000123'];
        $row['canonical_json'] = json_encode($canonical);

        $src = new MailSuggestionsSource();
        $cards = $src->collectCards($this->context([$row], [], $this->primaryTypesConfig()));

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
        $row = $this->suggestionRow();
        $canonical = json_decode((string) $row['canonical_json'], true);
        $canonical['dates']   = ['dueDate' => 'not-a-date'];
        $canonical['payment'] = ['paymentReference' => '2026000123'];
        $row['canonical_json'] = json_encode($canonical);

        $src = new MailSuggestionsSource();
        $cards = $src->collectCards($this->context([$row], [], $this->primaryTypesConfig()));

        $this->assertSame(
            [['label' => 'Variabilní symbol', 'value' => '2026000123']],
            $cards[0]['details'],
        );
    }

    public function testCardWithoutPartnerFallsBackToTitleSubtitle(): void
    {
        $row = $this->suggestionRow();
        $canonical = json_decode((string) $row['canonical_json'], true);
        unset($canonical['supplier']);
        $row['canonical_json'] = json_encode($canonical);

        $src = new MailSuggestionsSource();
        $cards = $src->collectCards($this->context([$row], [], $this->primaryTypesConfig()));

        $card = $cards[0];
        $this->assertArrayNotHasKey('headline', $card);
        $this->assertSame('Přijatá faktura', $card['title']);
        // subtitle zůstává složený (částka · jistota · e-mail)
        $this->assertStringContainsString('12 500,00 CZK', $card['subtitle']);
        $this->assertStringContainsString('jistota 94 %', $card['subtitle']);
        $this->assertStringContainsString('Faktura 2026000123', $card['subtitle']);
        // emailSubject posílají všechny mail karty, i fallback
        $this->assertSame('Faktura 2026000123', $card['emailSubject']);
        $this->assertSame(94, $card['confidencePct']);
    }

    public function testSecondaryFindingsFromAnalysisJson(): void
    {
        // Neprázdné secondary_findings běhu → hint {type, type_label, note} (D7).
        $row = $this->suggestionRow();
        $row['analysis_json'] = json_encode([
            'secondary_findings' => [
                ['type' => 'other', 'note' => 'Příloha obsahuje i všeobecné podmínky.'],
            ],
        ]);

        $src = new MailSuggestionsSource();
        $cards = $src->collectCards($this->context([$row], [], $this->primaryTypesConfig()));

        $this->assertSame(
            [['type' => 'other', 'type_label' => 'Ostatní', 'note' => 'Příloha obsahuje i všeobecné podmínky.']],
            $cards[0]['secondaryFindings'],
        );
    }

    public function testCardWithoutSecondaryFindingsHasNoKey(): void
    {
        $src = new MailSuggestionsSource();
        $cards = $src->collectCards($this->context([$this->suggestionRow()], [], $this->primaryTypesConfig()));
        $this->assertArrayNotHasKey('secondaryFindings', $cards[0]);
    }

    // ── Přílohy karet ────────────────────────────────────────────────────

    public function testCardCarriesAllContentAttachmentsWithStructureAndOrder(): void
    {
        // Zpráva 101 se dvěma obsahovými přílohami; pořadí z batch dotazu
        // (att_order) se zachovává; source_attachments filtr zanikl —
        // karta nese vždy všechny obsahové přílohy.
        $atts = [
            $this->attachmentRow(11, 101, 'Faktura.pdf'),
            $this->attachmentRow(12, 101, 'scan-001.jpg', 'image/jpeg', 102400),
        ];
        $src = new MailSuggestionsSource();
        $cards = $src->collectCards(
            $this->context([$this->suggestionRow()], [], $this->primaryTypesConfig(), 'cs', [], $atts),
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

    public function testAttachmentsCappedAtThreeWithTotal(): void
    {
        $atts = [];
        foreach ([21, 22, 23, 24, 25] as $i => $id) {
            $atts[] = $this->attachmentRow($id, 101, sprintf('priloha-%d.pdf', $i + 1));
        }
        $src = new MailSuggestionsSource();
        $cards = $src->collectCards(
            $this->context([$this->suggestionRow()], [], $this->primaryTypesConfig(), 'cs', [], $atts),
        );

        $this->assertSame([21, 22, 23], array_column($cards[0]['attachments'], 'id'));
        $this->assertSame(5, $cards[0]['attachmentsTotal']);
    }

    public function testCardWithoutAttachmentsHasNoAttachmentKeys(): void
    {
        $src = new MailSuggestionsSource();
        $cards = $src->collectCards($this->context([$this->suggestionRow()], [], $this->primaryTypesConfig()));

        $this->assertArrayNotHasKey('attachments', $cards[0]);
        $this->assertArrayNotHasKey('attachmentsTotal', $cards[0]);
    }

    public function testAttachmentBatchQueryNotRunWithoutCards(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturnCallback(
            function (mixed ...$args): array {
                $this->assertStringNotContainsString('core_attachments_files', (string) $args[0]);
                return [];
            },
        );
        $src = new MailSuggestionsSource();
        $this->assertSame([], $src->collectCards(new FeedContext($db, null, 'cs', 30)));
    }
}
