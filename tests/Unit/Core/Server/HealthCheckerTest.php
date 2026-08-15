<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Server;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Server\HealthChecker;
use Shipard\Core\Server\PermissionSpec;

class HealthCheckerTest extends TestCase
{
    private string $tempRoot;
    private string $testUser;

    protected function setUp(): void
    {
        $this->tempRoot = sys_get_temp_dir() . '/shpd-health-test-' . uniqid();
        mkdir($this->tempRoot, 0750, true);
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

    /**
     * PermissionSpec where 'user' resolves to the actual test user. Then the temp
     * tree we create matches the contract by default and we can introduce
     * mismatches one at a time.
     */
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

    /**
     * Build a tree that matches the contract: /etc/shipard, /opt/shipard,
     * data-sources, log. /etc/shipard is owned by root in production, but on
     * tests we can't chown to root — we override the spec to expect testUser
     * as owner for everything by constructing a custom set of entries via the
     * PermissionSpec subclass. Easier: override the global entries' "root"
     * owners by writing files via test user — and expect "owner=root" issue
     * to appear; for "all OK" tests we use a spec with all owners = user.
     */
    private function buildContractTree(PermissionSpec $spec, bool $withLogFile = false): void
    {
        mkdir($spec->getConfigDir(), 0750, true);
        chmod($spec->getConfigDir(), 0750);
        $this->writeFile($spec->getConfigDir() . '/server.json', '{}', 0640);
        mkdir($spec->getShipardRoot(), 0751, true);
        chmod($spec->getShipardRoot(), 0751);
        mkdir($spec->getDataSourcesDir(), 0750, true);
        chmod($spec->getDataSourcesDir(), 0750);
        mkdir($spec->getLogDir(), 0750, true);
        chmod($spec->getLogDir(), 0750);
        if ($withLogFile) {
            $this->writeFile($spec->getLogDir() . '/shipard.log', "", 0640);
        }
    }

    private function writeFile(string $path, string $content, int $mode): void
    {
        file_put_contents($path, $content);
        chmod($path, $mode);
    }

    private function createDsTree(PermissionSpec $spec, string $dsId, bool $withOptional = true): string
    {
        $dsDir = $spec->getDataSourcesDir() . '/' . $dsId;
        mkdir($dsDir . '/config', 0750, true);
        chmod($dsDir, 0750);
        chmod($dsDir . '/config', 0750);
        $this->writeFile($dsDir . '/config/main.json', '{}', 0600);
        if ($withOptional) {
            mkdir($dsDir . '/config/configuration', 0750, true);
            mkdir($dsDir . '/secrets', 0700, true);
            $this->writeFile($dsDir . '/secrets/secrets.key', "key\n", 0600);
            mkdir($dsDir . '/att', 0750, true);
            mkdir($dsDir . '/branding', 0750, true);
            mkdir($dsDir . '/cache/thumbnails', 0750, true);
            chmod($dsDir . '/cache', 0750);
        }
        return $dsDir;
    }

    /**
     * Filters out 'owner expected root' issues — the test user can't chown to
     * root, so those entries always appear "wrong" in tests. The tests still
     * cover everything else (group, mode, existence, type).
     *
     * @param list<array{severity: string, path: string, message: string, fixable: bool}> $issues
     * @return list<array{severity: string, path: string, message: string, fixable: bool}>
     */
    private function withoutRootOwnerIssues(array $issues): array
    {
        return array_values(array_filter(
            $issues,
            static fn($i) => !str_contains($i['message'], 'expected root'),
        ));
    }

    public function testReturnsNoIssuesWhenAllCorrect(): void
    {
        $spec = $this->makeSpec();
        $this->buildContractTree($spec, withLogFile: true);
        $this->createDsTree($spec, 'aaaa-bbbb-cccc-dddd');

        $checker = new HealthChecker($spec);
        $issues = $this->withoutRootOwnerIssues($checker->checkAll());

        $this->assertSame([], $issues, 'unexpected issues: ' . json_encode($issues));
    }

    public function testDetectsMissingMandatoryPath(): void
    {
        $spec = $this->makeSpec();
        // Don't create /opt/shipard at all
        mkdir($spec->getConfigDir(), 0750, true);
        $this->writeFile($spec->getConfigDir() . '/server.json', '{}', 0640);

        $checker = new HealthChecker($spec);
        $issues = $this->withoutRootOwnerIssues($checker->checkAll());

        $missing = array_filter($issues, static fn($i) => $i['message'] === 'does not exist');
        $this->assertGreaterThan(0, count($missing));
        foreach ($missing as $issue) {
            $this->assertFalse($issue['fixable'], "missing path '{$issue['path']}' should not be fixable");
        }
    }

    public function testIgnoresMissingOptionalPaths(): void
    {
        $spec = $this->makeSpec();
        $this->buildContractTree($spec);
        // DS without secrets/att/cache — those are optional
        $this->createDsTree($spec, 'aaaa-bbbb-cccc-dddd', withOptional: false);

        $checker = new HealthChecker($spec);
        $issues = $this->withoutRootOwnerIssues($checker->checkAll());

        $this->assertSame([], $issues);
    }

    public function testDetectsWrongMode(): void
    {
        $spec = $this->makeSpec();
        $this->buildContractTree($spec);
        $dsDir = $this->createDsTree($spec, 'aaaa-bbbb-cccc-dddd');
        // Break mode on secrets.key — should be 0600, set 0644
        chmod($dsDir . '/secrets/secrets.key', 0644);

        $checker = new HealthChecker($spec);
        $issues = $this->withoutRootOwnerIssues($checker->checkAll());

        // Dva nálezy: explicitní entry pro secrets.key + contentsMaxMode
        // check rekurzivního scanu secrets/.
        $modeIssues = array_values(array_filter($issues, static fn($i) => str_contains($i['message'], 'mode')));
        $this->assertCount(2, $modeIssues);
        foreach ($modeIssues as $issue) {
            $this->assertStringContainsString('secrets.key', $issue['path']);
        }
        $entryIssue = array_values(array_filter($modeIssues, static fn($i) => str_contains($i['message'], 'expected')))[0];
        $this->assertSame('mode 0644, expected 0600', $entryIssue['message']);
        $this->assertTrue($entryIssue['fixable']);
    }

    public function testDetectsWrongModeOnBrandingDir(): void
    {
        // Alfa incident: branding/ vytvořený pod rootem zůstal 0755 —
        // doctor ho musí hlásit jako fixable mode mismatch.
        $spec = $this->makeSpec();
        $this->buildContractTree($spec);
        $dsDir = $this->createDsTree($spec, 'aaaa-bbbb-cccc-dddd');
        chmod($dsDir . '/branding', 0755);

        $checker = new HealthChecker($spec);
        $issues = $this->withoutRootOwnerIssues($checker->checkAll());

        $brandingIssues = array_values(array_filter(
            $issues,
            static fn($i) => str_ends_with($i['path'], '/branding'),
        ));
        $this->assertCount(1, $brandingIssues, 'unexpected issues: ' . json_encode($issues));
        $this->assertSame('mode 0755, expected 0750', $brandingIssues[0]['message']);
        $this->assertTrue($brandingIssues[0]['fixable']);
    }

    public function testSecretsContentsModeAboveMaxIsReported(): void
    {
        $spec = $this->makeSpec();
        $this->buildContractTree($spec);
        $dsDir = $this->createDsTree($spec, 'aaaa-bbbb-cccc-dddd');
        // Soubor bez explicitní spec entry — pokrývá ho jen contentsMaxMode.
        $this->writeFile($dsDir . '/secrets/ai-gw-anthropic.key', 'sk-ant-x', 0640);

        $checker = new HealthChecker($spec);
        $issues = $this->withoutRootOwnerIssues($checker->checkAll());

        $modeIssues = array_values(array_filter(
            $issues,
            static fn($i) => str_ends_with($i['path'], '/secrets/ai-gw-anthropic.key'),
        ));
        $this->assertCount(1, $modeIssues, 'unexpected issues: ' . json_encode($issues));
        $this->assertStringContainsString('mode 0640 exceeds 0600', $modeIssues[0]['message']);
        $this->assertStringContainsString('chmod 0600', $modeIssues[0]['message']);
    }

    public function testSecretsContentsModeAtMaxIsClean(): void
    {
        $spec = $this->makeSpec();
        $this->buildContractTree($spec);
        $dsDir = $this->createDsTree($spec, 'aaaa-bbbb-cccc-dddd');
        $this->writeFile($dsDir . '/secrets/ai-gw-anthropic.key', 'sk-ant-x', 0600);
        // Přísnější než max je taky v pořádku.
        $this->writeFile($dsDir . '/secrets/oidc-op.key', 'pem', 0400);

        $checker = new HealthChecker($spec);
        $issues = $this->withoutRootOwnerIssues($checker->checkAll());

        $this->assertSame([], $issues, 'unexpected issues: ' . json_encode($issues));
    }

    public function testContentsMaxModeIgnoresDirsOutsideSecrets(): void
    {
        // att/ je recurse bez contentsMaxMode — 0644 soubor nesmí být nález.
        $spec = $this->makeSpec();
        $this->buildContractTree($spec);
        $dsDir = $this->createDsTree($spec, 'aaaa-bbbb-cccc-dddd');
        $this->writeFile($dsDir . '/att/upload.bin', 'x', 0644);

        $checker = new HealthChecker($spec);
        $issues = $this->withoutRootOwnerIssues($checker->checkAll());

        $this->assertSame([], $issues, 'unexpected issues: ' . json_encode($issues));
    }

    public function testDetectsWrongType(): void
    {
        $spec = $this->makeSpec();
        $this->buildContractTree($spec);
        // Break the dataSourcesDir itself: replace dir with file
        rmdir($spec->getDataSourcesDir());
        $this->writeFile($spec->getDataSourcesDir(), '', 0640);

        $checker = new HealthChecker($spec);
        $issues = $this->withoutRootOwnerIssues($checker->checkAll());

        $typeIssues = array_filter($issues, static fn($i) => str_contains($i['message'], 'found file'));
        $this->assertCount(1, $typeIssues);
        $issue = array_values($typeIssues)[0];
        $this->assertSame($spec->getDataSourcesDir(), $issue['path']);
        $this->assertFalse($issue['fixable']);
    }

    public function testDetectsWrongOwner(): void
    {
        // Spec with a deliberately wrong shipard user → every entry will show
        // "owner <testUser>, expected <fakeUser>", fixable=true.
        $fake = 'shipard-nonexistent-' . uniqid();
        $spec = new PermissionSpec(
            shipardUser: $fake,
            dataSourcesDir: $this->tempRoot . '/opt/shipard/data-sources',
            logDir: $this->tempRoot . '/opt/shipard/log',
            configDir: $this->tempRoot . '/etc/shipard',
            shipardRoot: $this->tempRoot . '/opt/shipard',
        );
        $this->buildContractTree($spec);

        $checker = new HealthChecker($spec);
        $issues = $checker->checkAll();
        $ownerIssues = array_filter($issues, static fn($i) => str_contains($i['message'], "expected {$fake}"));
        $this->assertGreaterThan(0, count($ownerIssues));
        foreach ($ownerIssues as $issue) {
            $this->assertTrue($issue['fixable']);
        }
    }

    public function testDiscoversDataSources(): void
    {
        $spec = $this->makeSpec();
        $this->buildContractTree($spec);
        $this->createDsTree($spec, 'aaaa-bbbb-cccc-dddd', withOptional: false);
        $this->createDsTree($spec, 'eeee-ffff-gggg-hhhh', withOptional: false);
        $this->createDsTree($spec, 'iiii-jjjj-kkkk-llll', withOptional: false);
        // Stray dir without config/main.json — should be ignored
        mkdir($spec->getDataSourcesDir() . '/not-a-ds', 0750, true);

        $discovered = $spec->discoverDataSources();
        $this->assertCount(3, $discovered);
        $this->assertSame(
            [
                $spec->getDataSourcesDir() . '/aaaa-bbbb-cccc-dddd',
                $spec->getDataSourcesDir() . '/eeee-ffff-gggg-hhhh',
                $spec->getDataSourcesDir() . '/iiii-jjjj-kkkk-llll',
            ],
            $discovered,
        );
    }

    public function testDiscoverDataSourcesEmptyWhenDirMissing(): void
    {
        $spec = $this->makeSpec();
        // dataSourcesDir doesn't exist yet
        $this->assertSame([], $spec->discoverDataSources());
    }

    public function testDetectPoolUserParsesShipardPool(): void
    {
        $poolDir = $this->tempRoot . '/php/8.5/fpm/pool.d';
        mkdir($poolDir, 0750, true);
        file_put_contents($poolDir . '/shipard.conf', "[shipard]\nuser = sebik\ngroup = sebik\n");

        $spec = $this->makeSpec();
        $checker = new HealthChecker($spec);

        // Inject our temp pattern via the public arg
        $this->assertSame('sebik', $checker->detectPoolUser($this->tempRoot . '/php/*/fpm/pool.d/shipard.conf'));
    }

    public function testDetectPoolUserReturnsNullWhenAbsent(): void
    {
        $spec = $this->makeSpec();
        $checker = new HealthChecker($spec);
        $this->assertNull($checker->detectPoolUser($this->tempRoot . '/never/exists/*.conf'));
    }

    public function testRecursiveScanDetectsWrongOwnerInsideAttDir(): void
    {
        // Spec where 'user' resolves to a non-existent user → every owner check
        // (including recursive content) reports as wrong.
        $fake = 'shipard-nonexistent-' . uniqid();
        $spec = new PermissionSpec(
            shipardUser: $fake,
            dataSourcesDir: $this->tempRoot . '/opt/shipard/data-sources',
            logDir: $this->tempRoot . '/opt/shipard/log',
            configDir: $this->tempRoot . '/etc/shipard',
            shipardRoot: $this->tempRoot . '/opt/shipard',
        );
        $this->buildContractTree($spec);
        $dsDir = $this->createDsTree($spec, 'aaaa-bbbb-cccc-dddd');
        // Plant a file inside att/ — recursive scan should pick it up
        file_put_contents($dsDir . '/att/upload.bin', "hello");

        $checker = new HealthChecker($spec);
        $issues = $checker->checkAll();
        $contentIssues = array_filter(
            $issues,
            static fn($i) => str_ends_with($i['path'], '/att/upload.bin'),
        );
        $this->assertCount(
            2,
            $contentIssues,
            'expected owner + group issues for the planted file, got: ' . json_encode($contentIssues),
        );
        foreach ($contentIssues as $issue) {
            $this->assertTrue($issue['fixable']);
        }
    }

    public function testRecursiveScanIgnoresContentsOfNonRecurseDirs(): void
    {
        // /opt/shipard itself is NOT recurse=true → planting a file inside
        // should NOT produce content-level issues (only the dir's own
        // owner/group/mode is checked).
        $spec = $this->makeSpec();
        $this->buildContractTree($spec);
        // shipard root has a 'shpd' symlink in real life — we plant a stray
        // file at the root for the test.
        file_put_contents($spec->getShipardRoot() . '/stray.txt', 'x');

        $checker = new HealthChecker($spec);
        $issues = $this->withoutRootOwnerIssues($checker->checkAll());
        $strayIssues = array_filter(
            $issues,
            static fn($i) => str_ends_with($i['path'], '/stray.txt'),
        );
        $this->assertCount(0, $strayIssues, 'non-recurse dir leaked into content scan');
    }

    public function testRecursiveScanWalksNestedDirs(): void
    {
        $fake = 'shipard-nonexistent-' . uniqid();
        $spec = new PermissionSpec(
            shipardUser: $fake,
            dataSourcesDir: $this->tempRoot . '/opt/shipard/data-sources',
            logDir: $this->tempRoot . '/opt/shipard/log',
            configDir: $this->tempRoot . '/etc/shipard',
            shipardRoot: $this->tempRoot . '/opt/shipard',
        );
        $this->buildContractTree($spec);
        $dsDir = $this->createDsTree($spec, 'aaaa-bbbb-cccc-dddd');
        // Plant a file 2 levels deep under cache/ (which is recurse=true)
        mkdir($dsDir . '/cache/sub/nested', 0750, true);
        file_put_contents($dsDir . '/cache/sub/nested/deep.bin', 'x');

        $checker = new HealthChecker($spec);
        $issues = $checker->checkAll();
        $deep = array_filter(
            $issues,
            static fn($i) => str_ends_with($i['path'], '/cache/sub/nested/deep.bin'),
        );
        $this->assertCount(2, $deep, 'recursive walk did not reach nested file');
    }
}
