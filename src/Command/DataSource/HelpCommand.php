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
        $output->writeln('<comment>Available commands:</comment>');
        $output->writeln('  <info>version</info>      Show Shipard data source tool version');
        $output->writeln('  <info>help</info>         Show this help message');
        $output->writeln('  <info>ds-upgrade</info>   Upgrade data source schema and configuration');
        $output->writeln('  <info>user-create</info>  Create a new user in the data source');
        $output->writeln('');
        $output->writeln('<comment>Usage:</comment>');
        $output->writeln('  shpd-ds <command> [options]');
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
