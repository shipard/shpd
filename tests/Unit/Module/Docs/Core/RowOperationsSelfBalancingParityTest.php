<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\Core;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Utils\JsoncParser;

/**
 * Parita vlajky `selfBalancing` (docs.core.rowOperations) s účtovacím
 * předpisem (economy.accounting.rules.cz): operace je samovyvažující právě
 * tehdy, když její kroky předpisu mají fixní strany (side na kroku, bez
 * sideSrc) a pokrývají MD i DAL. Kontrola vyrovnanosti cmnbkp
 * (AccountingDocument) na vlajku spoléhá — rozjetí s předpisem by kontrolu
 * buď otupilo, nebo vrátilo falešné `unbalanced`.
 */
class RowOperationsSelfBalancingParityTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $rowOperations;

    /** @var list<array<string, mixed>> */
    private array $documents;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 5);
        $this->rowOperations = JsoncParser::parseFile(
            $root . '/modules/docs/core/config/rowOperations.jsonc',
        );
        $rules = JsoncParser::parseFile(
            $root . '/modules/economy/accounting/config/accountingRules.cz.jsonc',
        );
        $this->documents = $rules['documents'];
    }

    public function testFlagMatchesPredpisSidesForEveryOperation(): void
    {
        foreach ($this->documents as $docRules) {
            $docType = (string) $docRules['docType'];

            // Per operace: fixní strany kroků (side bez sideSrc). Kroky bez
            // `operation` (head/vat/rounding) se řádkových pohybů netýkají.
            $fixedSides = [];
            foreach ($docRules['accounting'] as $step) {
                $op = $step['operation'] ?? null;
                if ($op === null) {
                    continue;
                }
                $fixedSides[$op] ??= [];
                if (!isset($step['sideSrc']) && isset($step['side'])) {
                    $fixedSides[$op][(int) $step['side']] = true;
                }
            }

            foreach ($fixedSides as $op => $sides) {
                $coversBoth = isset($sides[0], $sides[1]);
                $flagged = !empty($this->rowOperations[$op]['selfBalancing']);
                $this->assertSame(
                    $coversBoth,
                    $flagged,
                    "Operace '{$op}' ({$docType}): kroky předpisu "
                    . ($coversBoth ? 'pokrývají obě strany' : 'obě strany nepokrývají')
                    . ' — vlajka selfBalancing tomu neodpovídá.',
                );
            }
        }
    }

    public function testEveryFlaggedOperationHasBothSidedStepsSomewhere(): void
    {
        // Ochrana proti vlajce bez předpisu: operace flagnutá selfBalancing
        // bez oboustranných kroků by prošla vyrovnaností, ale nezaúčtovala by
        // obě strany.
        $flagged = array_keys(array_filter(
            $this->rowOperations,
            fn($attrs) => is_array($attrs) && !empty($attrs['selfBalancing']),
        ));
        $this->assertNotEmpty($flagged, 'FX čtveřice má nést selfBalancing: 1.');

        foreach ($flagged as $op) {
            $found = false;
            foreach ($this->documents as $docRules) {
                $sides = [];
                foreach ($docRules['accounting'] as $step) {
                    if (($step['operation'] ?? null) === $op
                        && !isset($step['sideSrc']) && isset($step['side'])) {
                        $sides[(int) $step['side']] = true;
                    }
                }
                if (isset($sides[0], $sides[1])) {
                    $found = true;
                    break;
                }
            }
            $this->assertTrue(
                $found,
                "Operace '{$op}' má selfBalancing: 1, ale žádný docType"
                . ' nemá kroky předpisu pokrývající obě strany.',
            );
        }
    }
}
