<?php

declare(strict_types=1);

namespace Shipard\Command\DataSource;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class VersionCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('version')
             ->setDescription('Show Shipard data source tool version');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->requireDataSource($output);
        $output->writeln('Shipard v0.1.0');
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
