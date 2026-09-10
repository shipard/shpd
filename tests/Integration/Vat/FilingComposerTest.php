<?php

declare(strict_types=1);

namespace Shipard\Tests\Integration\Vat;

use Shipard\Api\DocumentLoader;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Document\DocumentResult;
use Shipard\Core\Document\TableGateway;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Module\Economy\Vat\FilingComposer;
use Shipard\Module\Economy\Vat\FilingDocument;
use Shipard\Tests\Integration\IntegrationTestCase;

/**
 * Sestavení snapshotu podání nad reálným dev DS (#55 Fáze 2). Test si
 * zakládá **vlastní instance tvrzení** v izolovaném rozsahu a vlastní
 * doklady, takže může asertovat absolutní čísla — snapshot je celý jeho.
 *
 * Pokrývá: materializované mapování a sekce KH v dokladové úrovni, řádkové
 * zaokrouhlení podaných hodnot, rozdíl dodatečného přiznání s ř. 66,
 * idempotenci přepočtu, tvrdou chybu u kódu bez mapování a odmítnutí
 * přepočtu podaného podání.
 */
class FilingComposerTest extends IntegrationTestCase
{
    /** Izolovaný rozsah — instance tvrzení tam běžný provoz negeneruje. */
    private const DATE_BEGIN = '2029-01-01';
    private const DATE_END   = '2029-01-31';
    private const DUZP       = '2029-01-15';

    private ?ConfigRuntime $config = null;
    private int $registrationId = 0;

    /** @var list<int> */
    private array $createdPeriods = [];
    /** @var list<int> */
    private array $createdHeads = [];
    /** @var list<int> */
    private array $createdFilings = [];

    /** Původní profil podatele registrace — vrací ho teardown. */
    private mixed $originalProfile = null;
    private bool $profileRestored = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->config = ConfigRuntime::load($this->realDsPath, 'cs');

        if (!isset($this->tables[FilingDocument::TABLE])) {
            $this->markTestSkipped('DS nemá tabulku economy_vat_filings — spusťte ds-upgrade');
        }
        if (!is_array($this->config->cfgItem('economy.vat.reports.cz'))) {
            $this->markTestSkipped('DS nemá compiled mapování economy.vat.reports.cz');
        }

        $reg = $this->db->fetchRow(
            'SELECT id FROM economy_codebooks_vat_registrations'
            . ' WHERE country = %s AND docState IN (10,40,80) ORDER BY id LIMIT 1',
            'cz',
        );
        if ($reg === null) {
            $this->markTestSkipped('DS nemá registraci k DPH (cz)');
        }
        $this->registrationId = (int) $reg['id'];

        $collision = (int) $this->db->fetchSingle(
            'SELECT COUNT(*) FROM economy_vat_report_periods'
            . ' WHERE vat_registration = %i AND date_begin <= %s AND date_end >= %s AND docState != 90',
            $this->registrationId, self::DATE_END, self::DATE_BEGIN,
        );
        if ($collision > 0) {
            $this->markTestSkipped('DS už má instanci tvrzení v testovacím rozsahu 01/2029');
        }
    }

    protected function onTearDown(): void
    {
        $dibi = $this->db->getDibiConnection();
        foreach ($this->createdFilings as $id) {
            foreach (FilingDocument::SNAPSHOT_TABLES as $table) {
                $dibi->delete($table)->where('filing = %i', $id)->execute();
            }
            $dibi->delete(FilingDocument::TABLE)->where('id = %i', $id)->execute();
        }
        foreach ($this->createdHeads as $id) {
            $dibi->delete('docs_core_vat_recap')->where('doc_head = %i', $id)->execute();
            $dibi->delete('docs_core_heads')->where('id = %i', $id)->execute();
        }
        foreach ($this->createdPeriods as $id) {
            $dibi->delete('economy_vat_report_periods')->where('id = %i', $id)->execute();
        }
        if ($this->profileRestored) {
            $dibi->update('economy_codebooks_vat_registrations', ['filing_profile' => $this->originalProfile])
                ->where('id = %i', $this->registrationId)->execute();
        }
    }

    // ── Řádné přiznání ──────────────────────────────────────────────────────

    public function testRegularReturnSnapshot(): void
    {
        $periodId = $this->insertPeriod('return', '01/2029 IT');
        // Vydaná faktura: základní sazba, ř. 1; pod limitem 10 000 → sekce A5.
        $issued = $this->insertDoc('invno', $periodId, 'vat_period', 'cz-120', 1000.30, 210.06, 'CZ12345678');
        // Přijatá faktura: odpočet ř. 40 (plná výše), sekce B3.
        $received = $this->insertDoc('invni', $periodId, 'vat_period', 'cz-110', 500.50, 105.11, 'CZ87654321');

        $filingId = $this->createFiling($periodId, 'regular');

        // Dokladová úroveň: materializované mapování a sekce z enginu.
        $items = $this->items($filingId);
        $this->assertCount(2, $items);

        $issuedItem = $items[$issued];
        $this->assertSame(1, (int) $issuedItem['dp3_row']);
        $this->assertNull($issuedItem['dp3_col']);
        $this->assertSame('A4A5', $issuedItem['kh_group'], 'skupina z configu, před rozpadem');
        $this->assertSame('A5', $issuedItem['kh_section'], 'pod limitem 10 000 → agregát A5');
        $this->assertNull($issuedItem['sh_kod']);
        $this->assertSame('CZ12345678', $issuedItem['partner_vat_id'], 'naše plnění → DIČ odběratele');
        $this->assertEqualsWithDelta(1000.30, (float) $issuedItem['base_dom'], 0.001);

        $receivedItem = $items[$received];
        $this->assertSame(40, (int) $receivedItem['dp3_row']);
        $this->assertSame('full', $receivedItem['dp3_col']);
        $this->assertSame('B2B3', $receivedItem['kh_group']);
        $this->assertSame('B3', $receivedItem['kh_section']);
        $this->assertSame('CZ87654321', $receivedItem['partner_vat_id'], 'přijaté plnění → DIČ dodavatele');

        // Výstupní řádky: přesně vedle podaného, řádek po řádku na Kč.
        $rows = $this->returnRows($filingId);
        $this->assertSame(1000.30, $rows[1]['base'], 'přesná hodnota z kalkulátoru');
        $this->assertSame(1000.0, $rows[1]['base_filed']);
        $this->assertSame(210.06, $rows[1]['tax_full']);
        $this->assertSame(210.0, $rows[1]['tax_full_filed']);
        $this->assertSame(501.0, $rows[40]['base_filed']);
        $this->assertSame(105.0, $rows[40]['tax_full_filed']);
        $this->assertSame(105.0, $rows[46]['tax_full_filed']);
        $this->assertSame(210.0, $rows[62]['tax_full_filed']);
        $this->assertSame(105.0, $rows[63]['tax_full_filed']);
        $this->assertSame(105.0, $rows[64]['tax_full_filed'], '210 − 105 ze zaokrouhlených řádků');
        $this->assertSame(0.0, $rows[65]['tax_full_filed']);
        $this->assertSame(0.0, $rows[66]['tax_full_filed']);
        $this->assertSame(1, (int) $rows[46]['is_computed']);
        $this->assertSame(0, (int) $rows[1]['is_computed']);

        // Přesná daňová povinnost je 210,06 − 105,11 = 104,95 — jiné číslo
        // než podaných 105 Kč; snapshot drží obě.
        $this->assertSame(104.95, $rows[64]['tax_full']);

        $result = $this->filingResult($filingId);
        $this->assertFalse($result['isEmpty']);
        $this->assertSame(2, $result['docCount']);
        $this->assertSame(2, $result['itemCount']);
        $this->assertEqualsWithDelta(105.0, $result['return']['row64'], 0.001);
        $this->assertEqualsWithDelta(0.0, $result['return']['row66'], 0.001);
        $this->assertArrayHasKey('crossCheck', $result);
    }

    public function testEmptyPeriodComposesEmptySnapshot(): void
    {
        $periodId = $this->insertPeriod('return', '01/2029 IT prázdné');
        $filingId = $this->createFiling($periodId, 'regular');

        $this->assertSame([], $this->items($filingId));
        $result = $this->filingResult($filingId);
        $this->assertTrue($result['isEmpty'], 'prázdné podání se podává také');
        $this->assertSame(0, $result['docCount']);
        // Dopočtené řádky existují i bez dokladů — XML je potřebuje nulové.
        $rows = $this->returnRows($filingId);
        $this->assertSame(0.0, $rows[64]['tax_full_filed']);
    }

    // ── Dodatečné přiznání ──────────────────────────────────────────────────

    public function testSupplementaryFilingIsDiffWithRow66(): void
    {
        $periodId = $this->insertPeriod('return', '01/2029 IT dodatečné');
        $head = $this->insertDoc('invno', $periodId, 'vat_period', 'cz-120', 10000.0, 2100.0, 'CZ12345678');

        $regularId = $this->createFiling($periodId, 'regular');
        $this->fileFiling($regularId);

        // Doklad se po podání změnil — daň o 420 Kč vyšší.
        $this->db->getDibiConnection()->update('docs_core_vat_recap', [
            'base' => 12000.0, 'tax' => 2520.0, 'total' => 14520.0,
            'base_dom' => 12000.0, 'tax_dom' => 2520.0, 'total_dom' => 14520.0,
        ])->where('doc_head = %i', $head)->execute();

        $supplementaryId = $this->createFiling($periodId, 'supplementary', '2029-03-01');

        $rows = $this->returnRows($supplementaryId);
        $this->assertSame(2000.0, $rows[1]['base_filed'], 'podaná hodnota je rozdíl základu');
        $this->assertSame(420.0, $rows[1]['tax_full_filed']);
        $this->assertSame(0.0, $rows[64]['tax_full_filed'], 'dodatečné nevykazuje vlastní daň');
        $this->assertSame(0.0, $rows[65]['tax_full_filed']);
        $this->assertSame(420.0, $rows[66]['tax_full_filed'], 'ř. 66 = změna daňové povinnosti');
        // Přesné hodnoty zůstávají plným obsahem období, ne rozdílem.
        $this->assertSame(12000.0, $rows[1]['base']);

        // Items jsou úplné i u rozdílového přiznání (D15).
        $this->assertCount(1, $this->items($supplementaryId));

        $filing = $this->db->fetchRow(
            'SELECT previous_filing, sequence FROM economy_vat_filings WHERE id = %i',
            $supplementaryId,
        );
        $this->assertSame($regularId, (int) $filing['previous_filing']);
        $this->assertSame(2, (int) $filing['sequence']);
    }

    // ── Idempotence a guardy ────────────────────────────────────────────────

    public function testRecomposeDoesNotDuplicateRows(): void
    {
        $periodId = $this->insertPeriod('return', '01/2029 IT přepočet');
        $this->insertDoc('invno', $periodId, 'vat_period', 'cz-120', 1000.0, 210.0, 'CZ12345678');
        $filingId = $this->createFiling($periodId, 'regular');

        $itemsBefore = count($this->items($filingId));
        $rowsBefore  = count($this->returnRows($filingId));

        $dibi = $this->db->getDibiConnection();
        $dibi->begin();
        (new FilingComposer($dibi, $this->config))->compose($filingId);
        $dibi->commit();

        $this->assertCount($itemsBefore, $this->items($filingId));
        $this->assertCount($rowsBefore, $this->returnRows($filingId));
    }

    public function testUnmappedVatCodeIsHardError(): void
    {
        $periodId = $this->insertPeriod('return', '01/2029 IT chyba');
        $this->insertDoc('invno', $periodId, 'vat_period', 'cz-999', 1000.0, 210.0, 'CZ12345678');

        $result = $this->saveFiling($periodId, 'regular');
        $this->assertFalse($result->isSuccess(), 'kód bez mapování nesmí projít tiše');
        $this->assertStringContainsString('cz-999', (string) $result->getErrorMessage());

        // Rollback celého uložení — hlavička podání nesmí zůstat.
        $this->assertSame(0, (int) $this->db->fetchSingle(
            'SELECT COUNT(*) FROM economy_vat_filings WHERE report_period = %i',
            $periodId,
        ));
    }

    public function testComposerRefusesFiledFiling(): void
    {
        $periodId = $this->insertPeriod('return', '01/2029 IT podané');
        $this->insertDoc('invno', $periodId, 'vat_period', 'cz-120', 1000.0, 210.0, 'CZ12345678');
        $filingId = $this->createFiling($periodId, 'regular');
        $this->fileFiling($filingId);

        $dibi = $this->db->getDibiConnection();
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('není ve stavu Sestaveno');
        (new FilingComposer($dibi, $this->config))->compose($filingId);
    }

    // ── Kontrolní hlášení ───────────────────────────────────────────────────

    public function testControlStatementSnapshotSplitsSections(): void
    {
        $periodId = $this->insertPeriod('cs', '01/2029 IT KH');
        // Nad limitem 10 000 vč. daně + CZ DIČ → detail v A4.
        $this->insertDoc('invno', $periodId, 'cs_period', 'cz-120', 20000.0, 4200.0, 'CZ12345678');
        // Pod limitem → agregát A5.
        $this->insertDoc('invno', $periodId, 'cs_period', 'cz-120', 1000.0, 210.0, 'CZ12345678');

        $filingId = $this->createFiling($periodId, 'regular');

        $rows = $this->db->fetchAll(
            'SELECT section, row_kind, doc_head, base1, tax1 FROM economy_vat_filing_cs_rows'
            . ' WHERE filing = %i ORDER BY section',
            $filingId,
        );
        $bySection = [];
        foreach ($rows as $row) {
            $bySection[(string) $row['section']][] = $row;
        }

        $this->assertArrayHasKey('A4', $bySection);
        $this->assertArrayHasKey('A5', $bySection);
        $this->assertCount(1, $bySection['A4']);
        $this->assertSame('detail', $bySection['A4'][0]['row_kind']);
        $this->assertSame(20000.0, (float) $bySection['A4'][0]['base1']);
        $this->assertSame('aggregate', $bySection['A5'][0]['row_kind']);
        $this->assertNull($bySection['A5'][0]['doc_head'], 'agregát není vázaný na doklad');
        $this->assertSame(1000.0, (float) $bySection['A5'][0]['base1']);

        // KH se podává na haléře — sloupce `_filed` u něj neexistují.
        $result = $this->filingResult($filingId);
        $this->assertSame(1, $result['cs']['sections']['A4']['rows']);
        $this->assertEqualsWithDelta(20000.0, $result['cs']['sections']['A4']['base'], 0.001);
        $this->assertArrayNotHasKey('crossCheck', $result, 'křížová kontrola je věc přiznání');

        // Podklad věty C: základy per řádek přiznání spočítané nad TOUTO
        // dokladovou úrovní (#55 X11) — 20 000 + 1 000 na ř. 1.
        $this->assertEqualsWithDelta(21000.0, $result['cs']['dp3Base']['1'], 0.001);
    }

    // ── Hlavička podání (#55 Fáze 3) ────────────────────────────────────────

    public function testHeaderIsPrefilledFromFilingProfile(): void
    {
        $this->setRegistrationProfile([
            'typ_ds'   => 'P',
            'c_ufo'    => '464',
            'c_okec'   => '620200',
            'naz_obce' => 'Ukázkov',
            'email'    => 'ucto@example.com',
        ]);

        $periodId = $this->insertPeriod('return', '01/2029 hlavička');
        $this->insertDoc('invno', $periodId, 'vat_period', 'cz-120', 1000.0, 210.0, 'CZ12345678');
        $filingId = $this->createFiling($periodId, 'regular');

        $header = $this->filingHeader($filingId);
        $this->assertSame('economy.vat.filingHeaderCzDp3/2026', $header['_schema']);
        $this->assertSame('464', $header['c_ufo']);
        $this->assertSame('620200', $header['c_okec']);
        $this->assertSame('Ukázkov', $header['naz_obce']);
        $this->assertSame('ucto@example.com', $header['email']);
        $this->assertSame('P', $header['typ_platce'], 'default schématu');
        $this->assertTrue($header['trans'], 'daň na výstupu 210 Kč → vznikla daňová povinnost');
        $this->assertSame(
            preg_replace('/\D+/', '', (string) $this->registrationVatId()),
            $header['dic'],
            'DIČ ve větě P je číselná část z registrace',
        );

        // Koeficient ř. 52 je součástí snapshotu — XML ho vypisuje jako
        // `koef_p20_nov` a živý resolver by po změně vydal jiné číslo.
        $this->assertArrayHasKey('coefficient', $this->filingResult($filingId)['return']);
    }

    public function testControlStatementHeaderUsesItsOwnSchema(): void
    {
        $this->setRegistrationProfile(['typ_ds' => 'P', 'c_ufo' => '464', 'c_okec' => '620200']);

        $periodId = $this->insertPeriod('cs', '01/2029 KH hlav');
        $this->insertDoc('invno', $periodId, 'cs_period', 'cz-120', 1000.0, 210.0, 'CZ12345678');
        $header = $this->filingHeader($this->createFiling($periodId, 'regular'));

        $this->assertSame('economy.vat.filingHeaderCzKh1/2026', $header['_schema']);
        $this->assertSame('464', $header['c_ufo']);
        $this->assertArrayNotHasKey('c_okec', $header, 'věta D kontrolního hlášení kód činnosti nemá');
        $this->assertArrayNotHasKey('trans', $header);
    }

    /**
     * Přepočet konceptu je běžná operace — ruční úpravy hlavičky, jediného
     * ručně zadávaného kusu snapshotu, přežít musí.
     */
    public function testRecomposeKeepsEditedHeader(): void
    {
        $this->setRegistrationProfile(['typ_ds' => 'P', 'c_ufo' => '464']);

        $periodId = $this->insertPeriod('return', '01/2029 hlav edit');
        $this->insertDoc('invno', $periodId, 'vat_period', 'cz-120', 1000.0, 210.0, 'CZ12345678');
        $filingId = $this->createFiling($periodId, 'regular');

        $edited = $this->filingHeader($filingId);
        $edited['sest_prijmeni'] = 'Nováková';
        $edited['c_ufo']         = '451';
        $this->db->getDibiConnection()
            ->update(FilingDocument::TABLE, ['header' => json_encode($edited, JSON_UNESCAPED_UNICODE)])
            ->where('id = %i', $filingId)->execute();

        (new FilingComposer($this->db->getDibiConnection(), $this->config))->compose($filingId);

        $header = $this->filingHeader($filingId);
        $this->assertSame('Nováková', $header['sest_prijmeni']);
        $this->assertSame('451', $header['c_ufo'], 'přepočet hlavičku nepřepisuje z profilu');
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function insertPeriod(string $type, string $name): int
    {
        $dibi = $this->db->getDibiConnection();
        $dibi->insert('economy_vat_report_periods', [
            'vat_registration' => $this->registrationId,
            'report_type'      => $type,
            'name'             => mb_substr($name, 0, 20),
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

    private function insertDoc(
        string $docType,
        int $periodId,
        string $periodColumn,
        string $vatCode,
        float $base,
        float $tax,
        string $partnerVatId,
    ): int {
        $series = $this->db->fetchRow(
            'SELECT id FROM docs_core_number_series WHERE doc_type = %s AND docState = 40 ORDER BY id LIMIT 1',
            $docType,
        );
        if ($series === null) {
            $this->markTestSkipped("DS nemá aktivní řadu {$docType}");
        }
        $total    = round($base + $tax, 2);
        $snapshot = json_encode(['vat_id' => $partnerVatId], JSON_UNESCAPED_UNICODE);
        $isIssued = $docType === 'invno';

        $dibi = $this->db->getDibiConnection();
        $dibi->insert('docs_core_heads', [
            'doc_type'           => $docType,
            'number_series'      => (int) $series['id'],
            'doc_number'         => 'IT-FILING-' . uniqid(),
            'partner_doc_number' => 'P-' . uniqid(),
            'issue_date'         => self::DUZP,
            'accounting_date'    => self::DUZP,
            'due_date'           => self::DUZP,
            'vat_duzp'           => self::DUZP,
            'vat_dppd'           => self::DUZP,
            'vat_mode'           => 1,
            'vat_registration'   => $this->registrationId,
            $periodColumn        => $periodId,
            'customer_snapshot'  => $isIssued ? $snapshot : null,
            'supplier_snapshot'  => $isIssued ? null : $snapshot,
            'doc_currency'       => 'czk',
            'home_currency'      => 'czk',
            'exchange_rate'      => 1.0,
            'doc_text'           => 'IT podání DPH',
            'total_base'         => $base,
            'total_vat'          => $tax,
            'total_amount'       => $total,
            'total_base_dom'     => $base,
            'total_vat_dom'      => $tax,
            'total_amount_dom'   => $total,
            'docState'           => 40,
            'docStateMain'       => 2,
        ])->execute();
        $headId = (int) $dibi->getInsertId();
        $this->createdHeads[] = $headId;

        $dibi->insert('docs_core_vat_recap', [
            'doc_head' => $headId, 'vat_code' => $vatCode, 'vat_pct' => 21.0,
            'base' => $base, 'tax' => $tax, 'total' => $total,
            'base_dom' => $base, 'tax_dom' => $tax, 'total_dom' => $total,
            'sum_base' => 1, 'sum_tax' => 1, 'sum_total' => 1,
            'is_reverse_pair' => 0, 'order_pos' => 0,
        ])->execute();
        return $headId;
    }

    /** Uložení podání přes TableGateway — validace i snapshot jako z UI. */
    private function saveFiling(int $periodId, string $kind, ?string $dateFound = null): DocumentResult
    {
        $data = [
            'report_period' => $periodId,
            'filing_kind'   => $kind,
            'docState'      => FilingDocument::DOC_STATE_COMPOSED,
            'docStateMain'  => 1,
        ];
        if ($dateFound !== null) {
            $data['date_found'] = $dateFound;
        }
        return $this->gateway()->saveDocument($data);
    }

    private function createFiling(int $periodId, string $kind, ?string $dateFound = null): int
    {
        $result = $this->saveFiling($periodId, $kind, $dateFound);
        $this->assertTrue(
            $result->isSuccess(),
            'uložení podání: ' . ($result->getErrorMessage() ?? json_encode($result->getValidation()?->toArray())),
        );
        $id = (int) ($result->getData()['id'] ?? 0);
        $this->createdFilings[] = $id;
        return $id;
    }

    /** Přechod Sestaveno → Podáno přes Document (jako z formuláře). */
    private function fileFiling(int $filingId): void
    {
        $gateway  = $this->gateway();
        $existing = $gateway->loadDocument($filingId);
        $existing['docState']     = FilingDocument::DOC_STATE_FILED;
        $existing['docStateMain'] = 2;
        $result = $gateway->saveDocument($existing);
        $this->assertTrue(
            $result->isSuccess(),
            'podání: ' . ($result->getErrorMessage() ?? json_encode($result->getValidation()?->toArray())),
        );
    }

    private function gateway(): TableGateway
    {
        $definition = $this->tables[FilingDocument::TABLE];
        $registry   = DocumentLoader::load(
            $this->dsConfig,
            new ModulePathResolver([dirname(__DIR__, 3) . '/modules']),
        );
        return new TableGateway(
            FilingDocument::TABLE,
            $this->db->getDibiConnection(),
            $registry,
            $definition->childTables,
            $this->config,
            $this->dsConfig,
            null,
            $definition->docStates,
        );
    }

    /** @return array<int, array<string, mixed>> doc_head → řádek items */
    private function items(int $filingId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT * FROM economy_vat_filing_items WHERE filing = %i ORDER BY id',
            $filingId,
        );
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['doc_head']] = $row;
        }
        return $out;
    }

    /** @return array<int, array<string, mixed>> číslo řádku → řádek přiznání */
    private function returnRows(int $filingId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT * FROM economy_vat_filing_return_rows WHERE filing = %i ORDER BY `row`',
            $filingId,
        );
        $out = [];
        foreach ($rows as $row) {
            $data = $row;
            foreach (['base', 'tax_full', 'tax_reduced', 'base_filed', 'tax_full_filed', 'tax_reduced_filed'] as $col) {
                $data[$col] = (float) $data[$col];
            }
            $out[(int) $data['row']] = $data;
        }
        return $out;
    }

    /** @return array<string, mixed> */
    /**
     * Dočasně nastaví profil podatele registrace; původní hodnotu vrátí
     * teardown — test si sahá na sdílený záznam dev DS.
     *
     * @param array<string, mixed> $profile
     */
    private function setRegistrationProfile(array $profile): void
    {
        if (!$this->profileRestored) {
            $this->originalProfile = $this->db->fetchSingle(
                'SELECT filing_profile FROM economy_codebooks_vat_registrations WHERE id = %i',
                $this->registrationId,
            );
            $this->profileRestored = true;
        }

        $profile['_schema'] = 'economy.vat.filingProfileCz/2026';
        $this->db->getDibiConnection()
            ->update('economy_codebooks_vat_registrations', [
                'filing_profile' => json_encode($profile, JSON_UNESCAPED_UNICODE),
            ])
            ->where('id = %i', $this->registrationId)->execute();
    }

    private function registrationVatId(): string
    {
        return (string) $this->db->fetchSingle(
            'SELECT vat_id FROM economy_codebooks_vat_registrations WHERE id = %i',
            $this->registrationId,
        );
    }

    /** @return array<string, mixed> */
    private function filingHeader(int $filingId): array
    {
        $json = $this->db->fetchSingle('SELECT header FROM economy_vat_filings WHERE id = %i', $filingId);
        $this->assertIsString($json, 'snapshot musí mít hlavičku');
        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private function filingResult(int $filingId): array
    {
        $json = $this->db->fetchSingle('SELECT result FROM economy_vat_filings WHERE id = %i', $filingId);
        $this->assertIsString($json, 'snapshot musí mít výsledek');
        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }
}
