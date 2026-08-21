<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\BookingHistory;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Core\Exchange\BookingHistory\AccountTagMap;
use Shipard\Module\Core\Exchange\BookingHistory\AccountTagMatch;
use Shipard\Module\Core\Exchange\BookingHistory\BookingHistoryAnalyzer;
use Shipard\Module\Core\Exchange\BookingHistory\BookingHistoryFile;
use Shipard\Module\Economy\Items\AccountingItemsOffer;

/**
 * Sanity check názvů u přesné shody analytiky (D36,
 * tasks/booking-history-followups.md). Motivace z pilotního běhu: zdroj
 * vedl pod 518201–518203 finanční leasing, kde nabídka má telefon /
 * internet / poštovné.
 */
class AccountTagMapNameCheckTest extends TestCase
{
    /** @return array<string, list<string>> */
    private function accounts(): array
    {
        return [
            '518201' => ['it.phone'],
            '518202' => ['it.internet'],
            '518203' => ['services.postage'],
            '503100' => ['vehicle.fuel'],
        ];
    }

    /** @return array<string, array<string, list<string>>> */
    private function names(): array
    {
        return [
            '518201' => ['it.phone' => ['Telefonní služby', 'Phone services']],
            '518202' => ['it.internet' => ['Internetové připojení', 'Internet connectivity']],
            '518203' => ['services.postage' => ['Poštovné', 'Postage']],
            '503100' => ['vehicle.fuel' => ['Pohonné hmoty', 'Fuel']],
        ];
    }

    private function map(bool $strict = true): AccountTagMap
    {
        return new AccountTagMap($this->accounts(), $this->names(), $strict);
    }

    public function testSimilarNameKeepsExactMatch(): void
    {
        foreach (['Internetové připojení', 'internetove pripojeni', 'Internet', 'Připojení k internetu ADSL'] as $itemName) {
            $match = $this->map()->resolve('518202', $itemName);
            $this->assertSame(AccountTagMatch::KIND_EXACT, $match->kind, "název „{$itemName}“ má projít");
            $this->assertSame('it.internet', $match->tag);
            $this->assertFalse($match->degradedExact);
        }
    }

    public function testEnglishOfferNameAlsoCounts(): void
    {
        // Porovnává se proti všem jazykovým variantám názvu z nabídky.
        $match = $this->map()->resolve('518203', 'Postage and stamps');
        $this->assertSame(AccountTagMatch::KIND_EXACT, $match->kind);
    }

    public function testLeasingNameDegradesToSyntheticLogic(): void
    {
        // 518 nese v nabídce tři různé štítky → po degradaci kolizní účet.
        $match = $this->map()->resolve('518202', 'Finanční leasing 2');

        $this->assertSame(AccountTagMatch::KIND_AMBIGUOUS, $match->kind);
        $this->assertNull($match->tag);
        $this->assertTrue($match->degradedExact);
        $this->assertSame(['it.phone', 'it.internet', 'services.postage'], $match->candidates);
    }

    public function testMissingItemNameDegrades(): void
    {
        foreach ([null, '', '   '] as $itemName) {
            $match = $this->map()->resolve('518202', $itemName);
            $this->assertTrue($match->degradedExact, 'bez názvu nelze analytiku ověřit');
            $this->assertNull($match->tag);
        }
    }

    public function testDegradationCanStillLandOnUniqueSynthetic(): void
    {
        // 503 nese jen pohonné hmoty → degradace skončí syntetickou shodou.
        // Hrubší úroveň je legitimní signál, jen slabší (a je to vidět).
        $match = $this->map()->resolve('503100', 'Kancelářský nábytek');

        $this->assertSame(AccountTagMatch::KIND_SYNTHETIC, $match->kind);
        $this->assertSame('vehicle.fuel', $match->tag);
        $this->assertTrue($match->degradedExact);
    }

    public function testCheckIsSkippedWithoutStrictNames(): void
    {
        $match = $this->map(strict: false)->resolve('518202', 'Finanční leasing 2');

        $this->assertSame(AccountTagMatch::KIND_EXACT, $match->kind);
        $this->assertSame('it.internet', $match->tag);
        $this->assertFalse($match->degradedExact);
    }

    public function testMapWithoutOfferNamesCannotVerifyAndLetsExactPass(): void
    {
        // Mapa bez názvů (starší volající) nemá čím ověřovat — degradovat
        // všechno by reverz zabilo.
        $map = new AccountTagMap($this->accounts(), [], true);
        $this->assertSame(AccountTagMatch::KIND_EXACT, $map->resolve('518202', 'Finanční leasing')->kind);
    }

    public function testAmbiguousAccountIsUnaffected(): void
    {
        $map = new AccountTagMap(
            ['501100' => ['office.supplies', 'vehicle.parts']],
            ['501100' => ['office.supplies' => ['Kancelářské potřeby']]],
            true,
        );
        $match = $map->resolve('501100', 'Cokoli');

        $this->assertSame(AccountTagMatch::KIND_AMBIGUOUS, $match->kind);
        $this->assertFalse($match->degradedExact, 'kolizní účet žádnou přesnou shodu nenavrhl');
    }

    /**
     * Přeházená slova a skloňování: similar_text tady propadne, tokenová
     * podobnost s prefixem projde. Leasing naopak neprojde ani jednou —
     * to je celý smysl kontroly.
     */
    public function testWordOrderAndInflectionAreTolerated(): void
    {
        $map = $this->map();
        $this->assertSame('it.internet', $map->resolve('518202', 'Připojení k internetu ADSL')->tag);
        $this->assertSame('it.internet', $map->resolve('518202', 'Připojení internetové — pobočka')->tag);
        $this->assertNull($map->resolve('518202', 'Finanční leasing 4Z43461')->tag);
        $this->assertNull($map->resolve('518202', 'Nájem výrobní haly')->tag);
    }

    public function testNormalizeNameStripsDiacriticsAndPunctuation(): void
    {
        $this->assertSame('internetove pripojeni', AccountTagMap::normalizeName("  Internetové   PŘIPOJENÍ!  "));
        $this->assertSame('pohonne hmoty nafta', AccountTagMap::normalizeName('Pohonné hmoty – nafta'));
        $this->assertSame('', AccountTagMap::normalizeName('—'));
    }

    public function testStrictNamesComeFromOfferOnlyWhenRequested(): void
    {
        $offer = new AccountingItemsOffer($this->createStub(DataSourceConnection::class));

        $strict = AccountTagMap::fromOffer($offer, 'default', true);
        $loose  = AccountTagMap::fromOffer($offer, 'default');

        $this->assertTrue($strict->strictNames());
        $this->assertFalse($loose->strictNames());

        // Reálná nabídka: 518202 = internetové připojení.
        $this->assertSame('it.internet', $loose->resolve('518202', 'Finanční leasing')->tag);
        $this->assertNull($strict->resolve('518202', 'Finanční leasing')->tag);
        $this->assertSame('it.internet', $strict->resolve('518202', 'Internet')->tag);
    }

    public function testAnalyzerCountsDegradedExactMatches(): void
    {
        $file = BookingHistoryFile::open(
            dirname(__DIR__, 5) . '/Fixtures/Exchange/bookingHistory/unknownChart.jsonl',
        );
        $this->assertTrue($file->header->chartVariantIsGuessed());

        $analysis = (new BookingHistoryAnalyzer())->analyze(
            $file,
            new AccountTagMap($this->accounts(), $this->names(), true),
        );

        // Degradují: dva leasingy + záznam bez itemName + nábytek na 503100.
        $this->assertTrue($analysis->hasDegradedExact());
        $this->assertSame(4, $analysis->degradedExact['records']);
        $this->assertSame(34, $analysis->degradedExact['rows']);

        $accounts = $analysis->degradedExactAccounts();
        $this->assertSame('518202', $accounts[0]['account']);
        $this->assertSame(18, $accounts[0]['rows']);
        $this->assertSame(['it.internet'], $accounts[0]['offerTags']);

        // Falešné it.internet/it.phone řádky z reverzu zmizely; zůstal jen
        // ten, který kontrolou názvu prošel.
        $usage = $analysis->tagUsage();
        $this->assertSame(24, $usage['it.internet']['reverseRows']);
        $this->assertArrayNotHasKey('it.phone', $usage);
    }
}
