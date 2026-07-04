<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Api\Controller;

use PHPUnit\Framework\TestCase;
use Shipard\Api\Controller\AccbalController;
use Shipard\Api\Request;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Document\JournalEventDispatcher;
use Shipard\Module\Economy\Accbal\MatchSummary;

/**
 * Unit testy AccbalController::match — validace body (zrcadlí CLI accbal-match)
 * a tvar agregátní response. Běh matcheru je stubnutý přes runMatch() seam
 * (BalanceMatcher je final).
 */
class AccbalControllerTest extends TestCase
{
    private TestableAccbalController $controller;

    protected function setUp(): void
    {
        $this->controller = new TestableAccbalController(
            $this->createMock(DataSourceConnection::class),
            $this->createMock(ConfigRuntime::class),
            new JournalEventDispatcher([]),
        );
    }

    private function match(array $body): \Shipard\Api\Response
    {
        $request = Request::fromArray(
            'POST',
            '/api/v1/_accbal/match',
            [],
            json_encode($body, JSON_THROW_ON_ERROR),
            [],
        );
        return $this->controller->match($request);
    }

    private static function summary(int $allocated, int $planned, float $amount): MatchSummary
    {
        $s = new MatchSummary();
        $s->allocated = $allocated;
        $s->planned = $planned;
        $s->routedUnallocated = 1;
        $s->skipped = ['no_open_items' => 3];
        $s->matchedAmount = $amount;
        return $s;
    }

    // ── Validace ─────────────────────────────────────────────────────────────

    public function testEmptyBodyIs400(): void
    {
        $payload = $this->match([])->getPayload();
        $this->assertFalse($payload['success']);
        $this->assertSame('VALIDATION', $payload['error']['code']);
        $this->assertNull($this->controller->capturedDryRun, 'matcher nesmí běžet');
    }

    public function testUnsupportedScopeIs400(): void
    {
        $payload = $this->match(['scope' => 'nonsense'])->getPayload();
        $this->assertFalse($payload['success']);
        $this->assertSame('VALIDATION', $payload['error']['code']);
    }

    public function testNonIntPartnerIs400(): void
    {
        $payload = $this->match(['partner' => 'abc'])->getPayload();
        $this->assertFalse($payload['success']);
        $this->assertSame('VALIDATION', $payload['error']['code']);
    }

    public function testNonBoolDryRunIs400(): void
    {
        $payload = $this->match(['scope' => 'all', 'dryRun' => 'yes'])->getPayload();
        $this->assertFalse($payload['success']);
        $this->assertSame('VALIDATION', $payload['error']['code']);
    }

    // ── Happy path ───────────────────────────────────────────────────────────

    public function testScopeAllReturnsAggregateWithoutResults(): void
    {
        $this->controller->summary = self::summary(allocated: 5, planned: 0, amount: 1234.56);

        $payload = $this->match(['scope' => 'all'])->getPayload();

        $this->assertTrue($payload['success']);
        $data = $payload['data'];
        $this->assertSame(
            ['dryRun', 'candidates', 'allocated', 'planned', 'routedUnallocated', 'skipped', 'matchedAmount'],
            array_keys($data),
        );
        $this->assertArrayNotHasKey('results', $data);
        $this->assertFalse($data['dryRun']);
        $this->assertSame(5, $data['allocated']);
        $this->assertSame(0, $data['planned']);
        $this->assertSame(1, $data['routedUnallocated']);
        $this->assertSame(['no_open_items' => 3], $data['skipped']);
        $this->assertSame(1234.56, $data['matchedAmount']);

        $this->assertSame([], $this->controller->capturedFilters);
        $this->assertFalse($this->controller->capturedDryRun);
    }

    public function testFiltersWithoutScopeAreAccepted(): void
    {
        $payload = $this->match(['partner' => 42, 'fiscalYear' => 7])->getPayload();

        $this->assertTrue($payload['success']);
        $this->assertSame(['partner' => 42, 'fiscalYear' => 7], $this->controller->capturedFilters);
    }

    public function testDryRunPropagatesToMatcherAndResponse(): void
    {
        $this->controller->summary = self::summary(allocated: 0, planned: 8, amount: 999.99);

        $payload = $this->match(['scope' => 'all', 'dryRun' => true])->getPayload();

        $this->assertTrue($payload['success']);
        $this->assertTrue($payload['data']['dryRun']);
        $this->assertSame(0, $payload['data']['allocated']);
        $this->assertSame(8, $payload['data']['planned']);
        $this->assertTrue($this->controller->capturedDryRun);
    }
}

/**
 * Overriduje runMatch() seam — zaznamená argumenty a vrátí připravený agregát
 * místo reálného běhu BalanceMatcheru.
 */
class TestableAccbalController extends AccbalController
{
    public MatchSummary $summary;
    public ?array $capturedFilters = null;
    public ?bool $capturedDryRun = null;

    protected function runMatch(array $filters, bool $dryRun): MatchSummary
    {
        $this->capturedFilters = $filters;
        $this->capturedDryRun = $dryRun;
        return $this->summary ?? new MatchSummary();
    }
}
