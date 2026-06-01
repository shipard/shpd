<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Schema;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Utils\JsoncParser;

/**
 * The `.jsonc` is the human-edited source for each exchange schema and the
 * `.json` is the compiled (comment-free) version actually loaded by
 * SchemaLoader. Without a CLI compile step they're maintained by hand and
 * drift is the obvious risk — this test parses both and compares.
 */
class SchemaDriftTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function schemaProvider(): array
    {
        return [
            'shpd.docs.document.v1'   => ['shpd.docs.document.v1'],
            'shpd.persons.person.v1' => ['shpd.persons.person.v1'],
            'shpd.items.item.v1'     => ['shpd.items.item.v1'],
        ];
    }

    #[DataProvider('schemaProvider')]
    public function testJsoncAndJsonAreEquivalent(string $name): void
    {
        $base = dirname(__DIR__, 6) . '/modules/core/exchange/schemas';
        $jsoncPath = "{$base}/{$name}.jsonc";
        $jsonPath = "{$base}/{$name}.json";

        $this->assertFileExists($jsoncPath);
        $this->assertFileExists($jsonPath);

        $fromJsonc = JsoncParser::parse((string) file_get_contents($jsoncPath));
        $fromJson = json_decode((string) file_get_contents($jsonPath), true);

        $this->assertSame(
            $fromJsonc,
            $fromJson,
            "Schema drift between {$name}.jsonc and {$name}.json — recompile the .json from the .jsonc source.",
        );
    }
}
