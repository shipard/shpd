<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Logging;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Logging\LogTail;

class LogTailTest extends TestCase
{
	private string $tmpFile;

	protected function setUp(): void
	{
		$path = tempnam(sys_get_temp_dir(), 'shpd-tail-');
		if ($path === false) {
			$this->fail('Could not create temp file');
		}
		$this->tmpFile = $path;
	}

	protected function tearDown(): void
	{
		if (file_exists($this->tmpFile)) {
			unlink($this->tmpFile);
		}
	}

	private function writeLines(array $lines, bool $trailingNewline = true): void
	{
		$body = implode("\n", $lines);
		if ($trailingNewline) {
			$body .= "\n";
		}
		file_put_contents($this->tmpFile, $body);
	}

	public function testNonExistentFileReturnsEmpty(): void
	{
		$tail = new LogTail($this->tmpFile . '.does-not-exist');
		$this->assertSame([], $tail->readLast(10));
	}

	public function testEmptyFileReturnsEmpty(): void
	{
		// tempnam() already created an empty file
		$tail = new LogTail($this->tmpFile);
		$this->assertSame([], $tail->readLast(10));
	}

	public function testZeroLimitReturnsEmpty(): void
	{
		$this->writeLines(['a', 'b', 'c']);
		$tail = new LogTail($this->tmpFile);
		$this->assertSame([], $tail->readLast(0));
	}

	public function testReturnsAllWhenLimitExceedsLineCount(): void
	{
		$this->writeLines(['line-1', 'line-2', 'line-3']);
		$tail = new LogTail($this->tmpFile);
		$this->assertSame(['line-1', 'line-2', 'line-3'], $tail->readLast(10));
	}

	public function testReturnsLastN(): void
	{
		$lines = [];
		for ($i = 1; $i <= 10; $i++) {
			$lines[] = 'line-' . $i;
		}
		$this->writeLines($lines);
		$tail = new LogTail($this->tmpFile);
		$this->assertSame(['line-8', 'line-9', 'line-10'], $tail->readLast(3));
	}

	public function testFileWithoutTrailingNewline(): void
	{
		$this->writeLines(['a', 'b', 'c'], trailingNewline: false);
		$tail = new LogTail($this->tmpFile);
		$this->assertSame(['a', 'b', 'c'], $tail->readLast(3));
	}

	public function testLinesLongerThanChunk(): void
	{
		$lines = [];
		for ($i = 1; $i <= 5; $i++) {
			$lines[] = 'L' . $i . ':' . str_repeat('x', 200);
		}
		$this->writeLines($lines);
		$tail = new LogTail($this->tmpFile, 64);
		$result = $tail->readLast(3);
		$this->assertCount(3, $result);
		$this->assertSame($lines[2], $result[0]);
		$this->assertSame($lines[3], $result[1]);
		$this->assertSame($lines[4], $result[2]);
	}

	public function testManyLinesWithSmallChunk(): void
	{
		$lines = [];
		for ($i = 1; $i <= 100; $i++) {
			$lines[] = 'line-' . $i;
		}
		$this->writeLines($lines);
		$tail = new LogTail($this->tmpFile, 128);
		$result = $tail->readLast(20);
		$this->assertCount(20, $result);
		$this->assertSame('line-81', $result[0]);
		$this->assertSame('line-100', $result[19]);
	}

	public function testEmptyLinesAreFiltered(): void
	{
		$this->writeLines(['a', '', 'b', '  ', 'c']);
		$tail = new LogTail($this->tmpFile);
		$this->assertSame(['a', 'b', 'c'], $tail->readLast(10));
	}
}
