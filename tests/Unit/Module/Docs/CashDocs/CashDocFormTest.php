<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\CashDocs;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Form\FormDefinition;
use Shipard\Core\Form\FormElement;
use Shipard\Module\Docs\CashDocs\CashDocForm;

/**
 * Formulář pokladního dokladu: pokladna z řady (žádný tichý výběr první
 * řady), způsob úhrady jen Hotovost / Kartou, směr po vzniku řádků jen pro
 * čtení, recalculate nemaže systémovou pokladnu, hlavička anonymního dokladu.
 */
class CashDocFormTest extends TestCase
{
    private const DESK = ['id' => 7, 'code' => 'HP1', 'name' => 'Hlavní pokladna', 'currency' => 'czk'];

    /** @var list<string> */
    private array $sqlLog = [];

    private function db(int $rowsCount = 0, array $seriesRows = []): DataSourceConnection
    {
        $this->sqlLog = [];
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturnCallback(
            function (string $sql, mixed ...$params) use ($rowsCount): ?array {
                $this->sqlLog[] = $sql;
                if (str_contains($sql, 'COUNT(*)')) {
                    return ['cnt' => $rowsCount];
                }
                if (str_contains($sql, 'economy_codebooks_cash_desks')) {
                    return self::DESK;
                }
                if (str_contains($sql, 'base_persons_persons')) {
                    return ['full_name' => 'Partner s.r.o.'];
                }
                return null;
            },
        );
        $db->method('fetchAll')->willReturnCallback(
            static fn (string $sql, mixed ...$params): array =>
                str_contains($sql, 'docs_core_number_series') ? $seriesRows : [],
        );
        return $db;
    }

    private function config(): ConfigRuntime
    {
        $items = [
            'docs.core.paymentMethods' => [
                '0' => ['name' => 'Hotovost'], '1' => ['name' => 'Převodem'], '2' => ['name' => 'Kartou'],
                '3' => ['name' => 'Dobírkou'], '4' => ['name' => 'Zápočtem'],
            ],
            'docs.core.cashDirections' => [
                '0' => ['name' => '—'], '1' => ['name' => 'Příjem'], '2' => ['name' => 'Výdej'],
            ],
            'docs.core.docTypes' => [
                'cash' => ['trade_dir' => 0, 'trade_dir_column' => 'cash_dir', 'series_binding' => 'cash_desk'],
            ],
        ];
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnCallback(
            static fn (string $id): mixed => $items[$id] ?? null,
        );
        return $config;
    }

    private function form(int $rowsCount = 0, array $seriesRows = []): TestableCashDocForm
    {
        $form = new TestableCashDocForm('docs_core_heads');
        $form->setConfig($this->config());
        $form->setDb($this->db($rowsCount, $seriesRows));
        return $form;
    }

    private function findElement(FormDefinition $def, string $column): ?FormElement
    {
        foreach ($def->tabs as $tab) {
            if ($tab->id !== 'basic') {
                continue;
            }
            foreach ($tab->sections as $section) {
                foreach ($section->columns as $col) {
                    foreach ($col->elements as $el) {
                        if ($el->column === $column) {
                            return $el;
                        }
                    }
                }
            }
        }
        return null;
    }

    /** @return list<string> obsah html elementů basic tabu */
    private function htmlBlocks(FormDefinition $def): array
    {
        $out = [];
        foreach ($def->tabs[0]->sections as $section) {
            foreach ($section->columns as $col) {
                foreach ($col->elements as $el) {
                    if ($el->type === 'html') {
                        $out[] = (string) $el->content;
                    }
                }
            }
        }
        return $out;
    }

    public function testNewRecordWithoutSeriesShowsSeriesSelectAndDoesNotAutoPick(): void
    {
        $series = [['id' => 12, 'name' => 'Pokladní doklad — HP1', 'doc_type' => 'cash']];
        $def = $this->form(seriesRows: $series)->buildFormDefinition(['doc_type' => 'cash'], true);

        $el = $this->findElement($def, 'number_series');
        $this->assertNotNull($el);
        $this->assertFalse($el->hidden, 'bez řady je select Pokladna viditelný');
        $this->assertSame('Pokladna', $el->label);
        $this->assertSame([12], array_column($el->options, 'value'));

        foreach ($this->sqlLog as $sql) {
            $this->assertStringNotContainsString(
                'ORDER BY `id` ASC LIMIT 1',
                $sql,
                'vázaný typ nesmí potichu vybrat první řadu',
            );
        }
    }

    public function testNewRecordWithSeriesHidesSelectAndTakesCurrencyFromDesk(): void
    {
        $form = $this->form();
        $def = $form->buildFormDefinition(['doc_type' => 'cash', 'number_series' => 12], true);
        $this->assertTrue($this->findElement($def, 'number_series')->hidden);

        $data = ['doc_type' => 'cash', 'number_series' => 12];
        $form->applyClientDefaultsPub($data, true);
        $this->assertSame(0, $data['payment_method'], 'default Hotovost');
        $this->assertSame('czk', $data['doc_currency']);
    }

    public function testPaymentMethodOptionsAreCashAndCardOnly(): void
    {
        $def = $this->form()->buildFormDefinition(['doc_type' => 'cash', 'number_series' => 12], true);

        $el = $this->findElement($def, 'payment_method');
        $this->assertSame([0, 2], array_column($el->options, 'value'));
        $this->assertSame('reload', $el->triggers);
    }

    public function testCashDirIsRequiredReloadAndLockedOnceRowsExist(): void
    {
        $def = $this->form()->buildFormDefinition(['doc_type' => 'cash', 'number_series' => 12], true);
        $el = $this->findElement($def, 'cash_dir');
        $this->assertSame([1, 2], array_column($el->options, 'value'), 'směr 0 se nenabízí');
        $this->assertTrue($el->required);
        $this->assertSame('reload', $el->triggers);
        $this->assertFalse($el->readOnly);

        $def = $this->form(rowsCount: 2)->buildFormDefinition(
            ['id' => 5, 'doc_type' => 'cash', 'cash_desk' => 7, 'cash_dir' => 1],
            false,
        );
        $el = $this->findElement($def, 'cash_dir');
        $this->assertTrue($el->readOnly);
        $this->assertNotNull($el->hint);
    }

    public function testDocCurrencyIsReadOnlyAndCashDeskSectionRendered(): void
    {
        $def = $this->form()->buildFormDefinition(['doc_type' => 'cash', 'number_series' => 12], true);

        $this->assertTrue($this->findElement($def, 'doc_currency')->readOnly);
        $this->assertNull($this->findElement($def, 'cash_desk'), 'pokladna se nezadává');
        $this->assertStringContainsString('HP1 — Hlavní pokladna · CZK', implode("\n", $this->htmlBlocks($def)));
    }

    public function testRecalculatePaymentMethodKeepsCashDesk(): void
    {
        $data = [
            'id' => 5, 'doc_type' => 'cash', 'cash_desk' => 7, 'cash_dir' => 1,
            'payment_method' => 2, 'doc_currency' => 'czk',
        ];
        $result = $this->form()->recalculate('payment_method', $data);

        $this->assertSame(7, $result->data['cash_desk'], 'base by pokladnu u karty vynulovala');
    }

    public function testHeaderInfoForAnonymousDocumentUsesDirection(): void
    {
        $form = $this->form();

        $info = $form->buildHeaderInfo(['doc_type' => 'cash', 'cash_dir' => 1, 'doc_number' => '31HP12600001']);
        $this->assertNotNull($info);
        $this->assertSame('Příjmový pokladní doklad', $info->title);
        $this->assertSame('Příjmový pokladní doklad', $info->info[0]['value']);
        $this->assertSame('31HP12600001', $info->info[1]['value']);
        $this->assertSame('wallet', $info->icon);

        $info = $form->buildHeaderInfo(['doc_type' => 'cash', 'cash_dir' => 2]);
        $this->assertSame('Výdajový pokladní doklad', $info->title);
    }

    public function testHeaderInfoPartnerFromSnapshotByDirection(): void
    {
        $form = $this->form();
        $snapshots = [
            'customer_snapshot' => json_encode(['name' => 'Odběratel a.s.']),
            'supplier_snapshot' => json_encode(['name' => 'Dodavatel s.r.o.']),
        ];

        $info = $form->buildHeaderInfo(['doc_type' => 'cash', 'cash_dir' => 1, 'partner' => 5] + $snapshots);
        $this->assertSame('Odběratel a.s.', $info->title, 'příjem → partner je odběratel');

        $info = $form->buildHeaderInfo(['doc_type' => 'cash', 'cash_dir' => 2, 'partner' => 5] + $snapshots);
        $this->assertSame('Dodavatel s.r.o.', $info->title, 'výdej → partner je dodavatel');

        $info = $form->buildHeaderInfo(['doc_type' => 'cash', 'cash_dir' => 1, 'partner' => 5]);
        $this->assertSame('Partner s.r.o.', $info->title, 'bez snapshotu živé jméno');
    }
}

class TestableCashDocForm extends CashDocForm
{
    public function applyClientDefaultsPub(array &$data, bool $isNew): void
    {
        $this->applyClientDefaults($data, $isNew);
    }
}
