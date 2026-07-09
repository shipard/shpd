<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Enrich;

use Dibi\Connection;
use Dibi\Row;
use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Exchange\Enrich\RowHistoryEnricher;
use Shipard\Module\Core\Exchange\Resolve\PartyResolver;
use Shipard\Module\Core\Exchange\Resolve\ResolveResult;

class RowHistoryEnricherTest extends TestCase
{
    /**
     * @param list<array<string, mixed>> $history
     */
    private function buildEnricher(array $history, ?PartyResolver $party = null): RowHistoryEnricher
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetchAll')->willReturn(array_map(
            static fn(array $row) => new Row($row),
            $history,
        ));

        if ($party === null) {
            $party = $this->createMock(PartyResolver::class);
            $party->method('resolve')->willReturn(ResolveResult::matched(42, 'companyId'));
        }

        return new RowHistoryEnricher($db, $party);
    }

    /**
     * @return array<string, mixed>
     */
    private function histRow(
        string $description,
        string $itemCode,
        ?string $vatCode = 'std21',
        ?string $accountNumber = '518001',
        int $docHead = 1001,
        ?string $docNumber = 'FP-2026-0042',
    ): array {
        return [
            'description'    => $description,
            'vat_code'       => $vatCode,
            'item_code'      => $itemCode,
            'account_number' => $accountNumber,
            'doc_head'       => $docHead,
            'doc_number'     => $docNumber,
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function canonical(array $rows, ?string $selfParty = 'customer'): array
    {
        return [
            'format'        => 'shpd.docs.document',
            'formatVersion' => '1.0',
            'docType'       => 'invoiceReceived',
            'selfParty'     => $selfParty,
            'supplier'      => ['name' => 'O2 Czech Republic', 'companyId' => '60193336'],
            'rows'          => $rows,
        ];
    }

    public function testNormalizedExactMatchFillsTriple(): void
    {
        // Datumy/období/částky se normalizací odstraní → 6/2026 vs 7/2026 je exact-norm.
        $enricher = $this->buildEnricher([
            $this->histRow('Internet 500M 6/2026', 'NET500'),
        ]);

        $result = $enricher->enrich($this->canonical([
            ['description' => 'Internet 500M 7/2026'],
        ]));

        $row = $result['rows'][0];
        $this->assertSame('NET500', $row['item']['ourCode']);
        $this->assertSame('std21', $row['vat']['code']);
        $this->assertSame('518001', $row['account']);

        $enrichment = $result['_resolve']['rows'][0]['enrichment'];
        $this->assertSame(0, $result['_resolve']['rows'][0]['index']);
        $this->assertSame('historyExactNorm', $enrichment['matchedBy']);
        $this->assertSame('high', $enrichment['confidence']);
        $this->assertSame(1001, $enrichment['sourceDocId']);
        $this->assertSame('FP-2026-0042', $enrichment['sourceDocNumber']);
        $this->assertSame(
            ['ourCode' => 'NET500', 'vatCode' => 'std21', 'account' => '518001'],
            $enrichment['suggested'],
        );
    }

    public function testMissingHistoryDocNumberFallsBackToNull(): void
    {
        $enricher = $this->buildEnricher([
            $this->histRow('Internet 500M', 'NET500', docNumber: null),
        ]);

        $result = $enricher->enrich($this->canonical([
            ['description' => 'Internet 500M'],
        ]));

        $enrichment = $result['_resolve']['rows'][0]['enrichment'];
        $this->assertSame('historyExactRaw', $enrichment['matchedBy']);
        $this->assertNull($enrichment['sourceDocNumber']);
    }

    public function testRawExactMatchBeatsNormalizedCollision(): void
    {
        // „Linka 500" a „Linka 1000" po normalizaci kolidují („linka"). Historie
        // nejnovější první — bez přednosti stupně 0 by vyhrála novější L1000.
        $enricher = $this->buildEnricher([
            $this->histRow('Linka 1000', 'L1000', docHead: 2002),
            $this->histRow('Linka 500', 'L500', docHead: 2001),
        ]);

        $result = $enricher->enrich($this->canonical([
            ['description' => 'Linka 500'],
        ]));

        $enrichment = $result['_resolve']['rows'][0]['enrichment'];
        $this->assertSame('L500', $result['rows'][0]['item']['ourCode']);
        $this->assertSame('historyExactRaw', $enrichment['matchedBy']);
        $this->assertSame('high', $enrichment['confidence']);
        $this->assertSame(2001, $enrichment['sourceDocId']);
    }

    public function testFuzzyMatchAboveThresholdIsMedium(): void
    {
        // 4 společné tokeny z 6 → Jaccard 0.667 ≥ 0.6, ale ne exact-norm.
        $enricher = $this->buildEnricher([
            $this->histRow('Pronájem kancelářských prostor budova A', 'RENT-A'),
        ]);

        $result = $enricher->enrich($this->canonical([
            ['description' => 'Pronájem kancelářských prostor budova B'],
        ]));

        $enrichment = $result['_resolve']['rows'][0]['enrichment'];
        $this->assertSame('RENT-A', $result['rows'][0]['item']['ourCode']);
        $this->assertSame('historyFuzzy', $enrichment['matchedBy']);
        $this->assertSame('medium', $enrichment['confidence']);
    }

    public function testFuzzyBelowThresholdYieldsNoSuggestion(): void
    {
        $enricher = $this->buildEnricher([
            $this->histRow('Instalace zásuvek elektro', 'ELE01'),
        ]);

        $result = $enricher->enrich($this->canonical([
            ['description' => 'Konzultace vývoje software'],
        ]));

        $this->assertArrayNotHasKey('item', $result['rows'][0]);
        $enrichment = $result['_resolve']['rows'][0]['enrichment'];
        $this->assertNull($enrichment['matchedBy']);
        $this->assertNull($enrichment['confidence']);
        $this->assertNull($enrichment['sourceDocNumber']);
        $this->assertSame([], $enrichment['suggested']);
    }

    public function testFilledOurCodeIsSkipped(): void
    {
        $enricher = $this->buildEnricher([
            $this->histRow('Internet 500M', 'NET500'),
        ]);

        $input = $this->canonical([
            ['description' => 'Internet 500M', 'item' => ['ourCode' => 'X1']],
        ]);
        $result = $enricher->enrich($input);

        $this->assertSame('X1', $result['rows'][0]['item']['ourCode']);
        $enrichment = $result['_resolve']['rows'][0]['enrichment'];
        $this->assertNull($enrichment['matchedBy']);
        $this->assertSame('hasOurCode', $enrichment['skipped']);
    }

    public function testAiVatCodeNotOverwrittenAndEmptyHistoryAccountNotSet(): void
    {
        $enricher = $this->buildEnricher([
            $this->histRow('Internet 500M', 'NET500', vatCode: 'std21', accountNumber: null),
        ]);

        $result = $enricher->enrich($this->canonical([
            ['description' => 'Internet 500M', 'vat' => ['code' => 'osvob']],
        ]));

        $row = $result['rows'][0];
        $this->assertSame('NET500', $row['item']['ourCode']);
        $this->assertSame('osvob', $row['vat']['code']);
        $this->assertArrayNotHasKey('account', $row);
        $this->assertSame(
            ['ourCode' => 'NET500'],
            $result['_resolve']['rows'][0]['enrichment']['suggested'],
        );
    }

    public function testAccountingRowIsSkipped(): void
    {
        $enricher = $this->buildEnricher([
            $this->histRow('Poplatek za vedení účtu', 'FEE01'),
        ]);

        $result = $enricher->enrich($this->canonical([
            ['description' => 'Poplatek za vedení účtu', 'operation' => 'acc.record', 'accSide' => 'debit', 'account' => '568001'],
        ]));

        $this->assertArrayNotHasKey('item', $result['rows'][0]);
        $this->assertSame('568001', $result['rows'][0]['account']);
        $enrichment = $result['_resolve']['rows'][0]['enrichment'];
        $this->assertNull($enrichment['matchedBy']);
        $this->assertSame('noItemRow', $enrichment['skipped']);
    }

    public function testUnmatchedPartnerLeavesCanonicalUntouched(): void
    {
        $party = $this->createMock(PartyResolver::class);
        $party->method('resolve')->willReturn(ResolveResult::notFound());

        $db = $this->createMock(Connection::class);
        $db->expects($this->never())->method('fetchAll');

        $enricher = new RowHistoryEnricher($db, $party);
        $input = $this->canonical([['description' => 'Internet 500M']]);

        $this->assertSame($input, $enricher->enrich($input));
    }

    public function testMissingSelfPartySkipsWithoutResolve(): void
    {
        $party = $this->createMock(PartyResolver::class);
        $party->expects($this->never())->method('resolve');

        $db = $this->createMock(Connection::class);
        $db->expects($this->never())->method('fetchAll');

        $enricher = new RowHistoryEnricher($db, $party);
        $input = $this->canonical([['description' => 'Internet 500M']], selfParty: null);

        $this->assertSame($input, $enricher->enrich($input));
    }

    public function testHistoryQueryFiltersStatesAndMapsDocType(): void
    {
        // Kandidáti s itemem mimo aktivní stavy (smazáno/archiv) se filtrují už
        // v SQL (JOIN economy_items ... docState IN 10/40/80); historie jen
        // z dokladů 20/40, docType přeložený na short code.
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('fetchAll')
            ->with(
                $this->logicalAnd(
                    $this->stringContains('docs_core_rows'),
                    $this->stringContains('economy_items'),
                    $this->stringContains('economy_accounting_accounts'),
                ),
                10, 40, 80,
                42, 'invni',
                20, 40,
                200,
            )
            ->willReturn([]);

        $party = $this->createMock(PartyResolver::class);
        $party->method('resolve')->willReturn(ResolveResult::matched(42, 'companyId'));

        $enricher = new RowHistoryEnricher($db, $party);
        $enricher->enrich($this->canonical([['description' => 'Internet 500M']]));
    }

    public function testDoubleRunIsIdempotent(): void
    {
        // Fresh běh nad už obohaceným canonical: revert vlastních návrhů +
        // nový match musí dát identický výstup (D2, determinismus).
        $enricher = $this->buildEnricher([
            $this->histRow('Internet 500M 6/2026', 'NET500'),
        ]);

        $first = $enricher->enrich($this->canonical([
            ['description' => 'Internet 500M 7/2026'],
        ]));
        $second = $enricher->enrich($first);

        $this->assertSame($first, $second);
        $this->assertSame('NET500', $second['rows'][0]['item']['ourCode']);
        $this->assertSame('historyExactNorm', $second['_resolve']['rows'][0]['enrichment']['matchedBy']);
    }

    public function testFreshRunDropsSuggestionWhenHistoryNoLongerMatches(): void
    {
        // Persistnutý canonical nese návrh z /result; při apply už položka
        // v historii není (např. smazána) → autoritativní fresh běh návrh odvolá.
        $party = $this->createMock(PartyResolver::class);
        $party->method('resolve')->willReturn(ResolveResult::matched(42, 'companyId'));

        $db = $this->createMock(Connection::class);
        $db->method('fetchAll')->willReturn([]);

        $enricher = new RowHistoryEnricher($db, $party);

        $persisted = $this->canonical([
            [
                'description' => 'Internet 500M 7/2026',
                'item'        => ['ourCode' => 'NET500'],
                'vat'         => ['code' => 'std21'],
                'account'     => '518001',
            ],
        ]);
        $persisted['_resolve']['rows'] = [[
            'index'      => 0,
            'enrichment' => [
                'matchedBy'   => 'historyExactNorm',
                'confidence'  => 'high',
                'sourceDocId' => 1001,
                'suggested'   => ['ourCode' => 'NET500', 'vatCode' => 'std21', 'account' => '518001'],
            ],
        ]];

        $result = $enricher->enrich($persisted);

        $row = $result['rows'][0];
        $this->assertNull($row['item']['ourCode']);
        $this->assertNull($row['vat']['code']);
        $this->assertNull($row['account']);
        $enrichment = $result['_resolve']['rows'][0]['enrichment'];
        $this->assertNull($enrichment['matchedBy']);
        $this->assertSame([], $enrichment['suggested']);
    }
}
