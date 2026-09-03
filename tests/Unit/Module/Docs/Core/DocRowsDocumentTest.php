<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\Core;

use Dibi\Connection;
use PHPUnit\Framework\TestCase;
use Shipard\Module\Docs\Core\DocRowsDocument;

/**
 * DocRowsDocument::beforeSave — automatické order_pos nového řádku
 * (MAX + 1 v rámci dokladu), explicitní pořadí a update beze změny.
 */
class DocRowsDocumentTest extends TestCase
{
    private function docWithMax(mixed $max, bool $expectQuery = true): DocRowsDocument
    {
        $db = $this->createMock(Connection::class);
        if ($expectQuery) {
            $db->expects($this->once())->method('fetchSingle')->willReturnCallback(
                function (mixed ...$args) use ($max): mixed {
                    $this->assertStringContainsString('MAX([order_pos])', $args[0]);
                    $this->assertStringContainsString('[doc_head] = %i', $args[0]);
                    $this->assertSame(7, $args[1]);
                    return $max;
                },
            );
        } else {
            $db->expects($this->never())->method('fetchSingle');
        }
        $doc = new DocRowsDocument();
        $doc->setDb($db);
        return $doc;
    }

    public function testNewRowWithoutOrderGetsMaxPlusOne(): void
    {
        $doc = $this->docWithMax(3);
        $data = ['doc_head' => 7, 'row_kind' => 1, 'description' => 'Ukázka'];
        $doc->beforeSave($data, null);
        $this->assertSame(4, $data['order_pos']);
    }

    public function testNewRowWithZeroOrderGetsMaxPlusOne(): void
    {
        $doc = $this->docWithMax('12');
        $data = ['doc_head' => 7, 'order_pos' => 0];
        $doc->beforeSave($data, null);
        $this->assertSame(13, $data['order_pos']);
    }

    public function testFirstRowOfEmptyDocumentGetsOne(): void
    {
        // MAX() nad prázdnou skupinou vrací NULL
        $doc = $this->docWithMax(null);
        $data = ['doc_head' => 7, 'order_pos' => null];
        $doc->beforeSave($data, null);
        $this->assertSame(1, $data['order_pos']);
    }

    public function testExplicitOrderIsKept(): void
    {
        $doc = $this->docWithMax(9, expectQuery: false);
        $data = ['doc_head' => 7, 'order_pos' => 2];
        $doc->beforeSave($data, null);
        $this->assertSame(2, $data['order_pos']);
    }

    public function testUpdateDoesNotTouchOrder(): void
    {
        $doc = $this->docWithMax(9, expectQuery: false);
        $data = ['id' => 55, 'doc_head' => 7, 'order_pos' => 0];
        $doc->beforeSave($data, ['id' => 55, 'doc_head' => 7, 'order_pos' => 0]);
        $this->assertSame(0, $data['order_pos']);
    }

    public function testRowWithoutHeadIsLeftAlone(): void
    {
        $doc = $this->docWithMax(9, expectQuery: false);
        $data = ['description' => 'x'];
        $doc->beforeSave($data, null);
        $this->assertArrayNotHasKey('order_pos', $data);
    }
}
