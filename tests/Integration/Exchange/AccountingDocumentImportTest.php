<?php

declare(strict_types=1);

namespace Shipard\Tests\Integration\Exchange;

use Shipard\Api\DocumentEventHandlerLoader;
use Shipard\Api\DocumentLoader;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Module\Core\Exchange\Document\DocumentApplier;
use Shipard\Tests\Integration\IntegrationTestCase;

/**
 * End-to-end import účetního dokladu (docType accountingDocument → cmnbkp)
 * proti živému DS (`SHIPARD_INTEGRATION_DS_PATH`).
 *
 * Ověřuje Fázi 4a: applier přijme kanonický účetní doklad s kontačními řádky
 * a zapíše cmnbkp s účtem (z čísla), stranou, částkou a per-řádkovou saldo
 * identitou; bez hlavičkového partnera (žádná selfParty chyba); na stavu 40
 * se přes dispatcher zaúčtuje (vyrovnaný deník).
 */
class AccountingDocumentImportTest extends IntegrationTestCase
{
    private const FIXTURE_PREFIX = 'IT-ACCDOC';

    private ConfigRuntime $configRuntime;
    private DocumentApplier $applier;

    /** @var list<int> */
    private array $createdDocIds = [];

    private ?int $ownCompanyPersonId = null;
    private bool $createdOwnCompany = false;
    private int $partnerId = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $modulePathResolver = new ModulePathResolver([dirname(__DIR__, 3) . '/modules']);
        $documentRegistry = DocumentLoader::load($this->dsConfig, $modulePathResolver);
        $this->configRuntime = ConfigRuntime::load($this->realDsPath, 'cs');

        // cmnbkp řada (provisioner zakládá jednu, doc_number_code = null → bez
        // numberSeriesCode se použije first-active fallback).
        $series = $this->db->fetchRow(
            'SELECT id FROM docs_core_number_series WHERE doc_type = %s AND docState IN (%i, %i, %i) LIMIT 1',
            'cmnbkp', 10, 40, 80,
        );
        if ($series === null) {
            $this->markTestSkipped('DS nemá číselnou řadu pro cmnbkp.');
        }

        foreach (['518100', '321100'] as $number) {
            if ($this->accountId($number) === 0) {
                $this->markTestSkipped("DS nemá účet {$number}.");
            }
        }

        $person = $this->db->fetchRow(
            'SELECT id FROM base_persons_persons WHERE docState IN (%i, %i, %i) ORDER BY id LIMIT 1',
            10, 40, 80,
        );
        if ($person === null) {
            $this->markTestSkipped('DS nemá žádnou osobu pro per-řádkového partnera.');
        }
        $this->partnerId = (int) $person['id'];

        $dispatcher = DocumentEventHandlerLoader::load(
            $this->dsConfig,
            $modulePathResolver,
            $this->db->getDibiConnection(),
            $this->configRuntime,
        );

        $this->applier = DocumentApplier::create(
            $this->db->getDibiConnection(),
            $this->configRuntime,
            $this->dsConfig,
            $documentRegistry,
            $this->tables,
            $dispatcher,
        );

        $this->ensureOwnCompany();
    }

    protected function tearDown(): void
    {
        $dibi = $this->db->getDibiConnection();
        foreach ($this->createdDocIds as $id) {
            $dibi->query('DELETE FROM economy_accounting_journal WHERE doc_head = %i', $id);
            $dibi->query('DELETE FROM docs_core_rows WHERE doc_head = %i', $id);
            $dibi->query('DELETE FROM docs_core_vat_recap WHERE doc_head = %i', $id);
            $dibi->query('DELETE FROM docs_core_heads WHERE id = %i', $id);
        }
        if ($this->createdOwnCompany && $this->ownCompanyPersonId !== null) {
            $dibi->query('DELETE FROM base_persons_persons WHERE id = %i', $this->ownCompanyPersonId);
        }
        parent::tearDown();
    }

    public function testImportBalancedAccountingDocumentAtState40(): void
    {
        $seq = random_int(900_000_000, 999_999_999);
        $ourNumber = self::FIXTURE_PREFIX . '-' . $seq;

        $canonical = [
            'format'        => 'shpd.docs.document',
            'formatVersion' => '1.0',
            'docType'       => 'accountingDocument',
            'source'        => ['kind' => 'import'],
            'dates'         => ['issueDate' => '2026-06-10', 'accountingDate' => '2026-06-10'],
            'rows' => [
                // MD náklad 518100 / 1000
                ['operation' => 'acc.record', 'account' => '518100', 'accSide' => 'debit',
                 'totalPrice' => 1000.0],
                // DAL závazek 321100 / 1000 s partnerem + VS (per-řádková identita)
                ['operation' => 'acc.record', 'account' => '321100', 'accSide' => 'credit',
                 'totalPrice' => 1000.0, 'paymentReference' => 'VS-' . $seq],
            ],
            '_resolve' => [
                // Per-řádkový partner pin na DAL řádek.
                'rows' => [1 => ['partner' => ['userAction' => 'useExisting:' . $this->partnerId]]],
            ],
            'applyOptions' => [
                'targetDocState' => 40,
                'importNumber'   => ['docNumber' => $ourNumber, 'sequenceNumber' => $seq],
            ],
        ];

        $result = $this->applier->apply($canonical);
        $this->assertTrue(
            $result->success,
            'apply selhal: ' . $result->errorCode . ' — ' . $result->errorMessage
            . ' / ' . json_encode($result->canonical['_resolve']['issues'] ?? []),
        );
        $docId = (int) $result->savedId;
        $this->createdDocIds[] = $docId;

        $head = $this->db->fetchRow('SELECT * FROM docs_core_heads WHERE id = %i', $docId);
        $this->assertSame('cmnbkp', (string) $head['doc_type']);
        $this->assertSame(40, (int) $head['docState']);
        $this->assertSame($ourNumber, (string) $head['doc_number']);
        // Hlavičkový partner nepovinný — nezadán → null.
        $this->assertNull($head['partner']);

        // Řádky: účet z čísla, strana, částka, per-řádkový partner + VS.
        $rows = $this->db->fetchAll(
            'SELECT * FROM docs_core_rows WHERE doc_head = %i ORDER BY order_pos',
            $docId,
        );
        $this->assertCount(2, $rows);

        $md = $rows[0];
        $this->assertSame($this->accountId('518100'), (int) $md['account']);
        $this->assertSame(0, (int) $md['acc_side']);
        $this->assertEqualsWithDelta(1000.0, (float) $md['total_price'], 0.001);

        $dal = $rows[1];
        $this->assertSame($this->accountId('321100'), (int) $dal['account']);
        $this->assertSame(1, (int) $dal['acc_side']);
        $this->assertSame($this->partnerId, (int) $dal['partner']);
        $this->assertSame('VS-' . $seq, (string) $dal['payment_reference']);

        // Zaúčtování proběhlo (dispatcher) → vyrovnaný deník.
        $this->assertContains((int) $head['accounting_state'], [1, 2]);
        $journal = $this->db->fetchAll('SELECT * FROM economy_accounting_journal WHERE doc_head = %i', $docId);
        if ((int) $head['accounting_state'] === 1) {
            $this->assertCount(2, $journal);
            $dr = array_sum(array_map(fn($l) => (float) $l['money_dr'], $journal));
            $cr = array_sum(array_map(fn($l) => (float) $l['money_cr'], $journal));
            $this->assertEqualsWithDelta(1000.0, $dr, 0.001);
            $this->assertEqualsWithDelta($dr, $cr, 0.001);
            // Per-řádková identita propsaná do deníku (DAL řádek nese VS).
            $dalLine = array_values(array_filter(
                $journal,
                fn($l) => str_starts_with((string) $l['account_number'], '321'),
            ))[0];
            $this->assertSame('VS-' . $seq, (string) $dalLine['payment_reference']);
            $this->assertSame($this->partnerId, (int) $dalLine['partner']);
        }
    }

    private function accountId(string $number): int
    {
        $row = $this->db->fetchRow(
            'SELECT id FROM economy_accounting_accounts WHERE number = %s AND docState IN (%i, %i, %i) LIMIT 1',
            $number, 10, 40, 80,
        );
        return $row !== null ? (int) $row['id'] : 0;
    }

    private function ensureOwnCompany(): void
    {
        $row = $this->db->fetchRow('SELECT id FROM base_persons_persons WHERE is_own = 1 LIMIT 1');
        if ($row !== null) {
            $this->ownCompanyPersonId = (int) $row['id'];
            return;
        }
        $this->db->getDibiConnection()->insert('base_persons_persons', [
            'person_id'    => 'F-OWNAD',
            'person_type'  => 2,
            'full_name'    => self::FIXTURE_PREFIX . ' Own',
            'last_name'    => self::FIXTURE_PREFIX . ' Own',
            'first_name'   => '',
            'company_id'   => '00000098',
            'is_own'       => 1,
            'docState'     => 40,
            'docStateMain' => 3,
        ])->execute();
        $this->ownCompanyPersonId = (int) $this->db->getDibiConnection()->getInsertId();
        $this->createdOwnCompany = true;
    }
}
