<?php

declare(strict_types=1);

namespace Shipard\Command\DataSource;

use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Security\ApiKeyService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Listuje API klíče v DS. Default jen aktivní; `--include-inactive` přidá
 * i revokované. Volitelně filtruje per uživatel (`--user`, stejný resolver
 * jako `api-key-create`).
 *
 * Plaintext klíče tu nikdy nezobrazujeme — jen prefix.
 */
class ApiKeyListCommand extends Command
{
    private const USER_COL_MAX = 11;

    public function __construct(
        private readonly ?DataSourceConfig $dsConfig = null,
        private readonly ?DataSourceConnection $dsConnection = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('api-key-list')
             ->setDescription('List API keys in the data source')
             ->addOption('user', null, InputOption::VALUE_REQUIRED, 'Filter by user (login, email or numeric ID)')
             ->addOption('include-inactive', null, InputOption::VALUE_NONE, 'Include revoked keys in the output');
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

        $dsConfig = $this->dsConfig ?? new DataSourceConfig($dsDir);
        $dsConnection = $this->dsConnection ?? new DataSourceConnection($dsConfig);
        $service = new ApiKeyService($dsConnection);

        $userArg = $input->getOption('user');
        $includeInactive = (bool) $input->getOption('include-inactive');

        $userId = null;
        if ($userArg !== null && $userArg !== '') {
            $matches = $service->findUserMatches((string) $userArg);
            if (count($matches) === 0) {
                $output->writeln("<error>Error: User '{$userArg}' not found.</error>");
                return Command::FAILURE;
            }
            if (count($matches) > 1) {
                $output->writeln("<error>Error: User '{$userArg}' is ambiguous — use --user=<id> to disambiguate.</error>");
                return Command::FAILURE;
            }
            $userId = (int) $matches[0]['id'];
        }

        $rows = $service->listKeys($userId, $includeInactive);

        if ($rows === []) {
            $output->writeln($includeInactive ? 'No API keys found.' : 'No active API keys found.');
            return Command::SUCCESS;
        }

        $table = new Table($output);
        $table->setHeaders(['ID', 'USER', 'NAME', 'PREFIX', 'ACTIVE', 'EXPIRES', 'LAST USED', 'CREATED']);
        foreach ($rows as $row) {
            $table->addRow([
                (string) $row['id'],
                $this->truncateUser((string) ($row['user_login'] ?? '(unknown)')),
                (string) $row['name'],
                (string) $row['key_prefix'],
                ((int) $row['is_active']) === 1 ? 'yes' : 'no',
                $this->fmtDate($row['expires_at'] ?? null, '(never)'),
                $this->fmtDate($row['last_used_at'] ?? null, '(never)'),
                $this->fmtDate($row['created'] ?? null, ''),
            ]);
        }
        $table->render();

        return Command::SUCCESS;
    }

    private function truncateUser(string $login): string
    {
        if (mb_strlen($login) <= self::USER_COL_MAX) {
            return $login;
        }
        return mb_substr($login, 0, self::USER_COL_MAX - 1) . '…';
    }

    /**
     * `DataSourceConnection::normalizeValue()` převede DATETIME na ISO 8601
     * (`Y-m-d\TH:i:s`); pro tabulkový output převedeme zpět na čitelnější
     * `Y-m-d H:i:s`. Null/empty → placeholder.
     */
    private function fmtDate(mixed $value, string $fallback): string
    {
        if ($value === null || $value === '') {
            return $fallback;
        }
        $str = (string) $value;
        if (preg_match('/^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2}:\d{2})/', $str, $m)) {
            return $m[1] . ' ' . $m[2];
        }
        return $str;
    }
}
