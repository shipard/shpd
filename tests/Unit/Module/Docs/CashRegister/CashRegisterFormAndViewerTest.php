<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\CashRegister;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Form\FormDefinition;
use Shipard\Core\Form\FormElement;
use Shipard\Module\Docs\CashRegister\CashRegisterForm;
use Shipard\Module\Docs\CashRegister\CashRegisterViewer;

/**
 * Formulář a viewer prodejky: úhrada jen Hotovost / Kartou, měna pokladny
 * jen pro čtení, žádný směr; hlavička s odběratelem ze snapshotu; řádek
 * vieweru s datem a způsobem úhrady.
 */
class CashRegisterFormAndViewerTest extends TestCase
{
    private function config(): ConfigRuntime
    {
        $items = [
            'docs.core.paymentMethods' => [
                '0' => ['name' => 'Hotovost'], '1' => ['name' => 'Převodem'], '2' => ['name' => 'Kartou'],
            ],
            'docs.core.docTypes' => [
                'cashreg' => ['name' => 'Prodejka', 'trade_dir' => 1, 'series_binding' => 'cash_desk'],
            ],
            'docs.core.docStates' => [
                '10' => ['stateName' => 'Koncept', 'stateStyle' => 'concept', 'mainState' => 1],
                '40' => ['stateName' => 'V pořádku', 'stateStyle' => 'done', 'mainState' => 3],
            ],
        ];
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnCallback(
            static fn (string $id): mixed => $items[$id] ?? null,
        );
        return $config;
    }

    private function db(): DataSourceConnection
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturnCallback(
            static fn (string $sql, mixed ...$params): ?array =>
                str_contains($sql, 'economy_codebooks_cash_desks')
                    ? ['id' => 7, 'code' => 'HP1', 'name' => 'Hlavní pokladna', 'currency' => 'eur']
                    : null,
        );
        $db->method('fetchAll')->willReturn([]);
        return $db;
    }

    private function findElement(FormDefinition $def, string $column): ?FormElement
    {
        foreach ($def->tabs[0]->sections as $section) {
            foreach ($section->columns as $col) {
                foreach ($col->elements as $el) {
                    if ($el->column === $column) {
                        return $el;
                    }
                }
            }
        }
        return null;
    }

    public function testHeaderHasNoDirectionAndRestrictedPaymentMethods(): void
    {
        $form = new CashRegisterForm('docs_core_heads');
        $form->setConfig($this->config());
        $form->setDb($this->db());

        $def = $form->buildFormDefinition(['doc_type' => 'cashreg', 'number_series' => 12], true);

        $this->assertSame('Nová prodejka', $def->titleNew);
        $this->assertNull($this->findElement($def, 'cash_dir'), 'prodejka směr nemá');
        $this->assertNull($this->findElement($def, 'cash_desk'));
        $this->assertSame([0, 1, 2], array_column($this->findElement($def, 'payment_method')->options, 'value'), 'hotově, převodem (úhrada = pohledávka), kartou');
        $this->assertTrue($this->findElement($def, 'doc_currency')->readOnly);
        $this->assertNotNull($this->findElement($def, 'partner'));
        $this->assertFalse($this->findElement($def, 'partner')->required);
    }

    public function testHeaderInfoUsesCustomerSnapshot(): void
    {
        $form = new CashRegisterForm('docs_core_heads');
        $form->setConfig($this->config());

        $info = $form->buildHeaderInfo([
            'doc_type'          => 'cashreg',
            'customer_snapshot' => json_encode(['name' => 'Odběratel a.s.']),
            'supplier_snapshot' => json_encode(['name' => 'My s.r.o.']),
        ]);

        $this->assertSame('Odběratel a.s.', $info->title);
        $this->assertSame('Prodejka', $info->info[0]['value']);
        $this->assertSame('cash-register', $info->icon);
    }

    public function testViewerRowShowsDateAndPaymentMethodOnly(): void
    {
        $viewer = new CashRegisterViewer($this->db(), 'docs_core_heads');
        $viewer->setConfig($this->config());

        $row = $viewer->renderRow([
            'id' => 4, 'doc_type' => 'cashreg', 'doc_number' => '14HP12600001',
            'doc_text' => 'Prodej', 'docState' => 40, 'docStateMain' => 3,
            'issue_date' => '2026-06-10', 'due_date' => '2026-06-10',
            'total_amount' => '-1210.00', 'doc_currency' => 'czk',
            'cash_dir' => 0, 'payment_method' => 2, 'partner_name' => null,
        ]);

        $this->assertSame([['text' => '10. 6. 2026'], ['text' => 'Kartou', 'class' => 'muted']], $row['t2']);
        $this->assertSame('-1 210,00 CZK', $row['i2']['text'], 'vratka je záporná');
    }
}
