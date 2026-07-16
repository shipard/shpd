<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Alerts\Feed;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Alerts\AlertCheckRegistry;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Feed\FeedContext;
use Shipard\Core\Module\ModuleDefinition;
use Shipard\Module\Core\Alerts\Feed\AlertsSource;

/**
 * Unit testy pro AlertsSource.
 *
 * Pokrývají:
 *   - severity → kind mapping (error→urgent, warning→review, info→info)
 *   - actions passthrough beze změny (open_form/open_viewer)
 *   - title/subtitle/timestamp/id tvar
 *   - prázdný vstup → []
 *   - agregaci per check (práh GROUP_THRESHOLD = 3): skupinová karta
 *     nahrazuje individuální, titulek z registru s fallbackem na check_id,
 *     kind dle MAX(severity), jediná primary open_viewer akce
 */
final class AlertsSourceTest extends TestCase
{
    /**
     * Dvoufázový mock: agregátní dotaz (obsahuje GROUP BY) vrací `$groups`,
     * dotaz na individuální řádky vrací `$rows`. Fáze 2 se při prázdném
     * seznamu checků pod prahem vůbec nevolá — počet volání není konstantní,
     * proto callback dle SQL, ne willReturnOnConsecutiveCalls.
     *
     * @param list<array<string,mixed>> $groups
     * @param list<array<string,mixed>> $rows
     */
    private function context(array $groups, array $rows = [], string $lang = 'cs', ?int &$fetchCalls = null): FeedContext
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturnCallback(
            static function (mixed ...$args) use ($groups, $rows, &$fetchCalls): array {
                $fetchCalls = ($fetchCalls ?? 0) + 1;
                return str_contains((string) $args[0], 'GROUP BY') ? $groups : $rows;
            },
        );
        return new FeedContext($db, null, $lang, 30);
    }

    /** @return array<string,mixed> agregátní řádek fáze 1 */
    private function groupRow(
        string $checkId,
        int $cnt,
        int $maxSeverity,
        ?string $lastAt = '2026-06-28 12:00:00',
        ?string $firstAt = '2026-06-01 08:00:00',
    ): array {
        return [
            'check_id'     => $checkId,
            'cnt'          => $cnt,
            'max_severity' => $maxSeverity,
            'last_at'      => $lastAt,
            'first_at'     => $firstAt,
        ];
    }

    /** @return array<string,mixed> */
    private function alertRow(int $severity, int $id = 1, ?array $actions = null): array
    {
        return [
            'id'            => $id,
            'check_id'      => 'base.persons.missing_own_person',
            'title'         => 'Chybí vlastní firma',
            'message'       => 'Založ vlastní Osobu.',
            'severity'      => $severity,
            'actions'       => $actions === null ? null : json_encode($actions),
            'first_seen_at' => '2026-06-01 08:00:00',
            'last_seen_at'  => '2026-06-28 12:00:00',
        ];
    }

    /** Kontext pro jediný individuální alert (check pod prahem). */
    private function singleAlertContext(array $row): FeedContext
    {
        return $this->context(
            [$this->groupRow((string) $row['check_id'], 1, (int) $row['severity'])],
            [$row],
        );
    }

    // ── Individuální karty (checky pod prahem) ──────────────────────────────

    public function testErrorSeverityMapsToUrgent(): void
    {
        $src = new AlertsSource();
        $cards = $src->collectCards($this->singleAlertContext($this->alertRow(30)));

        $this->assertCount(1, $cards);
        $card = $cards[0];
        $this->assertSame('alert:1', $card['id']);
        $this->assertSame('alerts', $card['source']);
        $this->assertSame('urgent', $card['kind']);
        $this->assertSame('error', $card['stateStyle']);
        $this->assertSame('other', $card['category']);
        $this->assertSame('Chybí vlastní firma', $card['title']);
        $this->assertSame('Založ vlastní Osobu.', $card['subtitle']);
        $this->assertSame('2026-06-28T12:00:00+00:00', $card['timestamp']);
        $this->assertSame(30, $card['context']['severity']);
    }

    public function testWarningSeverityMapsToReview(): void
    {
        $src = new AlertsSource();
        $card = $src->collectCards($this->singleAlertContext($this->alertRow(20)))[0];
        $this->assertSame('review', $card['kind']);
        $this->assertSame('edit', $card['stateStyle']);
    }

    public function testInfoSeverityMapsToInfo(): void
    {
        $src = new AlertsSource();
        $card = $src->collectCards($this->singleAlertContext($this->alertRow(10)))[0];
        $this->assertSame('info', $card['kind']);
        $this->assertSame('concept', $card['stateStyle']);
    }

    public function testActionsPassthrough(): void
    {
        $actions = [
            ['id' => 'create_own_person', 'label' => 'Přidat vlastní firmu', 'kind' => 'open_form',
             'target' => ['table' => 'base_persons_persons', 'mode' => 'create'], 'primary' => true],
        ];
        $src = new AlertsSource();
        $card = $src->collectCards($this->singleAlertContext($this->alertRow(30, 1, $actions)))[0];

        $this->assertSame($actions, $card['actions']);
    }

    public function testInvalidActionsJsonYieldsEmpty(): void
    {
        $row = $this->alertRow(30);
        $row['actions'] = 'not-json{';
        $src = new AlertsSource();
        $card = $src->collectCards($this->singleAlertContext($row))[0];
        $this->assertSame([], $card['actions']);
    }

    public function testSubtitleFallsBackToCheckIdWhenNoMessage(): void
    {
        $row = $this->alertRow(20);
        $row['message'] = '';
        $src = new AlertsSource();
        $card = $src->collectCards($this->singleAlertContext($row))[0];
        $this->assertSame('base.persons.missing_own_person', $card['subtitle']);
    }

    public function testEmptyInputReturnsNoCards(): void
    {
        $src = new AlertsSource();
        $this->assertSame([], $src->collectCards($this->context([])));
    }

    // ── Agregace per check (skupinové karty) ────────────────────────────────

    public function testFourAlertsOfOneCheckCollapseIntoSingleGroupCard(): void
    {
        $fetchCalls = 0;
        $ctx = $this->context(
            [$this->groupRow('docs.core.stale_in_repair', 4, 20)],
            [$this->alertRow(20)], // fáze 2 se nesmí spustit — řádky by vyrobily kartu navíc
            'cs',
            $fetchCalls,
        );

        $src = new AlertsSource();
        $cards = $src->collectCards($ctx);

        $this->assertCount(1, $cards);
        $card = $cards[0];
        $this->assertSame('alert-group:docs.core.stale_in_repair', $card['id']);
        $this->assertSame('alerts', $card['source']);
        $this->assertSame('other', $card['category']);
        $this->assertStringContainsString('4', $card['subtitle']);
        $this->assertSame('4 upozornění', $card['subtitle']);
        $this->assertSame(4, $card['context']['count']);
        $this->assertTrue($card['context']['group']);
        $this->assertSame('2026-06-28T12:00:00+00:00', $card['timestamp']);
        $this->assertSame(1, $fetchCalls, 'fáze 2 se při prázdném seznamu checků pod prahem nevolá');
    }

    public function testThreeAlertsStayIndividual(): void
    {
        $ctx = $this->context(
            [$this->groupRow('base.persons.missing_own_person', 3, 20)],
            [$this->alertRow(20, 1), $this->alertRow(20, 2), $this->alertRow(20, 3)],
        );

        $src = new AlertsSource();
        $cards = $src->collectCards($ctx);

        $this->assertCount(3, $cards);
        $this->assertSame(['alert:1', 'alert:2', 'alert:3'], array_column($cards, 'id'));
    }

    public function testMixedChecksYieldGroupAndIndividualCards(): void
    {
        $ctx = $this->context(
            [
                $this->groupRow('docs.core.stale_in_repair', 5, 30),
                $this->groupRow('base.persons.missing_own_person', 2, 20),
            ],
            [$this->alertRow(20, 1), $this->alertRow(20, 2)],
        );

        $src = new AlertsSource();
        $cards = $src->collectCards($ctx);

        $this->assertCount(3, $cards);
        $this->assertSame(
            ['alert-group:docs.core.stale_in_repair', 'alert:1', 'alert:2'],
            array_column($cards, 'id'),
        );
    }

    public function testGroupCardKindFollowsMaxSeverity(): void
    {
        $src = new AlertsSource();

        $urgent = $src->collectCards($this->context([$this->groupRow('x.y.err', 4, 30)]))[0];
        $this->assertSame('urgent', $urgent['kind']);
        $this->assertSame('error', $urgent['stateStyle']);

        $info = $src->collectCards($this->context([$this->groupRow('x.y.info', 4, 10)]))[0];
        $this->assertSame('info', $info['kind']);
        $this->assertSame('concept', $info['stateStyle']);

        $review = $src->collectCards($this->context([$this->groupRow('x.y.warn', 4, 20)]))[0];
        $this->assertSame('review', $review['kind']);
        $this->assertSame('edit', $review['stateStyle']);
    }

    public function testGroupCardTitleComesFromRegistry(): void
    {
        $registry = new AlertCheckRegistry([ModuleDefinition::fromArray([
            'id'          => 'docs.core',
            'name'        => 'Docs Core',
            'alertChecks' => [[
                'id'       => 'docs.core.stale_in_repair',
                'name'     => 'Doklady dlouho v opravě',
                'class'    => 'X',
                'interval' => '1h',
            ]],
        ])], 'cs');

        $src = new AlertsSource($registry);
        $card = $src->collectCards($this->context([$this->groupRow('docs.core.stale_in_repair', 4, 20)]))[0];

        $this->assertSame('Doklady dlouho v opravě', $card['title']);
    }

    public function testGroupCardTitleFallsBackToCheckId(): void
    {
        // Bez registru (null).
        $src = new AlertsSource();
        $card = $src->collectCards($this->context([$this->groupRow('docs.core.stale_in_repair', 4, 20)]))[0];
        $this->assertSame('docs.core.stale_in_repair', $card['title']);

        // Registr bez daného checku (check mezitím zmizel z modulu).
        $src = new AlertsSource(new AlertCheckRegistry([], 'cs'));
        $card = $src->collectCards($this->context([$this->groupRow('docs.core.stale_in_repair', 4, 20)]))[0];
        $this->assertSame('docs.core.stale_in_repair', $card['title']);
    }

    public function testGroupCardActionOpensAlertsViewer(): void
    {
        $src = new AlertsSource();

        $cs = $src->collectCards($this->context([$this->groupRow('x.y.z', 4, 20)], [], 'cs'))[0];
        $this->assertCount(1, $cs['actions']);
        $action = $cs['actions'][0];
        $this->assertSame('open_viewer', $action['kind']);
        $this->assertSame(['viewerId' => 'core.alerts.alerts'], $action['target']);
        $this->assertTrue($action['primary']);
        $this->assertSame('Otevřít upozornění', $action['label']);

        $en = $src->collectCards($this->context([$this->groupRow('x.y.z', 4, 20)], [], 'en'))[0];
        $this->assertSame('Open alerts', $en['actions'][0]['label']);
        $this->assertSame('4 alerts', $en['subtitle']);
    }
}
