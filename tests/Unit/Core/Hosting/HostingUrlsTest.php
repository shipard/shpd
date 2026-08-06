<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Hosting;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Hosting\HostingUrls;

class HostingUrlsTest extends TestCase
{
    public function testAiGwBaseUrlFromProductionIssuer(): void
    {
        $this->assertSame(
            'https://portal.example.com/api/v1/_hosting/ai-gw',
            HostingUrls::aiGwBaseUrl('https://portal.example.com/api/v1/_hosting/oidc'),
        );
    }

    public function testAiGwBaseUrlFromDevIssuerWithTrailingSlash(): void
    {
        $this->assertSame(
            'http://127.0.0.1/gggg-gggg-gggg-gggg/api/v1/_hosting/ai-gw',
            HostingUrls::aiGwBaseUrl('http://127.0.0.1/gggg-gggg-gggg-gggg/api/v1/_hosting/oidc/'),
        );
    }
}
