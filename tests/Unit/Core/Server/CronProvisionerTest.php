<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Server;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Server\CronProvisioner;

class CronProvisionerTest extends TestCase
{
    public function testRenderTemplateContainsAllSlotsAndPaths(): void
    {
        $content = new CronProvisioner()->renderTemplate(
            '/usr/bin/php8.5',
            '/opt/shipard/shpd',
            'shipard',
            '/opt/shipard/log/cron.log',
        );

        foreach (CronProvisioner::SLOTS as $slot) {
            $this->assertStringContainsString(
                '/usr/bin/php8.5 /opt/shipard/shpd/bin/shpd-server cron --slot=' . $slot,
                $content,
            );
        }
        $this->assertStringContainsString('shipard', $content);
        $this->assertStringContainsString('>> /opt/shipard/log/cron.log 2>&1', $content);
        $this->assertStringContainsString('DO NOT EDIT', $content);
        $this->assertStringContainsString('* * * * *', $content);
        $this->assertStringContainsString('*/5 * * * *', $content);
        $this->assertStringContainsString('17 3 * * *', $content);
        $this->assertStringContainsString('43 4 * * 0', $content);
        $this->assertStringEndsWith("\n", $content);
    }

    public function testRenderTemplateIsDeterministic(): void
    {
        $p = new CronProvisioner();
        $this->assertSame(
            $p->renderTemplate('/usr/bin/php', '/repo', 'u'),
            $p->renderTemplate('/usr/bin/php', '/repo', 'u'),
        );
    }

    public function testParseTemplateVersionRoundtrip(): void
    {
        $content = new CronProvisioner()->renderTemplate('/usr/bin/php', '/repo', 'shipard');
        $this->assertSame(CronProvisioner::TEMPLATE_VERSION, CronProvisioner::parseTemplateVersion($content));
    }

    public function testParseTemplateVersionReturnsNullWithoutMarker(): void
    {
        $this->assertNull(CronProvisioner::parseTemplateVersion("* * * * * root /bin/true\n"));
        $this->assertNull(CronProvisioner::parseTemplateVersion(''));
    }

    public function testPathHelpers(): void
    {
        $this->assertSame('/opt/shipard/run/cron-minute.heartbeat', CronProvisioner::heartbeatPath('minute'));
        $this->assertSame('/opt/shipard/run/cron-weekly.lock', CronProvisioner::lockPath('weekly'));
        $this->assertSame('/tmp/run/cron-daily.heartbeat', CronProvisioner::heartbeatPath('daily', '/tmp/run'));
    }
}
