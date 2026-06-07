<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Api\Mcp;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Base\Persons\Mcp\PersonsGetTool;
use Shipard\Module\Base\Persons\Mcp\PersonsSearchTool;
use Shipard\Module\Core\Mail\Mcp\MailDraftDocumentTool;
use Shipard\Module\Core\Mail\Mcp\MailListPendingTool;
use Shipard\Module\Docs\Core\Mcp\DocumentsSearchTool;

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
        $this->assertTrue((new MailListPendingTool())->isReadOnly());
    }

    public function testDraftToolIsNotReadOnly(): void
    {
        $this->assertFalse((new MailDraftDocumentTool(null))->isReadOnly());
    }
}
