<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Command\Server;

use PHPUnit\Framework\TestCase;
use Shipard\Command\Server\FixPermissionsCommand;
use Shipard\Core\Server\PermissionSpec;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class TestableFixPermissionsCommand extends FixPermissionsCommand
{
    public bool $rootResult = false;

    public function __construct(string $tempConfigPath, PermissionSpec $spec)
    {
        parent::__construct($spec);
        $this->serverConfigPath = $tempConfigPath;
    }

    protected function isRoot(): bool
    {
        return $this->rootResult;
    }
}

class FixPermissionsCommandTest extends TestCase
{
    private string $tempRoot;
    private string $tempConfigPath;
    private string $testUser;

    protected function setUp(): void
    {
        $this->tempRoot = sys_get_temp_dir() . '/shpd-fix-test-' . uniqid();
        mkdir($this->tempRoot, 0750, true);
        $this->tempConfigPath = $this->tempRoot . '/server.json';
        $info = posix_getpwuid(posix_getuid());
        $this->testUser = $info['name'];
    }

    protected function tearDown(): void
    {
        $this->recursiveDelete($this->tempRoot);
    }

    private function recursiveDelete(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }
        if (is_file($path) || is_link($path)) {
            @chmod($path, 0700);
            @unlink($path);
            return;
        }
        @chmod($path, 0700);
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->recursiveDelete($path . '/' . $entry);
        }
        @rmdir($path);
    }

    private function makeSpec(): PermissionSpec
    {
        return new PermissionSpec(
            shipardUser: $this->testUser,
            dataSourcesDir: $this->tempRoot . '/opt/shipard/data-sources',
            logDir: $this->tempRoot . '/opt/shipard/log',
            configDir: $this->tempRoot . '/etc/shipard',
            shipardRoot: $this->tempRoot . '/opt/shipard',
        );
    }

    private function buildTreeWithBrokenMode(PermissionSpec $spec): string
    {
        mkdir($spec->getConfigDir(), 0750, true);
        file_put_contents($spec->getConfigDir() . '/server.json', '{}');
        chmod($spec->getConfigDir() . '/server.json', 0640);
        mkdir($spec->getShipardRoot(), 0751, true);
        chmod($spec->getShipardRoot(), 0751);
        mkdir($spec->getDataSourcesDir(), 0750, true);
        chmod($spec->getDataSourcesDir(), 0750);
        mkdir($spec->getLogDir(), 0750, true);
        chmod($spec->getLogDir(), 0750);

        // DS with broken mode on secrets.key
        $dsDir = $spec->getDataSourcesDir() . '/aaaa-bbbb-cccc-dddd';
        mkdir($dsDir . '/config', 0750, true);
        chmod($dsDir, 0750);
        chmod($dsDir . '/config', 0750);
        file_put_contents($dsDir . '/config/main.json', '{}');
        chmod($dsDir . '/config/main.json', 0600);
        mkdir($dsDir . '/secrets', 0700, true);
        file_put_contents($dsDir . '/secrets/secrets.key', "key");
        chmod($dsDir . '/secrets/secrets.key', 0644);   // should be 0600

        return $dsDir;
    }

    private function writeServerJson(string $mode = 'development'): void
    {
        file_put_contents($this->tempConfigPath, json_encode(['mode' => $mode]));
    }

    public function testRequiresRootWhenNotDryRun(): void
    {
        $this->writeServerJson();
        $spec = $this->makeSpec();
        $this->buildTreeWithBrokenMode($spec);

        $command = new TestableFixPermissionsCommand($this->tempConfigPath, $spec);
        $command->rootResult = false;

        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('must be run as root', $tester->getDisplay());
    }

    public function testDryRunAllowedWithoutRoot(): void
    {
        $this->writeServerJson();
        $spec = $this->makeSpec();
        $dsDir = $this->buildTreeWithBrokenMode($spec);

        $command = new TestableFixPermissionsCommand($this->tempConfigPath, $spec);
        $command->rootResult = false;

        $tester = new CommandTester($command);
        $exitCode = $tester->execute(['--dry-run' => true]);

        $this->assertSame(0, $exitCode);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('mode 0644, expected 0600', $display);
        $this->assertStringContainsString('--dry-run: no changes applied', $display);
        // Verify nothing changed
        $perms = fileperms($dsDir . '/secrets/secrets.key') & 0777;
        $this->assertSame(0644, $perms);
    }

    public function testListsPlannedChanges(): void
    {
        $this->writeServerJson();
        $spec = $this->makeSpec();
        $this->buildTreeWithBrokenMode($spec);

        $command = new TestableFixPermissionsCommand($this->tempConfigPath, $spec);
        $command->rootResult = false;

        $tester = new CommandTester($command);
        $exitCode = $tester->execute(['--dry-run' => true]);

        $this->assertSame(0, $exitCode);
        $display = $tester->getDisplay();
        $this->assertMatchesRegularExpression('/Will apply \d+ changes/', $display);
        $this->assertStringContainsString('mode 0644, expected 0600', $display);
    }

    public function testAppliesFixableModeChange(): void
    {
        $this->writeServerJson();
        $spec = $this->makeSpec();
        $dsDir = $this->buildTreeWithBrokenMode($spec);

        $command = new TestableFixPermissionsCommand($this->tempConfigPath, $spec);
        $command->rootResult = true;  // pretend root so the apply branch runs

        $tester = new CommandTester($command);
        $exitCode = $tester->execute(['--force' => true]);

        $display = $tester->getDisplay();
        // chmod by file owner (test user) works without real root
        $this->assertStringContainsString('chmod 0600', $display);
        $this->assertSame(0, $exitCode);
        $perms = fileperms($dsDir . '/secrets/secrets.key') & 0777;
        $this->assertSame(0600, $perms);
    }

    public function testReportsMissingConfigFile(): void
    {
        $spec = $this->makeSpec();

        $command = new TestableFixPermissionsCommand($this->tempConfigPath, $spec);
        $command->rootResult = true;

        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Config file missing', $tester->getDisplay());
    }

    public function testRecursiveChgrpFixesNestedContent(): void
    {
        // Create a tree owned by test user with a planted file. Then construct
        // a spec that expects a *different* group → recursive walk should
        // chgrp the planted file (chown to another user requires real root,
        // but chgrp to a group the test user belongs to works).
        $this->writeServerJson();

        // Find a secondary group the test user belongs to (other than primary)
        $info = posix_getpwuid(posix_getuid());
        $secondaryGroup = $this->findSecondaryGroup($info['name']);
        if ($secondaryGroup === null) {
            $this->markTestSkipped('test user has no secondary groups for chgrp test');
        }

        $spec = new PermissionSpec(
            shipardUser: $this->testUser,           // owner stays correct
            dataSourcesDir: $this->tempRoot . '/opt/shipard/data-sources',
            logDir: $this->tempRoot . '/opt/shipard/log',
            configDir: $this->tempRoot . '/etc/shipard',
            shipardRoot: $this->tempRoot . '/opt/shipard',
        );
        // Build a healthy tree
        mkdir($spec->getConfigDir(), 0750, true);
        file_put_contents($spec->getConfigDir() . '/server.json', '{}');
        chmod($spec->getConfigDir() . '/server.json', 0640);
        mkdir($spec->getShipardRoot(), 0751, true);
        chmod($spec->getShipardRoot(), 0751);
        mkdir($spec->getDataSourcesDir(), 0750, true);
        chmod($spec->getDataSourcesDir(), 0750);
        mkdir($spec->getLogDir(), 0750, true);
        chmod($spec->getLogDir(), 0750);
        $dsDir = $spec->getDataSourcesDir() . '/aaaa-bbbb-cccc-dddd';
        mkdir($dsDir . '/config', 0750, true);
        chmod($dsDir, 0750);
        chmod($dsDir . '/config', 0750);
        file_put_contents($dsDir . '/config/main.json', '{}');
        chmod($dsDir . '/config/main.json', 0600);
        mkdir($dsDir . '/att', 0750, true);
        chmod($dsDir . '/att', 0750);

        // Plant a file inside att/ and chgrp it to the secondary group
        // (so it doesn't match the primary group the spec expects).
        $planted = $dsDir . '/att/upload.bin';
        file_put_contents($planted, 'x');
        chgrp($planted, $secondaryGroup);
        $this->assertSame(
            $secondaryGroup,
            posix_getgrgid(stat($planted)['gid'])['name'],
            'precondition: planted file has secondary group',
        );

        $command = new TestableFixPermissionsCommand($this->tempConfigPath, $spec);
        $command->rootResult = true;

        $tester = new CommandTester($command);
        $exitCode = $tester->execute(['--force' => true]);

        $this->assertSame(0, $exitCode);
        $this->assertSame(
            $this->testUser,
            posix_getgrgid(stat($planted)['gid'])['name'],
            'planted file group was not fixed by recursive walk',
        );
    }

    private function findSecondaryGroup(string $user): ?string
    {
        $out = [];
        $rc = 0;
        exec('id -Gn ' . escapeshellarg($user) . ' 2>/dev/null', $out, $rc);
        if ($rc !== 0 || empty($out)) {
            return null;
        }
        $groups = preg_split('/\s+/', $out[0], -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($groups) < 2) {
            return null;   // user only has primary group
        }
        // Primary group name = user's primary gid name; secondary = anything else
        $info = posix_getpwnam($user);
        $primary = posix_getgrgid($info['gid'])['name'] ?? null;
        foreach ($groups as $g) {
            if ($g !== $primary) {
                return $g;
            }
        }
        return null;
    }
}
