<?php

declare(strict_types=1);

namespace Shipard\Tests\Integration\Reports;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Reports\ReportRequest;
use Shipard\Core\Reports\ReportResult;
use Shipard\Core\Reports\VatPeriodRange;
use Shipard\Module\Economy\Vat\Reports\VatReturnLiveBuilder;
use Shipard\Tests\Integration\IntegrationTestCase;

/**
 * Koeficient odpočtu v živém přiznání (#59 D13) nad reálným dev DS: doklad
 * s kráceným kódem (cz-118) bez záznamu koeficientu → ř. 52 = krácený nárok
 * × 1,00 a zpráva o defaultu; se záznamem 0,80 ve stavu 40 → ř. 52 přepočten,
 * zpráva o defaultu zmizí. Ostatní doklady instance test nezná — asertuje
 * relativně (52 = 46.krácený × koef, 63 = 46.plný + 52).
 */
class VatDeductionCoefficientTest extends IntegrationTestCase
{
    private const DUZP = '2026-06-10';
    private const YEAR = 2026;

    /** @var list<int> */
    private array $createdHeads = [];
    /** @var list<int> */
    private array $createdCoefficients = [];

    private ?ConfigRuntime $config = null;
    private int $registrationId = 0;
    /** @var array<string, mixed> */
    private array $period = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->config = ConfigRuntime::load($this->realDsPath, 'cs');

        if (!isset($this->tables['economy_vat_deduction_coefficients'])) {
            $this->markTestSkipped('DS nemá tabulku economy_vat_deduction_coefficients — spusťte ds-upgrade');
        }
        if (!is_array($this->config->cfgItem('economy.vat.reports.cz'))) {
            $this->markTestSkipped('DS nemá compiled mapování economy.vat.reports.cz');
        }

        $reg = $this->db->fetchRow(
            'SELECT id FROM economy_codebooks_vat_registrations WHERE country = %s AND docState IN (10,40,80) ORDER BY id LIMIT 1',
            'cz',
        );
        if ($reg === null) {
            $this->markTestSkipped('DS nemá registraci k DPH (cz)');
        }
        $this->registrationId = (int) $reg['id'];

        $period = $this->db->fetchRow(
            'SELECT id, name, date_begin, date_end FROM economy_vat_report_periods
             WHERE vat_registration = %i AND report_type = %s AND date_begin <= %s AND date_end >= %s AND docState != 90',
            $this->registrationId, 'return', self::DUZP, self::DUZP,
        );
        if ($period === null) {
            $this->markTestSkipped('DS nemá instanci přiznání pokrývající ' . self::DUZP);
        }
        $this->period = $period;

        $existing = $this->db->fetchSingle(
            'SELECT COUNT(*) FROM economy_vat_deduction_coefficients WHERE vat_registration = %i AND year IN (%i, %i) AND docState != 90',
            $this->registrationId, self::YEAR, self::YEAR - 1,
        );
        if ((int) $existing > 0) {
            $this->markTestSkipped('DS už má koeficient odpočtu pro ' . (self::YEAR - 1) . '/' . self::YEAR . ' — test by přepsal uživatelská data');
        }
    }

    protected function onTearDown(): void
    {
        $dibi = $this->db->getDibiConnection();
        foreach ($this->createdHeads as $id) {
            $dibi->delete('docs_core_vat_recap')->where('doc_head = %i', $id)->execute();
            $dibi->delete('docs_core_heads')->where('id = %i', $id)->execute();
        }
        foreach ($this->createdCoefficients as $id) {
            $dibi->delete('economy_vat_deduction_coefficients')->where('id = %i', $id)->execute();
        }
    }

    public function testRow52FollowsCoefficientRecord(): void
    {
        // Krácený kód cz-118 (ř. 40, sloupec krácený) na přijaté faktuře.
        $this->insertReceivedInvoice(1000.0, 210.0, 'cz-118');

        // 1. Bez záznamu: default 1,00 + doporučení.
        $result = $this->buildReport();
        $rows = $this->rowsByNumber($result);
        $codes = array_map(static fn ($m) => $m->code, $result->messages);

        $reduced46 = $rows['46']['taxReduced']['balance'];
        $this->assertGreaterThanOrEqual(210.0, $reduced46, 'krácený nárok obsahuje náš doklad');
        $this->assertEqualsWithDelta($reduced46, $rows['52']['tax']['balance'], 0.001, 'ř. 52 = krácený × 1,00');
        $this->assertEqualsWithDelta(
            $rows['46']['tax']['balance'] + $rows['52']['tax']['balance'],
            $rows['63']['tax']['balance'],
            0.001,
            '63 = 46 (plný) + 52',
        );
        $this->assertContains('vatReturn.deductionCoefficient', $codes);
        $this->assertContains('vatReturn.deductionCoefficientDefault', $codes, 'bez záznamu report doporučí koeficient nastavit');
        $this->assertSame('Krácený odpočet (ř. 40–45 × koeficient)', $rows['52']['_label']);

        // 2. Koncept 0,50 se nepočítá.
        $draftId = $this->insertCoefficient(0.5, 10);
        $rows = $this->rowsByNumber($this->buildReport());
        $this->assertEqualsWithDelta($reduced46, $rows['52']['tax']['balance'], 0.001, 'koncept koeficientu se ignoruje');
        $this->db->getDibiConnection()->update('economy_vat_deduction_coefficients', ['docState' => 90, 'docStateMain' => 4])
            ->where('id = %i', $draftId)->execute();

        // 3. Záznam 0,80 ve stavu 40 → přepočet, default zpráva zmizí.
        $this->insertCoefficient(0.8, 40);
        $result = $this->buildReport();
        $rows = $this->rowsByNumber($result);
        $codes = array_map(static fn ($m) => $m->code, $result->messages);

        $this->assertEqualsWithDelta(round($reduced46 * 0.8, 2), $rows['52']['tax']['balance'], 0.001, 'ř. 52 = krácený × 0,80');
        $this->assertEqualsWithDelta(
            $rows['46']['tax']['balance'] + $rows['52']['tax']['balance'],
            $rows['63']['tax']['balance'],
            0.001,
        );
        $this->assertContains('vatReturn.deductionCoefficient', $codes);
        $this->assertNotContains('vatReturn.deductionCoefficientDefault', $codes);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function buildReport(): ReportResult
    {
        $range = new VatPeriodRange(
            periodId: (int) $this->period['id'],
            reportType: 'return',
            periodName: (string) $this->period['name'],
            registrationId: $this->registrationId,
            registrationName: 'IT',
            dateBegin: $this->iso($this->period['date_begin']),
            dateEnd: $this->iso($this->period['date_end']),
        );
        $request = new ReportRequest(
            reportId: 'economy.vat.returnLive',
            range: null,
            params: $range->toParamsArray(),
            db: $this->db,
            config: $this->config,
            dataSource: 'it',
            language: 'cs',
            vatRange: $range,
        );
        return (new VatReturnLiveBuilder())->build($request);
    }

    /** @return array<string, array<string, mixed>> číslo řádku → buňky + `_label` */
    private function rowsByNumber(ReportResult $result): array
    {
        $out = [];
        foreach ($result->rows as $row) {
            $out[(string) $row->account] = $row->values + ['_label' => $row->label];
        }
        return $out;
    }

    private function insertCoefficient(float $provisional, int $docState): int
    {
        $dibi = $this->db->getDibiConnection();
        $dibi->insert('economy_vat_deduction_coefficients', [
            'vat_registration' => $this->registrationId, 'year' => self::YEAR,
            'coefficient_provisional' => $provisional, 'coefficient_settled' => null,
            'note' => 'IT koeficient', 'docState' => $docState, 'docStateMain' => $docState === 40 ? 2 : 1,
        ])->execute();
        $id = (int) $dibi->getInsertId();
        $this->createdCoefficients[] = $id;
        return $id;
    }

    private function insertReceivedInvoice(float $base, float $tax, string $vatCode): int
    {
        $series = $this->db->fetchRow(
            'SELECT id FROM docs_core_number_series WHERE doc_type = %s AND docState = 40 ORDER BY id LIMIT 1',
            'invni',
        );
        if ($series === null) {
            $this->markTestSkipped('DS nemá aktivní řadu invni');
        }
        $total = round($base + $tax, 2);

        $dibi = $this->db->getDibiConnection();
        $dibi->insert('docs_core_heads', [
            'doc_type' => 'invni', 'number_series' => (int) $series['id'],
            'doc_number' => 'IT-VATCOEF-' . uniqid(),
            'issue_date' => self::DUZP, 'accounting_date' => self::DUZP, 'due_date' => self::DUZP,
            'vat_duzp' => self::DUZP, 'vat_dppd' => self::DUZP,
            'vat_mode' => 1, 'vat_registration' => $this->registrationId,
            'vat_period' => (int) $this->period['id'],
            'doc_currency' => 'czk', 'home_currency' => 'czk', 'exchange_rate' => 1.0,
            'doc_text' => 'IT DPH koeficient',
            'total_base' => $base, 'total_vat' => $tax, 'total_amount' => $total,
            'total_base_dom' => $base, 'total_vat_dom' => $tax, 'total_amount_dom' => $total,
            'docState' => 40, 'docStateMain' => 2,
        ])->execute();
        $headId = (int) $dibi->getInsertId();
        $this->createdHeads[] = $headId;

        $dibi->insert('docs_core_vat_recap', [
            'doc_head' => $headId, 'vat_code' => $vatCode, 'vat_pct' => 21.0,
            'base' => $base, 'tax' => $tax, 'total' => $total,
            'base_dom' => $base, 'tax_dom' => $tax, 'total_dom' => $total,
            'sum_base' => 1, 'sum_tax' => 1, 'sum_total' => 1, 'is_reverse_pair' => 0, 'order_pos' => 0,
        ])->execute();
        return $headId;
    }

    private function iso(mixed $value): string
    {
        return $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : substr((string) $value, 0, 10);
    }
}
