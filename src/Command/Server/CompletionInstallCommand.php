<?php

declare(strict_types=1);

namespace Shipard\Command\Server;

use Shipard\Core\Server\CompletionInstaller;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Idempotentní instalace bash completion pro shpd-server a shpd-ds.
 * Volá ho `shpd-server upgrade` jako subproces (po pullu běží vždy nový
 * kód, vzor cron-install) a `server-init`; admin může spustit i ručně.
 */
class CompletionInstallCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('completion-install')
             ->setDescription('Install bash completion for shpd-server and shpd-ds into /etc/bash_completion.d (idempotent)');
    }

    protected function getEuid(): int
    {
        return posix_geteuid();
    }

    protected function createInstaller(): CompletionInstaller
    {
        return new CompletionInstaller();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->getEuid() !== 0) {
            $output->writeln('<error>completion-install must run as root (writes /etc/bash_completion.d).</error>');
            return Command::FAILURE;
        }

        $installer = $this->createInstaller();
        $failed = false;
        foreach (CompletionInstaller::BINARIES as $binary) {
            $result = $installer->install($binary);
            match ($result['status']) {
                'installed'  => $output->writeln('✓ ' . $result['message'] . ' written'),
                'up-to-date' => $output->writeln('✓ ' . $result['message'] . ' up to date'),
                'skipped'    => $output->writeln('<comment>  [WARN] ' . $result['message'] . ' — skipping</comment>'),
                default      => $output->writeln('<error>  [FAIL] ' . $result['message'] . '</error>'),
            };
            if ($result['status'] === 'error') {
                $failed = true;
            }
        }

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }
}
