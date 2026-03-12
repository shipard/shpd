<?php

declare(strict_types=1);

namespace Shipard\Command\Server;

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
        $output->writeln('');
        $output->writeln('<info>Shipard Server Management v0.1.0</info>');
        $output->writeln('');
        $output->writeln('<comment>Available commands:</comment>');
        $output->writeln('  <info>version</info>        Show Shipard server version');
        $output->writeln('  <info>help</info>           Show this help message');
        $output->writeln('  <info>server-init</info>    Initialize the Shipard server configuration');
        $output->writeln('  <info>ds-create</info>      Create a new data source');
        $output->writeln('');
        $output->writeln('<comment>Usage:</comment>');
        $output->writeln('  shpd-server <command> [options]');
        $output->writeln('');
        $output->writeln('<comment>Options:</comment>');
        $output->writeln('  ds-create --name <name>    Name of the data source to create');
        $output->writeln('');

        return Command::SUCCESS;
    }
}
