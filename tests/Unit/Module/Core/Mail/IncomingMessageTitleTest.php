<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Mail;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Module\Core\Mail\IncomingMessageTitle;

/**
 * Pravidlo D3 (tasks/mail-message-title-partner.md): ai_title místo
 * předmětu jen u generického / prázdného předmětu nebo ruční zprávy.
 */
final class IncomingMessageTitleTest extends TestCase
{
    private const SOURCE_EMAIL = 2;

    /** @return list<string> Vzory z dodávaného configu. */
    private function shippedPatterns(): array
    {
        $patterns = JsoncParser::parseFile(
            dirname(__DIR__, 5) . '/modules/core/mail/config/genericSubjectPatterns.jsonc',
        );
        $this->assertIsArray($patterns);
        return array_values($patterns);
    }

    public function testNormalEmailKeepsSubject(): void
    {
        $this->assertSame(
            'Faktura 2026-0042 od Dodavatel s.r.o.',
            IncomingMessageTitle::display('Faktura 2026-0042 od Dodavatel s.r.o.', 'Faktura — AI titulek', self::SOURCE_EMAIL, $this->shippedPatterns()),
        );
        $this->assertFalse(IncomingMessageTitle::usesAiTitle('Faktura 2026-0042', 'AI', self::SOURCE_EMAIL, $this->shippedPatterns()));
    }

    public function testGenericSubjectShowsAiTitle(): void
    {
        $patterns = $this->shippedPatterns();
        $this->assertSame('AI titulek', IncomingMessageTitle::display('Message from KM_C258', 'AI titulek', self::SOURCE_EMAIL, $patterns));
        $this->assertTrue(IncomingMessageTitle::usesAiTitle('Message from KM_C258', 'AI titulek', self::SOURCE_EMAIL, $patterns));
    }

    public function testEmptySubjectShowsAiTitle(): void
    {
        $this->assertSame('AI titulek', IncomingMessageTitle::display('', 'AI titulek', self::SOURCE_EMAIL, []));
        $this->assertSame('AI titulek', IncomingMessageTitle::display('   ', ' AI titulek ', self::SOURCE_EMAIL, []));
    }

    public function testManualSourceShowsAiTitleRegardlessOfSubject(): void
    {
        $this->assertSame(
            'Faktura 2026-0042 — Dodavatel s.r.o., 13 105 Kč',
            IncomingMessageTitle::display('faktura_final_v2.pdf', 'Faktura 2026-0042 — Dodavatel s.r.o., 13 105 Kč', IncomingMessageTitle::SOURCE_MANUAL, []),
        );
    }

    public function testWithoutAiTitleSubjectAlwaysWins(): void
    {
        $patterns = $this->shippedPatterns();
        $this->assertSame('Message from KM_C258', IncomingMessageTitle::display('Message from KM_C258', null, self::SOURCE_EMAIL, $patterns));
        $this->assertSame('Message from KM_C258', IncomingMessageTitle::display('Message from KM_C258', '  ', self::SOURCE_EMAIL, $patterns));
        $this->assertSame('scan.pdf', IncomingMessageTitle::display('scan.pdf', null, IncomingMessageTitle::SOURCE_MANUAL, $patterns));
        $this->assertSame('', IncomingMessageTitle::display('', null, self::SOURCE_EMAIL, $patterns));
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function subjectProvider(): array
    {
        return [
            'MFP message'             => ['Message from KM_C258', true],
            'MFP message lowercase'   => ['message from printer', true],
            'scan from'               => ['Scan from MFP-Office', true],
            'scanned document'        => ['Scanned Document 2026-09-01', true],
            'scan colon'              => ['Scan: 20260901', true],
            'scan underscore number'  => ['Scan_0012', true],
            'scan number'             => ['Scan0012.pdf', true],
            'czech scan'              => ['Skenováno z kancelářské tiskárny', true],
            'image file'              => ['IMG_2031.jpg', true],
            'doc file'                => ['DOC-0004', true],
            'attached image'          => ['Attached Image', true],
            'bare fwd'                => ['Fwd:', true],
            'no subject'              => ['(no subject)', true],
            'czech no subject'        => ['bez předmětu', true],
            'invoice subject'         => ['Faktura 2026-0042', false],
            'scanner maintenance'     => ['Scanner maintenance contract', false],
            'fwd with content'        => ['Fwd: Faktura 2026-0042', false],
            'document word'           => ['Dokumentace k projektu', false],
            'image in sentence'       => ['Image rights agreement', false],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('subjectProvider')]
    public function testShippedPatterns(string $subject, bool $generic): void
    {
        $this->assertSame($generic, IncomingMessageTitle::isGeneric($subject, $this->shippedPatterns()), $subject);
    }

    public function testInvalidPatternIsSkippedNotFatal(): void
    {
        $patterns = ['(unclosed', '^Message from '];
        $this->assertTrue(IncomingMessageTitle::isGeneric('Message from X', $patterns));
        $this->assertFalse(IncomingMessageTitle::isGeneric('Faktura', $patterns));
        // Neřetězcové položky se ignorují.
        $this->assertFalse(IncomingMessageTitle::isGeneric('Faktura', [42, null, '']));
    }

    public function testTildeInPatternIsEscaped(): void
    {
        $this->assertTrue(IncomingMessageTitle::isGeneric('~scan~', ['^~scan~$']));
    }

    public function testPatternsFromConfig(): void
    {
        $this->assertSame([], IncomingMessageTitle::patternsFrom(null));

        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnMap([
            [IncomingMessageTitle::CFG_ITEM, ['^Message from ', '', 7, '^Scan']],
        ]);
        $this->assertSame(['^Message from ', '^Scan'], IncomingMessageTitle::patternsFrom($config));

        $broken = $this->createMock(ConfigRuntime::class);
        $broken->method('cfgItem')->willReturn('not-a-list');
        $this->assertSame([], IncomingMessageTitle::patternsFrom($broken));
    }
}
