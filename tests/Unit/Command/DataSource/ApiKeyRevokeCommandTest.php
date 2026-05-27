<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Command\DataSource;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shipard\Command\DataSource\ApiKeyRevokeCommand;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class TestableApiKeyRevokeCommand extends ApiKeyRevokeCommand
{
    public function __construct(
        DataSourceConfig $dsConfig,
        DataSourceConnection $dsConnection,
        private readonly string $dsDir,
    ) {
        parent::__construct($dsConfig, $dsConnection);
    }

    protected function getDataSourceDir(): string
    {
        return $this->dsDir;
    }
}

class ApiKeyRevokeCommandTest extends TestCase
{
    private string $tempDir;
    private MockObject $dsConfig;
    private MockObject $dsConnection;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/shpd_apikey_revoke_test_' . uniqid();
        mkdir($this->tempDir . '/config', 0755, true);
        file_put_contents($this->tempDir . '/config/main.json', '{}');

        $this->dsConfig = $this->createMock(DataSourceConfig::class);
        $this->dsConnection = $this->createMock(DataSourceConnection::class);
    }

    protected function tearDown(): void
    {
        $this->rmdir($this->tempDir);
    }

    private function tester(): CommandTester
    {
        $command = new TestableApiKeyRevokeCommand($this->dsConfig, $this->dsConnection, $this->tempDir);
        (new Application())->add($command);
        return new CommandTester($command);
    }

    private function activeRow(): array
    {
        return [
            'id'           => 42,
            'user_id'      => 5,
            'user_login'   => 'alice',
            'name'         => 'integration-x',
            'key_prefix'   => 'aabbccdd1122',
            'is_active'    => 1,
            'created'      => '2026-05-27 14:30:00',
            'modified'     => '2026-05-27 14:30:00',
            'last_used_at' => '2026-05-27 16:42:00',
        ];
    }

    private function inactiveRow(): array
    {
        return [
            'id'           => 42,
            'user_id'      => 5,
            'user_login'   => 'alice',
            'name'         => 'integration-x',
            'key_prefix'   => 'aabbccdd1122',
            'is_active'    => 0,
            'created'      => '2026-05-27 14:30:00',
            'modified'     => '2026-05-27 15:00:00',
            'last_used_at' => null,
        ];
    }

    public function testRevokesByIdWithExplicitYes(): void
    {
        $row = $this->activeRow();
        $this->dsConnection->method('fetchRow')->willReturnCallback(
            function (string $sql, $param) use ($row): ?array {
                if (str_contains($sql, 'k.id = %i') && $param === 42) {
                    return $row;
                }
                if (str_contains($sql, 'WHERE id = %i') && $param === 42) {
                    // service.revokeKey first re-reads id/is_active
                    return ['id' => 42, 'is_active' => 1];
                }
                return null;
            },
        );

        $this->dsConnection->expects($this->once())
            ->method('updateWhere')
            ->with(
                'core_system_api_keys',
                $this->callback(fn(array $data) => $data['is_active'] === 0),
                'id = %i',
                42,
            );

        $tester = $this->tester();
        $exitCode = $tester->execute(['--id' => '42', '--yes' => true]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $output = $tester->getDisplay();
        $this->assertStringContainsString('About to revoke this API key', $output);
        $this->assertStringContainsString('ID:           42', $output);
        $this->assertStringContainsString('User:         alice (id=5)', $output);
        $this->assertStringContainsString('Name:         integration-x', $output);
        $this->assertStringContainsString('Prefix:       aabbccdd1122', $output);
        $this->assertStringContainsString('API key revoked. Active = 0.', $output);
    }

    public function testInteractiveConfirmationProceeds(): void
    {
        $this->dsConnection->method('fetchRow')->willReturn(
            $this->activeRow(),
            ['id' => 42, 'is_active' => 1],
        );
        $this->dsConnection->expects($this->once())->method('updateWhere');

        $tester = $this->tester();
        $tester->setInputs(['y']);
        $exitCode = $tester->execute(['--id' => '42']);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('Proceed?', $tester->getDisplay());
        $this->assertStringContainsString('API key revoked', $tester->getDisplay());
    }

    public function testInteractiveConfirmationAborts(): void
    {
        $this->dsConnection->method('fetchRow')->willReturn($this->activeRow());
        $this->dsConnection->expects($this->never())->method('updateWhere');

        $tester = $this->tester();
        $tester->setInputs(['n']);
        $exitCode = $tester->execute(['--id' => '42']);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('Aborted. No changes made.', $tester->getDisplay());
    }

    public function testRevokesByPrefix(): void
    {
        $this->dsConnection->method('fetchSingle')->willReturn(1);

        $this->dsConnection->method('fetchRow')->willReturnCallback(
            function (string $sql, $param): ?array {
                if (str_contains($sql, 'k.key_prefix = %s') && $param === 'aabbccdd1122') {
                    return $this->activeRow();
                }
                if (str_contains($sql, 'WHERE id = %i') && $param === 42) {
                    return ['id' => 42, 'is_active' => 1];
                }
                return null;
            },
        );

        $this->dsConnection->expects($this->once())->method('updateWhere');

        $tester = $this->tester();
        $exitCode = $tester->execute(['--prefix' => 'aabbccdd1122', '--yes' => true]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('API key revoked', $tester->getDisplay());
    }

    public function testPrefixAmbiguous(): void
    {
        $this->dsConnection->method('fetchSingle')->willReturn(2);
        $this->dsConnection->expects($this->never())->method('updateWhere');

        $tester = $this->tester();
        $exitCode = $tester->execute(['--prefix' => 'aabbccdd1122', '--yes' => true]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $output = $tester->getDisplay();
        $this->assertStringContainsString('matches 2 keys', $output);
        $this->assertStringContainsString('--id to disambiguate', $output);
    }

    public function testPrefixWrongLength(): void
    {
        $this->dsConnection->expects($this->never())->method('updateWhere');

        $tester = $this->tester();
        $exitCode = $tester->execute(['--prefix' => 'aabbccdd', '--yes' => true]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('exactly 12 characters', $tester->getDisplay());
    }

    public function testPrefixNotFound(): void
    {
        $this->dsConnection->method('fetchSingle')->willReturn(0);
        $this->dsConnection->expects($this->never())->method('updateWhere');

        $tester = $this->tester();
        $exitCode = $tester->execute(['--prefix' => 'zzzzzzzzzzzz', '--yes' => true]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString("'zzzzzzzzzzzz' not found", $tester->getDisplay());
    }

    public function testIdNotFound(): void
    {
        $this->dsConnection->method('fetchRow')->willReturn(null);
        $this->dsConnection->expects($this->never())->method('updateWhere');

        $tester = $this->tester();
        $exitCode = $tester->execute(['--id' => '999', '--yes' => true]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('id=999 not found', $tester->getDisplay());
    }

    public function testNoIdentifierFails(): void
    {
        $this->dsConnection->expects($this->never())->method('updateWhere');

        $tester = $this->tester();
        $exitCode = $tester->execute([]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('Either --id or --prefix is required', $tester->getDisplay());
    }

    public function testBothIdentifiersFails(): void
    {
        $this->dsConnection->expects($this->never())->method('updateWhere');

        $tester = $this->tester();
        $exitCode = $tester->execute(['--id' => '42', '--prefix' => 'aabbccdd1122']);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('not both', $tester->getDisplay());
    }

    public function testIdMustBeNumeric(): void
    {
        $this->dsConnection->expects($this->never())->method('updateWhere');

        $tester = $this->tester();
        $exitCode = $tester->execute(['--id' => 'abc', '--yes' => true]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('--id must be a numeric value', $tester->getDisplay());
    }

    public function testIdempotentOnAlreadyRevoked(): void
    {
        $this->dsConnection->method('fetchRow')->willReturn($this->inactiveRow());
        $this->dsConnection->expects($this->never())->method('updateWhere');

        $tester = $this->tester();
        $exitCode = $tester->execute(['--id' => '42', '--yes' => true]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $output = $tester->getDisplay();
        $this->assertStringContainsString('already revoked', $output);
        $this->assertStringContainsString('2026-05-27 15:00:00', $output);
        $this->assertStringContainsString('No changes made.', $output);
    }

    private function rmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rmdir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
