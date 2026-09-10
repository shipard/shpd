<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Vat\Xml;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Core\Version;
use Shipard\Module\Economy\Vat\Xml\Dp3XmlWriter;
use Shipard\Module\Economy\Vat\Xml\EpoXsdValidator;
use Shipard\Module\Economy\Vat\Xml\FilingPeriod;
use Shipard\Module\Economy\Vat\Xml\FilingXmlInput;
use Shipard\Module\Economy\Vat\Xml\FilingXmlValidationException;
use Shipard\Module\Economy\Vat\Xml\FilingXmlValidator;
use Shipard\Module\Economy\Vat\Xml\VatXmlMapping;

/**
 * Generátor XML přiznání k DPH (issue #55, Fáze 3). Testuje se proti
 * **golden souborům** (`tests/Fixtures/vat-xml/synthetic/`) a proti
 * **oficiálnímu XSD** z repozitáře — první drží tvar výstupu, druhé
 * říká, že by ho přijal daňový portál.
 *
 * V golden souborech je `verzeSW` nahrazená zástupným `@version@`, aby
 * bump verze aplikace neshodil testy; všechno ostatní se porovnává
 * bajt po bajtu.
 */
class Dp3XmlWriterTest extends TestCase
{
    private const CONFIG   = __DIR__ . '/../../../../../../modules/economy/vat/config/vat-xml-cz.jsonc';
    private const FIXTURES = __DIR__ . '/../../../../../Fixtures/vat-xml/synthetic';

    /** Hlavička, kterou by po sestavení vyrobil composer z profilu podatele. */
    private const HEADER = [
        'typ_ds'        => 'P',
        'dic'           => '12345678',
        'zkrobchjm'     => 'Ukázka s.r.o.',
        'c_ufo'         => '464',
        'c_pracufo'     => '3305',
        'ulice'         => 'Dlouhá',
        'c_pop'         => '12',
        'naz_obce'      => 'Ukázkov',
        'psc'           => '76001',
        'stat'          => 'CZ',
        'c_telef'       => '571000000',
        'email'         => 'ucto@example.com',
        'sest_prijmeni' => 'Nováková',
        'sest_jmeno'    => 'Jana',
        'sest_telef'    => '571000001',
        'typ_platce'    => 'P',
        'c_okec'        => '620200',
        'trans'         => true,
    ];

    // ── Golden soubory ──────────────────────────────────────────────────────

    public function testRegularReturnMatchesGoldenFile(): void
    {
        $this->assertMatchesGolden('dp3-regular.xml', $this->write($this->regularInput()));
    }

    public function testSupplementaryReturnMatchesGoldenFile(): void
    {
        $this->assertMatchesGolden('dp3-supplementary.xml', $this->write($this->supplementaryInput()));
    }

    // ── Pravidla výpisu hodnot ──────────────────────────────────────────────

    public function testZeroIsOmittedExceptOnTotalRows(): void
    {
        $xml = $this->write($this->regularInput());

        // Ř. 46 a 63 jsou nulové jen ve sloupci, který se nepoužil —
        // součtové řádky se vypisují i s nulou.
        $this->assertStringContainsString('odp_sum_kr="0"', $xml, 'součtový ř. 46 chybí');
        $this->assertStringNotContainsString('obrat5=', $xml, 'nulový ř. 2 se nevypisuje');
        $this->assertStringNotContainsString('dano_no=', $xml, 'nulový nadměrný odpočet se nevypisuje');
        $this->assertStringNotContainsString('<Veta2', $xml, 'věta bez hodnot nevzniká');
        $this->assertStringNotContainsString('<Veta3', $xml);
    }

    public function testNegativeValuesAreKept(): void
    {
        $xml = $this->write($this->supplementaryInput());
        $this->assertStringContainsString('dan_zocelk="-105"', $xml);
        $this->assertStringContainsString('dano="-105"', $xml, 'změnu povinnosti nese ř. 66');
    }

    public function testCoefficientIsWrittenAsPercentOfTheStoredShare(): void
    {
        $xml = $this->write($this->reducedDeductionInput());

        $this->assertStringContainsString('koef_p20_nov="87.00"', $xml);
        $this->assertStringContainsString('odp_uprav_kf="183"', $xml);
    }

    /** Plný nárok krácení nevykazuje — koeficient by tvrdil opak. */
    public function testCoefficientIsOmittedWhenNothingIsReduced(): void
    {
        $xml = $this->write($this->regularInput());
        $this->assertStringNotContainsString('koef_p20_nov', $xml);
        $this->assertStringNotContainsString('<Veta5', $xml);
    }

    public function testTextAttachmentIsWrappedIntoSeparateSentences(): void
    {
        $xml = $this->write($this->supplementaryInput());

        preg_match_all('/<VetaR[^>]*t_prilohy="([^"]*)"/', $xml, $matches);
        $this->assertGreaterThan(1, count($matches[1]), 'dlouhá poznámka se zalomí na víc řádků');
        foreach ($matches[1] as $line) {
            $this->assertLessThanOrEqual(72, mb_strlen(html_entity_decode($line)));
        }
        $this->assertStringContainsString('kod_sekce="D" poradi="1"', $xml);
        $this->assertStringContainsString('poradi="2"', $xml);
    }

    /** Řádné přiznání textovou přílohu nezná, i když má poznámku. */
    public function testTextAttachmentIsSkippedForKindsWithoutIt(): void
    {
        $xml = $this->write($this->regularInput(['note' => 'Poznámka k řádnému přiznání.']));
        $this->assertStringNotContainsString('<VetaR', $xml);
    }

    // ── Věta D ──────────────────────────────────────────────────────────────

    #[DataProvider('formaProvider')]
    public function testFormaFollowsKindAndPredecessor(string $kind, ?string $previous, string $expected): void
    {
        $xml = $this->write($this->regularInput([
            'filingKind'   => $kind,
            'previousKind' => $previous,
            'dateFound'    => '2026-07-28',
        ]));
        $this->assertStringContainsString('dapdph_forma="' . $expected . '"', $xml);
    }

    /** @return array<string, array{0: string, 1: ?string, 2: string}> */
    public static function formaProvider(): array
    {
        return [
            'řádné'              => ['regular', null, 'B'],
            'opravné'            => ['corrective', 'regular', 'O'],
            'dodatečné'          => ['supplementary', 'regular', 'D'],
            'opravné dodatečné'  => ['corrective', 'supplementary', 'E'],
        ];
    }

    public function testMonthlyQuarterlyAndPartialPeriods(): void
    {
        $monthly = $this->write($this->regularInput());
        $this->assertStringContainsString('rok="2026" mesic="4"', $monthly);
        $this->assertStringNotContainsString('zdobd_od', $monthly, 'celé období rozsah nevypisuje');

        $quarterly = $this->write($this->regularInput([
            'period' => FilingPeriod::fromRange('2026-07-01', '2026-09-30'),
        ]));
        $this->assertStringContainsString('rok="2026" ctvrt="3"', $quarterly);

        // Vznik plátcovství uprostřed měsíce — částečné období.
        $partial = $this->write($this->regularInput([
            'period' => FilingPeriod::fromRange('2026-04-10', '2026-04-30'),
        ]));
        $this->assertStringContainsString('mesic="4" zdobd_od="10.04.2026" zdobd_do="30.04.2026"', $partial);
    }

    public function testFilingDateFallsBackToCompositionDate(): void
    {
        $draft = $this->write($this->regularInput(['dateFiled' => null]));
        $this->assertStringContainsString('d_poddp="02.05.2026"', $draft, 'koncept nese datum sestavení');

        $filed = $this->write($this->regularInput(['dateFiled' => '2026-05-04']));
        $this->assertStringContainsString('d_poddp="04.05.2026"', $filed);
    }

    public function testDateFoundIsOnlyOnFormsThatRequireIt(): void
    {
        $this->assertStringNotContainsString('d_zjist', $this->write($this->regularInput()));
        $this->assertStringContainsString('d_zjist="28.07.2026"', $this->write($this->supplementaryInput()));
    }

    public function testBooleanHeaderFieldBecomesYesNo(): void
    {
        $this->assertStringContainsString('trans="A"', $this->write($this->regularInput()));
        $this->assertStringContainsString(
            'trans="N"',
            $this->write($this->regularInput(['header' => ['trans' => false] + self::HEADER])),
        );
    }

    public function testTaxNumberLosesItsCountryPrefix(): void
    {
        $xml = $this->write($this->regularInput(['header' => ['dic' => 'CZ12345678'] + self::HEADER]));
        $this->assertStringContainsString('dic="12345678"', $xml);
    }

    // ── Validace ────────────────────────────────────────────────────────────

    public function testEveryGeneratedFileValidatesAgainstTheOfficialSchema(): void
    {
        foreach ([$this->regularInput(), $this->supplementaryInput(), $this->reducedDeductionInput()] as $input) {
            $this->assertSame(
                [],
                (new EpoXsdValidator())->validate($this->write($input), 'return'),
            );
        }
    }

    public function testMissingTaxNumberIsAFieldLevelError(): void
    {
        $header = self::HEADER;
        unset($header['dic']);

        try {
            $this->assertValid($this->regularInput(['header' => $header]));
            $this->fail('podání bez DIČ musí selhat');
        } catch (FilingXmlValidationException $e) {
            $this->assertSame(['header.dic'], array_column($e->toArray(), 'column'));
            $this->assertSame('required', $e->toArray()[0]['code']);
        }
    }

    public function testLegalEntityWithoutBusinessNameIsRejected(): void
    {
        $header = self::HEADER;
        unset($header['zkrobchjm']);

        $errors = $this->validate($this->regularInput(['header' => $header]));
        $this->assertSame(['header.zkrobchjm'], array_column($errors, 'column'));
    }

    public function testNaturalPersonNeedsBothNames(): void
    {
        $header = ['typ_ds' => 'F', 'prijmeni' => 'Novák'] + self::HEADER;
        unset($header['zkrobchjm']);

        $errors = $this->validate($this->regularInput(['header' => $header]));
        $this->assertSame(['header.jmeno'], array_column($errors, 'column'));
    }

    public function testPeriodThatIsNeitherMonthNorQuarterIsRejected(): void
    {
        $errors = $this->validate($this->regularInput([
            'period' => FilingPeriod::fromRange('2026-02-01', '2026-05-31'),
        ]));
        $this->assertSame('_form', $errors[0]['column']);
        $this->assertSame('invalid_period', $errors[0]['code']);
    }

    public function testSchemaValidationCatchesWhatTheDomainValidatorLetsThrough(): void
    {
        // Prázdná hlavička projde generováním, ale XSD chce povinné
        // atributy věty P — a to je poslední pojistka před uložením.
        $xml    = $this->write($this->regularInput(['header' => []]));
        $errors = (new EpoXsdValidator())->validate($xml, 'return');

        $this->assertNotSame([], $errors);
        $this->assertStringContainsString('VetaP', implode(' ', $errors));
    }

    // ── Determinismus ───────────────────────────────────────────────────────

    public function testRepeatedGenerationIsByteIdentical(): void
    {
        $input = $this->regularInput();
        $this->assertSame($this->write($input), $this->write($input));
    }

    // ── Pomocné ─────────────────────────────────────────────────────────────

    private function mapping(): VatXmlMapping
    {
        return VatXmlMapping::fromArray(JsoncParser::parseFile(self::CONFIG), 'return');
    }

    private function write(FilingXmlInput $input): string
    {
        return (new Dp3XmlWriter($this->mapping()))->write($input);
    }

    /** @return list<array<string, mixed>> */
    private function validate(FilingXmlInput $input): array
    {
        return array_map(
            static fn (object $e): array => ['column' => $e->column, 'code' => $e->code],
            (new FilingXmlValidator($this->mapping()))->validate($input),
        );
    }

    private function assertValid(FilingXmlInput $input): void
    {
        (new FilingXmlValidator($this->mapping()))->assertValid($input);
    }

    /** @param array<string, mixed> $override */
    private function regularInput(array $override = []): FilingXmlInput
    {
        return $this->input($override + [
            'filingKind' => 'regular',
            'returnRows' => [
                1  => ['base' => 1000.0, 'full' => 210.0, 'reduced' => 0.0],
                40 => ['base' => 501.0, 'full' => 105.0, 'reduced' => 0.0],
                46 => ['base' => 0.0, 'full' => 105.0, 'reduced' => 0.0],
                62 => ['base' => 0.0, 'full' => 210.0, 'reduced' => 0.0],
                63 => ['base' => 0.0, 'full' => 105.0, 'reduced' => 0.0],
                64 => ['base' => 0.0, 'full' => 105.0, 'reduced' => 0.0],
                65 => ['base' => 0.0, 'full' => 0.0, 'reduced' => 0.0],
                66 => ['base' => 0.0, 'full' => 0.0, 'reduced' => 0.0],
            ],
            'coefficients' => ['coefficient' => 1.0],
            'dateFiled'    => '2026-05-04',
        ]);
    }

    /** Dodatečné přiznání: rozdíly proti podanému a důvody v příloze. */
    private function supplementaryInput(): FilingXmlInput
    {
        return $this->input([
            'filingKind'   => 'supplementary',
            'previousKind' => 'regular',
            'dateIssue'    => '2026-08-01',
            'dateFiled'    => '2026-08-03',
            'dateFound'    => '2026-07-28',
            'returnRows'   => [
                1  => ['base' => -500.0, 'full' => -105.0, 'reduced' => 0.0],
                46 => ['base' => 0.0, 'full' => 0.0, 'reduced' => 0.0],
                62 => ['base' => 0.0, 'full' => -105.0, 'reduced' => 0.0],
                63 => ['base' => 0.0, 'full' => 0.0, 'reduced' => 0.0],
                66 => ['base' => 0.0, 'full' => -105.0, 'reduced' => 0.0],
            ],
            'note' => 'Opravena chybně vystavená faktura z dubna 2026, u které byla uplatněna'
                . ' základní sazba namísto osvobození podle § 66 zákona o DPH.',
        ]);
    }

    /** Krácený nárok na odpočet — ř. 46 krácený, ř. 52 a zálohový koeficient. */
    private function reducedDeductionInput(): FilingXmlInput
    {
        return $this->input([
            'filingKind' => 'regular',
            'period'     => FilingPeriod::fromRange('2026-07-01', '2026-09-30'),
            'dateIssue'  => '2026-10-20',
            'returnRows' => [
                40 => ['base' => 1000.0, 'full' => 0.0, 'reduced' => 210.0],
                46 => ['base' => 0.0, 'full' => 0.0, 'reduced' => 210.0],
                52 => ['base' => 0.0, 'full' => 183.0, 'reduced' => 0.0],
                62 => ['base' => 0.0, 'full' => 0.0, 'reduced' => 0.0],
                63 => ['base' => 0.0, 'full' => 183.0, 'reduced' => 0.0],
                65 => ['base' => 0.0, 'full' => 183.0, 'reduced' => 0.0],
            ],
            'coefficients' => ['coefficient' => 0.87],
        ]);
    }

    /** @param array<string, mixed> $values */
    private function input(array $values): FilingXmlInput
    {
        return new FilingXmlInput(
            reportType: 'return',
            filingKind: $values['filingKind'] ?? 'regular',
            previousKind: $values['previousKind'] ?? null,
            header: $values['header'] ?? self::HEADER,
            period: $values['period'] ?? FilingPeriod::fromRange('2026-04-01', '2026-04-30'),
            dateIssue: $values['dateIssue'] ?? '2026-05-02',
            dateFiled: array_key_exists('dateFiled', $values) ? $values['dateFiled'] : null,
            dateFound: $values['dateFound'] ?? null,
            returnRows: $values['returnRows'] ?? [],
            coefficients: $values['coefficients'] ?? [],
            note: $values['note'] ?? null,
        );
    }

    private function assertMatchesGolden(string $file, string $xml): void
    {
        $path = self::FIXTURES . '/' . $file;
        $this->assertFileExists($path);
        $this->assertSame(
            str_replace('@version@', Version::VERSION, (string) file_get_contents($path)),
            $xml,
            "Vygenerované XML se liší od {$file}",
        );
    }
}
