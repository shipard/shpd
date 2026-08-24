<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\Core;

use Dibi\Connection;
use Dibi\Row;
use PHPUnit\Framework\TestCase;
use Shipard\Tests\Fixtures\Module\Docs\Core\TestableDocsHeadsDocument;

class DocDocumentValidateTest extends TestCase
{
    private function dbWithOwn(): Connection
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturn(new Row(['id' => 1])); // own person exists
        return $db;
    }

    /** @return array<string, mixed> */
    private function konceptData(): array
    {
        return [
            'docState'        => 10,
            'number_series'   => 1,
            'issue_date'      => '2026-05-06',
            'accounting_date' => '2026-05-06',
        ];
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
            'rows' => [['row_kind' => 1, 'total_price' => 100]],
            'doc_currency'     => 'czk',
            'home_currency'    => 'czk',
        ];
    }

    public function testKonceptMinimalValid(): void
    {
        $doc = new TestableDocsHeadsDocument();
        $doc->setDb($this->dbWithOwn());

        $data = $this->konceptData();
        $result = $doc->validate($data);

        $this->assertTrue($result->isValid());
    }

    public function testKonceptMissingNumberSeriesFails(): void
    {
        $doc = new TestableDocsHeadsDocument();
        $doc->setDb($this->dbWithOwn());

        $data = $this->konceptData();
        unset($data['number_series']);

        $errors = $doc->validate($data)->toArray();
        $this->assertContains('number_series', array_column($errors, 'column'));
    }

    public function testKonceptMissingIssueDateFails(): void
    {
        $doc = new TestableDocsHeadsDocument();
        $doc->setDb($this->dbWithOwn());

        $data = $this->konceptData();
        unset($data['issue_date']);

        $errors = $doc->validate($data)->toArray();
        $this->assertContains('issue_date', array_column($errors, 'column'));
    }

    public function testConfirmedRequiresPartner(): void
    {
        $doc = new TestableDocsHeadsDocument();
        $doc->setDb($this->dbWithOwn());

        $data = $this->confirmedData();
        unset($data['partner']);

        $errors = $doc->validate($data)->toArray();
        $matched = array_filter(
            $errors,
            fn(array $e) => $e['column'] === 'partner' && $e['code'] === 'required',
        );
        $this->assertNotEmpty($matched);
    }

    public function testConfirmedRequiresVatRegistrationWhenVatModeNonZero(): void
    {
        $doc = new TestableDocsHeadsDocument();
        $doc->setDb($this->dbWithOwn());

        $data = $this->confirmedData();
        unset($data['vat_registration']);

        $errors = $doc->validate($data)->toArray();
        $matched = array_filter(
            $errors,
            fn(array $e) => $e['column'] === 'vat_registration' && $e['code'] === 'required',
        );
        $this->assertNotEmpty($matched);
    }

    public function testConfirmedAllowsMissingVatRegistrationWhenVatModeZero(): void
    {
        $doc = new TestableDocsHeadsDocument();
        $doc->setDb($this->dbWithOwn());

        $data = $this->confirmedData();
        $data['vat_mode'] = 0;
        unset($data['vat_registration']);

        $errors = $doc->validate($data)->toArray();
        $matched = array_filter(
            $errors,
            fn(array $e) => $e['column'] === 'vat_registration',
        );
        $this->assertEmpty($matched);
    }

    public function testConfirmedRequiresAtLeastOneRow(): void
    {
        $doc = new TestableDocsHeadsDocument();
        $doc->setDb($this->dbWithOwn());

        $data = $this->confirmedData();
        $data['rows'] = [];

        $errors = $doc->validate($data)->toArray();
        $matched = array_filter(
            $errors,
            fn(array $e) => $e['column'] === 'rows' && $e['code'] === 'no_rows',
        );
        $this->assertNotEmpty($matched);
    }

    public function testConfirmedHeaderOnlySaveReadsRowsFromDb(): void
    {
        // Header-only data-save ve 20: rows nejsou v payloadu (spravuje je
        // sub-form), řádky existují v DB → no_rows nesmí falešně padnout.
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturn(new Row(['id' => 1])); // own person
        $db->method('fetchAll')->willReturn([
            new Row(['id' => 7, 'row_kind' => 1, 'total_price' => 100]),
        ]);
        $doc = new TestableDocsHeadsDocument();
        $doc->setDb($db);

        $data = $this->confirmedData();
        unset($data['rows']);
        $data['id'] = 42;

        $this->assertTrue($doc->validate($data)->isValid());
    }

    public function testConfirmedHeaderOnlySaveFailsWithoutRowsInDb(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturn(new Row(['id' => 1])); // own person
        $db->method('fetchAll')->willReturn([]);
        $doc = new TestableDocsHeadsDocument();
        $doc->setDb($db);

        $data = $this->confirmedData();
        unset($data['rows']);
        $data['id'] = 42;

        $errors = $doc->validate($data)->toArray();
        $matched = array_filter(
            $errors,
            fn(array $e) => $e['column'] === 'rows' && $e['code'] === 'no_rows',
        );
        $this->assertNotEmpty($matched);
    }

    public function testConfirmedRequiresExchangeRateForForeignCurrency(): void
    {
        $doc = new TestableDocsHeadsDocument();
        $doc->setDb($this->dbWithOwn());

        $data = $this->confirmedData();
        $data['doc_currency'] = 'eur';

        $errors = $doc->validate($data)->toArray();
        $matched = array_filter(
            $errors,
            fn(array $e) => $e['column'] === 'exchange_rate' && $e['code'] === 'required',
        );
        $this->assertNotEmpty($matched);
    }

    public function testConfirmedRefusesWithoutOwnCompany(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturn(null); // no own person

        $doc = new TestableDocsHeadsDocument();
        $doc->setDb($db);

        $data = $this->confirmedData();
        $errors = $doc->validate($data)->toArray();

        $matched = array_filter(
            $errors,
            fn(array $e) => $e['code'] === 'no_own_company',
        );
        $this->assertNotEmpty($matched);
    }

    public function testApplyPaymentReferenceDefaultUsesSequenceNumber(): void
    {
        $doc = new TestableDocsHeadsDocument();
        $data = ['sequence_number' => 42];
        $doc->applyPaymentReferenceDefaultPub($data);
        $this->assertSame('42', $data['payment_reference']);
    }

    public function testApplyPaymentReferenceDefaultDoesNotOverrideUserValue(): void
    {
        $doc = new TestableDocsHeadsDocument();
        $data = ['sequence_number' => 42, 'payment_reference' => '12345'];
        $doc->applyPaymentReferenceDefaultPub($data);
        $this->assertSame('12345', $data['payment_reference']);
    }

    public function testApplyPaymentReferenceDefaultNoSequenceNoOp(): void
    {
        $doc = new TestableDocsHeadsDocument();
        $data = [];
        $doc->applyPaymentReferenceDefaultPub($data);
        $this->assertArrayNotHasKey('payment_reference', $data);
    }
}
