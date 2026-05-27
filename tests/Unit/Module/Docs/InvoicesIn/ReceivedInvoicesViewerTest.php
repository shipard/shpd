<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\InvoicesIn;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Docs\InvoicesIn\ReceivedInvoicesViewer;

class ReceivedInvoicesViewerTest extends TestCase
{
    public function testSelectRowsAppliesInvniFilter(): void
    {
        $captured = [];
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturnCallback(
            function (...$args) use (&$captured): array {
                $captured = $args;
                return [];
            },
        );

        $viewer = new ReceivedInvoicesViewer($db, 'docs_core_heads');
        $viewer->selectRows(null, [], 0);

        $sql = (string) ($captured[0] ?? '');
        $this->assertStringContainsString('h.`doc_type` = %s', $sql);
        $this->assertContains('invni', $captured);
    }

    public function testGetNewRecordDefaultsReturnsInvni(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $viewer = new ReceivedInvoicesViewer($db, 'docs_core_heads');

        $this->assertSame(['doc_type' => 'invni'], $viewer->getNewRecordDefaults());
    }

    public function testGetNumberSeriesFiltersByTypeAndConfirmedState(): void
    {
        $captured = [];
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturnCallback(
            function (...$args) use (&$captured): array {
                $captured = $args;
                return [
                    ['id' => 1, 'name' => 'FPB tuzemsko'],
                    ['id' => 2, 'name' => 'FPB zahraničí'],
                ];
            },
        );

        $viewer = new ReceivedInvoicesViewer($db, 'docs_core_heads');
        $series = $viewer->getNumberSeries();

        $sql = (string) ($captured[0] ?? '');
        $this->assertStringContainsString('`doc_type` = %s', $sql);
        $this->assertStringContainsString('`docState` = 40', $sql);
        $this->assertStringContainsString('ORDER BY `name` ASC', $sql);
        $this->assertContains('invni', $captured);
        $this->assertSame(
            [['id' => 1, 'name' => 'FPB tuzemsko'], ['id' => 2, 'name' => 'FPB zahraničí']],
            $series,
        );
    }
}
