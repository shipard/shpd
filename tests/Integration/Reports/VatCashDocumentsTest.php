<?php

declare(strict_types=1);

namespace Shipard\Tests\Integration\Reports;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Module\Docs\Core\BoundNumberSeriesProvisioner;
use Shipard\Module\Economy\Vat\ControlStatementCalculator;
use Shipard\Module\Economy\Vat\ReportPeriodsProvisioner;
use Shipard\Module\Economy\Vat\VatDocumentSelection;
use Shipard\Module\Economy\Vat\VatOutputsMapping;
use Shipard\Module\Economy\Vat\VatPeriodAssigner;
use Shipard\Module\Economy\Vat\VatReturnCalculator;
use Shipard\Tests\Integration\IntegrationTestCase;

/**
 * Pokladní doklady a prodejky v živých DPH výstupech (#59 D11) nad reálným
 * dev DS: VatPeriodAssigner zařadí PD i prodejku do instance tvrzení podle
 * registrace + DUZP, VatDocumentSelection ho vybere bez ohledu na doc_type,
 * příjmový PD s cz-120 přistane v DP3 na výstupním řádku, výdajový PD
 * s cz-110 na vstupu (ř. 40), prodejka nad 10 000 s partnerem s CZ DIČ
 * v KH A4, anonymní v A5. Kód economy.vat se nemění.
 */
class VatCashDocumentsTest extends IntegrationTestCase
{
    private const DUZP = '2026-06-10';

    /** @var list<int> */
    private array $createdHeads = [];
    /** @var list<int> */
    private array $createdCashDesks = [];
    /** @var list<int> */
    private array $createdSeries = [];

    private ?ConfigRuntime $config = null;
    private int $registrationId = 0;
    private int $returnPeriodId = 0;
    private int $deskId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->config = ConfigRuntime::load($this->realDsPath, 'cs');

        $reg = $this->db->fetchRow(
            'SELECT id FROM economy_codebooks_vat_registrations WHERE country = %s AND docState IN (10,40,80) ORDER BY id LIMIT 1',
            'cz',
        );
        if ($reg === null) {
            $this->markTestSkipped('DS nemá registraci k DPH (cz)');
        }
        $this->registrationId = (int) $reg['id'];

        $period = $this->db->fetchRow(
            'SELECT id FROM economy_vat_report_periods
             WHERE vat_registration = %i AND report_type = %s AND date_begin <= %s AND date_end >= %s AND docState != 90',
            $this->registrationId, 'return', self::DUZP, self::DUZP,
        );
        if ($period === null) {
            $this->markTestSkipped('DS nemá instanci přiznání pokrývající ' . self::DUZP);
        }
        $this->returnPeriodId = (int) $period['id'];

        if (!is_array($this->config->cfgItem('economy.vat.reports.cz'))) {
            $this->markTestSkipped('DS nemá compiled mapování economy.vat.reports.cz');
        }
    }

    protected function onTearDown(): void
    {
        $dibi = $this->db->getDibiConnection();
        foreach ($this->createdHeads as $id) {
            $dibi->delete('docs_core_vat_recap')->where('doc_head = %i', $id)->execute();
            $dibi->delete('docs_core_heads')->where('id = %i', $id)->execute();
        }
        foreach ($this->createdSeries as $id) {
            $dibi->delete('docs_core_number_counters')->where('number_series = %i', $id)->execute();
            $dibi->delete('docs_core_number_series')->where('id = %i', $id)->execute();
        }
        foreach ($this->createdCashDesks as $id) {
            $dibi->delete('economy_codebooks_cash_desks')->where('id = %i', $id)->execute();
        }
    }

    public function testCashDocumentsAreAssignedSelectedAndReported(): void
    {
        $this->createCashDesk();
        $mapping = new VatOutputsMapping($this->config->cfgItem('economy.vat.reports.cz'));

        // 1. Přiřazení instance: VatPeriodAssigner nečte doc_type — stejná
        //    instance pro PD příjem, PD výdej i prodejku.
        $lookup = new ReportPeriodsProvisioner($this->db);
        $lookup->setCreateMissing(false);
        $assigner = new VatPeriodAssigner($lookup, $mapping);
        $headBase = ['vat_registration' => $this->registrationId, 'vat_duzp' => self::DUZP, 'vat_dppd' => self::DUZP];

        $receiptPeriods = $assigner->compute($headBase, [['vat_code' => 'cz-120']]);
        $this->assertSame($this->returnPeriodId, $receiptPeriods['vat_period']);
        $this->assertSame($receiptPeriods, $assigner->compute($headBase, [['vat_code' => 'cz-120']]), 'prodejka = stejné období');
        $disbursementPeriods = $assigner->compute($headBase, [['vat_code' => 'cz-110']]);
        $this->assertSame($this->returnPeriodId, $disbursementPeriods['vat_period']);

        // 2. Doklady ve stavu 40 s materializovaným vat_period.
        $receipt = $this->insertHead('cash', 1, 1000.0, 210.0, $receiptPeriods, 'cz-120', null);
        $disbursement = $this->insertHead('cash', 2, 500.0, 105.0, $disbursementPeriods, 'cz-110', null);
        $saleWithVatId = $this->insertHead('cashreg', 0, 10000.0, 2100.0, $receiptPeriods, 'cz-120',
            json_encode(['name' => 'Odběratel a.s.', 'vat_id' => 'CZ12345678']));
        $saleAnonymous = $this->insertHead('cashreg', 0, 10000.0, 2100.0, $receiptPeriods, 'cz-120', null);
        // koncept se do tvrzení nedostane
        $draft = $this->insertHead('cash', 1, 99.0, 20.79, $receiptPeriods, 'cz-120', null, docState: 10);

        // 3. Výběr dokladů instance — bez filtru na doc_type.
        $docs = (new VatDocumentSelection($this->db))->load($this->returnPeriodId, 'vat_period');
        $ids = array_column($docs, 'id');
        foreach ([$receipt, $disbursement, $saleWithVatId, $saleAnonymous] as $id) {
            $this->assertContains($id, $ids, "doklad {$id} ve výběru instance");
        }
        $this->assertNotContains($draft, $ids, 'koncept mimo tvrzení');

        $ours = array_values(array_filter($docs, fn($d) => in_array($d['id'], [$receipt, $disbursement, $saleWithVatId, $saleAnonymous], true)));
        $byId = array_column($ours, null, 'id');
        $this->assertSame('CZ12345678', $byId[$saleWithVatId]['customer_vat_id'], 'DIČ odběratele ze snapshotu');
        $this->assertSame('', $byId[$saleAnonymous]['customer_vat_id']);

        // 4. DP3: příjem + prodejky na výstupním řádku cz-120, výdej na řádku 40.
        $return = (new VatReturnCalculator($mapping))->calculate($ours);
        $outputRow = $mapping->dp3('cz-120')['row'];
        $inputRow = $mapping->dp3('cz-110')['row'];
        $this->assertEqualsWithDelta(21000.0, $return['rows'][$outputRow]['base'], 0.001, 'PD příjem 1 000 + 2× prodejka 10 000');
        $this->assertEqualsWithDelta(4410.0, $return['rows'][$outputRow]['taxFull'], 0.001);
        $this->assertEqualsWithDelta(500.0, $return['rows'][$inputRow]['base'], 0.001, 'PD výdej na vstupu');
        $this->assertEqualsWithDelta(105.0, $return['rows'][$inputRow]['taxFull'], 0.001);

        // 5. KH: prodejka > 10 000 s CZ DIČ → A4, anonymní → A5.
        $kh = (new ControlStatementCalculator($mapping, [
            'cz-120' => ['category' => 'standard'],
            'cz-110' => ['category' => 'standard'],
        ]))->calculate($ours);
        $a4Numbers = array_column($kh['sections']['A4'], 'evidNumber');
        $this->assertContains($byId[$saleWithVatId]['doc_number'], $a4Numbers);
        $this->assertNotContains($byId[$saleAnonymous]['doc_number'], $a4Numbers);
        $this->assertNotContains($byId[$receipt]['doc_number'], $a4Numbers, 'PD 1 210 je pod limitem');
        $this->assertNotEmpty($kh['sections']['A5'], 'anonymní prodejka + PD v agregaci A5');
    }

    // ── Fixtures ────────────────────────────────────────────────────────────

    private function createCashDesk(): void
    {
        $dibi = $this->db->getDibiConnection();
        $dibi->insert('economy_codebooks_cash_desks', [
            'code' => 'IT' . substr(uniqid(), -5), 'name' => 'IT pokladna DPH', 'currency' => 'czk',
            'docState' => 40, 'docStateMain' => 3,
        ])->execute();
        $this->deskId = (int) $dibi->getInsertId();
        $this->createdCashDesks[] = $this->deskId;
        (new BoundNumberSeriesProvisioner($this->db, $this->config))->provisionForCashDesk($this->deskId);
        foreach ($this->db->fetchAll('SELECT id FROM docs_core_number_series WHERE cash_desk = %i', $this->deskId) as $s) {
            $this->createdSeries[] = (int) $s['id'];
        }
    }

    /**
     * @param array{vat_period: ?int, cs_period: ?int, rs_period: ?int} $periods
     */
    private function insertHead(
        string $docType,
        int $cashDir,
        float $base,
        float $tax,
        array $periods,
        string $vatCode,
        ?string $customerSnapshot,
        int $docState = 40,
    ): int {
        $series = $this->db->fetchRow(
            'SELECT id FROM docs_core_number_series WHERE doc_type = %s AND cash_desk = %i LIMIT 1',
            $docType, $this->deskId,
        );
        $this->assertNotNull($series, "řada {$docType} pokladny");
        $total = round($base + $tax, 2);

        $dibi = $this->db->getDibiConnection();
        $dibi->insert('docs_core_heads', [
            'doc_type' => $docType, 'number_series' => (int) $series['id'], 'cash_desk' => $this->deskId,
            'cash_dir' => $cashDir, 'payment_method' => 0,
            'doc_number' => 'IT-VATCASH-' . uniqid(),
            'issue_date' => self::DUZP, 'accounting_date' => self::DUZP, 'due_date' => self::DUZP,
            'vat_duzp' => self::DUZP, 'vat_dppd' => self::DUZP,
            'vat_mode' => 1, 'vat_registration' => $this->registrationId,
            'vat_period' => $periods['vat_period'], 'cs_period' => $periods['cs_period'], 'rs_period' => $periods['rs_period'],
            'customer_snapshot' => $customerSnapshot,
            'doc_currency' => 'czk', 'home_currency' => 'czk', 'exchange_rate' => 1.0,
            'doc_text' => 'IT DPH pokladna',
            'total_base' => $base, 'total_vat' => $tax, 'total_amount' => $total,
            'total_base_dom' => $base, 'total_vat_dom' => $tax, 'total_amount_dom' => $total,
            'docState' => $docState, 'docStateMain' => $docState === 40 ? 2 : 1,
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
}
