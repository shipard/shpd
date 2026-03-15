<?php

declare(strict_types=1);

namespace Shipard\Command\Server;

use Shipard\Core\Utils\JsoncParser;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class NextTableIdCommand extends Command
{
    public function __construct()
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('next-table-id')
             ->setDescription('Print the next available table ID');
    }

    protected function getModulesBasePath(): string
    {
        return dirname(__DIR__, 3) . '/modules';
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $pattern = $this->getModulesBasePath() . '/*/*/tables/*.jsonc';
        $files = glob($pattern) ?: [];
        $tableIds = [];
        foreach ($files as $file) {
            $data = JsoncParser::parseFile($file);
            if (isset($data['tableId']) && is_int($data['tableId']) && $data['tableId'] > 0) {
                $tableIds[] = $data['tableId'];
            }
        }
        $next = empty($tableIds) ? 1 : max($tableIds) + 1;
        $output->writeln((string) $next);
        return Command::SUCCESS;
    }
}
