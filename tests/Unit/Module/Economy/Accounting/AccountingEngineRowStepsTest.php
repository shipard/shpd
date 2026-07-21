<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Accounting;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Module\Economy\Accounting\AccountingEngine;
use Shipard\Module\Economy\Accounting\AccountMaskResolver;

/**
 * Řádkové kroky předpisu pro zálohy a majetek (vlna C): krok
 * `accountSrc: "row"` s `reverseSign` otočí záporný řádek na kladný zápis
 * na straně kroku a operace s `rowPaymentId` razítkuje platební identitu
 * z řádku místo z hlavičky. Dodatek D10: kategorie s řetězem masek
 * (`accountMask` jako pole) a výběr položky kategorie pořadím + query.
 * Engine je final — kroky se volají reflexí přes privátní metody
 * (konvence projektu).
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

    // ── D10: kategorie s řetězem masek ──────────────────────────────────────

    private const ADVANCES_RECEIVED_RULES = [
        ['cat' => 'advances.received', 'accountMask' => '324', 'query' => ['vat_amount' => 0]],
        ['cat' => 'advances.received', 'accountMask' => ['3249', '324']],
    ];

    private const ADVANCES_GIVEN_RULES = [
        ['cat' => 'advances.given', 'accountMask' => '314', 'query' => ['vat_amount' => 0]],
        ['cat' => 'advances.given', 'accountMask' => '3149'],
    ];

    /**
     * Engine s reálným AccountMaskResolverem (final, nemockovatelný) nad
     * mockovaným spojením: dotaz na rozvrh (LIKE) vrací účet dle
     * $chartByMask, ostatní SQL (OwnCompanyResolver) null → země cz.
     *
     * @param array<string, array{id: int, number: string}> $chartByMask
     * @param list<array<string, mixed>> $accountsRules
     */
    private function engineWithChart(array $chartByMask, array $accountsRules): AccountingEngine
    {
        $db = $this->createMock(\Dibi\Connection::class);
        $db->method('fetch')->willReturnCallback(
            function (...$args) use ($chartByMask) {
                $sql = (string) ($args[0] ?? '');
                if (str_contains($sql, 'economy_accounting_accounts') && str_contains($sql, 'LIKE')) {
                    $mask = (string) ($args[1] ?? '');
                    return isset($chartByMask[$mask]) ? new \Dibi\Row($chartByMask[$mask]) : null;
                }
                return null;
            },
        );

        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnMap([
            ['economy.accounting.rules.cz', ['accounts' => $accountsRules]],
        ]);

        $engine = new AccountingEngine($db, $config);
        // maskResolver vzniká až v accountDocument — pro přímé volání
        // resolveCategoryAccount se injektuje reflexí.
        $prop = new \ReflectionProperty(AccountingEngine::class, 'maskResolver');
        $prop->setValue($engine, new AccountMaskResolver($db));
        return $engine;
    }

    /**
     * @param array<string, mixed> $record
     * @return array{id?: int, number: string, is_error?: bool}
     */
    private function resolveCategoryAccount(AccountingEngine $engine, string $cat, array $record): array
    {
        $method = new \ReflectionMethod(AccountingEngine::class, 'resolveCategoryAccount');
        return $method->invoke(
            $engine,
            ['cat' => $cat],
            $record,
            ['accounting_date' => '2026-06-10'],
            null,
        );
    }

    /** @return list<array{code: string, message: string, rowId: int|null}> */
    private function messagesOf(AccountingEngine $engine): array
    {
        $prop = new \ReflectionProperty(AccountingEngine::class, 'messages');
        return $prop->getValue($engine);
    }

    public function testMaskChainFallsBackToNextMask(): void
    {
        // Rozvrh bez 3249xx (vzor lefreal) — řetěz ["3249", "324"] spadne
        // na druhou masku.
        $engine = $this->engineWithChart(
            ['324' => ['id' => 50, 'number' => '324100']],
            self::ADVANCES_RECEIVED_RULES,
        );

        $account = $this->resolveCategoryAccount(
            $engine, 'advances.received', ['vat_amount' => '210.00'],
        );

        $this->assertSame(['id' => 50, 'number' => '324100'], $account);
        $this->assertSame([], $this->messagesOf($engine));
    }

    public function testMaskChainExhaustedProducesErrorAccount(): void
    {
        $engine = $this->engineWithChart([], self::ADVANCES_RECEIVED_RULES);

        $account = $this->resolveCategoryAccount(
            $engine, 'advances.received', ['vat_amount' => '210.00'],
        );

        $this->assertTrue($account['is_error']);
        $this->assertSame('3249??', $account['number']);
        $messages = $this->messagesOf($engine);
        $this->assertCount(1, $messages);
        $this->assertSame('account_not_found', $messages[0]['code']);
        $this->assertStringContainsString('3249, 324', $messages[0]['message']);
    }

    public function testCategoryEntrySelectionByOrderAndQuery(): void
    {
        // Loose porovnání query: vat_amount "0.00" i NULL → brutto maska
        // (první záznam), nenulová daň → zdaněná analytika.
        $chart = [
            '314'  => ['id' => 60, 'number' => '314100'],
            '3149' => ['id' => 61, 'number' => '314900'],
        ];

        foreach (['0.00', null] as $bruttoAmount) {
            $engine = $this->engineWithChart($chart, self::ADVANCES_GIVEN_RULES);
            $account = $this->resolveCategoryAccount(
                $engine, 'advances.given', ['vat_amount' => $bruttoAmount],
            );
            $this->assertSame('314100', $account['number'], 'vat_amount ' . var_export($bruttoAmount, true));
        }

        $engine = $this->engineWithChart($chart, self::ADVANCES_GIVEN_RULES);
        $account = $this->resolveCategoryAccount(
            $engine, 'advances.given', ['vat_amount' => '-1050.00'],
        );
        $this->assertSame('314900', $account['number']);
    }
}
