<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Accounting;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Utils\JsoncParser;

/**
 * Konzistence předpisu pro pokladnu (#59 D8) — programová kontrola nad
 * jsonc, bez DS:
 *
 *   1. maska card.transit a účet pokladny 211100 existují v obou seed rozvrzích,
 *   2. invno/invni: saldo krok má {"$ne": 0} a existuje právě jeden krok
 *      accountSrc cashDesk s query payment_method 0,
 *   3. cash/cashreg: každý pohyb z rowOperations povolený pro typ má krok
 *      předpisu a každý krok odkazuje na povolený pohyb (parita),
 *   4. cash: každý řádkový / DPH krok nese headQuery cash_dir (bez něj by
 *      účtoval obě strany).
 */
class CashAccountingRulesTest extends TestCase
{
    private const MODULES = __DIR__ . '/../../../../../modules';

    /** @return array<string, mixed> */
    private function rules(): array
    {
        return JsoncParser::parseFile(self::MODULES . '/economy/accounting/config/accountingRules.cz.jsonc');
    }

    /** @return list<array<string, mixed>> */
    private function stepsOf(string $docType): array
    {
        foreach ($this->rules()['documents'] as $doc) {
            if (($doc['docType'] ?? null) === $docType) {
                return array_values($doc['accounting']);
            }
        }
        $this->fail("Předpis nemá blok docType {$docType}");
    }

    /** @return array<string, list<string>> docType → povolené operace */
    private function allowedOperations(): array
    {
        $ops = JsoncParser::parseFile(self::MODULES . '/docs/core/config/rowOperations.jsonc');
        $out = [];
        foreach ($ops as $code => $entry) {
            foreach (array_keys($entry['docTypes'] ?? []) as $docType) {
                $out[$docType][] = (string) $code;
            }
        }
        return $out;
    }

    public function testCardTransitAndCashDeskAccountsExistInBothSeedCharts(): void
    {
        $masks = [];
        foreach ($this->rules()['accounts'] as $entry) {
            if (($entry['cat'] ?? null) === 'card.transit') {
                $masks[] = (string) $entry['accountMask'];
            }
        }
        $this->assertSame(['261100'], $masks, 'card.transit má jedinou pevnou analytiku');
        $this->assertArrayHasKey('card.transit', $this->rules()['categories']);

        foreach (['accountChartDefault', 'accountChartNpo'] as $chart) {
            $numbers = array_flip(array_map(
                fn($e) => (string) $e['number'],
                JsoncParser::parseFile(self::MODULES . "/economy/accounting/config/{$chart}.jsonc"),
            ));
            $this->assertArrayHasKey('261100', $numbers, "{$chart} nemá 261100");
            $this->assertArrayHasKey('211100', $numbers, "{$chart} nemá 211100 (výchozí účet pokladny)");
        }
    }

    public function testInvoicesBookCashPaymentOnCashDesk(): void
    {
        foreach (['invno' => 'receivables', 'invni' => 'payables'] as $docType => $balanceCat) {
            $steps = $this->stepsOf($docType);

            $balance = array_values(array_filter($steps, fn($s) => ($s['cat'] ?? null) === $balanceCat && ($s['src'] ?? null) === 'head'));
            $this->assertCount(1, $balance, "{$docType}: jeden saldo krok");
            $this->assertSame(['payment_method' => ['$ne' => 0]], $balance[0]['query'], "{$docType}: saldo jen mimo Hotovost");

            $cashDesk = array_values(array_filter($steps, fn($s) => ($s['accountSrc'] ?? null) === 'cashDesk'));
            $this->assertCount(1, $cashDesk, "{$docType}: jeden krok pokladny");
            $this->assertSame(['payment_method' => 0], $cashDesk[0]['query']);
            $this->assertSame($balance[0]['side'], $cashDesk[0]['side'], 'pokladna na téže straně jako saldo');
        }
    }

    public function testCashAndCashRegisterOperationsMatchRowOperations(): void
    {
        $allowed = $this->allowedOperations();

        foreach (['cash', 'cashreg'] as $docType) {
            $inSteps = [];
            foreach ($this->stepsOf($docType) as $step) {
                foreach (array_merge((array) ($step['operations'] ?? []), isset($step['operation']) ? [$step['operation']] : []) as $op) {
                    $inSteps[$op] = true;
                }
            }
            $this->assertEqualsCanonicalizing(
                $allowed[$docType],
                array_keys($inSteps),
                "{$docType}: pohyby předpisu ≠ pohyby povolené v rowOperations",
            );
        }
    }

    public function testCashRowAndVatStepsCarryHeadQueryDirection(): void
    {
        foreach ($this->stepsOf('cash') as $i => $step) {
            if (in_array($step['src'] ?? null, ['rows', 'vat'], true)) {
                $this->assertContains(
                    $step['headQuery']['cash_dir'] ?? null,
                    [1, 2],
                    "cash krok #{$i} ({$step['src']}) bez headQuery cash_dir",
                );
            } else {
                $dir = $step['headQuery']['cash_dir'] ?? $step['query']['cash_dir'] ?? null;
                $this->assertContains($dir, [1, 2], "cash head krok #{$i} bez směru");
            }
        }

        // protistrana: pro každý směr právě jeden krok pokladny a jeden karty
        foreach ([1, 2] as $dir) {
            $desk = array_filter($this->stepsOf('cash'), fn($s) => ($s['accountSrc'] ?? null) === 'cashDesk' && ($s['query']['cash_dir'] ?? null) === $dir);
            $card = array_filter($this->stepsOf('cash'), fn($s) => ($s['cat'] ?? null) === 'card.transit' && ($s['query']['cash_dir'] ?? null) === $dir);
            $this->assertCount(1, $desk, "cash_dir {$dir}: krok pokladny");
            $this->assertCount(1, $card, "cash_dir {$dir}: krok karty");
            $this->assertSame($dir === 1 ? 0 : 1, array_values($desk)[0]['side'], 'příjem MD, výdej DAL');
        }
    }
}
