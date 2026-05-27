<?php

declare(strict_types=1);

namespace Shipard\Command\DataSource;

use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Security\ApiKeyService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

/**
 * Deaktivuje API klíč (`is_active = 0`). Row v `core_system_api_keys` zůstává
 * pro auditní stopu — nikdy nemažeme.
 *
 * Identifikace klíče: `--id` (preferované) nebo `--prefix` (12 znaků). Pokud
 * by prefix matchnul víc klíčů (vzácné, viz spec Otevřené body 3), command
 * selže a vyzve k `--id`.
 */
class ApiKeyRevokeCommand extends Command
{
    public function __construct(
        private readonly ?DataSourceConfig $dsConfig = null,
        private readonly ?DataSourceConnection $dsConnection = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('api-key-revoke')
             ->setDescription('Revoke (deactivate) an API key')
             ->addOption('id', null, InputOption::VALUE_REQUIRED, 'Numeric key ID')
             ->addOption('prefix', null, InputOption::VALUE_REQUIRED, 'Key prefix (' . ApiKeyService::KEY_PREFIX_LENGTH . ' chars)')
             ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Skip interactive confirmation');
    }

    protected function getDataSourceDir(): string
    {
        return getcwd();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dsDir = $this->getDataSourceDir();

        if ($this->dsConfig === null && !file_exists($dsDir . '/config/main.json')) {
            $output->writeln('<error>Error: Not a Shipard data source directory</error>');
            return Command::FAILURE;
        }

        $idOpt = $input->getOption('id');
        $prefixOpt = $input->getOption('prefix');

        if ($idOpt === null && $prefixOpt === null) {
            $output->writeln('<error>Error: Either --id or --prefix is required</error>');
            return Command::FAILURE;
        }
        if ($idOpt !== null && $prefixOpt !== null) {
            $output->writeln('<error>Error: Use either --id or --prefix, not both</error>');
            return Command::FAILURE;
        }

        $dsConfig = $this->dsConfig ?? new DataSourceConfig($dsDir);
        $dsConnection = $this->dsConnection ?? new DataSourceConnection($dsConfig);
        $service = new ApiKeyService($dsConnection);

        $row = null;
        if ($idOpt !== null) {
            if (!ctype_digit((string) $idOpt)) {
                $output->writeln('<error>Error: --id must be a numeric value</error>');
                return Command::FAILURE;
            }
            $row = $service->findKeyById((int) $idOpt);
            if ($row === null) {
                $output->writeln("<error>Error: API key with id={$idOpt} not found.</error>");
                return Command::FAILURE;
            }
        } else {
            $prefix = (string) $prefixOpt;
            if (strlen($prefix) !== ApiKeyService::KEY_PREFIX_LENGTH) {
                $output->writeln(
                    '<error>Error: --prefix must be exactly ' . ApiKeyService::KEY_PREFIX_LENGTH . ' characters</error>',
                );
                return Command::FAILURE;
            }
            $count = $service->countKeysByPrefix($prefix);
            if ($count === 0) {
                $output->writeln("<error>Error: API key with prefix '{$prefix}' not found.</error>");
                return Command::FAILURE;
            }
            if ($count > 1) {
                $output->writeln(
                    "<error>Error: Prefix '{$prefix}' matches {$count} keys — use --id to disambiguate.</error>",
                );
                return Command::FAILURE;
            }
            $row = $service->findKeyByPrefix($prefix);
            if ($row === null) {
                // Defensive — count said 1 but row vanished between calls.
                $output->writeln("<error>Error: API key with prefix '{$prefix}' not found.</error>");
                return Command::FAILURE;
            }
        }

        if ((int) $row['is_active'] === 0) {
            $when = $this->fmtDate($row['modified'] ?? null);
            $suffix = $when !== null ? " (revoked at {$when})" : '';
            $output->writeln("API key already revoked{$suffix}. No changes made.");
            return Command::SUCCESS;
        }

        $output->writeln('About to revoke this API key:');
        $output->writeln('');
        $output->writeln('  ID:           ' . $row['id']);
        $output->writeln('  User:         ' . ($row['user_login'] ?? '(unknown)') . ' (id=' . $row['user_id'] . ')');
        $output->writeln('  Name:         ' . $row['name']);
        $output->writeln('  Prefix:       ' . $row['key_prefix']);
        $output->writeln('  Created:      ' . ($this->fmtDate($row['created'] ?? null) ?? '(unknown)'));
        $output->writeln('  Last used:    ' . ($this->fmtDate($row['last_used_at'] ?? null) ?? '(never)'));
        $output->writeln('');

        if (!(bool) $input->getOption('yes')) {
            /** @var QuestionHelper $helper */
            $helper = $this->getHelper('question');
            $question = new ConfirmationQuestion('Proceed? [y/N]: ', false);
            if (!$helper->ask($input, $output, $question)) {
                $output->writeln('Aborted. No changes made.');
                return Command::SUCCESS;
            }
        }

        $changed = $service->revokeKey((int) $row['id']);
        if (!$changed) {
            // Race: key was revoked between read and write. Idempotent — report success.
            $output->writeln('API key already revoked. No changes made.');
            return Command::SUCCESS;
        }

        $output->writeln('API key revoked. Active = 0.');
        return Command::SUCCESS;
    }

    private function fmtDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $str = (string) $value;
        if (preg_match('/^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2}:\d{2})/', $str, $m)) {
            return $m[1] . ' ' . $m[2];
        }
        return $str;
    }
}
