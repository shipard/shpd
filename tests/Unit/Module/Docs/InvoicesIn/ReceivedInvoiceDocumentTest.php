<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\InvoicesIn;

use Dibi\Connection;
use Dibi\Row;
use PHPUnit\Framework\TestCase;
use Shipard\Module\Docs\InvoicesIn\ReceivedInvoiceDocument;

class ReceivedInvoiceDocumentTest extends TestCase
{
    private function dbWithOwn(): Connection
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturn(new Row(['id' => 1])); // own person exists
        return $db;
    }

    /** @return array<string, mixed> */
    private function confirmedData(): array
    {
        return [
            'docState'         => 20,
            'number_series'    => 1,
            'issue_date'       => '2026-05-06',
            'accounting_date'  => '2026-05-06',
            'partner'          => 50,
            'vat_registration' => 1,
            'vat_mode'         => 1,
            'rows'             => [['row_kind' => 1, 'total_price' => 100]],
            'doc_currency'     => 'czk',
            'home_currency'    => 'czk',
        ];
    }

    public function testConfirmedRequiresAnyPartnerBankInfo(): void
    {
        $doc = new ReceivedInvoiceDocument();
        $doc->setDb($this->dbWithOwn());

        $data = $this->confirmedData();
        // No partner_bank, partner_bank_account, or partner_bank_iban set
        $errors = $doc->validate($data)->toArray();

        $matched = array_filter(
            $errors,
            fn (array $e) => $e['code'] === 'partner_bank_required',
        );
        $this->assertNotEmpty($matched, 'Confirmed FPB without partner bank info must fail');
        // Bound to the partner_bank column (header-tab lookup) so the UI shows it
        // next to that field + colors the Hlavička tab — not a form-level error.
        $this->assertSame('partner_bank', array_values($matched)[0]['column']);
    }

    public function testPartnerBankIdSatisfiesRequirement(): void
    {
        $doc = new ReceivedInvoiceDocument();
        $doc->setDb($this->dbWithOwn());

        $data = $this->confirmedData();
        $data['partner_bank'] = 42;

        $errors = $doc->validate($data)->toArray();
        $matched = array_filter($errors, fn (array $e) => $e['code'] === 'partner_bank_required');
        $this->assertEmpty($matched);
    }

    public function testPartnerBankAccountNumberSatisfiesRequirement(): void
    {
        $doc = new ReceivedInvoiceDocument();
        $doc->setDb($this->dbWithOwn());

        $data = $this->confirmedData();
        $data['partner_bank_account'] = '123456/0100';

        $errors = $doc->validate($data)->toArray();
        $matched = array_filter($errors, fn (array $e) => $e['code'] === 'partner_bank_required');
        $this->assertEmpty($matched);
    }

    public function testPartnerBankIbanSatisfiesRequirement(): void
    {
        $doc = new ReceivedInvoiceDocument();
        $doc->setDb($this->dbWithOwn());

        $data = $this->confirmedData();
        $data['partner_bank_iban'] = 'CZ6508000000192000145399';

        $errors = $doc->validate($data)->toArray();
        $matched = array_filter($errors, fn (array $e) => $e['code'] === 'partner_bank_required');
        $this->assertEmpty($matched);
    }

    public function testKonceptDoesNotRequirePartnerBankInfo(): void
    {
        $doc = new ReceivedInvoiceDocument();
        $doc->setDb($this->dbWithOwn());

        $data = [
            'docState'        => 10,
            'number_series'   => 1,
            'issue_date'      => '2026-05-06',
            'accounting_date' => '2026-05-06',
        ];
        $errors = $doc->validate($data)->toArray();

        $matched = array_filter($errors, fn (array $e) => $e['code'] === 'partner_bank_required');
        $this->assertEmpty($matched);
    }

    public function testInheritsParentValidation(): void
    {
        $doc = new ReceivedInvoiceDocument();
        $doc->setDb($this->dbWithOwn());

        $data = $this->confirmedData();
        unset($data['partner']);

        $errors = $doc->validate($data)->toArray();
        $matched = array_filter(
            $errors,
            fn (array $e) => $e['column'] === 'partner' && $e['code'] === 'required',
        );
        $this->assertNotEmpty($matched);
    }
}
