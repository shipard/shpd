<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\Core;

use Dibi\Connection;
use Dibi\Row;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Settings\SettingsStore;
use Shipard\Tests\Fixtures\Module\Docs\Core\TestableDocsHeadsDocument;

class DocDocumentDefaultsTest extends TestCase
{
    public function testApplyDateDefaultsFillsMissingFields(): void
    {
        $doc = new TestableDocsHeadsDocument();
        // No DB needed — partner null → fallback 14 days
        $data = [
            'issue_date' => '2026-05-06',
        ];
        $doc->applyDateDefaultsPub($data);

        $this->assertSame('2026-05-06', $data['accounting_date']);
        $this->assertSame('2026-05-06', $data['vat_duzp']);
        $this->assertSame('2026-05-06', $data['vat_dppd']);
        $this->assertSame('2026-05-20', $data['due_date']);
    }

    public function testApplyDateDefaultsUsesPartnerPaymentTerm(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturn(new Row(['payment_term_days' => 30]));

        $doc = new TestableDocsHeadsDocument();
        $doc->setDb($db);

        $data = [
            'issue_date' => '2026-05-06',
            'partner'    => 42,
        ];
        $doc->applyDateDefaultsPub($data);

        $this->assertSame('2026-06-05', $data['due_date']); // +30 days
    }

    public function testApplyDateDefaultsRespectsExistingValues(): void
    {
        $doc = new TestableDocsHeadsDocument();
        $data = [
            'issue_date'      => '2026-05-06',
            'accounting_date' => '2026-04-01',
            'vat_duzp'        => '2026-04-15',
            'vat_dppd'        => '2026-04-20',
            'due_date'        => '2026-06-01',
        ];
        $doc->applyDateDefaultsPub($data);

        $this->assertSame('2026-04-01', $data['accounting_date']);
        $this->assertSame('2026-04-15', $data['vat_duzp']);
        $this->assertSame('2026-04-20', $data['vat_dppd']);
        $this->assertSame('2026-06-01', $data['due_date']);
    }

    public function testApplyDateDefaultsNullPartnerFallback(): void
    {
        $doc = new TestableDocsHeadsDocument();
        $data = [
            'issue_date' => '2026-01-15',
            'partner'    => null,
        ];
        $doc->applyDateDefaultsPub($data);

        $this->assertSame('2026-01-29', $data['due_date']); // +14 days fallback
    }

    public function testApplyHomeCurrencyFromSettings(): void
    {
        $doc = new TestableDocsHeadsDocument();
        $doc->setSettings($this->createSettingsWithHomeCurrency('eur'));

        $data = [];
        $doc->applyHomeCurrencyPub($data);

        $this->assertSame('eur', $data['home_currency']);
        $this->assertSame('eur', $data['doc_currency']);
    }

    public function testApplyHomeCurrencyFallbackCzkWithoutSettings(): void
    {
        $doc = new TestableDocsHeadsDocument();
        $data = [];
        $doc->applyHomeCurrencyPub($data);

        $this->assertSame('czk', $data['home_currency']);
        $this->assertSame('czk', $data['doc_currency']);
    }

    public function testApplyHomeCurrencyFallbackCzkWhenUndecided(): void
    {
        // Nerozhodnutý klíč (null) = dnešní chování.
        $doc = new TestableDocsHeadsDocument();
        $doc->setSettings($this->createSettingsWithHomeCurrency(null));

        $data = [];
        $doc->applyHomeCurrencyPub($data);

        $this->assertSame('czk', $data['home_currency']);
        $this->assertSame('czk', $data['doc_currency']);
    }

    public function testApplyHomeCurrencyDoesNotOverrideExisting(): void
    {
        $doc = new TestableDocsHeadsDocument();
        $doc->setSettings($this->createSettingsWithHomeCurrency('czk'));

        $data = ['home_currency' => 'usd', 'doc_currency' => 'eur'];
        $doc->applyHomeCurrencyPub($data);

        $this->assertSame('usd', $data['home_currency']);
        $this->assertSame('eur', $data['doc_currency']);
    }

    private function createSettingsWithHomeCurrency(?string $currency): SettingsStore
    {
        $settings = $this->createMock(SettingsStore::class);
        $settings->method('get')->willReturnCallback(
            static fn(string $key): mixed => $key === 'economy.homeCurrency' ? $currency : null,
        );
        return $settings;
    }

    public function testResolveFiscalYearIdReturnsNullWithoutDb(): void
    {
        $doc = new TestableDocsHeadsDocument();
        $this->assertNull($doc->resolveFiscalYearIdPub('2026-05-06'));
    }

    public function testResolveFiscalYearIdReturnsIdWhenFound(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturn(new Row(['id' => 13]));

        $doc = new TestableDocsHeadsDocument();
        $doc->setDb($db);

        $this->assertSame(13, $doc->resolveFiscalYearIdPub('2026-05-06'));
    }

    public function testResolveFiscalYearIdReturnsNullWhenAbsent(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturn(null);

        $doc = new TestableDocsHeadsDocument();
        $doc->setDb($db);

        $this->assertNull($doc->resolveFiscalYearIdPub('2026-05-06'));
    }

    public function testResolveFiscalMonthIdReturnsId(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturn(new Row(['id' => 105]));

        $doc = new TestableDocsHeadsDocument();
        $doc->setDb($db);

        $this->assertSame(105, $doc->resolveFiscalMonthIdPub('2026-05-15'));
    }

    public function testResolveVatPeriodIdRequiresBothInputs(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturn(new Row(['id' => 9]));

        $doc = new TestableDocsHeadsDocument();
        $doc->setDb($db);

        $this->assertNull($doc->resolveVatPeriodIdPub(null, 1));
        $this->assertNull($doc->resolveVatPeriodIdPub('2026-05-06', null));
        $this->assertSame(9, $doc->resolveVatPeriodIdPub('2026-05-06', 1));
    }

    public function testResolveAccountingPeriodsPopulatesAllThree(): void
    {
        $db = $this->createMock(Connection::class);
        $callCount = 0;
        $db->method('fetch')->willReturnCallback(
            function () use (&$callCount): ?Row {
                $callCount++;
                return match ($callCount) {
                    1 => new Row(['id' => 100]),  // fiscal year
                    2 => new Row(['id' => 200]),  // fiscal month
                    3 => new Row(['id' => 300]),  // vat period
                    default => null,
                };
            }
        );

        $doc = new TestableDocsHeadsDocument();
        $doc->setDb($db);

        $data = [
            'accounting_date'  => '2026-05-06',
            'vat_duzp'         => '2026-05-06',
            'vat_registration' => 7,
        ];
        $doc->resolveAccountingPeriodsPub($data);

        $this->assertSame(100, $data['fiscal_year']);
        $this->assertSame(200, $data['fiscal_month']);
        $this->assertSame(300, $data['vat_period']);
    }
}
