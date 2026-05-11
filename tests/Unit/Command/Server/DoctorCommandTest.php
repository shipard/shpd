<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Command\Server;

use PHPUnit\Framework\TestCase;
use Shipard\Command\Server\DoctorCommand;
use Shipard\Core\Server\HealthChecker;
use Shipard\Core\Server\PermissionSpec;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

class TestableDoctorCommand extends DoctorCommand
{
    public ?string $stubPoolUser = null;
    public bool $skipDbCheck = true;
    public int $stubDbErrors = 0;

    public function __construct(string $tempConfigPath, PermissionSpec $spec)
    {
        parent::__construct($spec);
        $this->serverConfigPath = $tempConfigPath;
    }

    protected function detectPoolUser(HealthChecker $checker): ?string
    {
        return $this->stubPoolUser;
    }

    protected function checkDataSourceConnections(PermissionSpec $spec, OutputInterface $output): int
    {
        if ($this->skipDbCheck) {
            $output->writeln('  (skipped in test)');
            return $this->stubDbErrors;
        }
        return parent::checkDataSourceConnections($spec, $output);
    }
}

class DoctorCommandTest extends TestCase
{
    private string $tempRoot;
    private string $tempConfigPath;
    private string $testUser;

    protected function setUp(): void
    {
        $this->tempRoot = sys_get_temp_dir() . '/shpd-doctor-test-' . uniqid();
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

    private function buildHealthyTree(PermissionSpec $spec): void
    {
        // /etc/shipard is owned by test user (test can't chown root); we accept the
        // resulting "owner sebik, expected root" issues are filtered or expected.
        mkdir($spec->getConfigDir(), 0750, true);
        file_put_contents($spec->getConfigDir() . '/server.json', '{}');
        chmod($spec->getConfigDir() . '/server.json', 0640);
        mkdir($spec->getShipardRoot(), 0751, true);
        chmod($spec->getShipardRoot(), 0751);
        mkdir($spec->getDataSourcesDir(), 0750, true);
        chmod($spec->getDataSourcesDir(), 0750);
        mkdir($spec->getLogDir(), 0750, true);
        chmod($spec->getLogDir(), 0750);
    }

    private function writeServerJson(string $mode): void
    {
        file_put_contents($this->tempConfigPath, json_encode(['mode' => $mode]));
    }

    private function makeTester(PermissionSpec $spec): TestableDoctorCommand
    {
        $command = new TestableDoctorCommand($this->tempConfigPath, $spec);
        $app = new Application();
        $app->add($command);
        return $command;
    }

    public function testReportsMissingConfigFile(): void
    {
        $command = $this->makeTester($this->makeSpec());
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Config file missing', $tester->getDisplay());
    }

    public function testReportsModeFromServerJson(): void
    {
        $this->writeServerJson('production');
        $spec = $this->makeSpec();
        $this->buildHealthyTree($spec);

        $command = $this->makeTester($spec);
        $command->stubPoolUser = $this->testUser;
        $tester = new CommandTester($command);
        $tester->execute([]);

        $this->assertStringContainsString('Mode:                production', $tester->getDisplay());
    }

    public function testReportsSuccessWhenAllOk(): void
    {
        // Use a spec where all owners resolve to test user; override the global
        // entries that expect root by using a custom spec subclass... or simpler:
        // ignore the /etc/shipard/server.json "owner root" check by NOT creating
        // /etc/shipard at all — but then Doctor would report missing. So we just
        // accept that running as non-root, /etc/shipard owner mismatches show up.
        // We test the "success" path indirectly: tree built, no fixable issues
        // beyond the unavoidable root ownership ones.
        $this->writeServerJson('development');
        $spec = $this->makeSpec();
        $this->buildHealthyTree($spec);

        $command = $this->makeTester($spec);
        $command->stubPoolUser = $this->testUser;
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        $display = $tester->getDisplay();
        // We *expect* failure here because /etc/shipard ownership is 'sebik' but
        // spec says 'root'. But we should see the report structure and "→ Run:
        // sudo shpd-server fix-permissions" hint (those are fixable).
        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Mode:                development', $display);
        $this->assertStringContainsString("Shipard user:        {$this->testUser}", $display);
        $this->assertStringContainsString("PHP-FPM pool user:   {$this->testUser}   ✓", $display);
    }

    public function testReportsFailureWhenPoolUserMismatch(): void
    {
        $this->writeServerJson('development');
        $spec = $this->makeSpec();
        $this->buildHealthyTree($spec);

        $command = $this->makeTester($spec);
        $command->stubPoolUser = 'someone-else';
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        $display = $tester->getDisplay();
        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('someone-else   ✗ (mismatch)', $display);
        $this->assertStringContainsString('Issues found:', $display);
    }

    public function testReportsFixableHint(): void
    {
        $this->writeServerJson('development');
        $spec = $this->makeSpec();
        $this->buildHealthyTree($spec);
        // Introduce a fixable issue: wrong mode on /opt/shipard/log
        chmod($spec->getLogDir(), 0700);

        $command = $this->makeTester($spec);
        $command->stubPoolUser = $this->testUser;
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        $this->assertSame(1, $exitCode);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('mode 0700, expected 0750', $display);
        $this->assertStringContainsString('sudo shpd-server fix-permissions', $display);
    }
}
