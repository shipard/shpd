<?php

declare(strict_types=1);

namespace Shipard\Core\Server;

/**
 * Jediný zdroj pravdy pro systémový cron: sloty, cesty k lock/heartbeat
 * souborům a (od kroku 2) šablona /etc/cron.d/shipard. Sdílí ji dispatcher
 * (`shpd-server cron`), generátor (`cron-install`) a doctor.
 */
final class CronProvisioner
{
    public const TEMPLATE_VERSION = 1;
    public const CRON_FILE = '/etc/cron.d/shipard';
    public const RUN_DIR = '/opt/shipard/run';

    public const SLOTS = ['minute', 'five-minutes', 'daily', 'weekly'];

    public static function heartbeatPath(string $slot, string $runDir = self::RUN_DIR): string
    {
        return $runDir . '/cron-' . $slot . '.heartbeat';
    }

    public static function lockPath(string $slot, string $runDir = self::RUN_DIR): string
    {
        return $runDir . '/cron-' . $slot . '.lock';
    }
}
