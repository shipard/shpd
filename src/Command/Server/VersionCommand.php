<?php

declare(strict_types=1);

namespace Shipard\Command\Server;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class VersionCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('version')
             ->setDescription('Show Shipard server version');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('Shipard v0.1.0');
        return Command::SUCCESS;
    }
}
