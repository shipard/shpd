<?php

declare(strict_types=1);

namespace Shipard\Command\DataSource;

use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class UserSetAdminCommand extends Command
{
    public function __construct(
        private readonly ?DataSourceConfig $dsConfig = null,
        private readonly ?DataSourceConnection $dsConnection = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('user-set-admin')
             ->setDescription('Grant or revoke administrator rights for a user')
             ->addOption('login', null, InputOption::VALUE_REQUIRED, 'Login name')
             ->addOption('revoke', null, InputOption::VALUE_NONE, 'Revoke administrator rights instead of granting them');
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

        $login = $input->getOption('login');
        $revoke = (bool) $input->getOption('revoke');

        if (empty($login)) {
            $output->writeln('<error>Error: Option --login is required</error>');
            return Command::FAILURE;
        }

        $dsConfig = $this->dsConfig ?? new DataSourceConfig($dsDir);
        $dsConnection = $this->dsConnection ?? new DataSourceConnection($dsConfig);

        $user = $dsConnection->fetchRow(
            'SELECT id, login, is_admin, is_active FROM core_system_users WHERE login = %s',
            $login,
        );

        if ($user === null) {
            $output->writeln("<error>Error: User with login '{$login}' not found.</error>");
            return Command::FAILURE;
        }

        $targetValue = $revoke ? 0 : 1;

        if ((int) $user['is_admin'] === $targetValue) {
            $output->writeln("User '{$login}' is already " . ($revoke ? 'a non-admin.' : 'an admin.'));
            return Command::SUCCESS;
        }

        if ($revoke && $user['is_active']) {
            $activeAdmins = $dsConnection->fetchRow(
                'SELECT COUNT(*) AS cnt FROM core_system_users WHERE is_admin = 1 AND is_active = 1',
            );
            if ((int) ($activeAdmins['cnt'] ?? 0) <= 1) {
                $output->writeln("<error>Error: Cannot revoke admin rights from '{$login}' — it is the last active admin of this data source.</error>");
                return Command::FAILURE;
            }
        }

        $dsConnection->execute(
            'UPDATE core_system_users SET is_admin = %i WHERE id = %i',
            $targetValue,
            (int) $user['id'],
        );

        $output->writeln($revoke
            ? "Admin rights revoked from user '{$login}'."
            : "Admin rights granted to user '{$login}'.");

        return Command::SUCCESS;
    }
}
