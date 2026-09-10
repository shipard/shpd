<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Vat;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Module\Economy\Vat\ControlStatementCalculator;
use Shipard\Module\Economy\Vat\FilingRounding;

/**
 * Úplnost mapování podání DPH na XML pro EPO (issue #55, Fáze 3, X4) proti
 * **schématům v repozitáři** (`modules/economy/vat/xsd/`) — bez sítě.
 *
 * Invariant: pro každou větu schématu platí `mapované ∪ vědomě
 * nemapované = atributy v XSD`. Nový atribut v novém vydání schématu tak
 * shodí testy, dokud se nerozhodne, co s ním — stejný princip jako
 * explicitní `null` v mapování kódů DPH (VatReportsMappingCompletenessTest).
 *
 * Věty D a P (hlavička) se tu kontrolují jen na existenci deklarace;
 * jejich pole nese strukturované schéma `economy.vat.filingHeaderCz*`
 * a hlídá je test hlavičky.
 */
class VatXmlMappingCompletenessTest extends TestCase
{
    private const MODULE = __DIR__ . '/../../../../../modules/economy/vat';

    /** Věty, jejichž obsah nepochází ze snapshotu, ale ze schématu hlavičky. */
    private const HEADER_MARKER = '*header*';

    /** Celá věta se negeneruje. */
    private const SKIP_MARKER = '*';

    private const DOCUMENTS = ['dp3', 'kh1', 'shv'];

    private const XSD_BY_DOCUMENT = [
        'dp3' => 'dphdp3_epo2.xsd',
        'kh1' => 'dphkh1_epo2.xsd',
        'shv' => 'dphshv_epo2.xsd',
    ];

    /** Typ tvrzení (economy.vat.reportTypes) → sekce XML configu. */
    private const DOCUMENT_BY_REPORT_TYPE = ['return' => 'dp3', 'cs' => 'kh1', 'rs' => 'shv'];

    // ── Struktura vůči XSD ──────────────────────────────────────────────────

    public function testEveryXsdAttributeIsMappedOrDeclaredUnmapped(): void
    {
        foreach (self::DOCUMENTS as $document) {
            $xsd      = $this->xsdAttributes($document);
            $declared = $this->declaredAttributes($document);

            // Obálku (`Pisemnost` + element písemnosti) staví EpoXmlWriter
            // z configu `element` / `verzePis` a z `Core\Version`.
            unset($xsd['Pisemnost'], $xsd[$this->xmlConfig()[$document]['element']]);

            foreach ($xsd as $element => $attributes) {
                $this->assertArrayHasKey(
                    $element,
                    $declared,
                    "{$document}: věta '{$element}' ze schématu není v vat-xml-cz.jsonc ani mapovaná,"
                    . ' ani vědomě vynechaná',
                );
                if (in_array(self::SKIP_MARKER, $declared[$element], true)
                    || in_array(self::HEADER_MARKER, $declared[$element], true)
                ) {
                    continue;
                }

                sort($attributes);
                $mine = $declared[$element];
                sort($mine);
                $this->assertSame(
                    $attributes,
                    $mine,
                    "{$document}/{$element}: mapované + vědomě nemapované atributy se neshodují se schématem",
                );
            }

            foreach (array_keys($declared) as $element) {
                $this->assertArrayHasKey(
                    $element,
                    $xsd,
                    "{$document}: config mapuje větu '{$element}', kterou schéma nezná",
                );
            }
        }
    }

    public function testRequiredXsdAttributesAreNeverLeftUnmapped(): void
    {
        foreach (self::DOCUMENTS as $document) {
            $config   = $this->xmlConfig()[$document];
            $required = $this->xsdAttributes($document, requiredOnly: true);
            $unmapped = array_merge_recursive(
                $config['unmapped'] ?? [],
                $config['optionalUnmapped'] ?? [],
            );

            foreach ($required as $element => $attributes) {
                $declared = $unmapped[$element] ?? [];
                if (in_array(self::SKIP_MARKER, $declared, true)
                    || in_array(self::HEADER_MARKER, $declared, true)
                ) {
                    continue;
                }
                $this->assertSame(
                    [],
                    array_values(array_intersect($attributes, $declared)),
                    "{$document}/{$element}: povinný atribut schématu je veden jako nemapovaný"
                    . ' — podání by neprošlo XSD validací',
                );
            }
        }
    }

    public function testHeaderSentencesAreDeclaredForEveryDocument(): void
    {
        foreach (self::DOCUMENTS as $document) {
            $unmapped = $this->xmlConfig()[$document]['unmapped'] ?? [];
            foreach (['VetaD', 'VetaP'] as $element) {
                $this->assertSame(
                    [self::HEADER_MARKER],
                    $unmapped[$element] ?? null,
                    "{$document}: věta '{$element}' musí být deklarovaná jako hlavička",
                );
            }
        }
    }

    public function testEnvelopeElementsAreNotMapped(): void
    {
        foreach (self::DOCUMENTS as $document) {
            $config = $this->xmlConfig()[$document];
            $this->assertArrayNotHasKey('Pisemnost', $this->declaredAttributes($document));
            $this->assertArrayHasKey(
                $config['element'],
                $this->rawXsdElements($document),
                "{$document}: element písemnosti '{$config['element']}' schéma nezná",
            );
            $this->assertMatchesRegularExpression('/^\d{2}\.\d{2}$/', (string) $config['verzePis']);
        }
    }

    // ── Vazba na mapování kódů DPH a na snapshot ────────────────────────────

    public function testEveryDp3RowUsedByCodeMappingHasXmlMapping(): void
    {
        $rows = $this->xmlConfig()['dp3']['rows'];

        foreach ($this->reportsConfig()['vatOutputs'] as $code => $outputs) {
            $dp3 = $outputs['dp3'] ?? null;
            if ($dp3 === null) {
                continue;
            }
            $row = (string) $dp3['row'];
            $this->assertArrayHasKey($row, $rows, "Kód {$code} míří na řádek {$row} bez mapování do XML");

            // Sloupec „V plné výši" / „Krácený odpočet" musí mít atribut,
            // jinak by hodnota z dokladů tiše vypadla.
            $slot = ($dp3['col'] ?? null) === 'reduced' ? 'reduced' : 'full';
            if (($dp3['col'] ?? null) !== null) {
                $this->assertArrayHasKey(
                    $slot,
                    $rows[$row],
                    "Kód {$code}: řádek {$row} nemá atribut pro sloupec '{$slot}'",
                );
            }
        }
    }

    public function testLabelledAndComputedRowsHaveXmlMapping(): void
    {
        $rows = $this->xmlConfig()['dp3']['rows'];

        foreach (array_keys($this->reportsConfig()['dp3Rows']) as $row) {
            $this->assertArrayHasKey((string) $row, $rows, "Řádek {$row} má popisek, ale nemá mapování do XML");
        }
        foreach (FilingRounding::COMPUTED_ROWS as $row) {
            $this->assertArrayHasKey((string) $row, $rows, "Dopočtený řádek {$row} nemá mapování do XML");
        }
        foreach ($this->xmlConfig()['dp3']['alwaysEmit'] as $row) {
            $this->assertArrayHasKey((string) $row, $rows, "Řádek {$row} v alwaysEmit nemá mapování");
        }
    }

    public function testEveryRowMapsAtLeastOneAttributeAndOnlyKnownSlots(): void
    {
        foreach ($this->xmlConfig()['dp3']['rows'] as $row => $definition) {
            $this->assertMatchesRegularExpression('/^\d{1,2}$/', (string) $row);
            $slots = array_diff(array_keys($definition), ['veta']);
            $this->assertNotSame([], $slots, "Řádek {$row} nemapuje žádný atribut");
            $this->assertSame(
                [],
                array_diff($slots, ['base', 'full', 'reduced', 'percent']),
                "Řádek {$row} používá neznámý slot (povolené: base, full, reduced, percent)",
            );
        }
    }

    public function testControlStatementSectionsMatchCalculator(): void
    {
        $sections = array_keys($this->xmlConfig()['kh1']['sections']);
        sort($sections);
        $expected = ControlStatementCalculator::SECTIONS;
        sort($expected);

        $this->assertSame(
            $expected,
            $sections,
            'Sekce kontrolního hlášení v XML mapování se rozešly s ControlStatementCalculator',
        );
    }

    public function testAggregateSectionsCarryNoDocumentAttributes(): void
    {
        $sections = $this->xmlConfig()['kh1']['sections'];

        foreach (ControlStatementCalculator::AGGREGATE_SECTIONS as $section) {
            foreach (['vatId', 'evidNumber', 'date', 'kodPredPl'] as $key) {
                $this->assertArrayNotHasKey(
                    $key,
                    $sections[$section],
                    "Agregátní sekce {$section} nesmí mapovat údaje dokladu",
                );
            }
        }
    }

    public function testVetaCReferencesMappedReturnRows(): void
    {
        $config = $this->xmlConfig()['kh1']['vetaC'];
        $rows   = $this->xmlConfig()['dp3']['rows'];
        $slot   = (string) $config['slot'];

        foreach ($config['attributes'] as $attribute => $definition) {
            foreach ($definition['rows'] as $row) {
                $this->assertArrayHasKey(
                    (string) $row,
                    $rows,
                    "Věta C, atribut {$attribute}: řádek {$row} neexistuje v mapování přiznání",
                );
                $this->assertArrayHasKey(
                    $slot,
                    $rows[(string) $row],
                    "Věta C, atribut {$attribute}: řádek {$row} nemá slot '{$slot}'",
                );
            }
        }
    }

    public function testFilingKindsHaveFormaCode(): void
    {
        foreach ($this->reportsConfig()['reportTypes'] as $type => $definition) {
            $document = self::DOCUMENT_BY_REPORT_TYPE[$type];
            $forma    = $this->xmlConfig()[$document]['forma'];

            foreach ($definition['filingKinds'] ?? [] as $kind) {
                $this->assertArrayHasKey(
                    $kind,
                    $forma,
                    "Typ {$type}: druh podání '{$kind}' nemá kód formy v XML mapování",
                );
            }
            foreach (array_keys($forma) as $key) {
                // „<druh>@<druh předchozího podání>" — obě části musí být
                // druhem povoleným u tohoto typu tvrzení.
                foreach (explode('@', (string) $key) as $kind) {
                    $this->assertContains(
                        $kind,
                        $definition['filingKinds'] ?? [],
                        "Typ {$type}: forma '{$key}' odkazuje na nepovolený druh podání '{$kind}'",
                    );
                }
            }
        }
    }

    // ── Pomocné ─────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function xmlConfig(): array
    {
        return JsoncParser::parseFile(self::MODULE . '/config/vat-xml-cz.jsonc');
    }

    /** @return array<string, mixed> */
    private function reportsConfig(): array
    {
        return JsoncParser::parseFile(self::MODULE . '/config/vat-reports-cz.jsonc');
    }

    /**
     * Atributy schématu per věta. Vlastníkem atributu je nejbližší
     * nadřazený pojmenovaný element — atributy se v XSD deklarují různě
     * hluboko (complexType, simpleContent/extension).
     *
     * @return array<string, list<string>>
     */
    private function xsdAttributes(string $document, bool $requiredOnly = false): array
    {
        $dom = new \DOMDocument();
        $dom->load(self::MODULE . '/xsd/' . self::XSD_BY_DOCUMENT[$document]);
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('xs', 'http://www.w3.org/2001/XMLSchema');

        $out = [];
        foreach ($xpath->query('//xs:element[@name]') as $element) {
            if (!$element instanceof \DOMElement) {
                continue;
            }
            $out[$element->getAttribute('name')] = [];
        }
        foreach ($xpath->query('//xs:attribute[@name]') as $attribute) {
            if (!$attribute instanceof \DOMElement) {
                continue;
            }
            if ($requiredOnly && $attribute->getAttribute('use') !== 'required') {
                continue;
            }
            $owner = $this->ownerElement($attribute);
            if ($owner !== null) {
                $out[$owner][] = $attribute->getAttribute('name');
            }
        }
        return $out;
    }

    /** @return array<string, list<string>> */
    private function rawXsdElements(string $document): array
    {
        return $this->xsdAttributes($document);
    }

    private function ownerElement(\DOMNode $node): ?string
    {
        for ($parent = $node->parentNode; $parent !== null; $parent = $parent->parentNode) {
            if ($parent instanceof \DOMElement
                && $parent->localName === 'element'
                && $parent->hasAttribute('name')
            ) {
                return $parent->getAttribute('name');
            }
        }
        return null;
    }

    /**
     * Atributy, které config pro danou písemnost mapuje nebo vědomě
     * nemapuje, per věta.
     *
     * @return array<string, list<string>>
     */
    private function declaredAttributes(string $document): array
    {
        $config = $this->xmlConfig()[$document];
        $out    = [];

        $add = static function (string $element, mixed ...$attributes) use (&$out): void {
            foreach ($attributes as $attribute) {
                if (is_string($attribute) && $attribute !== '') {
                    $out[$element][] = $attribute;
                }
            }
            $out[$element] ??= [];
        };

        foreach ($config['rows'] ?? [] as $row) {
            $add(
                $row['veta'],
                $row['base'] ?? null,
                $row['full'] ?? null,
                $row['reduced'] ?? null,
                $row['percent']['attr'] ?? null,
            );
        }
        if (isset($config['note'])) {
            $add(
                $config['note']['veta'],
                $config['note']['sectionAttr'],
                $config['note']['orderAttr'],
                $config['note']['textAttr'],
            );
        }
        foreach ($config['sections'] ?? [] as $section) {
            $add(
                $section['veta'],
                $section['vatId']['attr'] ?? null,
                $section['vatId']['countryAttr'] ?? null,
                $section['evidNumber'] ?? null,
                $section['date'] ?? null,
                $section['kodPredPl'] ?? null,
                ...array_keys($section['constants'] ?? []),
                ...array_values($section['bands'] ?? []),
            );
        }
        if (isset($config['vetaC'])) {
            $add($config['vetaC']['veta'], ...array_keys($config['vetaC']['attributes']));
        }
        if (isset($config['row'])) {
            $add(
                $config['row']['veta'],
                $config['row']['vatId']['attr'] ?? null,
                $config['row']['vatId']['countryAttr'] ?? null,
                $config['row']['code'],
                $config['row']['count'],
                $config['row']['value'],
            );
        }

        foreach ([$config['unmapped'] ?? [], $config['optionalUnmapped'] ?? []] as $group) {
            foreach ($group as $element => $attributes) {
                $add($element, ...$attributes);
            }
        }

        foreach ($out as $element => $attributes) {
            $this->assertSame(
                array_unique($attributes),
                $attributes,
                "{$document}/{$element}: atribut je deklarovaný dvakrát",
            );
        }
        return $out;
    }
}
