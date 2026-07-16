<?php

declare(strict_types=1);

namespace Shipard\Command\Server;

use Shipard\Core\Config\ServerConfig;
use Shipard\Core\Server\CronProvisioner;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Idempotentní generátor /etc/cron.d/shipard + runtime adresáře pro locky
 * a heartbeaty. Volá ho `shpd-server upgrade` jako subproces (po pullu běží
 * vždy nový kód), admin ho může spustit i ručně. Přepisuje jen když se
 * rendrovaný obsah liší od souboru na disku.
 */
class CronInstallCommand extends Command
{
    public function __construct(
        private readonly ?ServerConfig $serverConfig = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('cron-install')
             ->setDescription('Generate /etc/cron.d/shipard and the runtime directory (idempotent)')
             ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show the rendered file and whether it would be (re)written, without changing anything');
    }

    protected function getCronFilePath(): string
    {
        return CronProvisioner::CRON_FILE;
    }

    protected function getRunDir(): string
    {
        return CronProvisioner::RUN_DIR;
    }

    protected function getRepoRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    protected function getPhpBinary(): string
    {
        return PHP_BINARY;
    }

    protected function getEuid(): int
    {
        return posix_geteuid();
    }

    /** Server mode; 'development' když server.json chybí (produkce ho má vždy). */
    protected function getServerMode(): string
    {
        try {
            $cfg = $this->serverConfig;
            if ($cfg === null) {
                $cfg = new ServerConfig();
                $cfg->load();
            }
            return $cfg->getMode();
        } catch (\Throwable) {
            return 'development';
        }
    }

    /** Stejná logika jako DoctorCommand::detectShipardUser(). */
    protected function detectShipardUser(string $mode): string
    {
        if ($mode === 'production') {
            return 'shipard';
        }
        if (is_dir('/opt/shipard')) {
            $stat = @stat('/opt/shipard');
            if ($stat !== false) {
                $info = posix_getpwuid($stat['uid']);
                if (is_array($info) && isset($info['name'])) {
                    return $info['name'];
                }
            }
        }
        $login = posix_getlogin();
        return $login !== false && $login !== '' ? $login : 'unknown';
    }

    /** Chown/chgrp — vyžaduje root; v testech no-op override. */
    protected function applyOwnership(string $path, string $user): void
    {
        @chown($path, $user);
        @chgrp($path, $user);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $mode = $this->getServerMode();
        $shipardUser = $this->detectShipardUser($mode);
        $cronFile = $this->getCronFilePath();

        $content = new CronProvisioner()->renderTemplate(
            $this->getPhpBinary(),
            $this->getRepoRoot(),
            $shipardUser,
        );

        $existing = is_file($cronFile) ? (string) @file_get_contents($cronFile) : null;
        $upToDate = $existing === $content;

        if ($input->getOption('dry-run')) {
            $output->writeln('Target: ' . $cronFile);
            $output->writeln($upToDate
                ? '<info>Up to date — nothing would be written.</info>'
                : ($existing === null
                    ? '<comment>Would create the file:</comment>'
                    : '<comment>Content differs — would rewrite:</comment>'));
            $output->writeln('');
            $output->write($content);
            return Command::SUCCESS;
        }

        if ($this->getEuid() !== 0) {
            $output->writeln('<error>cron-install must run as root (writes ' . dirname($cronFile) . ').</error>');
            return Command::FAILURE;
        }

        // Runtime adresář pro locky a heartbeaty — píší do něj cron joby
        // běžící pod shipard uživatelem.
        $runDir = $this->getRunDir();
        if (!is_dir($runDir)) {
            if (!@mkdir($runDir, 0750, true)) {
                $output->writeln('<error>Cannot create ' . $runDir . '</error>');
                return Command::FAILURE;
            }
            $output->writeln('✓ created ' . $runDir);
        }
        @chmod($runDir, 0750);
        $this->applyOwnership($runDir, $shipardUser);

        if ($upToDate) {
            $output->writeln(sprintf(
                '✓ %s up to date (template version %d)',
                $cronFile,
                CronProvisioner::TEMPLATE_VERSION,
            ));
            return Command::SUCCESS;
        }

        // Atomický zápis: jméno s tečkou cron ignoruje, rozepsaný soubor se
        // tedy nikdy nevykoná; rename je na stejném filesystému atomické.
        $tmp = dirname($cronFile) . '/shipard.tmp';
        if (@file_put_contents($tmp, $content) === false) {
            $output->writeln('<error>Cannot write ' . $tmp . '</error>');
            return Command::FAILURE;
        }
        @chmod($tmp, 0644);
        if (!@rename($tmp, $cronFile)) {
            @unlink($tmp);
            $output->writeln('<error>Cannot move ' . $tmp . ' to ' . $cronFile . '</error>');
            return Command::FAILURE;
        }

        $output->writeln(sprintf(
            '✓ %s written (template version %d)',
            $cronFile,
            CronProvisioner::TEMPLATE_VERSION,
        ));
        return Command::SUCCESS;
    }
}
