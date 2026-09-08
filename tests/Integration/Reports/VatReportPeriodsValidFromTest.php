<?php

declare(strict_types=1);

namespace Shipard\Tests\Integration\Reports;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Module\Economy\Vat\ReportPeriodsProvisioner;
use Shipard\Module\Economy\Vat\VatOutputsMapping;
use Shipard\Module\Economy\Vat\VatPeriodAssigner;
use Shipard\Module\Economy\Vat\VatPeriodRecalculator;
use Shipard\Tests\Integration\IntegrationTestCase;

/**
 * Zákonný počátek kontrolního hlášení (#58, `reportTypes.cs.validFrom`)
 * nad reálným dev DS:
 *
 * - generátor (`ReportPeriodsProvisioner::create`) před počátkem nic
 *   nezaloží, od počátku ano;
 * - on-demand cesta (assigner + provisioner s createMissing) pro doklad
 *   z roku 2015 s KH kódem nezaloží koncept `cs` a nechá `cs_period` NULL,
 *   přiznání přiřadí;
 * - přepočet (`VatPeriodRecalculator`, V4) považuje ukazatel dokladu 2014
 *   na anachronickou `cs` instanci 2014 za nekonzistentní → NULL; doklad
 *   2017 na `cs` 2017 zůstane beze změny.
 *
 * Test si vlastní instance i doklady zakládá a po sobě maže; registraci
 * bere existující (cz).
 */
class VatReportPeriodsValidFromTest extends IntegrationTestCase
{
    private const VALID_FROM = '2016-01-01';

    /** @var list<int> */
    private array $createdPeriods = [];
    /** @var list<int> */
    private array $createdHeads = [];

    private ?ConfigRuntime $config = null;
    private ?VatOutputsMapping $mapping = null;
    private int $registrationId = 0;
    private int $seriesId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->config = ConfigRuntime::load($this->realDsPath, 'cs');
        $this->mapping = VatOutputsMapping::fromConfig($this->config);
        if ($this->mapping === null) {
            $this->markTestSkipped('DS nemá compiled mapování economy.vat.reports.cz');
        }
        if ($this->mapping->validFrom('cs') !== self::VALID_FROM) {
            $this->markTestSkipped('Compiled config nemá reportTypes.cs.validFrom = ' . self::VALID_FROM . ' (spusť ds-upgrade)');
        }

        $reg = $this->db->fetchRow(
            'SELECT id, valid_from FROM economy_codebooks_vat_registrations WHERE country = %s AND docState IN (10,40,80) ORDER BY id LIMIT 1',
            'cz',
        );
        if ($reg === null) {
            $this->markTestSkipped('DS nemá registraci k DPH (cz)');
        }
        $regValidFrom = VatPeriodAssigner::isoDate($reg['valid_from']);
        if ($regValidFrom !== null && $regValidFrom > '2014-01-01') {
            $this->markTestSkipped('Registrace DPH platí až od ' . $regValidFrom . ' — test potřebuje platnost před 2014');
        }
        $this->registrationId = (int) $reg['id'];

        $series = $this->db->fetchRow(
            'SELECT id FROM docs_core_number_series WHERE doc_type = %s AND docState != 90 ORDER BY id LIMIT 1',
            'invno',
        );
        if ($series === null) {
            $this->markTestSkipped('DS nemá řadu invno');
        }
        $this->seriesId = (int) $series['id'];

        foreach (['2014-01-01', '2015-06-15', '2016-01-15', '2017-01-01'] as $date) {
            foreach (['return', 'cs'] as $type) {
                if ($this->db->fetchRow(
                    'SELECT id FROM economy_vat_report_periods WHERE vat_registration = %i AND report_type = %s'
                    . ' AND docState != 90 AND date_begin <= %s AND date_end >= %s',
                    $this->registrationId, $type, $date, $date,
                ) !== null) {
                    $this->markTestSkipped("DS už má instanci {$type} pokrývající {$date} — test potřebuje čistou historii");
                }
            }
        }
    }

    protected function onTearDown(): void
    {
        $dibi = $this->db->getDibiConnection();
        foreach ($this->createdHeads as $id) {
            $dibi->delete('docs_core_vat_recap')->where('doc_head = %i', $id)->execute();
            $dibi->delete('docs_core_heads')->where('id = %i', $id)->execute();
        }
        foreach ($this->createdPeriods as $id) {
            $dibi->delete('economy_vat_report_periods')->where('id = %i', $id)->execute();
        }
    }

    public function testGeneratorNeverStartsBeforeValidFrom(): void
    {
        $provisioner = new ReportPeriodsProvisioner($this->db, $this->mapping->validFromByType());

        $this->assertNull($provisioner->create($this->registrationId, 'cs', '2015-06-15', 10), 'KH před 2016 se nezakládá');
        $this->assertSame([], $provisioner->createdInstances());
        $this->assertSame(0, $this->countCsBefore(self::VALID_FROM), 'nic v DB');

        // přiznání a SH omezení nemají
        $return = $provisioner->create($this->registrationId, 'return', '2015-06-15', 10);
        $this->assertNotNull($return);
        $this->createdPeriods[] = $return['id'];

        // od počátku KH normálně
        $cs = $provisioner->create($this->registrationId, 'cs', '2016-01-15', 10);
        $this->assertNotNull($cs);
        $this->createdPeriods[] = $cs['id'];
        $this->assertSame('2016-01-01', $cs['date_begin']);
        $this->assertSame('2016-01-31', $cs['date_end']);
    }

    public function testOnDemandPathLeavesCsNullWithoutDraftFor2015Document(): void
    {
        $provisioner = new ReportPeriodsProvisioner($this->db, $this->mapping->validFromByType());
        $provisioner->setCreateMissing(true, 10);
        $assigner = new VatPeriodAssigner($provisioner, $this->mapping);

        // cz-120 má kh ≠ null → členství v KH, ale 2015 je před počátkem
        $out = $assigner->compute(
            ['vat_registration' => $this->registrationId, 'vat_duzp' => '2015-06-15', 'vat_dppd' => null],
            [['vat_code' => 'cz-120']],
        );
        $this->createdPeriods = array_merge($this->createdPeriods, $provisioner->createdInstances());

        $this->assertNotNull($out['vat_period'], 'přiznání se založí on-demand');
        $this->assertNull($out['cs_period']);
        $this->assertNull($out['rs_period']);
        $this->assertCount(1, $provisioner->createdInstances(), 'jediný nový koncept = přiznání, žádné KH');
        $this->assertSame(0, $this->countCsBefore(self::VALID_FROM));
    }

    public function testRecalculatorNullsPointerToAnachronisticCsInstance(): void
    {
        // Anachronická historie, jak ji zakládal D9 před #58: KH 01/2014 + doklad na ni.
        $return2014 = $this->insertPeriod('return', 'Q1/2014', '2014-01-01', '2014-03-31');
        $cs2014 = $this->insertPeriod('cs', '01/2014', '2014-01-01', '2014-01-31');
        $head2014 = $this->insertHead('2014-01-10', $return2014, $cs2014);

        // Legitimní: KH 01/2017 + doklad na ni.
        $return2017 = $this->insertPeriod('return', 'Q1/2017', '2017-01-01', '2017-03-31');
        $cs2017 = $this->insertPeriod('cs', '01/2017', '2017-01-01', '2017-01-31');
        $head2017 = $this->insertHead('2017-01-10', $return2017, $cs2017);

        $recalculator = new VatPeriodRecalculator($this->db, $this->mapping);

        $this->assertSame(1, $recalculator->recomputeForInstance($cs2014), 'jeden doklad přepsán');
        $row = $this->db->fetchRow('SELECT vat_period, cs_period FROM docs_core_heads WHERE id = %i', $head2014);
        $this->assertSame($return2014, (int) $row['vat_period'], 'přiznání zůstává');
        $this->assertNull($row['cs_period'], 'ukazatel na KH 2014 je nekonzistentní → NULL');

        $this->assertSame(0, $recalculator->recomputeForInstance($cs2017), 'doklad 2017 beze změny');
        $row = $this->db->fetchRow('SELECT vat_period, cs_period FROM docs_core_heads WHERE id = %i', $head2017);
        $this->assertSame($cs2017, (int) $row['cs_period']);

        // Opakovaný přepočet je idempotentní a nic nezakládá (find-only).
        $this->assertSame(0, $recalculator->recomputeForInstance($cs2014));
        $this->assertSame(0, $this->countCsBefore(self::VALID_FROM) - 1, 'jediná KH před 2016 je ta testovací');
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    private function countCsBefore(string $date): int
    {
        return (int) $this->db->fetchRow(
            'SELECT COUNT(*) AS c FROM economy_vat_report_periods WHERE vat_registration = %i AND report_type = %s'
            . ' AND docState != 90 AND date_begin < %s',
            $this->registrationId, 'cs', $date,
        )['c'];
    }

    private function insertPeriod(string $type, string $name, string $begin, string $end): int
    {
        $id = $this->db->insertRow('economy_vat_report_periods', [
            'vat_registration' => $this->registrationId,
            'report_type'      => $type,
            'name'             => $name,
            'date_begin'       => $begin,
            'date_end'         => $end,
            'locked'           => 0,
            'docState'         => 40,
            'docStateMain'     => 3,
        ]);
        $this->createdPeriods[] = $id;
        return $id;
    }

    private function insertHead(string $duzp, int $returnPeriod, int $csPeriod): int
    {
        $dibi = $this->db->getDibiConnection();
        $dibi->insert('docs_core_heads', [
            'doc_type' => 'invno', 'number_series' => $this->seriesId,
            'doc_number' => 'IT-VATVF-' . uniqid(),
            'issue_date' => $duzp, 'accounting_date' => $duzp, 'due_date' => $duzp,
            'vat_duzp' => $duzp, 'vat_dppd' => $duzp,
            'vat_mode' => 1, 'vat_registration' => $this->registrationId,
            'vat_period' => $returnPeriod, 'cs_period' => $csPeriod, 'rs_period' => null,
            'doc_currency' => 'czk', 'home_currency' => 'czk', 'exchange_rate' => 1.0,
            'doc_text' => 'IT DPH validFrom',
            'total_base' => 1000.0, 'total_vat' => 210.0, 'total_amount' => 1210.0,
            'total_base_dom' => 1000.0, 'total_vat_dom' => 210.0, 'total_amount_dom' => 1210.0,
            'docState' => 40, 'docStateMain' => 2,
        ])->execute();
        $headId = (int) $dibi->getInsertId();
        $this->createdHeads[] = $headId;

        $dibi->insert('docs_core_vat_recap', [
            'doc_head' => $headId, 'vat_code' => 'cz-120', 'vat_pct' => 21.0,
            'base' => 1000.0, 'tax' => 210.0, 'total' => 1210.0,
            'base_dom' => 1000.0, 'tax_dom' => 210.0, 'total_dom' => 1210.0,
            'sum_base' => 1, 'sum_tax' => 1, 'sum_total' => 1, 'is_reverse_pair' => 0, 'order_pos' => 0,
        ])->execute();
        return $headId;
    }
}
