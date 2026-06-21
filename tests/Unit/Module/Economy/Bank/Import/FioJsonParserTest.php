<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Bank\Import;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Economy\Bank\Import\Parsers\FioJsonParser;

class FioJsonParserTest extends TestCase
{
    private function fixture(): string
    {
        return file_get_contents(dirname(__DIR__, 5) . '/Fixtures/Bank/fio.json');
    }

    public function testStatementHeader(): void
    {
        $statements = (new FioJsonParser())->parse($this->fixture());
        $this->assertCount(1, $statements);
        $s = $statements[0];

        $this->assertSame('2900123456', $s->bankAccountRef);
        $this->assertSame('42', $s->statementNumber);
        $this->assertSame('CZK', $s->currency);
        $this->assertEqualsWithDelta(1000.0, $s->openingBalance, 0.001);
        $this->assertEqualsWithDelta(2160.0, $s->closingBalance, 0.001);
        $this->assertSame('2026-06-01', $s->periodStart->format('Y-m-d'));
        $this->assertSame('2026-06-30', $s->periodEnd->format('Y-m-d'));
        $this->assertCount(2, $s->transactions);
    }

    public function testIncomingTransaction(): void
    {
        $t = (new FioJsonParser())->parse($this->fixture())[0]->transactions[0];

        // column22 obnovené jako externalId
        $this->assertSame('EXT-FIO-001', $t->externalId);
        $this->assertEqualsWithDelta(1210.0, $t->amount, 0.001);
        $this->assertSame('2026-06-10', $t->dateTransaction->format('Y-m-d'));
        $this->assertSame('123456789/0800', $t->counterpartyAccount);
        $this->assertSame('Alfa s.r.o.', $t->counterpartyName);
        $this->assertSame('12345', $t->paymentReference);
        $this->assertSame('678', $t->specificSymbol);
        $this->assertNull($t->constantSymbol); // KS 0000 → null
        // memo sloučené, opakovaný název protistrany sloučen
        $this->assertSame('Platba za fakturu 12345 Alfa s.r.o.', $t->message);
    }

    public function testOutgoingTransaction(): void
    {
        $t = (new FioJsonParser())->parse($this->fixture())[0]->transactions[1];

        $this->assertSame('EXT-FIO-002', $t->externalId);
        $this->assertEqualsWithDelta(-50.0, $t->amount, 0.001);
        $this->assertSame('Bankovni poplatek', $t->message);
    }
}
