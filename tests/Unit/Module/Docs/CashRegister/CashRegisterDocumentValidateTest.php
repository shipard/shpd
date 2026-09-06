<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\CashRegister;

use Dibi\Connection;
use Dibi\Row;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Module\Docs\CashRegister\CashRegisterDocument;

/**
 * Prodejka: pevný směr (cash_dir musí být 0), úhrada jen hotově / kartou,
 * měna pokladny, vratka = záporné řádky projdou.
 */
class CashRegisterDocumentValidateTest extends TestCase
{
    private function doc(): CashRegisterDocument
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturnCallback(
            static function (string $sql, mixed ...$params): ?Row {
                if (str_contains($sql, 'docs_core_number_series')) {
                    return new Row(['doc_type' => 'cashreg', 'cash_desk' => 7, 'warehouse' => null]);
                }
                if (str_contains($sql, 'economy_codebooks_cash_desks')) {
                    return new Row(['code' => 'HP1', 'name' => 'Hlavní pokladna', 'currency' => 'czk']);
                }
                return new Row(['id' => 1]);
            },
        );
        $docTypes = ['cashreg' => ['trade_dir' => 1, 'series_binding' => 'cash_desk']];
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnCallback(
            static fn (string $id): mixed => $id === 'docs.core.docTypes' ? $docTypes : null,
        );

        $doc = new CashRegisterDocument();
        $doc->setDb($db);
        $doc->setConfig($config);
        return $doc;
    }

    /** @return array<string, mixed> */
    private function data(array $overrides = []): array
    {
        return array_merge([
            'docState'        => 10,
            'number_series'   => 2,
            'issue_date'      => '2026-06-10',
            'accounting_date' => '2026-06-10',
            'doc_currency'    => 'czk',
        ], $overrides);
    }

    /** @return list<array{column: string, code: string}> */
    private function errorsFor(array $errors, string $column): array
    {
        return array_values(array_filter($errors, fn(array $e) => $e['column'] === $column));
    }

    public function testCashDirMustStayZero(): void
    {
        $data = $this->data(['cash_dir' => 1]);
        $errors = $this->doc()->validate($data)->toArray();
        $this->assertSame('invalid_value', $this->errorsFor($errors, 'cash_dir')[0]['code']);

        $data = $this->data(['cash_dir' => 0]);
        $this->assertTrue($this->doc()->validate($data)->isValid());
        $this->assertSame(7, $data['cash_desk'], 'pokladna z řady');
    }

    public function testPaymentMethodAndCurrencyRules(): void
    {
        $data = $this->data(['payment_method' => 3]);
        $errors = $this->doc()->validate($data)->toArray();
        $this->assertSame('invalid_value', $this->errorsFor($errors, 'payment_method')[0]['code']);

        $data = $this->data(['doc_currency' => 'eur']);
        $errors = $this->doc()->validate($data)->toArray();
        $this->assertSame('currency_mismatch', $this->errorsFor($errors, 'doc_currency')[0]['code']);
    }

    public function testRefundWithNegativeRowsAndNoPartnerConfirms(): void
    {
        $data = $this->data([
            'docState'         => 40,
            'payment_method'   => 0,
            'vat_mode'         => 1,
            'vat_registration' => 1,
            'home_currency'    => 'czk',
            'rows'             => [[
                'row_kind' => 1, 'operation' => 'sale.goods',
                'quantity' => -1, 'unit_price' => 1000, 'total_price' => -1000,
            ]],
        ]);
        $result = $this->doc()->validate($data);

        $this->assertTrue($result->isValid(), json_encode($result->toArray()));
    }
}
