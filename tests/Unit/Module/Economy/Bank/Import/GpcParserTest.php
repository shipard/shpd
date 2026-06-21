<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Bank\Import;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Economy\Bank\Import\Parsers\GpcParser;

class GpcParserTest extends TestCase
{
    private function fixture(): string
    {
        return file_get_contents(dirname(__DIR__, 5) . '/Fixtures/Bank/statement.gpc');
    }

    public function testStatementHeader(): void
    {
        $statements = (new GpcParser())->parse($this->fixture());
        $this->assertCount(1, $statements);
        $s = $statements[0];

        $this->assertNotSame('', $s->bankAccountRef);
        $this->assertSame('042', $s->statementNumber);
        $this->assertNull($s->currency); // GPC měnu nenese
        $this->assertEqualsWithDelta(1000.0, $s->openingBalance, 0.001);
        $this->assertEqualsWithDelta(2160.0, $s->closingBalance, 0.001);
        $this->assertSame('2026-06-01', $s->periodStart->format('Y-m-d'));
        $this->assertSame('2026-06-30', $s->periodEnd->format('Y-m-d'));
        $this->assertCount(2, $s->transactions);
    }

    public function testIncomingRow(): void
    {
        $t = (new GpcParser())->parse($this->fixture())[0]->transactions[0];

        $this->assertNull($t->externalId); // GPC stabilní ID nemá
        $this->assertEqualsWithDelta(1210.0, $t->amount, 0.001); // typ 2 = příjem (+)
        $this->assertSame('2026-06-10', $t->dateTransaction->format('Y-m-d'));
        $this->assertSame('12345', $t->paymentReference);
        $this->assertSame('678', $t->specificSymbol);
        $this->assertNull($t->constantSymbol); // KS 0000 → null
        $this->assertStringContainsString('/0800', (string) $t->counterpartyAccount);
        $this->assertSame('Platba faktura 12345', $t->message);
    }

    public function testOutgoingRowWithMemoContinuation(): void
    {
        $t = (new GpcParser())->parse($this->fixture())[0]->transactions[1];

        $this->assertEqualsWithDelta(-50.0, $t->amount, 0.001); // typ 1 = výdaj (−)
        $this->assertNull($t->paymentReference);
        // memo 075 + continuation 078
        $this->assertSame('Bankovni poplatek Poplatek za vedeni', $t->message);
    }
}
