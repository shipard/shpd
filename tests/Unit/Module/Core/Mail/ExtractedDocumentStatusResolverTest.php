<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Mail;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Core\Mail\ExtractedDocumentDocument;
use Shipard\Module\Core\Mail\ExtractedDocumentStatusResolver;

/**
 * Sdílená pravidla confidence → status extrahovaného dokumentu (krok 2
 * tasks/mail-isdoc-import.md). Chování mapování a stropu D7 je zamčené
 * i přes AnalysisControllerExchangeTest (validateAndStoreCanonical) —
 * tady se testuje resolver přímo, hlavně thresholds fallbacky.
 */
class ExtractedDocumentStatusResolverTest extends TestCase
{
    private function resolver(?DataSourceConnection $db = null): ExtractedDocumentStatusResolver
    {
        return new ExtractedDocumentStatusResolver(
            $db ?? $this->createMock(DataSourceConnection::class),
        );
    }

    // ── thresholds ──────────────────────────────────────────────────────────

    public function testThresholdsForNullProfileReturnsDefaults(): void
    {
        $this->assertSame(
            ExtractedDocumentStatusResolver::DEFAULT_THRESHOLDS,
            $this->resolver()->thresholdsForProfile(null),
        );
    }

    public function testThresholdsForProfileReadsJson(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn([
            'confidence_thresholds' => '{"ready": 0.95, "review": 0.5}',
        ]);

        $this->assertSame(
            ['ready' => 0.95, 'review' => 0.5],
            $this->resolver($db)->thresholdsForProfile(17),
        );
    }

    public function testThresholdsForProfileFallsBackOnCorruptedJson(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn(['confidence_thresholds' => 'not json']);

        $this->assertSame(
            ExtractedDocumentStatusResolver::DEFAULT_THRESHOLDS,
            $this->resolver($db)->thresholdsForProfile(17),
        );
    }

    public function testThresholdsForDefaultProfileWithoutProfileUsesDefaults(): void
    {
        // DS bez AI profilu (ISDOC import musí fungovat i tam)
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn(null);

        $this->assertSame(
            ExtractedDocumentStatusResolver::DEFAULT_THRESHOLDS,
            $this->resolver($db)->thresholdsForDefaultProfile(),
        );
    }

    public function testThresholdsForDefaultProfileResolvesProfileRow(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturnOnConsecutiveCalls(
            ['id' => 17],
            ['confidence_thresholds' => '{"ready": 0.8, "review": 0.4}'],
        );

        $this->assertSame(
            ['ready' => 0.8, 'review' => 0.4],
            $this->resolver($db)->thresholdsForDefaultProfile(),
        );
    }

    // ── mapování confidence ─────────────────────────────────────────────────

    public function testMapConfidenceToStatus(): void
    {
        $resolver = $this->resolver();
        $thresholds = ExtractedDocumentStatusResolver::DEFAULT_THRESHOLDS;

        $this->assertSame(
            ExtractedDocumentDocument::STATUS_READY_TO_APPLY,
            $resolver->mapConfidenceToStatus(1.0, $thresholds),
        );
        $this->assertSame(
            ExtractedDocumentDocument::STATUS_PENDING_REVIEW,
            $resolver->mapConfidenceToStatus(0.7, $thresholds),
        );
        $this->assertSame(
            ExtractedDocumentDocument::STATUS_LOW_CONFIDENCE,
            $resolver->mapConfidenceToStatus(0.3, $thresholds),
        );
    }

    // ── strop D7 ────────────────────────────────────────────────────────────

    public function testCapDowngradesReadyWhenItemRowWithoutOurCode(): void
    {
        $canonical = ['rows' => [
            ['rowKind' => 'item', 'item' => ['name' => 'X']],
        ]];

        $this->assertSame(
            ExtractedDocumentDocument::STATUS_PENDING_REVIEW,
            $this->resolver()->capStatusByRowCoverage(
                ExtractedDocumentDocument::STATUS_READY_TO_APPLY,
                $canonical,
            ),
        );
    }

    public function testCapKeepsReadyWhenAllItemRowsHaveOurCode(): void
    {
        $canonical = ['rows' => [
            ['rowKind' => 'item', 'item' => ['ourCode' => 'ABC', 'name' => 'X']],
        ]];

        $this->assertSame(
            ExtractedDocumentDocument::STATUS_READY_TO_APPLY,
            $this->resolver()->capStatusByRowCoverage(
                ExtractedDocumentDocument::STATUS_READY_TO_APPLY,
                $canonical,
            ),
        );
    }

    public function testCapIgnoresAccountingRows(): void
    {
        $canonical = ['rows' => [
            ['operation' => 'acc.record', 'accSide' => 'debit', 'totalPrice' => 100.0],
        ]];

        $this->assertSame(
            ExtractedDocumentDocument::STATUS_READY_TO_APPLY,
            $this->resolver()->capStatusByRowCoverage(
                ExtractedDocumentDocument::STATUS_READY_TO_APPLY,
                $canonical,
            ),
        );
    }

    public function testCapLeavesNonReadyStatusAlone(): void
    {
        $this->assertSame(
            ExtractedDocumentDocument::STATUS_LOW_CONFIDENCE,
            $this->resolver()->capStatusByRowCoverage(
                ExtractedDocumentDocument::STATUS_LOW_CONFIDENCE,
                ['rows' => [['rowKind' => 'item', 'item' => []]]],
            ),
        );
    }
}
