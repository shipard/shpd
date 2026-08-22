<?php

declare(strict_types=1);

namespace Shipard\Command\DataSource;

use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * API klíč AI analyzeru pro endpoint GET /_hosting/ai-analyzer/lookup
 * (hosting-10 D1) — zrcadlo `hosting-router-key` nad
 * hosting_core_ai_analyzers.
 *
 * --generate vygeneruje token `shpd_hk_` + 43 url-safe znaků, na řádek
 * analyzeru uloží jen prefix (lookup) + SHA-256 hash a token vytiskne
 * JEDNOU — patří do sekce `sources_sync.api_key` v config.yaml
 * analyzer stroje. --revoke prefix i hash vynuluje (analyzer se
 * okamžitě odpojí, jede na stale sources).
 *
 * Formát validuje sdílený HostingApiKeyAuthenticator (prefix lookup +
 * hash_equals nad sha256 celého tokenu).
 *
 * Spec: tasks/hosting-10-ai-analyzer.md, docs/hosting.md.
 */
class HostingAnalyzerKeyCommand extends Command
{
    public const TOKEN_PREFIX = 'shpd_hk_';
    public const KEY_PREFIX_LENGTH = 12;

    public function __construct(
        private readonly ?DataSourceConfig $dsConfig = null,
        private readonly ?DataSourceConnection $dsConnection = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('hosting-analyzer-key')
             ->setDescription('Generate or revoke the lookup API key of a hosting AI analyzer')
             ->addOption('analyzer', null, InputOption::VALUE_REQUIRED, 'Analyzer row id (hosting_core_ai_analyzers.id)')
             ->addOption('generate', null, InputOption::VALUE_NONE, 'Generate a new key and print it once')
             ->addOption('revoke', null, InputOption::VALUE_NONE, 'Revoke the current key');
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

        $analyzerId = $input->getOption('analyzer');
        $generate = (bool) $input->getOption('generate');
        $revoke = (bool) $input->getOption('revoke');

        if (!is_string($analyzerId) || $analyzerId === '' || !ctype_digit($analyzerId)) {
            $output->writeln('<error>Error: --analyzer <id> is required</error>');
            return Command::FAILURE;
        }
        if ($generate === $revoke) {
            $output->writeln('<error>Error: pass exactly one of --generate or --revoke</error>');
            return Command::FAILURE;
        }

        $dsConfig = $this->dsConfig ?? new DataSourceConfig($dsDir);
        $dsConnection = $this->dsConnection ?? new DataSourceConnection($dsConfig);

        $row = $dsConnection->fetchRow(
            'SELECT id, name FROM hosting_core_ai_analyzers WHERE id = %i',
            (int) $analyzerId,
        );
        if ($row === null) {
            $output->writeln("<error>Error: analyzer '{$analyzerId}' not found in hosting_core_ai_analyzers.</error>");
            return Command::FAILURE;
        }

        if ($revoke) {
            $dsConnection->updateWhere(
                'hosting_core_ai_analyzers',
                [
                    'api_key_prefix' => null,
                    'api_key_hash' => null,
                    'modified' => date('Y-m-d H:i:s'),
                ],
                'id = %i',
                (int) $row['id'],
            );
            $output->writeln("<info>API key revoked: {$row['name']}</info>");
            return Command::SUCCESS;
        }

        $random = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $token = self::TOKEN_PREFIX . $random;

        $dsConnection->updateWhere(
            'hosting_core_ai_analyzers',
            [
                'api_key_prefix' => substr($random, 0, self::KEY_PREFIX_LENGTH),
                'api_key_hash' => hash('sha256', $token),
                'modified' => date('Y-m-d H:i:s'),
            ],
            'id = %i',
            (int) $row['id'],
        );

        $output->writeln("<info>API key generated: {$row['name']}</info>");
        $output->writeln('');
        $output->writeln('<comment>Analyzer key (shown only once — put it into config.yaml "sources_sync.api_key" on the analyzer machine):</comment>');
        $output->writeln($token);

        return Command::SUCCESS;
    }
}
