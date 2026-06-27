<?php

declare(strict_types=1);

namespace Shipard\Tests\Integration\Accounting;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Document\AbstractJournalEventHandler;
use Shipard\Core\Document\JournalEventDispatcher;
use Shipard\Module\Economy\Accounting\AccountingEngine;
use Shipard\Tests\Integration\IntegrationTestCase;

/**
 * Scénáře W3.3 z tasks/accounting-phase2.md — engine nad reálným dev DS
 * (účtový rozvrh, fiskální období a osoby z provisioneru).
 *
 * Dokladová data se vkládají přímo SQL s ručně spočtenými computed
 * hodnotami — engine čte DB, nezajímá ho, jak vznikla. Vše vytvořené se
 * v tearDown maže.
 */
class AccountingEngineTest extends IntegrationTestCase
{
    private const ACC_DATE = '2026-06-10';

    /** @var list<int> */
    private array $createdHeads = [];

    /** @var list<int> */
    private array $createdItems = [];

    private ?AccountingEngine $engine = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = new AccountingEngine(
            $this->db->getDibiConnection(),
            ConfigRuntime::load($this->realDsPath, 'cs'),
        );
    }

    protected function tearDown(): void
    {
        $dibi = $this->db->getDibiConnection();
        foreach ($this->createdHeads as $id) {
            $dibi->delete('economy_accounting_journal')->where('doc_head = %i', $id)->execute();
            $dibi->delete('docs_core_vat_recap')->where('doc_head = %i', $id)->execute();
            $dibi->delete('docs_core_rows')->where('doc_head = %i', $id)->execute();
            $dibi->delete('docs_core_heads')->where('id = %i', $id)->execute();
        }
        foreach ($this->createdItems as $id) {
            $dibi->delete('economy_items')->where('id = %i', $id)->execute();
        }
        parent::tearDown();
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function fiscalIds(): array
    {
        $fy = $this->db->fetchRow(
            'SELECT id FROM economy_codebooks_fiscal_years WHERE date_begin <= %s AND date_end >= %s LIMIT 1',
            self::ACC_DATE, self::ACC_DATE,
        );
        $fm = $this->db->fetchRow(
            'SELECT id FROM economy_codebooks_fiscal_months WHERE date_begin <= %s AND date_end >= %s AND period_type = 1 LIMIT 1',
            self::ACC_DATE, self::ACC_DATE,
        );
        if ($fy === null || $fm === null) {
            $this->markTestSkipped('Dev DS nemá fiskální období pro ' . self::ACC_DATE);
        }
        return [(int) $fy['id'], (int) $fm['id']];
    }

    private function anyPartnerId(): int
    {
        $row = $this->db->fetchRow('SELECT id FROM base_persons_persons ORDER BY id LIMIT 1');
        if ($row === null) {
            $this->markTestSkipped('Dev DS nemá žádnou osobu');
        }
        return (int) $row['id'];
    }

    private function seriesId(string $docType): int
    {
        $row = $this->db->fetchRow(
            'SELECT id FROM docs_core_number_series WHERE doc_type = %s LIMIT 1',
            $docType,
        );
        if ($row === null) {
            $this->markTestSkipped("Dev DS nemá číselnou řadu pro {$docType}");
        }
        return (int) $row['id'];
    }

    /**
     * Vloží hlavičku s defaulty CZK dokladu ve stavu 40. $overrides
     * přepisují cokoliv (totals, měnu, fiskální období…).
     */
    private function insertHead(string $docType, array $overrides = []): int
    {
        [$fy, $fm] = $this->fiscalIds();
        $head = array_merge([
            'doc_type'        => $docType,
            'number_series'   => $this->seriesId($docType),
            'doc_number'      => 'IT-ACC-' . uniqid(),
            'issue_date'      => self::ACC_DATE,
            'accounting_date' => self::ACC_DATE,
            'fiscal_year'     => $fy,
            'fiscal_month'    => $fm,
            'partner'         => $this->anyPartnerId(),
            'doc_currency'    => 'czk',
            'home_currency'   => 'czk',
            'exchange_rate'   => 1.0,
            'doc_text'        => 'IT test doklad',
            'docState'        => 40,
            'docStateMain'    => 2,
        ], $overrides);

        $dibi = $this->db->getDibiConnection();
        $dibi->insert('docs_core_heads', $head)->execute();
        $id = (int) $dibi->getInsertId();
        $this->createdHeads[] = $id;
        return $id;
    }

    /**
     * Vloží běžný řádek s computed hodnotami. Pro CZK doklady _dom = cur.
     */
    private function insertRow(int $headId, string $operation, float $base, float $vatPct, array $overrides = []): int
    {
        $amount = round($base * $vatPct / 100.0, 2);
        $row = array_merge([
            'doc_head'       => $headId,
            'row_kind'       => 1,
            'operation'      => $operation,
            'description'    => "Řádek {$operation}",
            'vat_code'       => 'cz-101',
            'vat_pct'        => $vatPct,
            'vat_base'       => $base,
            'vat_amount'     => $amount,
            'vat_total'      => round($base + $amount, 2),
            'vat_base_dom'   => $base,
            'vat_amount_dom' => $amount,
            'vat_total_dom'  => round($base + $amount, 2),
        ], $overrides);

        $dibi = $this->db->getDibiConnection();
        $dibi->insert('docs_core_rows', $row)->execute();
        return (int) $dibi->getInsertId();
    }

    private function insertRecap(int $headId, float $base, float $tax, array $overrides = []): void
    {
        $recap = array_merge([
            'doc_head'        => $headId,
            'vat_code'        => 'cz-101',
            'vat_pct'         => 21.0,
            'base'            => $base,
            'tax'             => $tax,
            'total'           => round($base + $tax, 2),
            'base_dom'        => $base,
            'tax_dom'         => $tax,
            'total_dom'       => round($base + $tax, 2),
            'sum_base'        => 1,
            'sum_tax'         => 1,
            'sum_total'       => 1,
            'is_reverse_pair' => 0,
            'order_pos'       => 0,
        ], $overrides);
        $this->db->getDibiConnection()->insert('docs_core_vat_recap', $recap)->execute();
    }

    /** @return list<array<string, mixed>> */
    private function journalOf(int $headId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT * FROM economy_accounting_journal WHERE doc_head = %i ORDER BY id',
            $headId,
        );
        return array_map(fn($r) => is_array($r) ? $r : $r->toArray(), $rows);
    }

    /** @return array<string, mixed> Jediný řádek deníku s daným prefixem čísla účtu */
    private function lineByPrefix(array $journal, string $prefix): array
    {
        $matches = array_values(array_filter(
            $journal,
            fn($l) => str_starts_with((string) $l['account_number'], $prefix),
        ));
        $this->assertCount(1, $matches, "Očekáván právě jeden řádek deníku {$prefix}*");
        return $matches[0];
    }

    private function assertBalanced(array $journal): void
    {
        $dr = array_sum(array_map(fn($l) => (float) $l['money_dr'], $journal));
        $cr = array_sum(array_map(fn($l) => (float) $l['money_cr'], $journal));
        $this->assertEqualsWithDelta($dr, $cr, 0.001, 'MD != DAL (dom)');

        $drCur = array_sum(array_map(fn($l) => (float) $l['money_dr_cur'], $journal));
        $crCur = array_sum(array_map(fn($l) => (float) $l['money_cr_cur'], $journal));
        $this->assertEqualsWithDelta($drCur, $crCur, 0.001, 'MD != DAL (cur)');
    }

    private function accountingState(int $headId): int
    {
        $row = $this->db->fetchRow('SELECT accounting_state FROM docs_core_heads WHERE id = %i', $headId);
        return (int) $row['accounting_state'];
    }

    // ── Scénáře ─────────────────────────────────────────────────────────────

    public function testInvnoCzkBasic(): void
    {
        $headId = $this->insertHead('invno', [
            'total_base' => 1000.0, 'total_vat' => 210.0, 'total_amount' => 1210.0,
            'total_base_dom' => 1000.0, 'total_vat_dom' => 210.0, 'total_amount_dom' => 1210.0,
        ]);
        $this->insertRow($headId, 'sale.services', 1000.0, 21.0);
        $this->insertRecap($headId, 1000.0, 210.0);

        $result = $this->engine->accountDocument($headId);

        $this->assertSame(1, $result['state']);
        $this->assertSame([], $result['messages']);
        $this->assertSame(1, $this->accountingState($headId));

        $journal = $this->journalOf($headId);
        $this->assertCount(3, $journal);
        $this->assertBalanced($journal);

        $revenue = $this->lineByPrefix($journal, '602');
        $this->assertEqualsWithDelta(1000.0, (float) $revenue['money_cr'], 0.001);
        $this->assertSame('sale.services', $revenue['operation']);

        $vat = $this->lineByPrefix($journal, '343');
        $this->assertEqualsWithDelta(210.0, (float) $vat['money_cr'], 0.001);

        $receivable = $this->lineByPrefix($journal, '311');
        $this->assertEqualsWithDelta(1210.0, (float) $receivable['money_dr'], 0.001);

        // CZK doklad: *_cur == domácí hodnoty
        foreach ($journal as $line) {
            $this->assertEqualsWithDelta((float) $line['money_dr'], (float) $line['money_dr_cur'], 0.001);
            $this->assertEqualsWithDelta((float) $line['money_cr'], (float) $line['money_cr_cur'], 0.001);
            $this->assertSame('czk', $line['currency']);
        }
    }

    public function testStampsPaymentIdentityFromHead(): void
    {
        // accbal Fáze 0: každý řádek deníku nese párovací symboly +
        // splatnost z hlavičky (konstantní přes doklad).
        $headId = $this->insertHead('invno', [
            'total_base' => 1000.0, 'total_vat' => 210.0, 'total_amount' => 1210.0,
            'total_base_dom' => 1000.0, 'total_vat_dom' => 210.0, 'total_amount_dom' => 1210.0,
            'payment_reference' => '20260042',
            'specific_symbol'   => '777',
            'constant_symbol'   => '0308',
            'due_date'          => '2026-07-10',
        ]);
        $this->insertRow($headId, 'sale.services', 1000.0, 21.0);
        $this->insertRecap($headId, 1000.0, 210.0);

        $this->assertSame(1, $this->engine->accountDocument($headId)['state']);

        $journal = $this->journalOf($headId);
        $this->assertNotEmpty($journal);
        foreach ($journal as $line) {
            $this->assertSame('20260042', $line['payment_reference']);
            $this->assertSame('777', $line['specific_symbol']);
            $this->assertSame('0308', $line['constant_symbol']);
            $this->assertSame(
                '2026-07-10',
                $line['due_date'] instanceof \DateTimeInterface
                    ? $line['due_date']->format('Y-m-d')
                    : (string) $line['due_date'],
            );
        }
    }

    public function testEmitsJournalWrittenOnAccountReaccountAndClear(): void
    {
        AcctJournalSpy::$calls = [];
        $dispatcher = new JournalEventDispatcher([
            ['class' => AcctJournalSpy::class, 'events' => ['journalWritten']],
        ]);
        $engine = new AccountingEngine(
            $this->db->getDibiConnection(),
            ConfigRuntime::load($this->realDsPath, 'cs'),
            $dispatcher,
        );

        $headId = $this->insertHead('invno', [
            'total_base' => 1000.0, 'total_vat' => 210.0, 'total_amount' => 1210.0,
            'total_base_dom' => 1000.0, 'total_vat_dom' => 210.0, 'total_amount_dom' => 1210.0,
        ]);
        $this->insertRow($headId, 'sale.services', 1000.0, 21.0);
        $this->insertRecap($headId, 1000.0, 210.0);

        $engine->accountDocument($headId);            // zápis deníku
        $engine->accountDocument($headId);            // reaccount (bez změny stavu)
        $engine->clearDocument($headId);              // vymazání deníku

        $this->assertSame(
            [['doc', $headId], ['doc', $headId], ['doc', $headId]],
            AcctJournalSpy::$calls,
            'journalWritten se vyšle po zápisu, reaccountu i clear',
        );
    }

    public function testJournalHandlerExceptionDoesNotBreakAccounting(): void
    {
        // Pozn.: spolknutá výjimka se zaloguje (ErrorLogger → stderr) — očekávané.
        $dispatcher = new JournalEventDispatcher([
            ['class' => ThrowingAcctJournalSpy::class, 'events' => ['journalWritten']],
        ]);
        $engine = new AccountingEngine(
            $this->db->getDibiConnection(),
            ConfigRuntime::load($this->realDsPath, 'cs'),
            $dispatcher,
        );

        $headId = $this->insertHead('invno', [
            'total_base' => 1000.0, 'total_vat' => 210.0, 'total_amount' => 1210.0,
            'total_base_dom' => 1000.0, 'total_vat_dom' => 210.0, 'total_amount_dom' => 1210.0,
        ]);
        $this->insertRow($headId, 'sale.services', 1000.0, 21.0);
        $this->insertRecap($headId, 1000.0, 210.0);

        // Výjimka v journal handleru nesmí shodit účtování — commit už proběhl.
        $result = $engine->accountDocument($headId);
        $this->assertSame(1, $result['state']);
        $this->assertSame(1, $this->accountingState($headId));
        $this->assertCount(3, $this->journalOf($headId));
    }

    public function testInvnoPdpOutputBooksNoVat(): void
    {
        // W4: PDP výstup (cz-150) — daň odvádí zákazník, doklad je jen základ.
        // Recap dle opraveného vat-cz.jsonc: tax 0, sum_tax 0, žádný pár.
        $headId = $this->insertHead('invno', [
            'total_base' => 1000.0, 'total_vat' => 0.0, 'total_amount' => 1000.0,
            'total_base_dom' => 1000.0, 'total_vat_dom' => 0.0, 'total_amount_dom' => 1000.0,
        ]);
        $this->insertRow($headId, 'sale.services', 1000.0, 21.0, ['vat_code' => 'cz-150']);
        $this->insertRecap($headId, 1000.0, 0.0, ['vat_code' => 'cz-150', 'sum_tax' => 0]);

        $result = $this->engine->accountDocument($headId);

        $this->assertSame(1, $result['state']);
        $journal = $this->journalOf($headId);
        $this->assertCount(2, $journal, 'PDP výstup nesmí generovat žádný řádek 343');
        $this->assertBalanced($journal);
        $this->assertEqualsWithDelta(1000.0, (float) $this->lineByPrefix($journal, '602')['money_cr'], 0.001);
        $this->assertEqualsWithDelta(1000.0, (float) $this->lineByPrefix($journal, '311')['money_dr'], 0.001);
    }

    public function testInvniCzkThreeOperations(): void
    {
        $headId = $this->insertHead('invni', [
            'total_base' => 350.0, 'total_vat' => 73.5, 'total_amount' => 423.5,
            'total_base_dom' => 350.0, 'total_vat_dom' => 73.5, 'total_amount_dom' => 423.5,
        ]);
        $this->insertRow($headId, 'purchase.goods', 100.0, 21.0);
        $this->insertRow($headId, 'purchase.services', 200.0, 21.0);
        $this->insertRow($headId, 'purchase.other', 50.0, 21.0);
        $this->insertRecap($headId, 350.0, 73.5);

        $result = $this->engine->accountDocument($headId);

        $this->assertSame(1, $result['state']);
        $journal = $this->journalOf($headId);
        $this->assertCount(5, $journal);
        $this->assertBalanced($journal);

        // Náklady MD, DPH MD, závazek DAL
        $this->assertEqualsWithDelta(100.0, (float) $this->lineByPrefix($journal, '504')['money_dr'], 0.001);
        $this->assertEqualsWithDelta(200.0, (float) $this->lineByPrefix($journal, '518')['money_dr'], 0.001);
        $this->assertEqualsWithDelta(50.0, (float) $this->lineByPrefix($journal, '548')['money_dr'], 0.001);
        $this->assertEqualsWithDelta(73.5, (float) $this->lineByPrefix($journal, '343')['money_dr'], 0.001);
        $this->assertEqualsWithDelta(423.5, (float) $this->lineByPrefix($journal, '321')['money_cr'], 0.001);
    }

    /**
     * W1: EU pořízení služeb (invni, cz-217, základ 1000, 21 %).
     * Recap dle DocDocument: primární cz-217 nese odpočet (tax 210,
     * sum_tax 0), pár cz-207 oddanění (is_reverse_pair 1). Engine:
     * odpočet MD (strana kroku), oddanění DAL (opačná strana).
     */
    public function testInvniEuAcquisitionBooksVatBothSides(): void
    {
        $headId = $this->insertHead('invni', [
            'total_base' => 1000.0, 'total_vat' => 0.0, 'total_amount' => 1000.0,
            'total_base_dom' => 1000.0, 'total_vat_dom' => 0.0, 'total_amount_dom' => 1000.0,
        ]);
        $this->insertRow($headId, 'purchase.services', 1000.0, 21.0, ['vat_code' => 'cz-217']);
        $this->insertRecap($headId, 1000.0, 210.0, [
            'vat_code' => 'cz-217', 'total' => 1000.0, 'total_dom' => 1000.0, 'sum_tax' => 0,
        ]);
        $this->insertRecap($headId, 1000.0, 210.0, [
            'vat_code' => 'cz-207', 'is_reverse_pair' => 1, 'order_pos' => 1,
            'sum_base' => 0, 'sum_tax' => 0, 'sum_total' => 0,
        ]);

        $result = $this->engine->accountDocument($headId);

        $this->assertSame(1, $result['state'], 'EU pořízení musí být vyrovnané: ' . json_encode($result['messages']));
        $journal = $this->journalOf($headId);
        $this->assertCount(4, $journal);
        $this->assertBalanced($journal);

        $this->assertEqualsWithDelta(1000.0, (float) $this->lineByPrefix($journal, '518')['money_dr'], 0.001);
        $this->assertEqualsWithDelta(1000.0, (float) $this->lineByPrefix($journal, '321')['money_cr'], 0.001);

        // Odpočet i oddanění na analytice svého kódu (mapování v předpisu)
        $deduction = $this->lineByPrefix($journal, '343217');
        $this->assertEqualsWithDelta(210.0, (float) $deduction['money_dr'], 0.001, 'Odpočet cz-217 na MD');
        $selfAssessment = $this->lineByPrefix($journal, '343207');
        $this->assertEqualsWithDelta(210.0, (float) $selfAssessment['money_cr'], 0.001, 'Oddanění cz-207 na DAL');
    }

    /**
     * W1: tuzemské PDP4 na vstupu (invni, cz-115 → pár cz-203).
     */
    public function testInvniDomesticPdpInputBooksVatBothSides(): void
    {
        $headId = $this->insertHead('invni', [
            'total_base' => 1000.0, 'total_vat' => 0.0, 'total_amount' => 1000.0,
            'total_base_dom' => 1000.0, 'total_vat_dom' => 0.0, 'total_amount_dom' => 1000.0,
        ]);
        $this->insertRow($headId, 'purchase.other', 1000.0, 21.0, ['vat_code' => 'cz-115']);
        $this->insertRecap($headId, 1000.0, 210.0, [
            'vat_code' => 'cz-115', 'total' => 1000.0, 'total_dom' => 1000.0, 'sum_tax' => 0,
        ]);
        $this->insertRecap($headId, 1000.0, 210.0, [
            'vat_code' => 'cz-203', 'is_reverse_pair' => 1, 'order_pos' => 1,
            'sum_base' => 0, 'sum_tax' => 0, 'sum_total' => 0,
        ]);

        $result = $this->engine->accountDocument($headId);

        $this->assertSame(1, $result['state'], 'PDP vstup musí být vyrovnaný: ' . json_encode($result['messages']));
        $journal = $this->journalOf($headId);
        $this->assertCount(4, $journal);
        $this->assertBalanced($journal);

        $this->assertEqualsWithDelta(1000.0, (float) $this->lineByPrefix($journal, '548')['money_dr'], 0.001);
        $this->assertEqualsWithDelta(1000.0, (float) $this->lineByPrefix($journal, '321')['money_cr'], 0.001);

        $this->assertEqualsWithDelta(210.0, (float) $this->lineByPrefix($journal, '343115')['money_dr'], 0.001);
        $this->assertEqualsWithDelta(210.0, (float) $this->lineByPrefix($journal, '343203')['money_cr'], 0.001);
    }

    /**
     * W2: tuzemský kód účtuje na svou analytiku (343120 výstup, 343110
     * vstup), ne na syntetiku 343.
     */
    public function testDomesticVatCodeBooksOnOwnAnalytics(): void
    {
        $headId = $this->insertHead('invno', [
            'total_base' => 1000.0, 'total_vat' => 210.0, 'total_amount' => 1210.0,
            'total_base_dom' => 1000.0, 'total_vat_dom' => 210.0, 'total_amount_dom' => 1210.0,
        ]);
        $this->insertRow($headId, 'sale.services', 1000.0, 21.0, ['vat_code' => 'cz-120']);
        $this->insertRecap($headId, 1000.0, 210.0, ['vat_code' => 'cz-120']);

        $this->assertSame(1, $this->engine->accountDocument($headId)['state']);
        $vat = $this->lineByPrefix($this->journalOf($headId), '343');
        $this->assertSame('343120', $vat['account_number']);
        $this->assertEqualsWithDelta(210.0, (float) $vat['money_cr'], 0.001);

        $headId2 = $this->insertHead('invni', [
            'total_base' => 500.0, 'total_vat' => 105.0, 'total_amount' => 605.0,
            'total_base_dom' => 500.0, 'total_vat_dom' => 105.0, 'total_amount_dom' => 605.0,
        ]);
        $this->insertRow($headId2, 'purchase.services', 500.0, 21.0, ['vat_code' => 'cz-110']);
        $this->insertRecap($headId2, 500.0, 105.0, ['vat_code' => 'cz-110']);

        $this->assertSame(1, $this->engine->accountDocument($headId2)['state']);
        $vat2 = $this->lineByPrefix($this->journalOf($headId2), '343');
        $this->assertSame('343110', $vat2['account_number']);
        $this->assertEqualsWithDelta(105.0, (float) $vat2['money_dr'], 0.001);
    }

    /**
     * W2: neznámý tuzemský kód spadne na syntetickou masku 343 (fallback
     * s query vat_code_country = cz) — chová se jako dřív.
     */
    public function testUnknownDomesticCodeFallsBackToSynthetic343(): void
    {
        $headId = $this->insertHead('invno', [
            'total_base' => 100.0, 'total_vat' => 21.0, 'total_amount' => 121.0,
            'total_base_dom' => 100.0, 'total_vat_dom' => 21.0, 'total_amount_dom' => 121.0,
        ]);
        $this->insertRow($headId, 'sale.services', 100.0, 21.0, ['vat_code' => 'cz-999']);
        $this->insertRecap($headId, 100.0, 21.0, ['vat_code' => 'cz-999']);

        $result = $this->engine->accountDocument($headId);

        $this->assertSame(1, $result['state']);
        $vat = $this->lineByPrefix($this->journalOf($headId), '343');
        // fallback maska 343 → první aktivní analytika dle čísla
        $this->assertSame(0, (int) $vat['is_error']);
        $this->assertEqualsWithDelta(21.0, (float) $vat['money_cr'], 0.001);
    }

    /**
     * W2: zahraniční kód bez mapování skončí hlasitě — chybový řádek
     * 343???, account_not_found, state 2. Žádné tiché smíchání cizí
     * DPH s tuzemskou na 343.
     */
    public function testForeignCodeWithoutMappingFailsLoudly(): void
    {
        $headId = $this->insertHead('invno', [
            'total_base' => 1000.0, 'total_vat' => 190.0, 'total_amount' => 1190.0,
            'total_base_dom' => 1000.0, 'total_vat_dom' => 190.0, 'total_amount_dom' => 1190.0,
        ]);
        $this->insertRow($headId, 'sale.services', 1000.0, 19.0, ['vat_code' => 'de-120']);
        $this->insertRecap($headId, 1000.0, 190.0, ['vat_code' => 'de-120', 'vat_pct' => 19.0]);

        $result = $this->engine->accountDocument($headId);

        $this->assertSame(2, $result['state']);
        $this->assertSame(2, $this->accountingState($headId));
        $codes = array_column($result['messages'], 'code');
        $this->assertContains('account_not_found', $codes);

        $journal = $this->journalOf($headId);
        $errorLines = array_values(array_filter($journal, fn($l) => (int) $l['is_error'] === 1));
        $this->assertCount(1, $errorLines);
        $this->assertSame('343???', $errorLines[0]['account_number']);
        $this->assertNull($errorLines[0]['account']);
        $this->assertEqualsWithDelta(190.0, (float) $errorLines[0]['money_cr'], 0.001);
    }

    public function testInvnoForeignCurrencyBothAmountSetsBalance(): void
    {
        $rate = 25.285;
        // Hodnoty konzistentní s Fází 1: dom = round(cur × rate), rounding_dom odvozeně 0
        $headId = $this->insertHead('invno', [
            'doc_currency'     => 'eur',
            'exchange_rate'    => $rate,
            'total_base'       => 100.0,  'total_base_dom'   => 2528.50,
            'total_vat'        => 21.0,   'total_vat_dom'    => 530.99,
            'total_amount'     => 121.0,  'total_amount_dom' => 3059.49,
        ]);
        $this->insertRow($headId, 'sale.services', 100.0, 21.0, [
            'vat_base_dom' => 2528.50, 'vat_amount_dom' => 530.99, 'vat_total_dom' => 3059.49,
        ]);
        $this->insertRecap($headId, 100.0, 21.0, ['base_dom' => 2528.50, 'tax_dom' => 530.99]);

        $result = $this->engine->accountDocument($headId);

        $this->assertSame(1, $result['state']);
        $journal = $this->journalOf($headId);
        $this->assertCount(3, $journal);
        $this->assertBalanced($journal);

        $revenue = $this->lineByPrefix($journal, '602');
        $this->assertEqualsWithDelta(2528.50, (float) $revenue['money_cr'], 0.001);
        $this->assertEqualsWithDelta(100.0, (float) $revenue['money_cr_cur'], 0.001);
        $this->assertSame('eur', $revenue['currency']);

        $receivable = $this->lineByPrefix($journal, '311');
        $this->assertEqualsWithDelta(3059.49, (float) $receivable['money_dr'], 0.001);
        $this->assertEqualsWithDelta(121.0, (float) $receivable['money_dr_cur'], 0.001);
    }

    public function testRoundingPositiveGoesTo648(): void
    {
        // 999.80 + 209.96 = 1209.76 → zaokrouhleno 1210.00, rounding +0.24
        $headId = $this->insertHead('invno', [
            'total_base' => 999.80, 'total_vat' => 209.96,
            'total_amount' => 1210.0, 'total_rounding' => 0.24,
            'total_base_dom' => 999.80, 'total_vat_dom' => 209.96,
            'total_amount_dom' => 1210.0, 'total_rounding_dom' => 0.24,
        ]);
        $this->insertRow($headId, 'sale.services', 999.80, 21.0);
        $this->insertRecap($headId, 999.80, 209.96);

        $result = $this->engine->accountDocument($headId);

        $this->assertSame(1, $result['state']);
        $journal = $this->journalOf($headId);
        $this->assertBalanced($journal);
        $rounding = $this->lineByPrefix($journal, '648');
        $this->assertEqualsWithDelta(0.24, (float) $rounding['money_cr'], 0.001);
    }

    public function testRoundingNegativeReverseSignGoesTo548(): void
    {
        // 1000.41 + 210.08 = 1210.49 → zaokrouhleno 1210.00, rounding −0.49
        $headId = $this->insertHead('invno', [
            'total_base' => 1000.41, 'total_vat' => 210.08,
            'total_amount' => 1210.0, 'total_rounding' => -0.49,
            'total_base_dom' => 1000.41, 'total_vat_dom' => 210.08,
            'total_amount_dom' => 1210.0, 'total_rounding_dom' => -0.49,
        ]);
        $this->insertRow($headId, 'sale.services', 1000.41, 21.0);
        $this->insertRecap($headId, 1000.41, 210.08);

        $result = $this->engine->accountDocument($headId);

        $this->assertSame(1, $result['state']);
        $journal = $this->journalOf($headId);
        $this->assertBalanced($journal);
        // reverseSign: −0.49 → MD +0.49 na zaokrouhlení-náklad
        $rounding = $this->lineByPrefix($journal, '548');
        $this->assertEqualsWithDelta(0.49, (float) $rounding['money_dr'], 0.001);
    }

    public function testAccEntryUsesItemAccount(): void
    {
        $account = $this->db->fetchRow(
            'SELECT id, number FROM economy_accounting_accounts
             WHERE account_level = 4 AND number LIKE %s ORDER BY number LIMIT 1',
            '568%',
        );
        if ($account === null) {
            $account = $this->db->fetchRow(
                'SELECT id, number FROM economy_accounting_accounts WHERE account_level = 4 ORDER BY number LIMIT 1',
            );
        }
        $itemId = $this->insertAccEntryItem((int) $account['id']);

        $headId = $this->insertHead('invno', [
            'total_base' => 1050.0, 'total_vat' => 220.5, 'total_amount' => 1270.5,
            'total_base_dom' => 1050.0, 'total_vat_dom' => 220.5, 'total_amount_dom' => 1270.5,
        ]);
        $this->insertRow($headId, 'sale.services', 1000.0, 21.0);
        $this->insertRow($headId, 'acc.entry', 50.0, 21.0, ['item' => $itemId]);
        $this->insertRecap($headId, 1050.0, 220.5);

        $result = $this->engine->accountDocument($headId);

        $this->assertSame(1, $result['state']);
        $journal = $this->journalOf($headId);
        $this->assertBalanced($journal);

        $itemLine = $this->lineByPrefix($journal, (string) $account['number']);
        $this->assertEqualsWithDelta(50.0, (float) $itemLine['money_cr'], 0.001);
        $this->assertSame((int) $account['id'], (int) $itemLine['account']);
    }

    public function testAccEntryWithoutItemAccountProducesErrorRow(): void
    {
        $itemId = $this->insertAccEntryItem(null);

        $headId = $this->insertHead('invno', [
            'total_base' => 50.0, 'total_vat' => 10.5, 'total_amount' => 60.5,
            'total_base_dom' => 50.0, 'total_vat_dom' => 10.5, 'total_amount_dom' => 60.5,
        ]);
        $this->insertRow($headId, 'acc.entry', 50.0, 21.0, ['item' => $itemId]);
        $this->insertRecap($headId, 50.0, 10.5);

        $result = $this->engine->accountDocument($headId);

        $this->assertSame(2, $result['state']);
        $this->assertSame(2, $this->accountingState($headId));
        $codes = array_column($result['messages'], 'code');
        $this->assertContains('item_account_missing', $codes);

        $journal = $this->journalOf($headId);
        $errorLines = array_values(array_filter($journal, fn($l) => (int) $l['is_error'] === 1));
        $this->assertCount(1, $errorLines);
        $this->assertSame('??????', $errorLines[0]['account_number']);
        $this->assertNull($errorLines[0]['account']);
        // ostatní řádky (DPH, pohledávka) zapsané i tak
        $this->assertGreaterThan(1, count($journal));
    }

    public function testMissingAccountInChartProducesMaskedErrorRow(): void
    {
        $dibi = $this->db->getDibiConnection();
        // Dočasně archivuj všechny analytické 311* — engine masku nedohledá
        $affected = $this->db->fetchAll(
            'SELECT id, docState FROM economy_accounting_accounts WHERE number LIKE %s AND account_level = 4',
            '311%',
        );
        $dibi->update('economy_accounting_accounts', ['docState' => 90])
            ->where('number LIKE %s AND account_level = 4', '311%')->execute();

        try {
            $headId = $this->insertHead('invno', [
                'total_base' => 1000.0, 'total_vat' => 210.0, 'total_amount' => 1210.0,
                'total_base_dom' => 1000.0, 'total_vat_dom' => 210.0, 'total_amount_dom' => 1210.0,
            ]);
            $this->insertRow($headId, 'sale.services', 1000.0, 21.0);
            $this->insertRecap($headId, 1000.0, 210.0);

            $result = $this->engine->accountDocument($headId);

            $this->assertSame(2, $result['state']);
            $codes = array_column($result['messages'], 'code');
            $this->assertContains('account_not_found', $codes);

            $journal = $this->journalOf($headId);
            $masked = $this->lineByPrefix($journal, '311');
            $this->assertSame('311???', $masked['account_number']);
            $this->assertSame(1, (int) $masked['is_error']);
            $this->assertNull($masked['account']);
            // ostatní řádky zapsané
            $this->assertCount(3, $journal);
        } finally {
            foreach ($affected as $acc) {
                $a = is_array($acc) ? $acc : $acc->toArray();
                $dibi->update('economy_accounting_accounts', ['docState' => $a['docState']])
                    ->where('id = %i', $a['id'])->execute();
            }
        }
    }

    public function testGroupingSumsSameAccountPartnerOperation(): void
    {
        $headId = $this->insertHead('invno', [
            'total_base' => 300.0, 'total_vat' => 63.0, 'total_amount' => 363.0,
            'total_base_dom' => 300.0, 'total_vat_dom' => 63.0, 'total_amount_dom' => 363.0,
        ]);
        $this->insertRow($headId, 'sale.services', 100.0, 21.0);
        $this->insertRow($headId, 'sale.services', 200.0, 21.0);
        $this->insertRecap($headId, 300.0, 63.0);

        $result = $this->engine->accountDocument($headId);

        $this->assertSame(1, $result['state']);
        $journal = $this->journalOf($headId);
        $this->assertCount(3, $journal, 'Dva řádky sale.services se měly sloučit do jednoho');
        $revenue = $this->lineByPrefix($journal, '602');
        $this->assertEqualsWithDelta(300.0, (float) $revenue['money_cr'], 0.001);
    }

    public function testSecondRunIsIdempotent(): void
    {
        $headId = $this->insertHead('invno', [
            'total_base' => 1000.0, 'total_vat' => 210.0, 'total_amount' => 1210.0,
            'total_base_dom' => 1000.0, 'total_vat_dom' => 210.0, 'total_amount_dom' => 1210.0,
        ]);
        $this->insertRow($headId, 'sale.services', 1000.0, 21.0);
        $this->insertRecap($headId, 1000.0, 210.0);

        $this->engine->accountDocument($headId);
        $first = $this->journalOf($headId);
        $this->engine->accountDocument($headId);
        $second = $this->journalOf($headId);

        $this->assertCount(count($first), $second, 'Druhý běh nesmí zdvojit řádky');
        $this->assertSame(1, $this->accountingState($headId));
    }

    public function testClearDocumentRemovesJournalAndResetsState(): void
    {
        $headId = $this->insertHead('invno', [
            'total_base' => 1000.0, 'total_vat' => 210.0, 'total_amount' => 1210.0,
            'total_base_dom' => 1000.0, 'total_vat_dom' => 210.0, 'total_amount_dom' => 1210.0,
        ]);
        $this->insertRow($headId, 'sale.services', 1000.0, 21.0);
        $this->insertRecap($headId, 1000.0, 210.0);
        $this->engine->accountDocument($headId);
        $this->assertNotSame([], $this->journalOf($headId));

        $this->engine->clearDocument($headId);

        $this->assertSame([], $this->journalOf($headId));
        $this->assertSame(0, $this->accountingState($headId));
    }

    public function testMissingFiscalPeriodSkipsJournal(): void
    {
        $headId = $this->insertHead('invno', [
            'fiscal_year' => null, 'fiscal_month' => null,
            'total_base' => 1000.0, 'total_vat' => 210.0, 'total_amount' => 1210.0,
            'total_base_dom' => 1000.0, 'total_vat_dom' => 210.0, 'total_amount_dom' => 1210.0,
        ]);
        $this->insertRow($headId, 'sale.services', 1000.0, 21.0);
        $this->insertRecap($headId, 1000.0, 210.0);

        $result = $this->engine->accountDocument($headId);

        $this->assertSame(2, $result['state']);
        $this->assertSame('fiscal_period_missing', $result['messages'][0]['code']);
        $this->assertSame([], $this->journalOf($headId));
    }

    public function testReaccountEndpointReturnsStateAndMessages(): void
    {
        $headId = $this->insertHead('invno', [
            'total_base' => 1000.0, 'total_vat' => 210.0, 'total_amount' => 1210.0,
            'total_base_dom' => 1000.0, 'total_vat_dom' => 210.0, 'total_amount_dom' => 1210.0,
        ]);
        $this->insertRow($headId, 'sale.services', 1000.0, 21.0);
        $this->insertRecap($headId, 1000.0, 210.0);

        $ctrl = new \Shipard\Module\Economy\Accounting\AccountingController(
            $this->db,
            ConfigRuntime::load($this->realDsPath, 'cs'),
        );

        $request = \Shipard\Api\Request::fromArray(
            'POST', '/_accounting/reaccount', [], json_encode(['docId' => $headId]), [],
        );
        $response = $ctrl->reaccount($request);
        $payload = $response->getPayload()['data'];

        $this->assertSame(1, $payload['accountingState']);
        $this->assertSame([], $payload['messages']);
        $this->assertCount(3, $this->journalOf($headId));

        // doklad mimo 40 → 422
        $this->db->getDibiConnection()->update('docs_core_heads', ['docState' => 80])
            ->where('id = %i', $headId)->execute();
        $denied = $ctrl->reaccount(\Shipard\Api\Request::fromArray(
            'POST', '/_accounting/reaccount', [], json_encode(['docId' => $headId]), [],
        ));
        $this->assertSame(422, $this->responseStatus($denied));

        // neexistující doklad → 404
        $missing = $ctrl->reaccount(\Shipard\Api\Request::fromArray(
            'POST', '/_accounting/reaccount', [], json_encode(['docId' => 999999999]), [],
        ));
        $this->assertSame(404, $this->responseStatus($missing));
    }

    private function responseStatus(\Shipard\Api\Response $response): int
    {
        $ref = new \ReflectionClass($response);
        return $ref->getProperty('status')->getValue($response);
    }

    public function testAccountingErrorsCheckFindsErrorDoc(): void
    {
        $headId = $this->insertHead('invno', [
            'accounting_state'    => 2,
            'accounting_messages' => json_encode([
                ['code' => 'account_not_found', 'message' => 'Účet nenalezen pro masku 311', 'rowId' => null],
            ], JSON_UNESCAPED_UNICODE),
        ]);

        $check = new \Shipard\Module\Economy\Accounting\Checks\AccountingErrorsCheck(
            $this->db,
            ConfigRuntime::load($this->realDsPath, 'cs'),
            'cs',
        );
        $findings = $check->run();

        $ours = array_values(array_filter(
            $findings,
            fn($f) => $f->findingKey === (string) $headId,
        ));
        $this->assertCount(1, $ours);
        $this->assertStringContainsString('chybu účtování', $ours[0]->title);
        $this->assertStringContainsString('Účet nenalezen pro masku 311', $ours[0]->message);
        $this->assertSame($headId, $ours[0]->subjectRowId);

        // Doklad mimo 40 / bez chyby se nehlásí
        $this->db->getDibiConnection()->update('docs_core_heads', ['accounting_state' => 1])
            ->where('id = %i', $headId)->execute();
        $after = array_filter(
            $check->run(),
            fn($f) => $f->findingKey === (string) $headId,
        );
        $this->assertCount(0, $after);
    }

    /**
     * Hotovo-když #5: alfanumerický účet (OSS konvence 343{CC}{NNN})
     * projde deriveStructure, maska ho dohledá a řádek deníku ho unese.
     */
    public function testAlphanumericAccountWorksEndToEnd(): void
    {
        $dibi = $this->db->getDibiConnection();
        $structure = \Shipard\Module\Economy\Accounting\AccountDocument::deriveStructure('343DE123');
        $this->assertSame(4, $structure['account_level']);
        $this->assertSame('343', $structure['g3']);

        $dibi->insert('economy_accounting_accounts', array_merge($structure, [
            'number'       => '343DE123',
            'name'         => 'DPH OSS DE základní (IT test)',
            'short_name'   => 'DPH OSS DE',
            'account_kind' => 1,
            'docState'     => 40,
            'docStateMain' => 3,
        ]))->execute();
        $accountId = (int) $dibi->getInsertId();

        try {
            // maska 343DE123 účet dohledá (LIKE, ci kolace)
            $resolver = new \Shipard\Module\Economy\Accounting\AccountMaskResolver($dibi);
            $found = $resolver->resolve('343DE123', self::ACC_DATE);
            $this->assertNotNull($found, 'AccountMaskResolver musí alfanumerický účet najít');
            $this->assertSame('343DE123', $found['number']);
            $this->assertSame($accountId, $found['id']);

            // řádek deníku ho unese (varchar 12) — přes acc.entry položku
            $itemId = $this->insertAccEntryItem($accountId);
            $headId = $this->insertHead('invno', [
                'total_base' => 50.0, 'total_vat' => 10.5, 'total_amount' => 60.5,
                'total_base_dom' => 50.0, 'total_vat_dom' => 10.5, 'total_amount_dom' => 60.5,
            ]);
            $this->insertRow($headId, 'acc.entry', 50.0, 21.0, ['item' => $itemId]);
            $this->insertRecap($headId, 50.0, 10.5);

            $result = $this->engine->accountDocument($headId);

            $this->assertSame(1, $result['state']);
            $line = $this->lineByPrefix($this->journalOf($headId), '343DE123');
            $this->assertSame($accountId, (int) $line['account']);
            $this->assertEqualsWithDelta(50.0, (float) $line['money_cr'], 0.001);
        } finally {
            $dibi->delete('economy_accounting_accounts')->where('id = %i', $accountId)->execute();
        }
    }

    private function accountId(string $number): int
    {
        $row = $this->db->fetchRow(
            'SELECT id FROM economy_accounting_accounts WHERE number = %s LIMIT 1',
            $number,
        );
        if ($row === null) {
            $this->markTestSkipped("Dev DS nemá účet {$number}");
        }
        return (int) $row['id'];
    }

    /**
     * Vloží kontační řádek účetního dokladu (cmnbkp): bez DPH, částka v
     * vat_base(_dom), strana + účet + identita přes $overrides.
     */
    private function insertAccRow(int $headId, string $operation, float $amount, int $side, array $overrides = []): int
    {
        return $this->insertRow($headId, $operation, $amount, 0.0, array_merge([
            'vat_code' => null,
            'vat_pct'  => null,
            'acc_side' => $side,
        ], $overrides));
    }

    // ── Účetní doklad (cmnbkp) ──────────────────────────────────────────────

    public function testCmnbkpAccountRecordBalancedWithPerRowIdentity(): void
    {
        // 518100 MD / 321100 DAL, 1000 Kč. Hlavička bez partnera; partner +
        // VS jen na DAL řádku (závazek) — engine je razítkuje per řádek.
        $headId = $this->insertHead('cmnbkp', [
            'partner'         => null,
            'payment_reference' => null,
            'total_base'      => 1000.0, 'total_vat' => 0.0, 'total_amount' => 1000.0,
            'total_base_dom'  => 1000.0, 'total_vat_dom' => 0.0, 'total_amount_dom' => 1000.0,
        ]);
        $partnerId = $this->anyPartnerId();
        $this->insertAccRow($headId, 'acc.record', 1000.0, 0, [
            'account' => $this->accountId('518100'), 'order_pos' => 0,
        ]);
        $this->insertAccRow($headId, 'acc.record', 1000.0, 1, [
            'account' => $this->accountId('321100'), 'order_pos' => 1,
            'partner' => $partnerId, 'payment_reference' => 'VS12345',
        ]);

        $result = $this->engine->accountDocument($headId);

        $this->assertSame(1, $result['state'], json_encode($result['messages']));
        $this->assertSame([], $result['messages']);

        $journal = $this->journalOf($headId);
        $this->assertCount(2, $journal);
        $this->assertBalanced($journal);

        $md = $this->lineByPrefix($journal, '518');
        $this->assertEqualsWithDelta(1000.0, (float) $md['money_dr'], 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $md['money_cr'], 0.001);
        // Nákladový řádek bez partnera (řádek partnera nenese) — null.
        $this->assertNull($md['partner']);
        $this->assertNull($md['payment_reference']);

        $dal = $this->lineByPrefix($journal, '321');
        $this->assertEqualsWithDelta(1000.0, (float) $dal['money_cr'], 0.001);
        $this->assertSame($partnerId, (int) $dal['partner']);
        $this->assertSame('VS12345', (string) $dal['payment_reference']);
    }

    public function testCmnbkpSameAccountDifferentVsNotMerged(): void
    {
        // Dva závazky na 321100, různý VS → dva řádky deníku (D7), ne sloučený.
        $headId = $this->insertHead('cmnbkp', [
            'partner'        => null,
            'total_base'     => 300.0, 'total_vat' => 0.0, 'total_amount' => 300.0,
            'total_base_dom' => 300.0, 'total_vat_dom' => 0.0, 'total_amount_dom' => 300.0,
        ]);
        $partnerId = $this->anyPartnerId();
        $acc321 = $this->accountId('321100');
        $this->insertAccRow($headId, 'acc.record', 300.0, 0, [
            'account' => $this->accountId('518100'), 'order_pos' => 0,
        ]);
        $this->insertAccRow($headId, 'acc.record', 100.0, 1, [
            'account' => $acc321, 'order_pos' => 1, 'partner' => $partnerId, 'payment_reference' => 'AAA',
        ]);
        $this->insertAccRow($headId, 'acc.record', 200.0, 1, [
            'account' => $acc321, 'order_pos' => 2, 'partner' => $partnerId, 'payment_reference' => 'BBB',
        ]);

        $this->engine->accountDocument($headId);
        $journal = $this->journalOf($headId);

        $dal321 = array_values(array_filter(
            $journal,
            fn($l) => str_starts_with((string) $l['account_number'], '321'),
        ));
        $this->assertCount(2, $dal321, 'Dva závazky s různým VS se nesmí slít do jednoho řádku deníku');
        $this->assertCount(3, $journal);
        $this->assertBalanced($journal);

        $vs = array_map(fn($l) => (string) $l['payment_reference'], $dal321);
        sort($vs);
        $this->assertSame(['AAA', 'BBB'], $vs);
    }

    public function testCmnbkpAccountFromItem(): void
    {
        // acc.item: účet přijde z položky typu 2 (Účetní položka).
        $headId = $this->insertHead('cmnbkp', [
            'partner'        => null,
            'total_base'     => 500.0, 'total_vat' => 0.0, 'total_amount' => 500.0,
            'total_base_dom' => 500.0, 'total_vat_dom' => 0.0, 'total_amount_dom' => 500.0,
        ]);
        $itemId = $this->insertAccEntryItem($this->accountId('518100'));
        $this->insertAccRow($headId, 'acc.item', 500.0, 0, ['item' => $itemId, 'order_pos' => 0]);
        $this->insertAccRow($headId, 'acc.record', 500.0, 1, [
            'account' => $this->accountId('321100'), 'order_pos' => 1,
        ]);

        $result = $this->engine->accountDocument($headId);

        $this->assertSame(1, $result['state'], json_encode($result['messages']));
        $journal = $this->journalOf($headId);
        $this->assertCount(2, $journal);
        $this->assertBalanced($journal);
        $md = $this->lineByPrefix($journal, '518');
        $this->assertEqualsWithDelta(500.0, (float) $md['money_dr'], 0.001);
    }

    private function insertAccEntryItem(?int $accountId): int
    {
        $kind = $this->db->fetchRow('SELECT id FROM economy_items_kinds ORDER BY id LIMIT 1');
        $unit = $this->db->fetchRow('SELECT id FROM core_units ORDER BY id LIMIT 1');
        if ($kind === null || $unit === null) {
            $this->markTestSkipped('Dev DS nemá druhy položek / jednotky');
        }

        $dibi = $this->db->getDibiConnection();
        $dibi->insert('economy_items', [
            'code'               => 'IT-ACC-' . uniqid(),
            'name'               => 'IT účetní položka',
            'item_kind'          => (int) $kind['id'],
            'unit'               => (int) $unit['id'],
            'item_type'          => 2,
            'accounting_account' => $accountId,
        ])->execute();
        $id = (int) $dibi->getInsertId();
        $this->createdItems[] = $id;
        return $id;
    }
}

/** Spy journal handler — zaznamenává (sourceKind, sourceId) staticky. */
class AcctJournalSpy extends AbstractJournalEventHandler
{
    /** @var list<array{0: string, 1: int}> */
    public static array $calls = [];

    public function onJournalWritten(string $sourceKind, int $sourceId): void
    {
        self::$calls[] = [$sourceKind, $sourceId];
    }
}

/** Vyhazuje výjimku — ověřuje, že nespadne účtování. */
class ThrowingAcctJournalSpy extends AbstractJournalEventHandler
{
    public function onJournalWritten(string $sourceKind, int $sourceId): void
    {
        throw new \RuntimeException('saldo boom');
    }
}
