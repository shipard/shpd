<?php

declare(strict_types=1);

namespace Shipard\Command\DataSource;

use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Database\SchemaIntrospector;
use Shipard\Core\Database\SchemaLoader;
use Shipard\Core\Security\DsSecretCipher;
use Shipard\Core\Security\Exception\InvalidCiphertextException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class DsSecretsHealthCommand extends Command
{
    public function __construct(
        private readonly ?DataSourceConfig $dsConfig = null,
        private readonly ?DataSourceConnection $dsConnection = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('ds-secrets-health')
             ->setDescription('Check health of the per-DS secrets infrastructure');
    }

    protected function getDataSourceDir(): string
    {
        return getcwd();
    }

    protected function getModulesBasePath(): string
    {
        return dirname(__DIR__, 3) . '/modules';
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dsDir = $this->getDataSourceDir();
        $modulesBasePath = $this->getModulesBasePath();
        $dsConfig = $this->dsConfig ?? new DataSourceConfig($dsDir);

        $errors = 0;
        $warnings = 0;

        $secretsDir = DsSecretCipher::secretsDirPath($dsDir);
        $keyFile = DsSecretCipher::keyFilePath($dsDir);

        $keyUsable = $this->checkKeyFile($output, $keyFile, $errors, $warnings);
        $this->checkSecretsDir($output, $secretsDir, $errors, $warnings);

        if ($keyUsable) {
            // Construct cipher from raw key bytes — bypass forConfig() so we can
            // still attempt decryption checks when permissions are wrong (those
            // are reported as warnings above, not blockers).
            try {
                $keyBytes = file_get_contents($keyFile);
                if ($keyBytes === false) {
                    throw new \RuntimeException("Failed to read {$keyFile}");
                }
                $cipher = DsSecretCipher::fromKey($keyBytes);
            } catch (\Throwable $e) {
                $output->writeln('<error>✗ Could not initialise cipher: ' . $e->getMessage() . '</error>');
                $errors++;
                return $this->finish($output, $errors, $warnings);
            }

            $loaded = SchemaLoader::loadResolvedTables($modulesBasePath, $dsConfig->getModules());
            foreach ($loaded['errors'] as $err) {
                $output->writeln('<error>✗ Module loading: ' . $err . '</error>');
                $errors++;
            }

            $encryptedColumns = SchemaIntrospector::findEncryptedColumns($loaded['tables']);
            if (count($encryptedColumns) > 0) {
                $dsConnection = $this->dsConnection ?? new DataSourceConnection($dsConfig);
                foreach ($encryptedColumns as $entry) {
                    $this->checkColumnDecryption(
                        $output, $dsConnection, $cipher, $entry['table'], $entry['column'], $errors,
                    );
                }
            }
        }

        return $this->finish($output, $errors, $warnings);
    }

    private function checkKeyFile(OutputInterface $output, string $keyFile, int &$errors, int &$warnings): bool
    {
        if (!is_file($keyFile)) {
            $output->writeln("<error>✗ secrets.key missing at {$keyFile}</error>");
            $errors++;
            return false;
        }

        $size = filesize($keyFile);
        if ($size !== DsSecretCipher::KEY_BYTES) {
            $output->writeln(sprintf(
                '<error>✗ secrets.key has size %d bytes (expected %d)</error>',
                $size, DsSecretCipher::KEY_BYTES,
            ));
            $errors++;
            $usable = false;
        } else {
            $output->writeln("✓ secrets.key present ({$size} bytes)");
            $usable = true;
        }

        $perms = fileperms($keyFile) & 0777;
        if ($perms !== 0600) {
            $output->writeln(sprintf(
                '<comment>✗ secrets.key permissions are %04o (should be 0600)</comment>',
                $perms,
            ));
            $output->writeln("    Fix: chmod 0600 {$keyFile}");
            $warnings++;
        } else {
            $output->writeln('✓ secrets.key permissions 0600');
        }

        return $usable;
    }

    private function checkSecretsDir(OutputInterface $output, string $secretsDir, int &$errors, int &$warnings): void
    {
        if (!is_dir($secretsDir)) {
            $output->writeln("<error>✗ secrets/ directory missing at {$secretsDir}</error>");
            $errors++;
            return;
        }

        $perms = fileperms($secretsDir) & 0777;
        if ($perms !== 0700) {
            $output->writeln(sprintf(
                '<comment>✗ secrets/ directory permissions are %04o (should be 0700)</comment>',
                $perms,
            ));
            $output->writeln("    Fix: chmod 0700 {$secretsDir}");
            $warnings++;
        } else {
            $output->writeln('✓ secrets/ directory permissions 0700');
        }
    }

    private function checkColumnDecryption(
        OutputInterface $output,
        DataSourceConnection $dsConnection,
        DsSecretCipher $cipher,
        string $table,
        string $column,
        int &$errors,
    ): void {
        try {
            $rows = $dsConnection->fetchAll(sprintf(
                'SELECT id, `%s` AS val FROM `%s` WHERE `%s` IS NOT NULL',
                $column, $table, $column,
            ));
        } catch (\Throwable $e) {
            $output->writeln(sprintf(
                '<error>✗ %s.%s — query failed: %s</error>',
                $table, $column, $e->getMessage(),
            ));
            $errors++;
            return;
        }

        $total = count($rows);
        $failures = [];
        foreach ($rows as $row) {
            try {
                $cipher->decrypt((string) $row['val']);
            } catch (InvalidCiphertextException $e) {
                $failures[] = ['id' => $row['id'], 'reason' => $e->getMessage()];
            }
        }

        if (count($failures) === 0) {
            $output->writeln(sprintf('✓ %s.%s — %d rows, all decryptable', $table, $column, $total));
            return;
        }

        $output->writeln(sprintf(
            '<error>✗ %s.%s — %d of %d rows failed decryption:</error>',
            $table, $column, count($failures), $total,
        ));
        foreach ($failures as $f) {
            $output->writeln(sprintf('    - row id=%s: %s', $f['id'], $f['reason']));
        }
        $errors++;
    }

    private function finish(OutputInterface $output, int $errors, int $warnings): int
    {
        $output->writeln('');
        if ($errors === 0 && $warnings === 0) {
            $output->writeln('<info>✓ All checks passed</info>');
            return 0;
        }

        $output->writeln(sprintf(
            '<error>✗ Health check failed (%d error%s, %d warning%s)</error>',
            $errors, $errors === 1 ? '' : 's',
            $warnings, $warnings === 1 ? '' : 's',
        ));
        return $errors > 0 ? 2 : 1;
    }
}
