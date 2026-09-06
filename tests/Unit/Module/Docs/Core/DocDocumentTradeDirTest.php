<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\Core;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Module\Docs\Core\CashDirection;
use Shipard\Module\Docs\Core\DocDocument;

/**
 * DocDocument::resolveTradeDir — jediná autorita směru obchodu (snapshoty,
 * DocRowsForm, DocsHeadsViewer). Čtyři větve dle tasks/docs-core-bound-series.md §5.
 */
class DocDocumentTradeDirTest extends TestCase
{
    private const DOC_TYPES = [
        'invno'  => ['trade_dir' => 1],
        'invni'  => ['trade_dir' => 2],
        'cmnbkp' => ['trade_dir' => 0],
        'cashb'  => ['trade_dir' => 0, 'trade_dir_column' => 'cash_dir', 'series_binding' => 'cash_desk'],
        'odd'    => ['trade_dir' => 0, 'trade_dir_column' => 'unknown_column'],
        'legacy' => [],
    ];

    private function config(?array $docTypes = self::DOC_TYPES): ConfigRuntime
    {
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnCallback(
            static fn (string $id): mixed => $id === 'docs.core.docTypes' ? $docTypes : null,
        );
        return $config;
    }

    public function testUnknownTypeOrMissingConfigIsNull(): void
    {
        $this->assertNull(DocDocument::resolveTradeDir(['doc_type' => 'nope'], $this->config()));
        $this->assertNull(DocDocument::resolveTradeDir([], $this->config()));
        $this->assertNull(DocDocument::resolveTradeDir(['doc_type' => 'invno'], null));
        $this->assertNull(DocDocument::resolveTradeDir(['doc_type' => 'invno'], $this->config(null)));
    }

    public function testFixedTradeDirIsReturnedRegardlessOfCashDir(): void
    {
        $config = $this->config();
        $this->assertSame(1, DocDocument::resolveTradeDir(['doc_type' => 'invno'], $config));
        $this->assertSame(2, DocDocument::resolveTradeDir(['doc_type' => 'invni'], $config));
        // cash_dir na faktuře směr nemění (validace ho drží na 0)
        $this->assertSame(1, DocDocument::resolveTradeDir(['doc_type' => 'invno', 'cash_dir' => 2], $config));
    }

    public function testTradeDirColumnMapsCashDirPerDocument(): void
    {
        $config = $this->config();
        $this->assertSame(1, DocDocument::resolveTradeDir(['doc_type' => 'cashb', 'cash_dir' => 1], $config));
        $this->assertSame(2, DocDocument::resolveTradeDir(['doc_type' => 'cashb', 'cash_dir' => '2'], $config));
        $this->assertNull(DocDocument::resolveTradeDir(['doc_type' => 'cashb', 'cash_dir' => 0], $config));
        $this->assertNull(DocDocument::resolveTradeDir(['doc_type' => 'cashb'], $config));
        $this->assertNull(DocDocument::resolveTradeDir(['doc_type' => 'cashb', 'cash_dir' => 7], $config));
    }

    public function testTypeWithoutSidesIsNull(): void
    {
        $config = $this->config();
        $this->assertNull(DocDocument::resolveTradeDir(['doc_type' => 'cmnbkp'], $config));
        $this->assertNull(DocDocument::resolveTradeDir(['doc_type' => 'cmnbkp', 'cash_dir' => 1], $config));
        $this->assertNull(DocDocument::resolveTradeDir(['doc_type' => 'legacy'], $config));
        // jiný než povolený trade_dir_column se ignoruje
        $this->assertNull(DocDocument::resolveTradeDir(['doc_type' => 'odd', 'unknown_column' => 1], $config));
    }

    public function testCashDirectionEnumMapsToTradeDir(): void
    {
        $this->assertSame(1, CashDirection::Receipt->tradeDir());
        $this->assertSame(2, CashDirection::Disbursement->tradeDir());
        $this->assertNull(CashDirection::NotApplicable->tradeDir());
        $this->assertSame(CashDirection::Disbursement, CashDirection::tryFrom(2));
        $this->assertNull(CashDirection::tryFrom(3));
    }
}
