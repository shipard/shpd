<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Feed;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Feed\FeedCollector;

/**
 * Unit testy pro FeedCollector — čisté transformace nad kartami feedu
 * (sortAndCap / countByKind / stripInternalFields). Přestěhováno
 * z DashboardControllerTest při extrakci collectoru (UI shells Fáze 3).
 *
 * Zapojení zdrojů, per-source izolaci a degradaci dle tabulek pokrývají
 * integrační testy v DashboardControllerTest (přes dashboard()).
 */
final class FeedCollectorTest extends TestCase
{
    /** @return array<string,mixed> */
    private function card(string $kind, ?string $timestamp, string $id = 'x'): array
    {
        return ['id' => $id, 'kind' => $kind, 'timestamp' => $timestamp];
    }

    // ── sortAndCap / countByKind ─────────────────────────────────────────────

    public function testSortAndCapOrdersByKindBand(): void
    {
        $collector = new FeedCollector();
        $input = [
            $this->card('info', '2026-06-28T10:00:00+00:00', 'i'),
            $this->card('ready', '2026-06-28T10:00:00+00:00', 'r'),
            $this->card('urgent', '2026-06-28T10:00:00+00:00', 'u'),
            $this->card('review', '2026-06-28T10:00:00+00:00', 'v'),
        ];
        [$sorted, $truncated] = $collector->sortAndCap($input, 30);

        $this->assertFalse($truncated);
        $this->assertSame(['u', 'v', 'r', 'i'], array_column($sorted, 'id'));
    }

    public function testSortAndCapTimestampDescWithinBand(): void
    {
        $collector = new FeedCollector();
        $input = [
            $this->card('ready', '2026-06-01T10:00:00+00:00', 'old'),
            $this->card('ready', '2026-06-28T10:00:00+00:00', 'new'),
            $this->card('ready', null, 'notime'),
        ];
        [$sorted] = $collector->sortAndCap($input, 30);

        // Nejnovější první, karta bez timestampu naspod pásma.
        $this->assertSame(['new', 'old', 'notime'], array_column($sorted, 'id'));
    }

    public function testSortAndCapCapsAndFlagsTruncation(): void
    {
        $collector = new FeedCollector();
        $input = [];
        for ($i = 0; $i < 35; $i++) {
            $input[] = $this->card('ready', '2026-06-28T10:00:00+00:00', "c$i");
        }
        [$sorted, $truncated] = $collector->sortAndCap($input, 30);

        $this->assertTrue($truncated);
        $this->assertCount(30, $sorted);
    }

    public function testCountByKindCountsOnlyActionable(): void
    {
        $collector = new FeedCollector();
        $cards = [
            $this->card('urgent', null),
            $this->card('urgent', null),
            $this->card('review', null),
            $this->card('ready', null),
            $this->card('info', null),   // nezapočítává se
        ];
        $this->assertSame(['urgent' => 2, 'review' => 1, 'ready' => 1], $collector->countByKind($cards));
    }

    // ── sectionBadges (UI shells Fáze 3, D2) ─────────────────────────────────

    /** @return array<string,mixed> */
    private function sectionCard(string $kind, ?string $navSection, string $id = 'x'): array
    {
        $card = ['id' => $id, 'kind' => $kind, 'timestamp' => null];
        if ($navSection !== null) {
            $card['navSection'] = $navSection;
        }
        return $card;
    }

    public function testSectionBadgesCountsOnlyUrgentAndReview(): void
    {
        $collector = new FeedCollector();
        $badges = $collector->sectionBadges([
            $this->sectionCard('urgent', 'accounting'),
            $this->sectionCard('review', 'accounting'),
            $this->sectionCard('ready', 'accounting'),   // nepočítá se (D2)
            $this->sectionCard('info', 'accounting'),    // nepočítá se (D2)
        ]);

        $this->assertSame(['accounting' => ['count' => 2, 'severity' => 'danger']], $badges);
    }

    public function testSectionBadgesIgnoresCardsWithoutNavSection(): void
    {
        $collector = new FeedCollector();
        $badges = $collector->sectionBadges([
            $this->sectionCard('urgent', null),
            [...$this->sectionCard('review', null), 'navSection' => null],
            $this->sectionCard('review', 'basic'),
        ]);

        $this->assertSame(['basic' => ['count' => 1, 'severity' => 'warning']], $badges);
    }

    public function testSectionBadgesMaxSeverityAndPerSectionSums(): void
    {
        $collector = new FeedCollector();
        $badges = $collector->sectionBadges([
            $this->sectionCard('review', 'accounting'),
            $this->sectionCard('urgent', 'accounting'),  // max severity vyhrává
            $this->sectionCard('review', 'accounting'),
            $this->sectionCard('review', '_top'),        // sentinel je platný klíč
            $this->sectionCard('review', '_top'),
        ]);

        $this->assertSame(3, $badges['accounting']['count']);
        $this->assertSame('danger', $badges['accounting']['severity']);
        $this->assertSame(['count' => 2, 'severity' => 'warning'], $badges['_top']);
    }

    public function testSectionBadgesEmptyFeedYieldsEmptyMap(): void
    {
        $this->assertSame([], (new FeedCollector())->sectionBadges([]));
    }

    // ── stripInternalFields ──────────────────────────────────────────────────

    public function testStripInternalFieldsRemovesAmountAndCurrency(): void
    {
        $collector = new FeedCollector();
        $stripped = $collector->stripInternalFields([
            ['id' => 'a', 'kind' => 'ready', 'amount' => 500.00, 'currency' => 'CZK', 'confidencePct' => 92],
            $this->card('info', null, 'i'),
        ]);

        foreach ($stripped as $card) {
            $this->assertArrayNotHasKey('amount', $card);
            $this->assertArrayNotHasKey('currency', $card);
        }
        // Ostatní pole zůstávají.
        $this->assertSame(92, $stripped[0]['confidencePct']);
    }
}
