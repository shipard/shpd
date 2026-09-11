<?php

declare(strict_types=1);

namespace Shipard\Tests\Integration\Vat;

use Shipard\Module\Economy\Vat\ClosedPeriodBalanceService;
use Shipard\Module\Economy\Vat\FilingDocument;
use Shipard\Tests\Integration\IntegrationTestCase;

/**
 * Kontrola zůstatků 343 za podané instance (#55 D31) nad reálným dev DS.
 *
 * Instance přiznání 01/2031 s podaným řádným podáním; doklad s deníkem
 * 343210 D 210 (bez zaúčtování přiznání) → nález; druhý doklad, který
 * analytiku vynuluje (jako budoucí cmnbkp přiznání), a saldo 343801 →
 * prázdné. Instance bez podaného podání se nekontroluje. Test si vše
 * zakládá a po sobě maže (řádky vkládá přímo — kontrola čte jen deník,
 * hlavičky a podání).
 */
class ClosedPeriodBalanceServiceTest extends IntegrationTestCase
{
    private const DATE_BEGIN = '2031-01-01';
    private const DATE_END   = '2031-01-31';
    private const DATE       = '2031-01-15';

    private int $registrationId = 0;
    private int $seriesId = 0;

    /** @var list<int> */
    private array $createdPeriods = [];
    /** @var list<int> */
    private array $createdHeads = [];
    /** @var list<int> */
    private array $createdFilings = [];
    /** @var list<int> */
    private array $createdJournal = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (!isset($this->tables[FilingDocument::TABLE]) || !isset($this->tables['economy_accounting_journal'])) {
            $this->markTestSkipped('DS nemá tabulky podání / deníku — spusťte ds-upgrade');
        }
        $reg = $this->db->fetchRow(
            'SELECT id FROM economy_codebooks_vat_registrations WHERE country = %s AND docState IN (10,40,80) ORDER BY id LIMIT 1',
            'cz',
        );
        if ($reg === null) {
            $this->markTestSkipped('DS nemá registraci k DPH (cz)');
        }
        $this->registrationId = (int) $reg['id'];

        $series = $this->db->fetchRow(
            'SELECT id FROM docs_core_number_series WHERE doc_type = %s AND docState = 40 ORDER BY id LIMIT 1',
            'invno',
        );
        if ($series === null) {
            $this->markTestSkipped('DS nemá aktivní řadu invno');
        }
        $this->seriesId = (int) $series['id'];

        $collision = (int) $this->db->fetchSingle(
            'SELECT COUNT(*) FROM economy_vat_report_periods'
            . ' WHERE vat_registration = %i AND date_begin <= %s AND date_end >= %s AND docState != 90',
            $this->registrationId, self::DATE_END, self::DATE_BEGIN,
        );
        if ($collision > 0) {
            $this->markTestSkipped('DS už má instanci tvrzení v testovacím rozsahu 01/2031');
        }
    }

    protected function onTearDown(): void
    {
        $dibi = $this->db->getDibiConnection();
        foreach ($this->createdJournal as $id) {
            $dibi->delete('economy_accounting_journal')->where('id = %i', $id)->execute();
        }
        foreach ($this->createdFilings as $id) {
            $dibi->delete(FilingDocument::TABLE)->where('id = %i', $id)->execute();
        }
        foreach ($this->createdHeads as $id) {
            $dibi->delete('docs_core_heads')->where('id = %i', $id)->execute();
        }
        foreach ($this->createdPeriods as $id) {
            $dibi->delete('economy_vat_report_periods')->where('id = %i', $id)->execute();
        }
    }

    public function testFiledPeriodWithoutPostedReturnReportsPerAnalytic(): void
    {
        $periodId = $this->insertPeriod();
        $head = $this->insertHead($periodId);
        $this->insertJournal($head, '343210', 0.0, 210.0);
        $this->insertJournal($head, '343120', 0.0, 120.0);
        $this->insertJournal($head, '343801', 330.0, 0.0);   // saldo přiznání — nepočítá se
        $this->insertJournal($head, '311000', 1330.0, 0.0);  // jiný účet — nepočítá se
        $this->insertFiling($periodId, FilingDocument::DOC_STATE_FILED);

        $service = new ClosedPeriodBalanceService($this->db);

        $balances = $service->balancesForPeriod($periodId);
        $this->assertSame(
            [['account' => '343120', 'balance' => -120.0], ['account' => '343210', 'balance' => -210.0]],
            $balances,
        );

        $findings = $service->findings($periodId);
        $this->assertCount(2, $findings);
        $this->assertSame($periodId, $findings[0]['period_id']);
        $this->assertSame('01/2031 IT', $findings[0]['period_name']);
        $this->assertSame('2031-01-31', $findings[0]['date_end']);

        // Rozsah měsíce s koncem období → totéž; sousední měsíc nic.
        $this->assertCount(2, $service->findingsForRange('2031-01-01', '2031-01-31'));
        $this->assertSame([], $service->findingsForRange('2031-02-01', '2031-02-28'));
    }

    public function testSettledAnalyticsAreSilent(): void
    {
        $periodId = $this->insertPeriod();
        $head = $this->insertHead($periodId);
        $this->insertJournal($head, '343210', 0.0, 210.0);
        // Doklad, který analytiku vynuluje (jako účetní doklad přiznání z F4b) —
        // patří do instance přes vat_period, dokud F4b nezavede acc_document.
        $settle = $this->insertHead($periodId);
        $this->insertJournal($settle, '343210', 210.0, 0.0);
        $this->insertJournal($settle, '343801', 0.0, 210.0);
        $this->insertFiling($periodId, FilingDocument::DOC_STATE_FILED);

        $service = new ClosedPeriodBalanceService($this->db);

        $this->assertSame([], $service->balancesForPeriod($periodId));
        $this->assertSame([], $service->findings($periodId));
    }

    public function testPeriodWithoutFiledFilingIsNotChecked(): void
    {
        $periodId = $this->insertPeriod();
        $head = $this->insertHead($periodId);
        $this->insertJournal($head, '343210', 0.0, 210.0);
        $this->insertFiling($periodId, FilingDocument::DOC_STATE_COMPOSED);   // jen sestavené

        $service = new ClosedPeriodBalanceService($this->db);

        $this->assertSame([], $service->filedReturnPeriods($periodId));
        $this->assertSame([], $service->findings($periodId));
        // Zůstatek sám existuje — jen se nehlásí, dokud není podáno.
        $this->assertCount(1, $service->balancesForPeriod($periodId));
    }

    public function testDeletedDocumentsAreIgnored(): void
    {
        $periodId = $this->insertPeriod();
        $head = $this->insertHead($periodId, 90);
        $this->insertJournal($head, '343210', 0.0, 210.0);
        $this->insertFiling($periodId, FilingDocument::DOC_STATE_FILED);

        $this->assertSame([], (new ClosedPeriodBalanceService($this->db))->balancesForPeriod($periodId));
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function insertPeriod(): int
    {
        $dibi = $this->db->getDibiConnection();
        $dibi->insert('economy_vat_report_periods', [
            'vat_registration' => $this->registrationId,
            'report_type'      => 'return',
            'name'             => '01/2031 IT',
            'date_begin'       => self::DATE_BEGIN,
            'date_end'         => self::DATE_END,
            'locked'           => 0,
            'docState'         => 40,
            'docStateMain'     => 3,
        ])->execute();
        $id = (int) $dibi->getInsertId();
        $this->createdPeriods[] = $id;
        return $id;
    }

    private function insertHead(int $periodId, int $docState = 40): int
    {
        $dibi = $this->db->getDibiConnection();
        $dibi->insert('docs_core_heads', [
            'doc_type'         => 'invno',
            'number_series'    => $this->seriesId,
            'doc_number'       => 'IT-CPB-' . uniqid(),
            'issue_date'       => self::DATE,
            'accounting_date'  => self::DATE,
            'due_date'         => self::DATE,
            'vat_duzp'         => self::DATE,
            'vat_mode'         => 1,
            'vat_registration' => $this->registrationId,
            'vat_period'       => $periodId,
            'doc_currency'     => 'czk',
            'home_currency'    => 'czk',
            'exchange_rate'    => 1.0,
            'doc_text'         => 'IT zůstatky 343',
            'docState'         => $docState,
            'docStateMain'     => $docState === 90 ? 5 : 2,
        ])->execute();
        $id = (int) $dibi->getInsertId();
        $this->createdHeads[] = $id;
        return $id;
    }

    private function insertJournal(int $headId, string $account, float $dr, float $cr): void
    {
        $dibi = $this->db->getDibiConnection();
        $dibi->insert('economy_accounting_journal', [
            'source_kind'     => 'doc',
            'doc_head'        => $headId,
            'doc_type'        => 'invno',
            'accounting_date' => self::DATE,
            'account_number'  => $account,
            'money_dr'        => $dr,
            'money_cr'        => $cr,
            'currency'        => 'czk',
            'money_dr_cur'    => $dr,
            'money_cr_cur'    => $cr,
            'text'            => 'IT zůstatky 343',
        ])->execute();
        $this->createdJournal[] = (int) $dibi->getInsertId();
    }

    private function insertFiling(int $periodId, int $docState): void
    {
        $dibi = $this->db->getDibiConnection();
        $dibi->insert(FilingDocument::TABLE, [
            'report_period' => $periodId,
            'report_type'   => 'return',
            'filing_kind'   => 'regular',
            'sequence'      => 1,
            'name'          => 'IT 01/2031 řádné',
            'date_issue'    => '2031-02-20',
            'date_filed'    => $docState === FilingDocument::DOC_STATE_FILED ? '2031-02-20' : null,
            'docState'      => $docState,
            'docStateMain'  => $docState === FilingDocument::DOC_STATE_FILED ? 2 : 1,
        ])->execute();
        $this->createdFilings[] = (int) $dibi->getInsertId();
    }
}
