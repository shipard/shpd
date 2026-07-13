<?php

declare(strict_types=1);

namespace Shipard\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Shipard\Api\TableLoader;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Module\ModulePathResolver;

abstract class IntegrationTestCase extends TestCase
{
    /** Path used by file storage during the test (isolated temp dir, not the real DS att/). */
    protected string $dsPath;
    /** Real DS path as configured by the env var (for DB + config access). */
    protected string $realDsPath;
    protected DataSourceConfig $dsConfig;
    protected DataSourceConnection $db;
    /** @var array<string, \Shipard\Core\Database\TableDefinition> */
    protected array $tables;

    /**
     * Table definitions per DS path + language. Definitions come from repo
     * files and tests never mutate them — one load per (DS, language) for
     * the whole run instead of one per test method. Intentionally never
     * freed.
     *
     * @var array<string, array<string, \Shipard\Core\Database\TableDefinition>>
     */
    private static array $tablesCache = [];

    protected function setUp(): void
    {
        $path = getenv('SHIPARD_INTEGRATION_DS_PATH');
        if ($path === false || $path === '' || !is_dir($path . '/config')) {
            $this->markTestSkipped(
                'Integration tests require SHIPARD_INTEGRATION_DS_PATH env var pointing to a valid data source directory.',
            );
        }

        $this->realDsPath = $path;
        // Isolate file storage into a temp dir so tests don't collide with files
        // owned by www-data or root in the real DS's att/ folder.
        $this->dsPath = sys_get_temp_dir() . '/shpd_it_' . uniqid('', true);
        mkdir($this->dsPath . '/att', 0755, true);
        mkdir($this->dsPath . '/cache/thumbnails', 0755, true);

        $this->dsConfig = new DataSourceConfig($path);
        $this->db = new DataSourceConnection($this->dsConfig);

        $cacheKey = $path . '|cs';
        if (!isset(self::$tablesCache[$cacheKey])) {
            $modulePathResolver = new ModulePathResolver([dirname(__DIR__, 2) . '/modules']);
            self::$tablesCache[$cacheKey] = TableLoader::load($this->dsConfig, $modulePathResolver, 'cs');
        }
        $this->tables = self::$tablesCache[$cacheKey];
    }

    final protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->onTearDown();
            $this->db->disconnect();
        }
        if (isset($this->dsPath) && is_dir($this->dsPath)) {
            $this->rmTree($this->dsPath);
        }
        unset($this->db, $this->tables, $this->dsConfig);
        parent::tearDown();
    }

    /** Subclass cleanup; runs ONLY when setUp completed ($db exists). */
    protected function onTearDown(): void
    {
    }

    private function rmTree(string $dir): void
    {
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) && !is_link($path) ? $this->rmTree($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
