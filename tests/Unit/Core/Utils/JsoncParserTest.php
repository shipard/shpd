<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Utils;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Utils\JsoncParser;

class JsoncParserTest extends TestCase
{
    public function testSingleLineComment(): void
    {
        $result = JsoncParser::parse('{"a": 1 // comment' . "\n}");
        $this->assertSame(['a' => 1], $result);
    }

    public function testMultiLineComment(): void
    {
        $result = JsoncParser::parse('{"a": /* mid */ 1}');
        $this->assertSame(['a' => 1], $result);
    }

    public function testTrailingCommaInArray(): void
    {
        $result = JsoncParser::parse('[1, 2, 3,]');
        $this->assertSame([1, 2, 3], $result);
    }

    public function testTrailingCommaInObject(): void
    {
        $result = JsoncParser::parse('{"a": 1, "b": 2,}');
        $this->assertSame(['a' => 1, 'b' => 2], $result);
    }

    public function testCommentInsideStringPreserved(): void
    {
        $result = JsoncParser::parse('{"url": "http://example.com"}');
        $this->assertSame(['url' => 'http://example.com'], $result);
    }

    public function testMultiLineCommentInsideStringPreserved(): void
    {
        $result = JsoncParser::parse('{"x": "/* not a comment */"}');
        $this->assertSame(['x' => '/* not a comment */'], $result);
    }

    public function testEscapedQuote(): void
    {
        $result = JsoncParser::parse('{"a": "say \"hi\""}');
        $this->assertSame(['a' => 'say "hi"'], $result);
    }

    public function testInvalidJsonThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        JsoncParser::parse('{invalid}');
    }

    public function testEmptyInputThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        JsoncParser::parse('');
    }

    public function testPureJsonUnchanged(): void
    {
        $result = JsoncParser::parse('{"a": 1, "b": [2, 3]}');
        $this->assertSame(['a' => 1, 'b' => [2, 3]], $result);
    }

    public function testTrailingCommaBeforeComment(): void
    {
        $result = JsoncParser::parse('{"a": 1, // comment' . "\n}");
        $this->assertSame(['a' => 1], $result);
    }

    public function testParseFileThrowsOnMissingFile(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/\/nonexistent\/path\.jsonc/');
        JsoncParser::parseFile('/nonexistent/path.jsonc');
    }
}
