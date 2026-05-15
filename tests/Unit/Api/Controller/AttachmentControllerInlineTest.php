<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Api\Controller;

use PHPUnit\Framework\TestCase;
use Shipard\Api\Controller\AttachmentController;
use Shipard\Core\Database\DataSourceConnection;

/**
 * Phase 3a — Content-Disposition derivation for AttachmentController.
 *
 * The `download` flow streams the file with `readfile()` + `exit`, which
 * is not testable end-to-end in PHPUnit. We instead unit-test the
 * decision helper {@see AttachmentController::computeDisposition()},
 * which encapsulates the entire allowlist policy. The streaming path is
 * exercised manually in M5 QA.
 */
class AttachmentControllerInlineTest extends TestCase
{
    private function controller(): AttachmentController
    {
        $db = $this->createMock(DataSourceConnection::class);
        return new AttachmentController($db, sys_get_temp_dir(), []);
    }

    public function testNoInlineRequestedReturnsAttachment(): void
    {
        $this->assertSame('attachment', $this->controller()->computeDisposition('application/pdf', false));
        $this->assertSame('attachment', $this->controller()->computeDisposition('image/jpeg', false));
        $this->assertSame('attachment', $this->controller()->computeDisposition('text/html', false));
    }

    public function testInlineAllowedForPdf(): void
    {
        $this->assertSame('inline', $this->controller()->computeDisposition('application/pdf', true));
    }

    public function testInlineAllowedForImages(): void
    {
        $this->assertSame('inline', $this->controller()->computeDisposition('image/jpeg', true));
        $this->assertSame('inline', $this->controller()->computeDisposition('image/png', true));
        $this->assertSame('inline', $this->controller()->computeDisposition('image/svg+xml', true));
    }

    public function testInlineDowngradedForHtml(): void
    {
        // Inline HTML could XSS via the same-origin iframe — must downgrade.
        $this->assertSame('attachment', $this->controller()->computeDisposition('text/html', true));
    }

    public function testInlineDowngradedForOtherTypes(): void
    {
        $this->assertSame('attachment', $this->controller()->computeDisposition('application/zip', true));
        $this->assertSame('attachment', $this->controller()->computeDisposition('text/plain', true));
        $this->assertSame('attachment', $this->controller()->computeDisposition('application/json', true));
    }
}
