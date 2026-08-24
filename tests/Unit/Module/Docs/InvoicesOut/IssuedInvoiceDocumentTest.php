<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\InvoicesOut;

use Dibi\Connection;
use Dibi\Row;
use PHPUnit\Framework\TestCase;
use Shipard\Module\Docs\InvoicesOut\IssuedInvoiceDocument;

class IssuedInvoiceDocumentTest extends TestCase
{
    private function dbWithOwn(): Connection
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturn(new Row(['id' => 1])); // own person exists
        return $db;
    }

    /**
     * Minimal data set that already passes parent (DocsHeadsDocument) validation
     * for state 40 (Done). Per-type rules layer on top of these.
     *
     * @return array<string, mixed>
     */
    private function confirmedData(): array
    {
        return [
            'docState'         => 40,
            'number_series'    => 1,
            'issue_date'       => '2026-05-06',
            'accounting_date'  => '2026-05-06',
            'partner'          => 50,
            'vat_registration' => 1,
            'vat_mode'         => 1,
            'rows'             => [['row_kind' => 1, 'total_price' => 100]],
            'doc_currency'     => 'czk',
            'home_currency'    => 'czk',
            'bank_account'     => 7,
        ];
    }

    public function testConfirmedRequiresBankAccount(): void
    {
        $doc = new IssuedInvoiceDocument();
        $doc->setDb($this->dbWithOwn());

        $data = $this->confirmedData();
        unset($data['bank_account']);

        $errors = $doc->validate($data)->toArray();
        $matched = array_filter(
            $errors,
            fn (array $e) => $e['column'] === 'bank_account' && $e['code'] === 'required',
        );
        $this->assertNotEmpty($matched, 'Expected required error on bank_account at Confirm');
    }

    public function testConfirmedAcceptsBankAccount(): void
    {
        $doc = new IssuedInvoiceDocument();
        $doc->setDb($this->dbWithOwn());

        $data = $this->confirmedData();
        $errors = $doc->validate($data)->toArray();

        $matched = array_filter($errors, fn (array $e) => $e['column'] === 'bank_account');
        $this->assertEmpty($matched);
    }

    public function testKonceptDoesNotRequireBankAccount(): void
    {
        $doc = new IssuedInvoiceDocument();
        $doc->setDb($this->dbWithOwn());

        // Concept (state 10) — no bank_account requirement
        $data = [
            'docState'        => 10,
            'number_series'   => 1,
            'issue_date'      => '2026-05-06',
            'accounting_date' => '2026-05-06',
        ];
        $errors = $doc->validate($data)->toArray();

        $matched = array_filter($errors, fn (array $e) => $e['column'] === 'bank_account');
        $this->assertEmpty($matched);
    }

    public function testInheritsParentValidation(): void
    {
        $doc = new IssuedInvoiceDocument();
        $doc->setDb($this->dbWithOwn());

        $data = $this->confirmedData();
        unset($data['partner']);

        $errors = $doc->validate($data)->toArray();
        $matched = array_filter(
            $errors,
            fn (array $e) => $e['column'] === 'partner' && $e['code'] === 'required',
        );
        $this->assertNotEmpty($matched, 'Per-type subclass must still inherit parent validation');
    }

    public function testEmptyStringBankAccountTreatedAsMissing(): void
    {
        $doc = new IssuedInvoiceDocument();
        $doc->setDb($this->dbWithOwn());

        $data = $this->confirmedData();
        $data['bank_account'] = '';

        $errors = $doc->validate($data)->toArray();
        $matched = array_filter(
            $errors,
            fn (array $e) => $e['column'] === 'bank_account' && $e['code'] === 'required',
        );
        $this->assertNotEmpty($matched);
    }
}
