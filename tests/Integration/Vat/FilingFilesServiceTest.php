<?php

declare(strict_types=1);

namespace Shipard\Tests\Integration\Vat;

use Shipard\Api\DocumentLoader;
use Shipard\Api\TableLoader;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Document\TableGateway;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Module\Core\Attachments\AttachmentService;
use Shipard\Module\Economy\Vat\FilingAttachmentGuard;
use Shipard\Module\Economy\Vat\FilingDocument;
use Shipard\Core\Config\ServerConfig;
use Shipard\Core\Render\RenderClient;
use Shipard\Module\Economy\Vat\Xml\FilingFilesService;
use Shipard\Module\Economy\Vat\Xml\FilingPdfService;
use Shipard\Tests\Integration\IntegrationTestCase;

/**
 * Soubory podání pro daňový portál nad reálným dev DS (#55 Fáze 3, X6).
 * Test si zakládá vlastní instanci tvrzení, doklad i podání, takže může
 * asertovat absolutní čísla i obsah příloh.
 *
 * Pokrývá: uložení XML jako přílohy s `metadata.kind`, nahrazení sady při
 * opakovaném generování konceptu, automatické dogenerování při přechodu do
 * stavu Podáno, ochranu souborů podaného tvrzení (`FilingAttachmentGuard`)
 * a determinismus opakovaného generování.
 */
class FilingFilesServiceTest extends IntegrationTestCase
{
    private const DATE_BEGIN = '2029-03-01';
    private const DATE_END   = '2029-03-31';
    private const DUZP       = '2029-03-15';

    private ?ConfigRuntime $config = null;
    private int $registrationId = 0;

    /** @var list<int> */
    private array $createdPeriods = [];
    /** @var list<int> */
    private array $createdHeads = [];
    /** @var list<int> */
    private array $createdFilings = [];

    private mixed $originalProfile = null;
    private bool $profileRestored = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->config = ConfigRuntime::load($this->realDsPath, 'cs');

        if (!isset($this->tables[FilingDocument::TABLE])) {
            $this->markTestSkipped('DS nemá tabulku economy_vat_filings — spusťte ds-upgrade');
        }
        if (!is_array($this->config->cfgItem('economy.vat.xml.cz'))) {
            $this->markTestSkipped('DS nemá compiled mapování economy.vat.xml.cz');
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
            $this->markTestSkipped('DS už má instanci tvrzení v testovacím rozsahu 03/2029');
        }

        $this->setRegistrationProfile();
    }

    protected function onTearDown(): void
    {
        $dibi = $this->db->getDibiConnection();
        foreach ($this->createdFilings as $id) {
            $dibi->delete('core_attachments_files')
                ->where('table_id = %i AND record_id = %i', FilingFilesService::TABLE_ID, $id)
                ->execute();
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

    // ── Generování a přílohy ────────────────────────────────────────────────

    public function testXmlIsStoredAsAttachmentWithItsKind(): void
    {
        $filingId = $this->composedFiling();
        $result   = $this->service()->generate($filingId);

        $this->assertCount(1, $result->files, 'bez PDF rendereru vzniká jen XML');
        $this->assertSame(FilingFilesService::KIND_XML, $result->files[0]->kind);
        $this->assertMatchesRegularExpression('/^DPHDP3-\d+-2029-03\.xml$/', $result->files[0]->name);
        $this->assertStringContainsString('<DPHDP3 verzePis=', $result->files[0]->content);

        $attachments = $this->service()->ownAttachments($filingId);
        $this->assertCount(1, $attachments);
        $this->assertSame(FilingFilesService::KIND_XML, FilingFilesService::kindOf($attachments[0]));
        $this->assertSame($result->attachmentIds, [(int) $attachments[0]['id']]);
        $this->assertTrue($this->service()->hasXml($filingId));

        // Soubor je opravdu na disku a je to vygenerované XML.
        $stored = file_get_contents($this->attachments()->getFilePath($attachments[0]));
        $this->assertSame($result->files[0]->content, $stored);
    }

    public function testRegeneratingADraftReplacesThePreviousSet(): void
    {
        $filingId = $this->composedFiling();
        $first    = $this->service()->generate($filingId);
        $second   = $this->service()->generate($filingId);

        $this->assertNotSame($first->attachmentIds, $second->attachmentIds);
        $this->assertCount(1, $this->service()->ownAttachments($filingId), 'stará sada je smazaná');
        $this->assertSame($first->files[0]->content, $second->files[0]->content, 'snapshot je týž');
    }

    /** Ručně nahranou přílohu přegenerování nesmí smazat. */
    public function testManualAttachmentsSurviveRegeneration(): void
    {
        $filingId = $this->composedFiling();
        $manualId = $this->uploadManualAttachment($filingId);
        $this->service()->generate($filingId);

        $manual = $this->attachments()->getAttachment($manualId);
        $this->assertSame(0, (int) $manual['is_deleted']);
    }

    // ── Lifecycle ───────────────────────────────────────────────────────────

    public function testFilingGeneratesTheFilesAutomatically(): void
    {
        $filingId = $this->composedFiling();
        $this->assertFalse($this->service()->hasXml($filingId));

        $this->fileFiling($filingId);

        $this->assertTrue($this->service()->hasXml($filingId), 'přechod do Podáno soubory dogeneruje');
    }

    public function testFilesOfAFiledFilingAreProtected(): void
    {
        $filingId = $this->composedFiling();
        $this->service()->generate($filingId);
        $this->fileFiling($filingId);

        $attachment = $this->service()->ownAttachments($filingId)[0];
        $guard      = new FilingAttachmentGuard($this->db);

        $this->assertNotNull($guard->refuse($attachment, FilingAttachmentGuard::OPERATION_DELETE));
        $this->assertNotNull($guard->refuse($attachment, FilingAttachmentGuard::OPERATION_RENAME));

        // Ručně nahraná příloha zůstává v rukou uživatele — k podanému
        // tvrzení se dokládá potvrzení o přijetí.
        $manual = $this->attachments()->getAttachment($this->uploadManualAttachment($filingId));
        $this->assertNull($guard->refuse($manual, FilingAttachmentGuard::OPERATION_DELETE));
    }

    public function testGuardRefusalStopsTheDeletion(): void
    {
        $filingId = $this->composedFiling();
        $this->service()->generate($filingId);
        $this->fileFiling($filingId);
        $attachmentId = (int) $this->service()->ownAttachments($filingId)[0]['id'];

        $guarded = new AttachmentService(
            $this->db,
            $this->realDsPath,
            $this->tables,
            ['economy_vat_filings' => [FilingAttachmentGuard::class]],
        );

        try {
            $guarded->softDelete($attachmentId);
            $this->fail('guard měl smazání odmítnout');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('podaného tvrzení', $e->getMessage());
        }
        $this->assertCount(1, $this->service()->ownAttachments($filingId));
    }

    // ── Tiskové výstupy (#55 X7) ────────────────────────────────────────────

    public function testPreviewAndContentPdfsAreGeneratedAndStored(): void
    {
        $render = $this->renderClient();
        if (!$render->isConfigured() || !$render->health()) {
            $this->markTestSkipped('Render služba není dostupná (docs/operations/render-service.md)');
        }

        $filingId = $this->composedFiling();
        $service  = new FilingFilesService(
            $this->db->getDibiConnection(),
            $this->config,
            $this->attachments(),
            new FilingPdfService($this->db->getDibiConnection(), $render, $this->config),
        );
        $result = $service->generate($filingId);

        $this->assertSame([], $result->warnings);
        $this->assertSame(
            [FilingFilesService::KIND_XML, FilingFilesService::KIND_PREVIEW, FilingFilesService::KIND_CONTENT],
            array_map(static fn ($file): string => $file->kind, $result->files),
        );
        foreach (array_slice($result->files, 1) as $pdf) {
            $this->assertStringEndsWith('.pdf', $pdf->name);
            $this->assertStringStartsWith('%PDF-', $pdf->content);
            $this->assertGreaterThan(1000, strlen($pdf->content));
        }
        $this->assertCount(3, $this->service()->ownAttachments($filingId));
    }

    /** Nedostupná tisková služba podání neshodí — XML je povinné, PDF ne. */
    public function testUnavailableRenderServiceOnlyWarns(): void
    {
        $filingId = $this->composedFiling();
        $service  = new FilingFilesService(
            $this->db->getDibiConnection(),
            $this->config,
            $this->attachments(),
            new FilingPdfService($this->db->getDibiConnection(), new RenderClient(null), $this->config),
        );
        $result = $service->generate($filingId);

        $this->assertCount(1, $result->files);
        $this->assertNotSame([], $result->warnings);
        $this->assertTrue($this->service()->hasXml($filingId));
    }

    // ── Determinismus ───────────────────────────────────────────────────────

    public function testRepeatedBuildIsByteIdentical(): void
    {
        $filingId = $this->composedFiling();
        $this->fileFiling($filingId);

        $this->assertSame(
            $this->service()->build($filingId)->xml()->content,
            $this->service()->build($filingId)->xml()->content,
        );
    }

    // ── Pomocné ─────────────────────────────────────────────────────────────

    private function service(): FilingFilesService
    {
        return new FilingFilesService(
            $this->db->getDibiConnection(),
            $this->config,
            $this->attachments(),
        );
    }

    private function renderClient(): RenderClient
    {
        try {
            $serverConfig = new ServerConfig();
            $serverConfig->load();
            return RenderClient::fromServerConfig($serverConfig);
        } catch (\Throwable) {
            return new RenderClient(null);
        }
    }

    private function attachments(): AttachmentService
    {
        return new AttachmentService($this->db, $this->realDsPath, $this->tables);
    }

    /** Podání ve stavu Sestaveno nad vlastní instancí s jedním dokladem. */
    private function composedFiling(): int
    {
        $periodId = $this->insertPeriod();
        $this->insertDoc($periodId);

        $result = $this->gateway()->saveDocument([
            'report_period' => $periodId,
            'filing_kind'   => FilingDocument::KIND_REGULAR,
            'docState'      => FilingDocument::DOC_STATE_COMPOSED,
            'docStateMain'  => 1,
        ]);
        $this->assertTrue(
            $result->isSuccess(),
            'uložení podání: ' . ($result->getErrorMessage() ?? json_encode($result->getValidation()?->toArray())),
        );

        $id = (int) $result->getData()['id'];
        $this->createdFilings[] = $id;
        return $id;
    }

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

    private function insertPeriod(): int
    {
        $dibi = $this->db->getDibiConnection();
        $dibi->insert('economy_vat_report_periods', [
            'vat_registration' => $this->registrationId,
            'report_type'      => 'return',
            'name'             => '03/2029 IT XML',
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

    private function insertDoc(int $periodId): int
    {
        $series = $this->db->fetchRow(
            'SELECT id FROM docs_core_number_series WHERE doc_type = %s AND docState = 40 ORDER BY id LIMIT 1',
            'invno',
        );
        if ($series === null) {
            $this->markTestSkipped('DS nemá aktivní řadu invno');
        }

        $dibi = $this->db->getDibiConnection();
        $dibi->insert('docs_core_heads', [
            'doc_type'           => 'invno',
            'number_series'      => (int) $series['id'],
            'doc_number'         => 'IT-XML-' . uniqid(),
            'partner_doc_number' => '',
            'issue_date'         => self::DUZP,
            'accounting_date'    => self::DUZP,
            'due_date'           => self::DUZP,
            'vat_duzp'           => self::DUZP,
            'vat_dppd'           => self::DUZP,
            'vat_mode'           => 1,
            'vat_registration'   => $this->registrationId,
            'vat_period'         => $periodId,
            'customer_snapshot'  => json_encode(['vat_id' => 'CZ12345678'], JSON_UNESCAPED_UNICODE),
            'doc_currency'       => 'czk',
            'home_currency'      => 'czk',
            'exchange_rate'      => 1.0,
            'doc_text'           => 'IT soubory podání',
            'total_base'         => 1000.0,
            'total_vat'          => 210.0,
            'total_amount'       => 1210.0,
            'total_base_dom'     => 1000.0,
            'total_vat_dom'      => 210.0,
            'total_amount_dom'   => 1210.0,
            'docState'           => 40,
            'docStateMain'       => 2,
        ])->execute();
        $headId = (int) $dibi->getInsertId();
        $this->createdHeads[] = $headId;

        $dibi->insert('docs_core_vat_recap', [
            'doc_head' => $headId, 'vat_code' => 'cz-120', 'vat_pct' => 21.0,
            'base' => 1000.0, 'tax' => 210.0, 'total' => 1210.0,
            'base_dom' => 1000.0, 'tax_dom' => 210.0, 'total_dom' => 1210.0,
            'sum_base' => 1, 'sum_tax' => 1, 'sum_total' => 1,
            'is_reverse_pair' => 0, 'order_pos' => 0,
        ])->execute();
        return $headId;
    }

    private function uploadManualAttachment(int $filingId): int
    {
        $tmp = tempnam(sys_get_temp_dir(), 'it');
        file_put_contents($tmp, "potvrzeni o prijeti\n");
        $result = $this->attachments()->upload(
            FilingFilesService::TABLE_ID,
            $filingId,
            'potvrzeni-' . uniqid() . '.txt',
            $tmp,
        );
        @unlink($tmp);

        $this->assertTrue($result['success'], (string) ($result['error'] ?? ''));
        return (int) $result['data']['id'];
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

    /** Profil podatele s minimem, které validace podání vyžaduje. */
    private function setRegistrationProfile(): void
    {
        $this->originalProfile = $this->db->fetchSingle(
            'SELECT filing_profile FROM economy_codebooks_vat_registrations WHERE id = %i',
            $this->registrationId,
        );
        $this->profileRestored = true;

        $this->db->getDibiConnection()
            ->update('economy_codebooks_vat_registrations', [
                'filing_profile' => json_encode([
                    '_schema'  => 'economy.vat.filingProfileCz/2026',
                    'typ_ds'   => 'P',
                    'c_ufo'    => '464',
                    'c_okec'   => '620200',
                    'naz_obce' => 'Ukázkov',
                    'psc'      => '76001',
                    'stat'     => 'CZ',
                ], JSON_UNESCAPED_UNICODE),
            ])
            ->where('id = %i', $this->registrationId)->execute();
    }
}
