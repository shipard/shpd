<?php

declare(strict_types=1);

namespace Shipard\Command\Server;

use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Config\ServerConfig;
use Shipard\Core\Database\DatabaseManager;
use Shipard\Core\Module\InstallModuleRegistry;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Security\DsSecretCipher;
use Shipard\Core\Utils\IdGenerator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class DsCreateCommand extends Command
{
    public function __construct(
        private readonly ?ServerConfig $serverConfig = null,
        private readonly ?DatabaseManager $databaseManager = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('ds-create')
             ->setDescription('Create a new data source')
             ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Name of the data source')
             ->addOption(
                 'module',
                 null,
                 InputOption::VALUE_REQUIRED,
                 'Install module id (e.g. install.base)',
                 'install.base',
             );
    }

    protected function getDataSourcesDir(): string
    {
        return '/opt/shipard/data-sources';
    }

    protected function getModulePathResolver(): ModulePathResolver
    {
        $cfg = $this->serverConfig;
        if ($cfg === null) {
            $cfg = new ServerConfig();
            $cfg->load();
        }
        return ModulePathResolver::fromServerConfig($cfg, dirname(__DIR__, 3) . '/modules');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $input->getOption('name');

        if (empty($name)) {
            $output->writeln('<error>Option --name is required</error>');
            return Command::FAILURE;
        }

        $moduleId = (string) $input->getOption('module');

        if (!preg_match('/^install\.[a-z][a-zA-Z0-9]*$/', $moduleId)) {
            $output->writeln('<error>Invalid install module id: ' . $moduleId . '</error>');
            $output->writeln('<comment>Must match pattern: install.<name></comment>');
            return Command::FAILURE;
        }

        // Load server config first — needed by both the module-path resolver
        // (extraModulesPath) and DatabaseManager.
        $config = $this->serverConfig ?? new ServerConfig();
        try {
            $config->load();
        } catch (\RuntimeException $e) {
            $output->writeln('<error>Failed to load server config: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        $resolver = $this->getModulePathResolver();
        $registry = new InstallModuleRegistry($resolver);
        if (!$registry->exists($moduleId)) {
            $output->writeln('<error>Install module not found: ' . $moduleId . '</error>');
            $available = array_map(fn($m) => $m['id'], $registry->list());
            if ($available) {
                $output->writeln('<comment>Available: ' . implode(', ', $available) . '</comment>');
            } else {
                $output->writeln('<comment>No install modules found in ' . $resolver->getRoots()[0] . '/install/</comment>');
            }
            return Command::FAILURE;
        }

        // Generate unique ID
        $generator = new IdGenerator();
        $dataSourcesDir = $this->getDataSourcesDir();
        $id = $generator->generate($dataSourcesDir);

        $dbName = IdGenerator::toDatabaseName($id);
        $dbUser = IdGenerator::toDatabaseUser($id);

        // Create directory structure
        $dataSourceDir = $dataSourcesDir . '/' . $id;
        $configDir = $dataSourceDir . '/config';

        if (!mkdir($configDir, 0755, true)) {
            $output->writeln('<error>Failed to create data source directory</error>');
            return Command::FAILURE;
        }

        // Create database and user
        $dbManager = $this->databaseManager ?? new DatabaseManager($config);

        // Create writable directories for attachments, branding and cache
        @mkdir($dataSourceDir . '/att', 0755);
        @mkdir($dataSourceDir . '/branding', 0755);
        @mkdir($dataSourceDir . '/cache/thumbnails', 0755, true);
        @mkdir($dataSourceDir . '/cache/oidc', 0755, true);

        $password = $dbManager->generatePassword();

        try {
            $dbManager->createDatabase($dbName);
            $dbManager->createUser($dbUser, $password, $dbName);
        } catch (\Exception $e) {
            $output->writeln('<error>Failed to create database: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        // Write config file
        $mainConfig = [
            'id'                => $id,
            'name'              => $name,
            'modules'           => [$moduleId],
            'database_name'     => $dbName,
            'database_user'     => $dbUser,
            'database_password' => $password,
            'created'           => date('c'),
        ];

        $configFile = $configDir . '/main.json';
        file_put_contents($configFile, json_encode($mainConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        chmod($configFile, 0600);

        // Generate per-DS secrets key for encrypted_text columns
        try {
            DsSecretCipher::generateKey($dataSourceDir);
            $warnings = DsSecretCipher::healthCheck(new DataSourceConfig($dataSourceDir));
            foreach ($warnings as $warning) {
                $output->writeln('<comment>  [WARN] ' . $warning . '</comment>');
            }
        } catch (\RuntimeException $e) {
            $output->writeln('<error>Failed to initialise secrets key: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        // Output summary
        $output->writeln('');
        $output->writeln('<info>Data source created successfully</info>');
        $output->writeln('');
        $output->writeln("  ID:            <comment>{$id}</comment>");
        $output->writeln("  Name:          <comment>{$name}</comment>");
        $output->writeln("  Module:        <comment>{$moduleId}</comment>");
        $output->writeln("  Database:      <comment>{$dbName}</comment>");
        $output->writeln("  DB User:       <comment>{$dbUser}</comment>");
        $output->writeln("  Directory:     <comment>{$dataSourceDir}</comment>");
        $output->writeln('');

        return Command::SUCCESS;
    }
}
