<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Logging;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Logging\ErrorLogger;

class ErrorLoggerTest extends TestCase
{
    private string $tempLog;

    protected function setUp(): void
    {
        ErrorLogger::resetForTesting();
        $this->tempLog = sys_get_temp_dir() . '/shipard-test-log-' . uniqid() . '.log';
        ErrorLogger::setLogPath($this->tempLog);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempLog)) {
            unlink($this->tempLog);
        }
        ErrorLogger::resetForTesting();
    }

    public function testInfoEntryIsValidJson(): void
    {
        ErrorLogger::info('Hello', ['key' => 'value']);
        $entry = $this->readFirstEntry();

        self::assertSame('info', $entry['level']);
        self::assertSame('Hello', $entry['msg']);
        self::assertSame(['key' => 'value'], (array) $entry['ctx']);
        self::assertNull($entry['ds']);
        self::assertNull($entry['request']);
        self::assertArrayHasKey('ts', $entry);
    }

    public function testEmptyContextSerializesAsObject(): void
    {
        ErrorLogger::info('no ctx');
        $raw = file_get_contents($this->tempLog);
        self::assertNotFalse($raw);
        // ctx must be an empty object {}, not array []
        self::assertStringContainsString('"ctx":{}', $raw);
    }

    public function testThresholdFiltersBelowLevel(): void
    {
        ErrorLogger::setLogLevel('warn');
        ErrorLogger::debug('dropped');
        ErrorLogger::info('dropped');
        ErrorLogger::warn('kept');
        ErrorLogger::error('kept');

        $entries = $this->readAllEntries();
        self::assertCount(2, $entries);
        self::assertSame('warn', $entries[0]['level']);
        self::assertSame('error', $entries[1]['level']);
    }

    public function testUnknownLevelFallsBackToDebug(): void
    {
        ErrorLogger::setLogLevel('nonsense');
        ErrorLogger::debug('kept');
        $entries = $this->readAllEntries();
        self::assertCount(1, $entries);
    }

    public function testDsIdAndRequestContextArePropagated(): void
    {
        ErrorLogger::setDsId('test-ds');
        ErrorLogger::setRequestContext('GET /test');
        ErrorLogger::warn('ctx test');

        $entry = $this->readFirstEntry();
        self::assertSame('test-ds', $entry['ds']);
        self::assertSame('GET /test', $entry['request']);
    }

    public function testLogExceptionRecordsClassMessageTrace(): void
    {
        $exception = new \RuntimeException('boom');
        ErrorLogger::logException($exception);

        $entry = $this->readFirstEntry();
        self::assertSame('error', $entry['level']);
        self::assertArrayHasKey('exception', $entry);
        self::assertSame('RuntimeException', $entry['exception']['class']);
        self::assertSame('boom', $entry['exception']['message']);
        self::assertNotEmpty($entry['exception']['trace']);
        self::assertSame('RuntimeException: boom', $entry['msg']);
    }

    public function testLogExceptionWithExplicitMessage(): void
    {
        $exception = new \RuntimeException('inner detail');
        ErrorLogger::logException($exception, 'Operation X failed');

        $entry = $this->readFirstEntry();
        self::assertSame('Operation X failed', $entry['msg']);
        self::assertSame('inner detail', $entry['exception']['message']);
    }

    public function testLogExceptionRespectsThreshold(): void
    {
        // Threshold above ERROR — nothing in this codebase, but defensive
        $reflection = new \ReflectionClass(ErrorLogger::class);
        $threshold = $reflection->getProperty('threshold');
        $threshold->setValue(null, 99);

        ErrorLogger::logException(new \RuntimeException('boom'));
        self::assertSame([], $this->readAllEntries());
    }

    public function testChainedExceptionsRecordedAsPrevious(): void
    {
        $inner = new \LogicException('root cause');
        $outer = new \RuntimeException('wrapper', 0, $inner);

        ErrorLogger::logException($outer);
        $entry = $this->readFirstEntry();

        self::assertSame('wrapper', $entry['exception']['message']);
        self::assertArrayHasKey('previous', $entry['exception']);
        self::assertSame('root cause', $entry['exception']['previous']['message']);
        self::assertSame('LogicException', $entry['exception']['previous']['class']);
    }

    public function testTraceIsTruncatedTo20Frames(): void
    {
        // Build a deep call stack synthetically
        $exception = self::recursiveThrow(40);
        ErrorLogger::logException($exception);

        $entry = $this->readFirstEntry();
        self::assertLessThanOrEqual(20, count($entry['exception']['trace']));
    }

    public function testFallbackToErrorLogWhenFileNotWritable(): void
    {
        ErrorLogger::setLogPath('/proc/cannot-write-here.log');
        // Should not throw — falls back to error_log()
        ErrorLogger::warn('survives unwritable path');

        // No exception thrown is the assertion here
        self::assertTrue(true);
    }

    public function testEntryIsSingleLineJson(): void
    {
        ErrorLogger::info('multi\nline\nin msg', ['nested' => ['a' => 1]]);
        $raw = file_get_contents($this->tempLog);
        self::assertNotFalse($raw);
        // Exactly one newline (the trailing one); no embedded line breaks in JSON
        self::assertSame(1, substr_count($raw, "\n"));
    }

    public function testTimestampIsIso8601(): void
    {
        ErrorLogger::info('time');
        $entry = $this->readFirstEntry();
        // ISO 8601 with timezone, e.g. 2026-05-07T12:34:56+02:00
        self::assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+\-]\d{2}:\d{2}$/',
            $entry['ts'],
        );
    }

    /** @return array<string, mixed> */
    private function readFirstEntry(): array
    {
        $entries = $this->readAllEntries();
        self::assertNotEmpty($entries, 'log file is empty');
        return $entries[0];
    }

    /** @return list<array<string, mixed>> */
    private function readAllEntries(): array
    {
        if (!file_exists($this->tempLog)) {
            return [];
        }
        $lines = file($this->tempLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return [];
        }
        return array_map(
            fn(string $line): array => json_decode($line, true) ?: [],
            $lines,
        );
    }

    private static function recursiveThrow(int $depth): \Throwable
    {
        if ($depth <= 0) {
            return new \RuntimeException('deep');
        }
        try {
            throw self::recursiveThrow($depth - 1);
        } catch (\Throwable $e) {
            return $e;
        }
    }
}
