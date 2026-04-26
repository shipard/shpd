<?php

declare(strict_types=1);

namespace Shipard\Command\DataSource;

use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Core\Mail\AIAnalyzerProvisioner;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Idempotentně provisionuje systémového uživatele `_ai_analyzer`, default
 * AI backend a default profil. Volá se automaticky z `ds-upgrade`, případně
 * ručně pro DS založené před Fází 3a.
 */
class AiAnalyzerBootstrapCommand extends Command
{
    public function __construct(
        private readonly ?DataSourceConfig $dsConfig = null,
        private readonly ?DataSourceConnection $dsConnection = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('ai-analyzer-bootstrap')
             ->setDescription('Ensure _ai_analyzer user, default AI backend and default profile exist');
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

        $provisioner = new AIAnalyzerProvisioner($dsConnection);
        $result = $provisioner->provision();

        $this->reportUser($output, $result['user']);
        $this->reportBackend($output, $result['backend']);
        $this->reportProfile($output, $result['profile']);

        return Command::SUCCESS;
    }

    /** @param array{id: int, created: bool} $user */
    private function reportUser(OutputInterface $output, array $user): void
    {
        if ($user['created']) {
            $output->writeln("<info>Created system user '_ai_analyzer' (id={$user['id']})</info>");
        } else {
            $output->writeln("System user '_ai_analyzer' already exists (id={$user['id']})");
        }
    }

    /** @param array{id: int, created: bool, skipped_reason?: string} $backend */
    private function reportBackend(OutputInterface $output, array $backend): void
    {
        if ($backend['created']) {
            $output->writeln("<info>Created default AI backend (id={$backend['id']})</info>");
            $output->writeln("<comment>API key not set yet. Run 'shpd-ds ai-analyzer-set-key --backend default --api-key …' to enable.</comment>");
            return;
        }

        if (isset($backend['skipped_reason'])) {
            $output->writeln("<comment>Default backend not created: {$backend['skipped_reason']}</comment>");
            return;
        }

        $output->writeln("Default backend already exists (id={$backend['id']})");
    }

    /** @param array{id: int, created: bool, skipped_reason?: string} $profile */
    private function reportProfile(OutputInterface $output, array $profile): void
    {
        if ($profile['created']) {
            $output->writeln("<info>Created default AI profile (id={$profile['id']})</info>");
            return;
        }

        if (isset($profile['skipped_reason'])) {
            $output->writeln("<comment>Default profile not created: {$profile['skipped_reason']}</comment>");
            return;
        }

        $output->writeln("Default profile already exists (id={$profile['id']})");
    }
}
