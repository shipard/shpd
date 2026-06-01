<?php

declare(strict_types=1);

namespace Shipard\Command\DataSource;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class HelpCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('help')
             ->setDescription('Show available commands');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->requireDataSource($output);

        $output->writeln('');
        $output->writeln('<info>Shipard Data Source Tool v0.1.0</info>');
        $output->writeln('');
        $output->writeln('<comment>Basic:</comment>');
        $output->writeln('  <info>version</info>                 Show Shipard data source tool version');
        $output->writeln('  <info>help</info>                    Show this help message');
        $output->writeln('  <info>ds-upgrade</info>              Upgrade the data source schema and configuration');
        $output->writeln('  <info>ds-reset</info>                Reset the data source — drop all data tables and recreate them, keeping users, API keys and protected tables');
        $output->writeln('');
        $output->writeln('<comment>Users:</comment>');
        $output->writeln('  <info>user-create</info>             Create a new user in the data source');
        $output->writeln('');
        $output->writeln('<comment>Secrets:</comment>');
        $output->writeln('  <info>ds-secrets-health</info>       Check health of the per-DS secrets infrastructure');
        $output->writeln('  <info>ds-secrets-rotate</info>       Rotate the per-DS secrets.key — re-encrypts all encrypted_text columns');
        $output->writeln('');
        $output->writeln('<comment>Mail:</comment>');
        $output->writeln('  <info>mail-router-bootstrap</info>   Ensure _mail_router system user and default mailbox exist');
        $output->writeln('  <info>mail-router-setup</info>       Generate (or rotate) the API key used by the external mail-router');
        $output->writeln('  <info>mail-idempotency-prune</info>  Remove expired idempotency keys for incoming mail');
        $output->writeln('');
        $output->writeln('<comment>AI Analyzer:</comment>');
        $output->writeln('  <info>ai-analyzer-bootstrap</info>   Ensure _ai_analyzer user, default AI backend and default profile exist');
        $output->writeln('  <info>ai-analyzer-setup</info>       Generate (or rotate) the API key used by the external AI analyzer');
        $output->writeln('  <info>ai-analyzer-set-key</info>     Set (or rotate) the API key on an AI backend; encrypts via DsSecretCipher');
        $output->writeln('  <info>ai-profile-reload</info>       Reload AI profile from JSONC template into the DB');
        $output->writeln('  <info>mail-analysis-reap</info>      Release expired AI analysis claims and re-queue affected messages');
        $output->writeln('');
        $output->writeln('<comment>Seed (test data):</comment>');
        $output->writeln('  <info>seed-persons</info>            Generate fake persons with optional contacts and bank accounts');
        $output->writeln('  <info>seed-clear</info>              Remove all seeded test persons (TEST- prefix)');
        $output->writeln('  <info>seed-mail</info>               Generate fake mailboxes and incoming messages for core.mail');
        $output->writeln('  <info>seed-mail-clear</info>         Remove all core.mail seed data (TEST- / TEST-MSG- prefix)');
        $output->writeln('');
        $output->writeln('<comment>Usage:</comment>');
        $output->writeln('  shpd-ds <command> [options]');
        $output->writeln('');
        $output->writeln('<comment>Examples:</comment>');
        $output->writeln('  shpd-ds ds-upgrade');
        $output->writeln('  shpd-ds ds-reset --dry-run');
        $output->writeln('  shpd-ds ds-reset -y');
        $output->writeln('  shpd-ds user-create --login=admin --password=...');
        $output->writeln('  shpd-ds ai-analyzer-set-key --key=<api-key>');
        $output->writeln('  shpd-ds ds-secrets-rotate --dry-run');
        $output->writeln('  shpd-ds seed-persons --count=100 --with-contacts --with-bank-accounts');
        $output->writeln('  shpd-ds seed-persons -c 20 --company-ratio=60');
        $output->writeln('  shpd-ds seed-clear');
        $output->writeln('  shpd-ds seed-mail -c 60');
        $output->writeln('  shpd-ds seed-mail-clear');
        $output->writeln('');
        $output->writeln('<comment>Note:</comment>');
        $output->writeln('  Run from within a data source directory (must contain config/main.json)');
        $output->writeln('');

        return Command::SUCCESS;
    }

    private function requireDataSource(OutputInterface $output): void
    {
        $configFile = getcwd() . '/config/main.json';
        if (!file_exists($configFile)) {
            $output->writeln('<error>Not a Shipard data source directory (config/main.json not found)</error>');
            exit(Command::FAILURE);
        }
    }
}
