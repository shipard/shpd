<?php

declare(strict_types=1);

namespace Shipard\Tests\Integration\Vat;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Module\Economy\Vat\FilingDocument;
use Shipard\Module\Economy\Vat\Xml\EpoXmlDiff;
use Shipard\Module\Economy\Vat\Xml\FilingFilesService;
use Shipard\Tests\Integration\IntegrationTestCase;

/**
 * **Zlatý test** (issue #55, X9): dává Shipard za dané období tentýž
 * soubor, jaký se za ně doopravdy podal?
 *
 * Referenční soubory leží v `tests/Fixtures/vat-xml/689089/` (viz jeho
 * README) a pojmenované jsou tak, jak je pojmenuje Shipard — z názvu se
 * tedy pozná typ tvrzení, období i druh podání, a test si k němu najde
 * odpovídající podání na zdroji dat.
 *
 * Test se **přeskočí**, když soubory nejsou nebo když zdroj dat nemá
 * odpovídající podání: běží nad migrovaným zdrojem (`btpg-p`), ne nad
 * ukázkovým.
 *
 * ```bash
 * SHIPARD_INTEGRATION_DS_PATH=/opt/shipard/data-sources/btpg-… \
 *   vendor/bin/phpunit --testsuite Integration --filter GoldenFilingXml
 * ```
 */
class GoldenFilingXmlTest extends IntegrationTestCase
{
    private const FIXTURES = __DIR__ . '/../../Fixtures/vat-xml/689089';

    /** Element písemnosti v názvu souboru → typ tvrzení. */
    private const REPORT_TYPE_BY_ELEMENT = [
        'DPHDP3' => 'return',
        'DPHKH1' => 'cs',
        'DPHSHV' => 'rs',
    ];

    private ?ConfigRuntime $config = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->config = ConfigRuntime::load($this->realDsPath, 'cs');

        if (!isset($this->tables[FilingDocument::TABLE])) {
            $this->markTestSkipped('DS nemá tabulku economy_vat_filings — spusťte ds-upgrade');
        }
        if ($this->fixtures() === []) {
            $this->markTestSkipped(
                'Zlatý test čeká na podané soubory v ' . self::FIXTURES . ' (viz README v adresáři)',
            );
        }
    }

    public function testGeneratedXmlMatchesWhatWasActuallyFiled(): void
    {
        $service  = new FilingFilesService($this->db->getDibiConnection(), $this->config);
        $compared = 0;
        $skipped  = [];

        foreach ($this->fixtures() as $file) {
            $name     = basename($file);
            $filingId = $this->findFiling($name);
            if ($filingId === null) {
                $skipped[] = $name;
                continue;
            }

            $generated = $service->build($filingId, xmlOnly: true)->xml();
            $this->assertSame(
                $name,
                $generated->name,
                'Podání #' . $filingId . ' se jmenuje jinak, než referenční soubor',
            );

            $differences = EpoXmlDiff::compare(
                (string) file_get_contents($file),
                $generated->content,
            );
            $this->assertSame(
                [],
                $differences,
                "{$name} (podání #{$filingId}):\n" . EpoXmlDiff::format($differences),
            );
            $compared++;
        }

        if ($compared === 0) {
            $this->markTestSkipped(
                'Zdroj dat nemá podání pro žádný referenční soubor (' . implode(', ', $skipped) . ')',
            );
        }
        $this->assertGreaterThan(0, $compared);
    }

    // ── Pomocné ─────────────────────────────────────────────────────────────

    /** @return list<string> */
    private function fixtures(): array
    {
        return array_values(glob(self::FIXTURES . '/*.xml') ?: []);
    }

    /**
     * Podané podání odpovídající referenčnímu souboru. Název nese typ,
     * DIČ, období a u neřádných podání i druh a pořadí — tedy přesně to,
     * čím je podání identifikované.
     */
    private function findFiling(string $fileName): ?int
    {
        if (preg_match(
            '/^(DPHDP3|DPHKH1|DPHSHV)-(\d+)-(\d{4})-(\d{2}|Q[1-4])(?:-([a-z]+)-(\d+))?\.xml$/',
            $fileName,
            $m,
        ) !== 1) {
            $this->fail("Referenční soubor '{$fileName}' nemá očekávaný název (viz README fixtur)");
        }

        [, $element, , $year, $period, $kind, $sequence] = $m + [5 => null, 6 => null];
        $reportType = self::REPORT_TYPE_BY_ELEMENT[$element];

        $conditions = [
            'p.report_type = %s' => $reportType,
            'YEAR(p.date_begin) = %i' => (int) $year,
            'f.filing_kind = %s' => $kind ?? FilingDocument::KIND_REGULAR,
            'f.docState = %i' => FilingDocument::DOC_STATE_FILED,
        ];
        if (str_starts_with($period, 'Q')) {
            $conditions['QUARTER(p.date_begin) = %i'] = (int) substr($period, 1);
        } else {
            $conditions['MONTH(p.date_begin) = %i'] = (int) $period;
        }
        if ($sequence !== null) {
            $conditions['f.sequence = %i'] = (int) $sequence;
        }

        $sql    = 'SELECT f.id FROM economy_vat_filings f'
            . ' JOIN economy_vat_report_periods p ON p.id = f.report_period WHERE '
            . implode(' AND ', array_keys($conditions)) . ' ORDER BY f.id LIMIT 1';
        $id = $this->db->fetchSingle($sql, ...array_values($conditions));

        return $id !== null && $id !== false ? (int) $id : null;
    }
}
