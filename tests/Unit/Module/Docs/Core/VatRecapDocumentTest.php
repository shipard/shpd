<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\Core;

use Dibi\Connection;
use PHPUnit\Framework\TestCase;
use Shipard\Module\Docs\Core\VatRecapDocument;

/**
 * Řádek převzaté rekapitulace DPH: nový bez pořadí se zařadí na konec
 * (stejná úmluva jako u řádků dokladu). Přepočet hlavičky po uložení dělá
 * `DocHeadRecomputer` a ověřuje ho integrační proklik nad dev DS — tady
 * jde jen o pořadí.
 */
class VatRecapDocumentTest extends TestCase
{
    private function docWithMax(mixed $max, bool $expectQuery = true): VatRecapDocument
    {
        $db = $this->createMock(Connection::class);
        if ($expectQuery) {
            $db->expects($this->once())->method('fetchSingle')->willReturnCallback(
                function (mixed ...$args) use ($max): mixed {
                    $this->assertStringContainsString('MAX([order_pos])', $args[0]);
                    $this->assertStringContainsString('docs_core_vat_recap', $args[0]);
                    $this->assertSame(7, $args[1]);
                    return $max;
                },
            );
        } else {
            $db->expects($this->never())->method('fetchSingle');
        }
        $doc = new VatRecapDocument();
        $doc->setDb($db);
        return $doc;
    }

    public function testNewLineWithoutOrderGetsMaxPlusOne(): void
    {
        $doc = $this->docWithMax(2);
        $data = ['doc_head' => 7, 'vat_code' => 'cz-110', 'vat_pct' => 21.0];
        $doc->beforeSave($data, null);
        $this->assertSame(3, $data['order_pos']);
    }

    public function testFirstLineOfEmptyDocumentGetsOne(): void
    {
        $doc = $this->docWithMax(null);
        $data = ['doc_head' => 7];
        $doc->beforeSave($data, null);
        $this->assertSame(1, $data['order_pos']);
    }

    public function testExplicitOrderIsKept(): void
    {
        $doc = $this->docWithMax(null, expectQuery: false);
        $data = ['doc_head' => 7, 'order_pos' => 5];
        $doc->beforeSave($data, null);
        $this->assertSame(5, $data['order_pos']);
    }

    public function testUpdateDoesNotTouchOrder(): void
    {
        $doc = $this->docWithMax(null, expectQuery: false);
        $data = ['id' => 3, 'doc_head' => 7, 'base' => 100.0];
        $doc->beforeSave($data, ['id' => 3, 'doc_head' => 7, 'order_pos' => 2]);
        $this->assertArrayNotHasKey('order_pos', $data);
    }
}
