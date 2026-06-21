<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Bank\Import;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Economy\Bank\Import\Parsers\CbaXmlParser;

class CbaXmlParserTest extends TestCase
{
    private function fixture(): string
    {
        return file_get_contents(dirname(__DIR__, 5) . '/Fixtures/Bank/camt053.xml');
    }

    public function testStatementHeader(): void
    {
        $statements = (new CbaXmlParser())->parse($this->fixture());
        $this->assertCount(1, $statements);
        $s = $statements[0];

        $this->assertSame('CZ6508000000192000145399', $s->bankAccountRef);
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
        $t = (new CbaXmlParser())->parse($this->fixture())[0]->transactions[0];

        $this->assertSame('REF-INC-001', $t->externalId);
        $this->assertEqualsWithDelta(1210.0, $t->amount, 0.001);
        // dateTransaction = BookgDt (ne ValDt)
        $this->assertSame('2026-06-10', $t->dateTransaction->format('Y-m-d'));
        $this->assertSame('2026-06-09', $t->dateValue->format('Y-m-d'));
        $this->assertSame('12345', $t->paymentReference);
        $this->assertSame('678', $t->specificSymbol);
        $this->assertSame('123456789/0800', $t->counterpartyAccount);
        $this->assertSame('Alfa s.r.o.', $t->counterpartyName);
        $this->assertSame('Platba za fakturu 12345', $t->message);
    }

    public function testOutgoingTransaction(): void
    {
        $t = (new CbaXmlParser())->parse($this->fixture())[0]->transactions[1];

        $this->assertSame('REF-OUT-001', $t->externalId);
        $this->assertEqualsWithDelta(-50.0, $t->amount, 0.001);
        $this->assertSame('Banka a.s.', $t->counterpartyName);
        // memo = Ustrd + AddtlTxInf sloučené
        $this->assertSame('Bankovni poplatek Poplatek za vedeni uctu', $t->message);
    }

    public function testInvalidXmlThrows(): void
    {
        $this->expectException(\Shipard\Module\Economy\Bank\Import\ImportException::class);
        (new CbaXmlParser())->parse('<not-camt/>');
    }
}
