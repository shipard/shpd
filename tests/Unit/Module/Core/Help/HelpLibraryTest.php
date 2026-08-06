<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Help;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Help\HelpLibrary;

/**
 * Knihovna uživatelské dokumentace nad dočasným adresářem — testy nesahají
 * na skutečné help/, aby nepadaly při každé úpravě obsahu.
 */
class HelpLibraryTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/shpd-help-' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/posta', 0777, true);

        file_put_contents($this->dir . '/slovnicek.md', <<<MD
        ---
        title: Slovníček
        summary: Co která věc znamená.
        keywords: [slovníček, pojmy, jistota]
        related: [posta/kontrola.md]
        ---

        # Slovníček

        Tělo slovníčku, zmiňuje storno dokladu.
        MD);

        file_put_contents($this->dir . '/posta/kontrola.md', <<<MD
        ---
        title: Kontrola vytěženého dokladu
        summary: Jak porovnat návrh s fakturou.
        keywords: [kontrola, vytěžení, AI přečetla špatně]
        ---

        # Kontrola vytěženého dokladu

        Postup kontroly.
        MD);

        // Rozcestník není stránka dokumentace.
        file_put_contents($this->dir . '/README.md', "# Rozcestník\n");
        // Soubor bez hlavičky se přeskočí.
        file_put_contents($this->dir . '/rozbite.md', "# Bez hlavicky\n");
    }

    protected function tearDown(): void
    {
        foreach (['slovnicek.md', 'README.md', 'rozbite.md', 'posta/kontrola.md'] as $f) {
            @unlink($this->dir . '/' . $f);
        }
        @rmdir($this->dir . '/posta');
        @rmdir($this->dir);
    }

    private function library(): HelpLibrary
    {
        return new HelpLibrary($this->dir);
    }

    public function testLoadsPagesAndSkipsReadmeAndPagesWithoutFrontMatter(): void
    {
        $paths = array_map(static fn($p) => $p->path, $this->library()->pages());
        $this->assertSame(['posta/kontrola.md', 'slovnicek.md'], $paths);
    }

    public function testParsesFrontMatter(): void
    {
        $page = $this->library()->page('slovnicek.md');
        $this->assertNotNull($page);
        $this->assertSame('Slovníček', $page->title);
        $this->assertSame('Co která věc znamená.', $page->summary);
        $this->assertSame(['slovníček', 'pojmy', 'jistota'], $page->keywords);
        $this->assertSame(['posta/kontrola.md'], $page->related);
        $this->assertStringContainsString('Tělo slovníčku', $page->body);
        $this->assertStringNotContainsString('summary:', $page->body);
    }

    public function testSearchIgnoresDiacriticsAndCase(): void
    {
        $hits = $this->library()->search('VYTEZENI');
        $this->assertNotEmpty($hits);
        $this->assertSame('posta/kontrola.md', $hits[0]['page']->path);
    }

    public function testKeywordBeatsBodyMention(): void
    {
        // „jistota" je klíčové slovo slovníčku; „storno" je jen v jeho těle.
        $byKeyword = $this->library()->search('jistota')[0]['score'];
        $byBody    = $this->library()->search('storno')[0]['score'];
        $this->assertGreaterThan($byBody, $byKeyword);
    }

    public function testSearchWithoutMatchReturnsNothing(): void
    {
        $this->assertSame([], $this->library()->search('kontrolní hlášení'));
    }

    /**
     * Regrese: číselný token v dotazu se v PHP stane int klíčem pole —
     * bez přetypování spadne str_contains() na TypeError. Uživatel se
     * přitom na čísla ptá běžně („účet 343“).
     */
    public function testNumericQueryTokenDoesNotCrash(): void
    {
        $hits = $this->library()->search('jistota 343');

        $this->assertCount(1, $hits);
        $this->assertSame('slovnicek.md', $hits[0]['page']->path);
    }

    public function testQueryWithOnlyNumbersReturnsNothing(): void
    {
        $this->assertSame([], $this->library()->search('343 518'));
    }

    public function testShortQueryTokensAreIgnored(): void
    {
        $this->assertSame([], $this->library()->search('a v'));
    }

    public function testPageRejectsTraversal(): void
    {
        $this->assertNull($this->library()->page('../composer.json'));
        $this->assertNull($this->library()->page('posta/../../composer.json'));
    }

    public function testPageReturnsNullForUnknownPath(): void
    {
        $this->assertNull($this->library()->page('neexistuje.md'));
    }

    public function testMissingDirectoryDegradesToEmpty(): void
    {
        $this->assertSame([], (new HelpLibrary($this->dir . '/nic'))->pages());
    }
}
