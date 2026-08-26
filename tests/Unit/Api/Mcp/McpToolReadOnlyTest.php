<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Api\Mcp;

use PHPUnit\Framework\TestCase;
use Shipard\Api\Mcp\FeedCardsTool;
use Shipard\Module\Base\Persons\Mcp\PersonsGetTool;
use Shipard\Module\Base\Persons\Mcp\PersonsSearchTool;
use Shipard\Module\Base\Registry\Mcp\RegistrySearchTool;
use Shipard\Module\Core\Help\Mcp\HelpGetPageTool;
use Shipard\Module\Core\Help\Mcp\HelpSearchTool;
use Shipard\Module\Core\Mail\Mcp\MailDraftDocumentTool;
use Shipard\Module\Core\Mail\Mcp\MailListPendingTool;
use Shipard\Module\Docs\Core\Mcp\DocumentsAggregateTool;
use Shipard\Module\Docs\Core\Mcp\DocumentsSearchTool;
use Shipard\Module\Economy\Accounting\Mcp\ReportListTool;
use Shipard\Module\Economy\Accounting\Mcp\ReportRunTool;

/**
 * The chat tool-use loop offers the model only read-only tools. These assert
 * the read-tier classification each tool declares.
 */
class McpToolReadOnlyTest extends TestCase
{
    public function testReadToolsAreReadOnly(): void
    {
        $this->assertTrue((new PersonsSearchTool())->isReadOnly());
        $this->assertTrue((new PersonsGetTool())->isReadOnly());
        $this->assertTrue((new DocumentsSearchTool())->isReadOnly());
        $this->assertTrue((new DocumentsAggregateTool())->isReadOnly());
        $this->assertTrue((new MailListPendingTool())->isReadOnly());
        $this->assertTrue((new RegistrySearchTool())->isReadOnly());
        $this->assertTrue((new HelpSearchTool())->isReadOnly());
        $this->assertTrue((new HelpGetPageTool())->isReadOnly());
        $this->assertTrue((new ReportListTool())->isReadOnly());
        $this->assertTrue((new ReportRunTool())->isReadOnly());
        $this->assertTrue((new FeedCardsTool())->isReadOnly());
    }

    public function testDraftToolIsNotReadOnly(): void
    {
        $this->assertFalse((new MailDraftDocumentTool(null))->isReadOnly());
    }
}
