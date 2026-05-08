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
        $output->writeln('  <info>ds-upgrade-all</info> Run ds-upgrade on all data sources');
        $output->writeln('  <info>next-table-id</info>  Print the next available table ID');
        $output->writeln('  <info>domain-add</info>     Add a host → data source mapping');
        $output->writeln('  <info>domain-list</info>    List all host → data source mappings');
        $output->writeln('  <info>domain-remove</info>  Remove a host → data source mapping');
        $output->writeln('');
        $output->writeln('<comment>Usage:</comment>');
        $output->writeln('  shpd-server <command> [options]');
        $output->writeln('');
        $output->writeln('<comment>Options:</comment>');
        $output->writeln('  ds-create --name <name>                  Name of the data source to create');
        $output->writeln('  ds-upgrade-all [--ds=<id>] [--stop-on-error] [--dry-run]');
        $output->writeln('                                           Upgrade all data sources');
        $output->writeln('  domain-add --host <host> --ds <ds-id>    Map a hostname to a data source');
        $output->writeln('  domain-remove --host <host>              Remove a hostname mapping');
        $output->writeln('');

        return Command::SUCCESS;
    }
}
