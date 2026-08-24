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
        ];
    }

    /** @return list<array{column: string, message: string, code: string}> */
    private function bankWarnings(array $warnings): array
    {
        return array_values(array_filter(
            $warnings,
            fn (array $w) => $w['code'] === 'partner_bank_recommended',
        ));
    }

    public function testConfirmedWithoutPartnerBankInfoWarnsButSaves(): void
    {
        $doc = new ReceivedInvoiceDocument();
        $doc->setDb($this->dbWithOwn());

        $data = $this->confirmedData();
        // No partner_bank, partner_bank_account, or partner_bank_iban set
        $result = $doc->validate($data);

        // Missing supplier bank info must not block the save (historical /
        // imported invoices are already paid) — warning only, doc stays valid.
        $this->assertTrue($result->isValid());
        $this->assertEmpty($result->toArray());

        $matched = $this->bankWarnings($result->warningsToArray());
        $this->assertNotEmpty($matched, 'Confirmed FPB without partner bank info must warn');
        // Bound to the partner_bank column (header-tab lookup) so a future UI
        // can show it next to that field — not as a form-level entry.
        $this->assertSame('partner_bank', $matched[0]['column']);
    }

    public function testPartnerBankIdSatisfiesRecommendation(): void
    {
        $doc = new ReceivedInvoiceDocument();
        $doc->setDb($this->dbWithOwn());

        $data = $this->confirmedData();
        $data['partner_bank'] = 42;

        $result = $doc->validate($data);
        $this->assertEmpty($this->bankWarnings($result->warningsToArray()));
    }

    public function testPartnerBankAccountNumberSatisfiesRecommendation(): void
    {
        $doc = new ReceivedInvoiceDocument();
        $doc->setDb($this->dbWithOwn());

        $data = $this->confirmedData();
        $data['partner_bank_account'] = '123456/0100';

        $result = $doc->validate($data);
        $this->assertEmpty($this->bankWarnings($result->warningsToArray()));
    }

    public function testPartnerBankIbanSatisfiesRecommendation(): void
    {
        $doc = new ReceivedInvoiceDocument();
        $doc->setDb($this->dbWithOwn());

        $data = $this->confirmedData();
        $data['partner_bank_iban'] = 'CZ6508000000192000145399';

        $result = $doc->validate($data);
        $this->assertEmpty($this->bankWarnings($result->warningsToArray()));
    }

    public function testCashPaymentDoesNotWarnAboutPartnerBankInfo(): void
    {
        $doc = new ReceivedInvoiceDocument();
        $doc->setDb($this->dbWithOwn());

        // Confirmed, no bank info, but paid in cash (payment_method = 0) —
        // supplier bank info is irrelevant, so no warning.
        $data = $this->confirmedData();
        $data['payment_method'] = 0;

        $result = $doc->validate($data);
        $this->assertEmpty(
            $this->bankWarnings($result->warningsToArray()),
            'Cash-paid FPB must not warn about supplier bank info',
        );
    }

    public function testBankTransferWithoutPartnerBankInfoWarns(): void
    {
        $doc = new ReceivedInvoiceDocument();
        $doc->setDb($this->dbWithOwn());

        // Explicit bank transfer (payment_method = 1), confirmed, no bank info.
        $data = $this->confirmedData();
        $data['payment_method'] = 1;

        $result = $doc->validate($data);
        $this->assertTrue($result->isValid());
        $this->assertNotEmpty(
            $this->bankWarnings($result->warningsToArray()),
            'Bank-transfer FPB without supplier bank info must warn',
        );
    }

    public function testKonceptDoesNotWarnAboutPartnerBankInfo(): void
    {
        $doc = new ReceivedInvoiceDocument();
        $doc->setDb($this->dbWithOwn());

        $data = [
            'docState'        => 10,
            'number_series'   => 1,
            'issue_date'      => '2026-05-06',
            'accounting_date' => '2026-05-06',
        ];
        $result = $doc->validate($data);

        $this->assertEmpty($this->bankWarnings($result->warningsToArray()));
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
