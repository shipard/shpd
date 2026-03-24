<?php

declare(strict_types=1);

namespace Shipard\Command\DataSource;

use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class UserCreateCommand extends Command
{
    public function __construct(
        private readonly ?DataSourceConfig $dsConfig = null,
        private readonly ?DataSourceConnection $dsConnection = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('user-create')
             ->setDescription('Create a new user in the data source')
             ->addOption('login', null, InputOption::VALUE_REQUIRED, 'Login name')
             ->addOption('password', null, InputOption::VALUE_REQUIRED, 'Password')
             ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Full name')
             ->addOption('email', null, InputOption::VALUE_OPTIONAL, 'E-mail address');
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
        $password = $input->getOption('password');
        $fullName = $input->getOption('name');
        $email = $input->getOption('email');

        if (empty($login)) {
            $output->writeln('<error>Error: Option --login is required</error>');
            return Command::FAILURE;
        }
        if (empty($password)) {
            $output->writeln('<error>Error: Option --password is required</error>');
            return Command::FAILURE;
        }
        if (empty($fullName)) {
            $output->writeln('<error>Error: Option --name is required</error>');
            return Command::FAILURE;
        }

        $dsConfig = $this->dsConfig ?? new DataSourceConfig($dsDir);
        $dsConnection = $this->dsConnection ?? new DataSourceConnection($dsConfig);

        $existing = $dsConnection->fetchRow(
            'SELECT id FROM core_system_users WHERE login = %s',
            $login,
        );

        if ($existing !== null) {
            $output->writeln("<error>Error: User with login '{$login}' already exists.</error>");
            return Command::FAILURE;
        }

        $passwordHash = password_hash($password, PASSWORD_DEFAULT);

        $id = $dsConnection->insertRow('core_system_users', [
            'login'         => $login,
            'password_hash' => $passwordHash,
            'full_name'     => $fullName,
            'email'         => $email ?: null,
            'is_active'     => 1,
        ]);

        $output->writeln('User created successfully.');
        $output->writeln("  ID:    {$id}");
        $output->writeln("  Login: {$login}");
        $output->writeln("  Name:  {$fullName}");
        $output->writeln('  Email: ' . ($email ?: '(none)'));

        return Command::SUCCESS;
    }
}
