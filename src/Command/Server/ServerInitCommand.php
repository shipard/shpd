<?php

declare(strict_types=1);

namespace Shipard\Command\Server;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ServerInitCommand extends Command
{
    protected string $serverConfigPath = '/etc/shipard/server.json';

    protected function configure(): void
    {
        $this->setName('server-init')
             ->setDescription('Initialize the Shipard server configuration');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->isRunningAsRoot()) {
            $output->writeln('<error>This command must be run as root</error>');
            return Command::FAILURE;
        }

        if (file_exists($this->serverConfigPath)) {
            $output->writeln('<info>Server is already initialized</info>');
            return Command::SUCCESS;
        }

        $password = $this->generatePassword();

        if (!$this->runMysqladmin($password)) {
            $output->writeln('<error>Failed to set MariaDB root password via mysqladmin</error>');
            return Command::FAILURE;
        }

        $dir = dirname($this->serverConfigPath);
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            $output->writeln('<error>Failed to create config directory</error>');
            return Command::FAILURE;
        }

        $config = [
            'host'           => '127.0.0.1',
            'port'           => 3306,
            'admin_user'     => 'root',
            'admin_password' => $password,
            'mode'           => 'production',
        ];
        file_put_contents($this->serverConfigPath, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        chmod($this->serverConfigPath, 0600);

        $output->writeln('<info>Server initialized successfully</info>');
        $output->writeln("  Config: <comment>{$this->serverConfigPath}</comment>");
        return Command::SUCCESS;
    }

    protected function isRunningAsRoot(): bool
    {
        return posix_getuid() === 0;
    }

    protected function generatePassword(): string
    {
        $charset = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()_+-=[]{}|';
        $password = '';
        for ($i = 0; $i < 32; $i++) {
            $password .= $charset[random_int(0, strlen($charset) - 1)];
        }
        return $password;
    }

    protected function runMysqladmin(string $password): bool
    {
        $escaped = escapeshellarg($password);
        exec("mysqladmin -u root password {$escaped}", $out, $exitCode);
        return $exitCode === 0;
    }
}
