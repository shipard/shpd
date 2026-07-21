<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Accounting;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Module\Economy\Accounting\AccountingEngine;

/**
 * Řádkové kroky předpisu pro zálohy a majetek (vlna C): krok
 * `accountSrc: "row"` s `reverseSign` otočí záporný řádek na kladný zápis
 * na straně kroku a operace s `rowPaymentId` razítkuje platební identitu
 * z řádku místo z hlavičky. Engine je final — kroky se volají reflexí
 * přes privátní buildRowLines (konvence projektu).
 */
class AccountingEngineRowStepsTest extends TestCase
{
    private const HEAD = [
        'partner'           => 7,
        'payment_reference' => '20260042',
        'specific_symbol'   => '777',
        'constant_symbol'   => '0308',
        'due_date'          => '2026-07-10',
    ];

    private const ROW_OPERATIONS = [
        'purchase.advanceDeduction' => ['rowAccount' => 'direct', 'rowPaymentId' => 1],
        'purchase.asset'            => ['rowAccount' => 'direct'],
    ];

    /**
     * @param array<string, mixed> $account řádek economy_accounting_accounts
     */
    private function engine(array $account): AccountingEngine
    {
        $db = $this->createMock(\Dibi\Connection::class);
        $db->method('fetch')->willReturn(new \Dibi\Row($account));

        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnMap([
            ['docs.core.rowOperations', self::ROW_OPERATIONS],
        ]);

        return new AccountingEngine($db, $config);
    }

    /**
     * @param array<string, mixed> $step
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function buildRowLines(AccountingEngine $engine, array $step, array $rows): array
    {
        $method = new \ReflectionMethod(AccountingEngine::class, 'buildRowLines');
        return $method->invoke($engine, $step, self::HEAD, $rows);
    }

    public function testReverseSignBooksNegativeRowPositiveOnStepSide(): void
    {
        $engine = $this->engine(['id' => 42, 'number' => '314901']);
        $step = [
            'src'         => 'rows',
            'accountSrc'  => 'row',
            'side'        => 1,
            'reverseSign' => 1,
            'operation'   => 'purchase.advanceDeduction',
        ];
        $rows = [[
            'id'                => 11,
            'operation'         => 'purchase.advanceDeduction',
            'description'       => 'Odpočet zálohy',
            'account'           => 42,
            'vat_base_dom'      => -5000.0,
            'vat_base'          => -5000.0,
            'payment_reference' => 'ZAL-1',
        ]];

        $lines = $this->buildRowLines($engine, $step, $rows);

        $this->assertCount(1, $lines);
        $line = $lines[0];
        $this->assertSame(1, $line['side']);
        $this->assertEqualsWithDelta(5000.0, $line['money_cr'], 0.001);
        $this->assertEqualsWithDelta(0.0, $line['money_dr'], 0.001);
        $this->assertEqualsWithDelta(5000.0, $line['money_cr_cur'], 0.001);
        $this->assertSame('314901', $line['account_number']);
        $this->assertFalse($line['is_error']);
        $this->assertSame('purchase.advanceDeduction', $line['operation']);
    }

    public function testRowPaymentIdStampsIdentityFromRowNotHead(): void
    {
        $engine = $this->engine(['id' => 42, 'number' => '314901']);
        $step = [
            'src'        => 'rows',
            'accountSrc' => 'row',
            'side'       => 1,
            'operation'  => 'purchase.advanceDeduction',
        ];
        $rows = [[
            'id'                => 11,
            'operation'         => 'purchase.advanceDeduction',
            'account'           => 42,
            'vat_base_dom'      => 5000.0,
            'vat_base'          => 5000.0,
            'payment_reference' => 'ZAL-1',
            'specific_symbol'   => null,
            'constant_symbol'   => null,
            'due_date'          => null,
        ]];

        $lines = $this->buildRowLines($engine, $step, $rows);

        $this->assertCount(1, $lines);
        $this->assertSame('ZAL-1', $lines[0]['payment_reference']);
        $this->assertNull($lines[0]['specific_symbol']);
        $this->assertNull($lines[0]['due_date']);
        // rowPartner vlajku operace nemá → partner zůstává z hlavičky.
        $this->assertSame(7, $lines[0]['partner']);
    }

    public function testOperationWithoutPaymentIdFlagKeepsHeadIdentity(): void
    {
        $engine = $this->engine(['id' => 43, 'number' => '042001']);
        $step = [
            'src'        => 'rows',
            'accountSrc' => 'row',
            'side'       => 0,
            'operation'  => 'purchase.asset',
        ];
        $rows = [[
            'id'                => 12,
            'operation'         => 'purchase.asset',
            'account'           => 43,
            'vat_base_dom'      => 43538.0,
            'vat_base'          => 43538.0,
            'payment_reference' => 'ZAL-9',
        ]];

        $lines = $this->buildRowLines($engine, $step, $rows);

        $this->assertCount(1, $lines);
        $this->assertSame(0, $lines[0]['side']);
        $this->assertEqualsWithDelta(43538.0, $lines[0]['money_dr'], 0.001);
        $this->assertSame('20260042', $lines[0]['payment_reference']);
        $this->assertSame('777', $lines[0]['specific_symbol']);
        $this->assertSame('2026-07-10', $lines[0]['due_date']);
    }
}
