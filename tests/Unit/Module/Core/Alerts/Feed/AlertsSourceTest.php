<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Alerts\Feed;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Feed\FeedContext;
use Shipard\Module\Core\Alerts\Feed\AlertsSource;

/**
 * Unit testy pro AlertsSource.
 *
 * Pokrývají:
 *   - severity → kind mapping (error→urgent, warning→review, info→info)
 *   - actions passthrough beze změny (open_form/open_viewer)
 *   - title/subtitle/timestamp/id tvar
 *   - prázdný vstup → []
 */
final class AlertsSourceTest extends TestCase
{
    /**
     * @param list<array<string,mixed>> $rows
     */
    private function context(array $rows): FeedContext
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturn($rows);
        return new FeedContext($db, null, 'cs', 30);
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

    public function testErrorSeverityMapsToUrgent(): void
    {
        $src = new AlertsSource();
        $cards = $src->collectCards($this->context([$this->alertRow(30)]));

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
        $card = $src->collectCards($this->context([$this->alertRow(20)]))[0];
        $this->assertSame('review', $card['kind']);
        $this->assertSame('edit', $card['stateStyle']);
    }

    public function testInfoSeverityMapsToInfo(): void
    {
        $src = new AlertsSource();
        $card = $src->collectCards($this->context([$this->alertRow(10)]))[0];
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
        $card = $src->collectCards($this->context([$this->alertRow(30, 1, $actions)]))[0];

        $this->assertSame($actions, $card['actions']);
    }

    public function testInvalidActionsJsonYieldsEmpty(): void
    {
        $row = $this->alertRow(30);
        $row['actions'] = 'not-json{';
        $src = new AlertsSource();
        $card = $src->collectCards($this->context([$row]))[0];
        $this->assertSame([], $card['actions']);
    }

    public function testSubtitleFallsBackToCheckIdWhenNoMessage(): void
    {
        $row = $this->alertRow(20);
        $row['message'] = '';
        $src = new AlertsSource();
        $card = $src->collectCards($this->context([$row]))[0];
        $this->assertSame('base.persons.missing_own_person', $card['subtitle']);
    }

    public function testEmptyInputReturnsNoCards(): void
    {
        $src = new AlertsSource();
        $this->assertSame([], $src->collectCards($this->context([])));
    }
}
