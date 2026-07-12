<?php

declare(strict_types=1);

namespace Shipard\Command\DataSource;

use Shipard\Api\SessionService;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Logging\ErrorLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Break-glass přihlášení (D9/D15): založí `shpd_st_` session přímo v DB,
 * úplně obchází HTTP login vrstvu — funguje bez ohledu na auth politiku
 * (i s `local: false`), při nedostupném IdP i rozbité auth konfiguraci.
 */
class AuthEmergencyLoginCommand extends Command
{
    public function __construct(
        private readonly ?DataSourceConfig $dsConfig = null,
        private readonly ?DataSourceConnection $dsConnection = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('auth-emergency-login')
             ->setDescription('Break-glass: create a session token for a user directly in the DB (bypasses auth policy)')
             ->addOption('login', null, InputOption::VALUE_REQUIRED, 'Login of an existing active user');
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
        if (empty($login)) {
            $output->writeln('<error>Error: Option --login is required</error>');
            return Command::FAILURE;
        }

        $dsConfig = $this->dsConfig ?? new DataSourceConfig($dsDir);
        $dsConnection = $this->dsConnection ?? new DataSourceConnection($dsConfig);

        $user = $dsConnection->fetchRow(
            'SELECT * FROM core_system_users WHERE login = %s',
            $login,
        );

        if ($user === null) {
            $output->writeln("<error>Error: User with login '{$login}' not found.</error>");
            return Command::FAILURE;
        }
        if (!$user['is_active']) {
            $output->writeln("<error>Error: User '{$login}' is inactive.</error>");
            return Command::FAILURE;
        }

        [$token, $expiresAt] = new SessionService()->createSession((int) $user['id'], $dsConnection);

        ErrorLogger::warn('Break-glass emergency login session created', [
            'login'   => (string) $user['login'],
            'user_id' => (int) $user['id'],
        ]);

        $output->writeln('<comment>Emergency session created (bypasses auth policy).</comment>');
        $output->writeln("  User:    {$user['login']} ({$user['full_name']})");
        $output->writeln("  Token:   {$token}");
        $output->writeln("  Expires: {$expiresAt}");
        $output->writeln('');
        $output->writeln('Use in the app: open /app/, then in the browser DevTools console run:');
        $output->writeln("  localStorage.setItem('shpd_token', '{$token}'); location.reload();");
        $output->writeln('Or call the API directly:');
        $output->writeln("  curl -H 'Authorization: Bearer {$token}' .../api/v1/core_system_users");

        return Command::SUCCESS;
    }
}
