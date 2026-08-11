<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Base\Registry;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Document\DocumentRegistry;
use Shipard\Module\Core\Attachments\AttachmentService;
use Shipard\Module\Core\Exchange\Resolve\PartyResolver;
use Shipard\Module\Core\Exchange\Resolve\ResolveResult;
use Shipard\Module\Base\Registry\RegistryApplier;

/**
 * Unit testy mappingu, partner resolve a binder návrhu RegistryApplieru
 * (message-centric ProposalTargetApplier). Plný apply/unapply round-trip
 * (transakce, kopie příloh, extracted_text, zápis target_* na zprávu)
 * potřebuje reálné dibi → Integration/Registry.
 */
class RegistryApplierTest extends TestCase
{
    private function applier(
        ?DataSourceConnection $db = null,
        ?PartyResolver $partyResolver = null,
        ?ConfigRuntime $config = null,
    ): RegistryApplier {
        return new RegistryApplier(
            $db ?? $this->createMock(DataSourceConnection::class),
            $this->createMock(DocumentRegistry::class),
            $this->createMock(AttachmentService::class),
            $config,
            $partyResolver,
        );
    }

    private function insuranceCanonical(): array
    {
        return [
            'schema'  => 'shpd.registry.document.v1',
            'docType' => 'insurance',
            'title'   => 'Pojistná smlouva — flotila vozidel',
            'summary' => 'Pojištění vozového parku. Platí do konce roku.',
            'party'   => ['name' => 'Pojišťovna ABC', 'companyId' => '12345678', 'email' => 'info@abc.cz'],
            'kindFields' => [
                'insurer'       => 'Pojišťovna ABC',
                'policyNumber'  => 'POJ-2026-001',
                'validFrom'     => '2026-01-01',
                'validTo'       => '2026-12-31',
                'annualPremium' => 45000.0,
                'currency'      => 'czk',
            ],
            'binderSuggestion' => 'Pojištění',
        ];
    }

    // ── buildDocumentData ────────────────────────────────────────────────────

    public function testBuildDocumentDataMapsCanonical(): void
    {
        $data = $this->applier()->buildDocumentData(
            $this->insuranceCanonical(), 'insurance', 42, 7, 100, 9,
        );

        $this->assertSame('Pojistná smlouva — flotila vozidel', $data['title']);
        $this->assertSame('insurance', $data['doc_kind']);
        $this->assertSame(42, $data['partner']);
        $this->assertSame(7, $data['binder']);
        // metadata = kindFields 1:1 — promoted sloupce doplní beforeSave,
        // applier je nenastavuje
        $this->assertSame('POJ-2026-001', $data['metadata']['policyNumber']);
        $this->assertSame('2026-12-31', $data['metadata']['validTo']);
        $this->assertArrayNotHasKey('ref_number', $data);
        $this->assertArrayNotHasKey('valid_to', $data);
        $this->assertSame('Pojištění vozového parku. Platí do konce roku.', $data['ai_summary']);
        $this->assertSame('mail', $data['source_kind']);
        $this->assertSame(100, $data['source_message']);
        // Vazba na extracted dokument zanikla — lineage nese jen source_message.
        $this->assertArrayNotHasKey('extracted_doc', $data);
        $this->assertSame(9, $data['created_by']);
        // vznik rovnou v Zařazeno (40), docStateMain z fallback mapy
        $this->assertSame(40, $data['docState']);
        $this->assertSame(3, $data['docStateMain']);
    }

    public function testBuildDocumentDataToleratesMissingKindFieldsAndSummary(): void
    {
        $canonical = [
            'schema'  => 'shpd.registry.document.v1',
            'docType' => 'official',
            'title'   => 'Výzva k doplnění',
        ];

        $data = $this->applier()->buildDocumentData($canonical, 'official', null, null, 0, null);

        $this->assertSame([], $data['metadata']);
        $this->assertNull($data['ai_summary']);
        $this->assertNull($data['partner']);
        $this->assertNull($data['binder']);
        $this->assertNull($data['source_message']);
        $this->assertNull($data['created_by']);
    }

    // ── resolvePartner ───────────────────────────────────────────────────────

    public function testResolvePartnerUsesPartyResolverMatch(): void
    {
        $resolver = $this->createMock(PartyResolver::class);
        $resolver->expects($this->once())->method('resolve')
            ->with(['companyId' => '12345678', 'name' => 'Pojišťovna ABC'])
            ->willReturn(ResolveResult::matched(42, 'companyId'));

        $db = $this->createMock(DataSourceConnection::class);
        $db->expects($this->never())->method('fetchAll');

        $partner = $this->applier($db, $resolver)->resolvePartner(
            ['companyId' => '12345678', 'name' => 'Pojišťovna ABC'], 'info@abc.cz',
        );

        $this->assertSame(42, $partner);
    }

    public function testResolvePartnerFallsBackToEmailOnAmbiguous(): void
    {
        $resolver = $this->createMock(PartyResolver::class);
        $resolver->method('resolve')->willReturn(ResolveResult::ambiguous([
            ['id' => 1], ['id' => 2],
        ]));

        $db = $this->createMock(DataSourceConnection::class);
        $db->expects($this->once())->method('fetchAll')->willReturn([['id' => 77]]);

        $partner = $this->applier($db, $resolver)->resolvePartner(
            ['name' => 'ABC'], 'info@abc.cz',
        );

        $this->assertSame(77, $partner);
    }

    public function testResolvePartnerNeverCreates(): void
    {
        // canCreate výsledek se nepoužije — jen Matched; e-mail nic nenajde → NULL
        $resolver = $this->createMock(PartyResolver::class);
        $resolver->method('resolve')->willReturn(ResolveResult::canCreate(['full_name' => 'Nová firma']));

        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturn([]);

        $this->assertNull($this->applier($db, $resolver)->resolvePartner(
            ['name' => 'Nová firma'], 'unknown@example.com',
        ));
    }

    public function testResolvePartnerWithoutResolverUsesEmail(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturn([['id' => 5]]);

        $this->assertSame(5, $this->applier($db)->resolvePartner(['name' => 'X'], 'a@b.cz'));
    }

    public function testResolvePartnerAmbiguousEmailReturnsNull(): void
    {
        // dva distinct matche → žádné hádání
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturn([['id' => 5], ['id' => 6]]);

        $this->assertNull($this->applier($db)->resolvePartner(null, 'shared@b.cz'));
    }

    // ── suggestBinder ────────────────────────────────────────────────────────

    public function testSuggestBinderPrefersHistory(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->expects($this->once())->method('fetchRow')
            ->willReturn(['binder' => 12, 'cnt' => 3]);

        $this->assertSame(12, $this->applier($db)->suggestBinder(42, 'insurance', 'Pojištění'));
    }

    public function testSuggestBinderFallsBackToNameMatch(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        // 1. volání = historie (nic), 2. volání = match jména
        $db->method('fetchRow')->willReturnOnConsecutiveCalls(null, ['id' => 8]);

        $this->assertSame(8, $this->applier($db)->suggestBinder(42, 'insurance', 'Pojištění'));
    }

    public function testSuggestBinderNameMatchWithoutPartner(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        // bez partnera se historie přeskakuje — jediný dotaz je match jména
        $db->expects($this->once())->method('fetchRow')->willReturn(['id' => 8]);

        $this->assertSame(8, $this->applier($db)->suggestBinder(null, 'insurance', 'Pojištění'));
    }

    public function testSuggestBinderReturnsNullAndNeverCreates(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn(null);

        $this->assertNull($this->applier($db)->suggestBinder(42, 'insurance', 'Neexistující šanon'));
        $this->assertNull($this->applier($db)->suggestBinder(null, 'insurance', null));
        $this->assertNull($this->applier($db)->suggestBinder(null, 'insurance', '   '));
    }

    // ── apply() vstupní guardy ───────────────────────────────────────────────

    public function testApplyRejectsMissingTitle(): void
    {
        $result = $this->applier()->apply(
            ['schema' => 'shpd.registry.document.v1', 'docType' => 'contract'],
            ['id' => 100, 'sender_email' => 'a@b.cz'],
            'contract',
            7,
        );

        $this->assertFalse($result->success);
        $this->assertSame('VALIDATION_ERROR', $result->errorCode);
        $this->assertSame(422, $result->statusCode);
    }

    // ── unapply() guardy ─────────────────────────────────────────────────────

    public function testUnapplyRejectsWhenDocumentMissing(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn(null);

        $result = $this->applier($db)->unapply(555, '2026-07-14 10:00:00');

        $this->assertFalse($result->success);
        $this->assertSame('DOC_ADVANCED', $result->errorCode);
        $this->assertSame(409, $result->statusCode);
    }

    public function testUnapplyRejectsWhenNotInFiledState(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn(['id' => 555, 'docState' => 80, 'modified' => null]);

        $result = $this->applier($db)->unapply(555, '2026-07-14 10:00:00');

        $this->assertFalse($result->success);
        $this->assertSame('DOC_ADVANCED', $result->errorCode);
    }

    public function testUnapplyRejectsWhenModifiedAfterApply(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn([
            'id' => 555, 'docState' => 40, 'modified' => '2026-07-14 12:00:00',
        ]);

        $result = $this->applier($db)->unapply(555, '2026-07-14 10:00:00');

        $this->assertFalse($result->success);
        $this->assertSame('DOC_ADVANCED', $result->errorCode);
        $this->assertSame(409, $result->statusCode);
    }

    public function testUnapplyGuardPassesWhenModifiedBeforeApply(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn([
            'id' => 555, 'docState' => 40, 'modified' => '2026-07-14 09:00:00',
        ]);

        // Guard projde; trash save pak spadne na mock dibi → INTERNAL_ERROR
        // (≠ DOC_ADVANCED), což je přesně hranice unit testu bez reálné DB.
        $result = $this->applier($db)->unapply(555, '2026-07-14 10:00:00');

        $this->assertFalse($result->success);
        $this->assertSame('INTERNAL_ERROR', $result->errorCode);
    }
}
