<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Help\Mcp;

use PHPUnit\Framework\TestCase;
use Shipard\Api\AuthContext;
use Shipard\Api\Mcp\McpInvocationContext;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Core\Help\HelpLibrary;
use Shipard\Module\Core\Help\Mcp\HelpGetPageTool;
use Shipard\Module\Core\Help\Mcp\HelpSearchTool;

/**
 * Nástroje nad skutečným help/ v repozitáři — zdrojem pravdy je obsah, který
 * uživatel čte na GitHubu, takže testy záměrně nesahají po fixtures. Tvrzení
 * jsou proto vázaná jen na to, co je konvencí (existence slovníčku, formát
 * obálky), ne na konkrétní text stránek.
 */
class HelpToolsTest extends TestCase
{
    private function ctx(): McpInvocationContext
    {
        // Nástroje nesahají do DB ani na auth, kontext je jen povinný argument
        // rozhraní McpTool — proto stačí stub spojení.
        return new McpInvocationContext(
            new AuthContext(true, 1, 'api_key'),
            $this->createStub(DataSourceConnection::class),
            [],
            null,
        );
    }

    private function library(): HelpLibrary
    {
        return HelpLibrary::default();
    }

    public function testBothToolsAreReadOnly(): void
    {
        $this->assertTrue((new HelpSearchTool())->isReadOnly());
        $this->assertTrue((new HelpGetPageTool())->isReadOnly());
    }

    public function testToolNames(): void
    {
        $this->assertSame('help_search', (new HelpSearchTool())->name());
        $this->assertSame('help_get_page', (new HelpGetPageTool())->name());
    }

    public function testInputSchemasRequireTheirArgument(): void
    {
        $this->assertSame(['query'], (new HelpSearchTool())->inputSchema()['required']);
        $this->assertSame(['path'], (new HelpGetPageTool())->inputSchema()['required']);
    }

    public function testDefaultLibraryFindsRepositoryHelpPages(): void
    {
        $paths = array_map(static fn($p) => $p->path, $this->library()->pages());
        $this->assertContains('slovnicek.md', $paths);
        $this->assertContains('co-dnes-nejde.md', $paths);
    }

    public function testSearchReturnsEnvelopeWithRefAndSummary(): void
    {
        $env = (new HelpSearchTool($this->library()))->call(['query' => 'slovníček'], $this->ctx());

        $this->assertNotSame([], $env['items']);
        $this->assertSame('help_page', $env['items'][0]['ref']['type']);
        $this->assertSame('slovnicek.md', $env['items'][0]['ref']['id']);
        $this->assertNotSame('', $env['items'][0]['label']);
        $this->assertSame(count($env['items']), $env['pagination']['returned']);
    }

    public function testSearchWithoutMatchTellsModelNotToGuess(): void
    {
        $env = (new HelpSearchTool($this->library()))->call(
            ['query' => 'zzzznesmyslnydotaz'],
            $this->ctx(),
        );

        $this->assertSame([], $env['items']);
        $this->assertStringContainsString('dohadem', $env['summary']);
    }

    public function testSearchMissingQueryThrowsInvalidArgument(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new HelpSearchTool($this->library()))->call([], $this->ctx());
    }

    public function testGetPageReturnsFullBodyInTextField(): void
    {
        $env = (new HelpGetPageTool($this->library()))->call(
            ['path' => 'slovnicek.md'],
            $this->ctx(),
        );

        $this->assertCount(1, $env['items']);
        $this->assertStringContainsString('# Slovníček', $env['items'][0]['text']);
        $this->assertStringNotContainsString('summary:', $env['items'][0]['text']);
    }

    public function testGetPageUnknownPathReturnsGuidanceNotError(): void
    {
        $env = (new HelpGetPageTool($this->library()))->call(
            ['path' => 'neexistuje.md'],
            $this->ctx(),
        );

        $this->assertSame([], $env['items']);
        $this->assertStringContainsString('help_search', $env['summary']);
    }

    public function testGetPageMissingPathThrowsInvalidArgument(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new HelpGetPageTool($this->library()))->call([], $this->ctx());
    }
}
