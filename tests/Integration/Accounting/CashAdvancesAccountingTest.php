<?php

declare(strict_types=1);

namespace Shipard\Tests\Integration\Accounting;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Module\Docs\Core\BoundNumberSeriesProvisioner;
use Shipard\Module\Economy\Accounting\AccountingEngine;
use Shipard\Tests\Integration\IntegrationTestCase;

/**
 * Zálohy na pokladních dokladech (#59 Task E, tasks/cash-import-fixes.md §3)
 * nad reálným dev DS.
 *
 * Odpočet zálohy (sale/purchase.advanceDeduction) se chová jako na faktuře:
 * záporný položkový řádek s DPH → reverseSign → 3249/3149, DPH z rekapitulace.
 * Hotovostní záloha (advance.received/given) je kontační bez DPH → 324/314
 * (vat_amount 0), partner + nepovinný VS na řádku. Záporná záloha = vrácení,
 * konvence D9: záporné částky zůstávají na stranách kroku (saldo účtu shodné
 * s otočeným zápisem).
 */
class CashAdvancesAccountingTest extends IntegrationTestCase
{
    private const ACC_DATE = '2026-06-10';

    /** @var list<int> */
    private array $createdHeads = [];
    /** @var list<int> */
    private array $createdCashDesks = [];
    /** @var list<int> */
    private array $createdSeries = [];

    private ?AccountingEngine $engine = null;
    private ?ConfigRuntime $config = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->config = ConfigRuntime::load($this->realDsPath, 'cs');
        $this->engine = new AccountingEngine($this->db->getDibiConnection(), $this->config);
    }

    protected function onTearDown(): void
    {
        $dibi = $this->db->getDibiConnection();
        foreach ($this->createdHeads as $id) {
            $dibi->delete('economy_accounting_journal')->where('doc_head = %i', $id)->execute();
            $dibi->delete('docs_core_vat_recap')->where('doc_head = %i', $id)->execute();
            $dibi->delete('docs_core_rows')->where('doc_head = %i', $id)->execute();
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

    // ── Kontrolní příklady ──────────────────────────────────────────────────

    public function testReceiptSaleWithAdvanceDeductionBooksLikeInvoice(): void
    {
        // Příjmový PD: prodej 10 000 + 21 % a odpočet přijaté zálohy −4 000 + 21 %:
        // 602 DAL 10 000 · 343120 DAL 1 260 · 3249 MD 4 000 · 211 MD 7 260
        $partner = $this->anyPartnerId();
        $deskId = $this->createCashDesk($this->accountIdByPrefix('211'));
        $headId = $this->insertHead('cash', $deskId, 6000.0, 1260.0, ['cash_dir' => 1, 'partner' => $partner]);
        $this->insertVatRow($headId, 'sale.services', 10000.0, 21.0);
        $this->insertVatRow($headId, 'sale.advanceDeduction', -4000.0, 21.0, ['payment_reference' => 'ZAL 2026/07']);
        $this->insertRecap($headId, 6000.0, 1260.0);

        $result = $this->engine->accountDocument($headId);

        $this->assertSame(1, $result['state'], json_encode($result['messages']));
        $journal = $this->journalOf($headId);
        $this->assertCount(4, $journal);
        $this->assertBalanced($journal);
        $this->assertEqualsWithDelta(10000.0, (float) $this->lineByPrefix($journal, '602')['money_cr'], 0.001);
        $this->assertEqualsWithDelta(1260.0, (float) $this->lineByPrefix($journal, '343120')['money_cr'], 0.001, 'DPH po odpočtu');

        $deduction = $this->lineByPrefix($journal, '3249');
        $this->assertEqualsWithDelta(4000.0, (float) $deduction['money_dr'], 0.001, 'odpočet zdaněné zálohy MD 3249 (reverseSign)');
        $this->assertSame('sale.advanceDeduction', $deduction['operation']);
        $this->assertSame('ZAL 2026/07', (string) $deduction['payment_reference']);

        $this->assertEqualsWithDelta(7260.0, (float) $this->lineByPrefix($journal, '211')['money_dr'], 0.001, 'pokladna o odpočet nižší');
        $this->assertNoLine($journal, '324100');
    }

    public function testReceiptAdvanceReceivedBooksOnUntaxedAdvancesWithPartner(): void
    {
        // Příjmový PD: přijatá záloha 5 000 (advance.received, partner, VS):
        // 324 DAL 5 000 (partner, payment_reference) · 211 MD 5 000
        $partner = $this->anyPartnerId();
        $deskId = $this->createCashDesk($this->accountIdByPrefix('211'));
        $headId = $this->insertHead('cash', $deskId, 5000.0, 0.0, ['cash_dir' => 1]);
        $this->insertPlainRow($headId, 'advance.received', 5000.0, ['partner' => $partner, 'payment_reference' => 'ZAL 2026/08']);

        $result = $this->engine->accountDocument($headId);

        $this->assertSame(1, $result['state'], json_encode($result['messages']));
        $journal = $this->journalOf($headId);
        $this->assertCount(2, $journal);
        $this->assertBalanced($journal);

        $advance = $this->lineByPrefix($journal, '324');
        $this->assertStringStartsNotWith('3249', (string) $advance['account_number'], 'bez DPH → 324, ne 3249');
        $this->assertEqualsWithDelta(5000.0, (float) $advance['money_cr'], 0.001);
        $this->assertSame($partner, (int) $advance['partner'], 'partner z řádku');
        $this->assertSame('ZAL 2026/08', (string) $advance['payment_reference']);
        $this->assertSame('advance.received', $advance['operation']);
        $this->assertEqualsWithDelta(5000.0, (float) $this->lineByPrefix($journal, '211')['money_dr'], 0.001);
    }

    public function testNegativeAdvanceReceivedIsRefundOnSameSides(): void
    {
        // Příjmový PD: přijatá záloha −5 000 (vrácení): D9 — záporné částky na
        // stranách kroku: 324 DAL −5 000 · 211 MD −5 000 (saldo = 324 MD / 211 DAL).
        $partner = $this->anyPartnerId();
        $deskId = $this->createCashDesk($this->accountIdByPrefix('211'));
        $headId = $this->insertHead('cash', $deskId, -5000.0, 0.0, ['cash_dir' => 1]);
        $this->insertPlainRow($headId, 'advance.received', -5000.0, ['partner' => $partner]);

        $result = $this->engine->accountDocument($headId);

        $this->assertSame(1, $result['state'], json_encode($result['messages']));
        $journal = $this->journalOf($headId);
        $this->assertCount(2, $journal);
        $this->assertBalanced($journal);

        $advance = $this->lineByPrefix($journal, '324');
        $this->assertEqualsWithDelta(-5000.0, (float) $advance['money_cr'], 0.001, 'vrácení = záporný DAL');
        $this->assertEqualsWithDelta(0.0, (float) $advance['money_dr'], 0.001);
        $this->assertNull($advance['payment_reference'], 'VS nepovinný');
        $this->assertSame($partner, (int) $advance['partner']);

        $cash = $this->lineByPrefix($journal, '211');
        $this->assertEqualsWithDelta(-5000.0, (float) $cash['money_dr'], 0.001, 'pokladna záporně MD = úbytek');

        // saldo účtu 324 po zálohách +5 000 a −5 000 je nula
        $this->assertEqualsWithDelta(5000.0, (float) $advance['money_dr'] - (float) $advance['money_cr'], 0.001);
    }

    public function testDisbursementAdvanceGivenBooksOnUntaxedAdvances(): void
    {
        // Výdajový PD: poskytnutá záloha 3 000 (advance.given): 314 MD 3 000 · 211 DAL 3 000
        $partner = $this->anyPartnerId();
        $deskId = $this->createCashDesk($this->accountIdByPrefix('211'));
        $headId = $this->insertHead('cash', $deskId, 3000.0, 0.0, ['cash_dir' => 2]);
        $this->insertPlainRow($headId, 'advance.given', 3000.0, ['partner' => $partner]);

        $result = $this->engine->accountDocument($headId);

        $this->assertSame(1, $result['state'], json_encode($result['messages']));
        $journal = $this->journalOf($headId);
        $this->assertCount(2, $journal);
        $this->assertBalanced($journal);

        $advance = $this->lineByPrefix($journal, '314');
        $this->assertStringStartsNotWith('3149', (string) $advance['account_number']);
        $this->assertEqualsWithDelta(3000.0, (float) $advance['money_dr'], 0.001);
        $this->assertSame($partner, (int) $advance['partner']);
        $this->assertSame('advance.given', $advance['operation']);
        $this->assertEqualsWithDelta(3000.0, (float) $this->lineByPrefix($journal, '211')['money_cr'], 0.001);
    }

    public function testDisbursementPurchaseWithAdvanceDeductionBooksLikeInvoice(): void
    {
        // Výdajový PD: nákup 2 000 + 21 % a odpočet poskytnuté zálohy −1 000 + 21 %:
        // 504 MD 2 000 · 343120 MD 210 · 3149 DAL 1 000 · 211 DAL 1 210
        $deskId = $this->createCashDesk($this->accountIdByPrefix('211'));
        $headId = $this->insertHead('cash', $deskId, 1000.0, 210.0, ['cash_dir' => 2]);
        $this->insertVatRow($headId, 'purchase.goods', 2000.0, 21.0);
        $this->insertVatRow($headId, 'purchase.advanceDeduction', -1000.0, 21.0);
        $this->insertRecap($headId, 1000.0, 210.0);

        $result = $this->engine->accountDocument($headId);

        $this->assertSame(1, $result['state'], json_encode($result['messages']));
        $journal = $this->journalOf($headId);
        $this->assertCount(4, $journal);
        $this->assertBalanced($journal);
        $this->assertEqualsWithDelta(2000.0, (float) $this->lineByPrefix($journal, '504')['money_dr'], 0.001);
        $this->assertEqualsWithDelta(210.0, (float) $this->lineByPrefix($journal, '343120')['money_dr'], 0.001);
        $this->assertEqualsWithDelta(1000.0, (float) $this->lineByPrefix($journal, '3149')['money_cr'], 0.001, 'odpočet DAL 3149');
        $this->assertEqualsWithDelta(1210.0, (float) $this->lineByPrefix($journal, '211')['money_cr'], 0.001);
    }

    // ── Fixtures ────────────────────────────────────────────────────────────

    /** @return array{0: int, 1: int} [fiscal_year, fiscal_month] */
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

    private function accountIdByPrefix(string $prefix): int
    {
        $row = $this->db->fetchRow(
            'SELECT id FROM economy_accounting_accounts
             WHERE number LIKE %like~ AND account_level = 4 AND docState IN (10,40,80)
             ORDER BY number LIMIT 1',
            $prefix,
        );
        if ($row === null) {
            $this->markTestSkipped("Dev DS nemá analytický účet {$prefix}*");
        }
        return (int) $row['id'];
    }

    private function createCashDesk(int $accountingAccount): int
    {
        $dibi = $this->db->getDibiConnection();
        $dibi->insert('economy_codebooks_cash_desks', [
            'code'               => 'IT' . substr(uniqid(), -5),
            'name'               => 'IT pokladna zálohy',
            'currency'           => 'czk',
            'accounting_account' => $accountingAccount,
            'docState'           => 40,
            'docStateMain'       => 3,
        ])->execute();
        $deskId = (int) $dibi->getInsertId();
        $this->createdCashDesks[] = $deskId;

        (new BoundNumberSeriesProvisioner($this->db, $this->config))->provisionForCashDesk($deskId);
        foreach ($this->db->fetchAll('SELECT id FROM docs_core_number_series WHERE cash_desk = %i', $deskId) as $s) {
            $this->createdSeries[] = (int) $s['id'];
        }
        return $deskId;
    }

    private function seriesFor(string $docType, int $deskId): int
    {
        $row = $this->db->fetchRow(
            'SELECT id FROM docs_core_number_series WHERE doc_type = %s AND cash_desk = %i LIMIT 1',
            $docType, $deskId,
        );
        if ($row === null) {
            $this->markTestSkipped("Chybí řada {$docType} pro pokladnu");
        }
        return (int) $row['id'];
    }

    /** Hlavička ve stavu 40 s ručně spočtenými součty (base/vat = součet přes řádky). */
    private function insertHead(string $docType, int $deskId, float $base, float $vat, array $overrides = []): int
    {
        [$fy, $fm] = $this->fiscalIds();
        $total = round($base + $vat, 2);
        $head = array_merge([
            'doc_type'         => $docType,
            'number_series'    => $this->seriesFor($docType, $deskId),
            'cash_desk'        => $deskId,
            'cash_dir'         => 0,
            'payment_method'   => 0,
            'doc_number'       => 'IT-ADV-' . uniqid(),
            'issue_date'       => self::ACC_DATE,
            'accounting_date'  => self::ACC_DATE,
            'due_date'         => self::ACC_DATE,
            'fiscal_year'      => $fy,
            'fiscal_month'     => $fm,
            'partner'          => null,
            'doc_currency'     => 'czk',
            'home_currency'    => 'czk',
            'exchange_rate'    => 1.0,
            'doc_text'         => 'IT zálohy pokladna',
            'total_base'       => $base,  'total_vat'     => $vat, 'total_amount'     => $total,
            'total_base_dom'   => $base,  'total_vat_dom' => $vat, 'total_amount_dom' => $total,
            'docState'         => 40,
            'docStateMain'     => 2,
        ], $overrides);

        $dibi = $this->db->getDibiConnection();
        $dibi->insert('docs_core_heads', $head)->execute();
        $id = (int) $dibi->getInsertId();
        $this->createdHeads[] = $id;
        return $id;
    }

    /** Položkový řádek s DPH (cz-120); záporný základ = odpočet zálohy. */
    private function insertVatRow(int $headId, string $operation, float $base, float $vatPct, array $overrides = []): int
    {
        $amount = round($base * $vatPct / 100.0, 2);
        $row = array_merge([
            'doc_head'       => $headId,
            'row_kind'       => 1,
            'operation'      => $operation,
            'description'    => "Řádek {$operation}",
            'vat_code'       => 'cz-120',
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

    /** Kontační řádek bez DPH (advance.received/given): částka přímo, vat_code NULL. */
    private function insertPlainRow(int $headId, string $operation, float $amount, array $overrides = []): int
    {
        $row = array_merge([
            'doc_head'        => $headId,
            'row_kind'        => 1,
            'operation'       => $operation,
            'description'     => "Řádek {$operation}",
            'price_calc_mode' => 1,
            'total_price'     => $amount,
            'vat_code'        => null,
            'vat_pct'         => 0,
            'vat_base'        => $amount,
            'vat_amount'      => 0,
            'vat_total'       => $amount,
            'vat_base_dom'    => $amount,
            'vat_amount_dom'  => 0,
            'vat_total_dom'   => $amount,
        ], $overrides);
        $dibi = $this->db->getDibiConnection();
        $dibi->insert('docs_core_rows', $row)->execute();
        return (int) $dibi->getInsertId();
    }

    private function insertRecap(int $headId, float $base, float $tax): void
    {
        $this->db->getDibiConnection()->insert('docs_core_vat_recap', [
            'doc_head'  => $headId,  'vat_code' => 'cz-120', 'vat_pct' => 21.0,
            'base'      => $base,    'tax'      => $tax,     'total'   => round($base + $tax, 2),
            'base_dom'  => $base,    'tax_dom'  => $tax,     'total_dom' => round($base + $tax, 2),
            'sum_base'  => 1, 'sum_tax' => 1, 'sum_total' => 1, 'is_reverse_pair' => 0, 'order_pos' => 0,
        ])->execute();
    }

    // ── Assert helpers ──────────────────────────────────────────────────────

    /** @return list<array<string, mixed>> */
    private function journalOf(int $headId): array
    {
        $rows = $this->db->fetchAll('SELECT * FROM economy_accounting_journal WHERE doc_head = %i ORDER BY id', $headId);
        return array_map(fn($r) => is_array($r) ? $r : $r->toArray(), $rows);
    }

    private function lineByPrefix(array $journal, string $prefix): array
    {
        $matches = array_values(array_filter($journal, fn($l) => str_starts_with((string) $l['account_number'], $prefix)));
        $this->assertCount(1, $matches, "Očekáván právě jeden řádek deníku {$prefix}*");
        return $matches[0];
    }

    private function assertNoLine(array $journal, string $prefix): void
    {
        $matches = array_filter($journal, fn($l) => str_starts_with((string) $l['account_number'], $prefix));
        $this->assertCount(0, $matches, "Řádek {$prefix}* nemá existovat");
    }

    private function assertBalanced(array $journal): void
    {
        $dr = array_sum(array_map(fn($l) => (float) $l['money_dr'], $journal));
        $cr = array_sum(array_map(fn($l) => (float) $l['money_cr'], $journal));
        $this->assertEqualsWithDelta($dr, $cr, 0.001, 'MD != DAL');
    }
}
