<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Codebooks;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Economy\Codebooks\VatAgendaNavGate;

class VatAgendaNavGateTest extends TestCase
{
    /**
     * @param mixed $vatAgenda hodnota klíče economy.vatAgenda (null = klíč není)
     */
    private function db(mixed $vatAgenda, int $registrationCount): DataSourceConnection
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchSingle')->willReturnCallback(
            static function (mixed ...$args) use ($vatAgenda, $registrationCount): mixed {
                if (($args[1] ?? null) === 'economy.vatAgenda') {
                    return $vatAgenda === null ? null : json_encode($vatAgenda);
                }
                if (str_contains((string) $args[0], 'COUNT(*)')) {
                    return $registrationCount;
                }
                return null;
            },
        );
        return $db;
    }

    public function testNonPayerWithoutAnyRegistrationHides(): void
    {
        $this->assertFalse((new VatAgendaNavGate())->isVisible($this->db(false, 0)));
    }

    public function testNonPayerWithHistoricalRegistrationShows(): void
    {
        // D11: „nikdy neexistovala" — i ukončená/smazaná registrace agendu drží.
        $this->assertTrue((new VatAgendaNavGate())->isVisible($this->db(false, 1)));
    }

    public function testPayerShows(): void
    {
        $this->assertTrue((new VatAgendaNavGate())->isVisible($this->db(true, 0)));
    }

    public function testUndecidedKeyShows(): void
    {
        // Neúplné nastavení nesmí schovávat funkčnost.
        $this->assertTrue((new VatAgendaNavGate())->isVisible($this->db(null, 0)));
    }
}
