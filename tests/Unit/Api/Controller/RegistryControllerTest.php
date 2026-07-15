<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Api\Controller;

use PHPUnit\Framework\TestCase;
use Shipard\Api\AuthContext;
use Shipard\Api\Controller\RegistryController;
use Shipard\Api\Response;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Base\Registry\ExtractedTextFiller;
use Shipard\Module\Base\Registry\FileFromMessageService;

/**
 * Unit testy extract-text akce (fromMessage kryje integrační
 * RegistryFromMessageTest — HTTP slupka je tenká a stejná).
 */
class RegistryControllerTest extends TestCase
{
    private function controller(?DataSourceConnection $db = null, ?ExtractedTextFiller $filler = null): RegistryController
    {
        return new RegistryController(
            $this->createMock(FileFromMessageService::class),
            $filler ?? $this->createMock(ExtractedTextFiller::class),
            $db ?? $this->createMock(DataSourceConnection::class),
        );
    }

    private function statusOf(Response $response): int
    {
        $ref = new \ReflectionClass($response);
        $prop = $ref->getProperty('status');
        return (int) $prop->getValue($response);
    }

    public function testExtractTextRequiresAuth(): void
    {
        $response = $this->controller()->extractText(AuthContext::anonymous(), 5);
        $this->assertSame(401, $this->statusOf($response));
    }

    public function testExtractTextReturns404ForMissingDocument(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn(null);

        $response = $this->controller($db)->extractText(new AuthContext(true, 1), 999);

        $this->assertSame(404, $this->statusOf($response));
        $this->assertSame('NOT_FOUND', $response->getPayload()['error']['code']);
    }

    public function testExtractTextReturns404ForTrashedDocument(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn(['id' => 5, 'docState' => 90]);

        $response = $this->controller($db)->extractText(new AuthContext(true, 1), 5);

        $this->assertSame(404, $this->statusOf($response));
    }

    public function testExtractTextRegeneratesAndReturnsStats(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn(['id' => 5, 'docState' => 40]);

        $filler = $this->createMock(ExtractedTextFiller::class);
        $filler->expects($this->once())
            ->method('fill')
            ->with(5, true)
            ->willReturn(['chars' => 1234, 'attachments' => 2]);

        $response = $this->controller($db, $filler)->extractText(new AuthContext(true, 1), 5);

        $this->assertSame(200, $this->statusOf($response));
        $this->assertSame(['chars' => 1234, 'attachments' => 2], $response->getPayload()['data']);
    }
}
