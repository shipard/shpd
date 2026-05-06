<?php

declare(strict_types=1);

namespace Shipard\Command\DataSource;

use Shipard\Core\Config\ConfigCompiler;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Database\ExtensionDefinition;
use Shipard\Core\Database\SchemaComparator;
use Shipard\Core\Database\SchemaValidator;
use Shipard\Core\Database\SqlGenerator;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Database\TableMerger;
use Shipard\Core\Module\ModuleLoader;
use Shipard\Core\Module\ModuleResolver;
use Shipard\Core\Security\DsSecretCipher;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Module\Core\Mail\AIAnalyzerProvisioner;
use Shipard\Module\Core\Mail\MailRouterProvisioner;
use Shipard\Module\Core\Units\UnitsProvisioner;
use Shipard\Module\Docs\Core\NumberSeriesProvisioner;
use Shipard\Module\Economy\Codebooks\FiscalYearsProvisioner;
use Shipard\Module\Economy\Codebooks\VatPeriodsProvisioner;
use Shipard\Module\Economy\Items\ItemKindsProvisioner;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class DsUpgradeCommand extends Command
{
    public function __construct(
        private readonly ?DataSourceConfig $dsConfig = null,
        private readonly ?DataSourceConnection $dsConnection = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('ds-upgrade')
             ->setDescription('Upgrade the data source schema and configuration');
    }

    protected function getDataSourceDir(): string
    {
        return getcwd();
    }

    protected function getModulesBasePath(): string
    {
        return dirname(__DIR__, 3) . '/modules';
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dsDir = $this->getDataSourceDir();
        $modulesBasePath = $this->getModulesBasePath();

        $dsConfig = $this->dsConfig ?? new DataSourceConfig($dsDir);
        $dsConnection = $this->dsConnection ?? new DataSourceConnection($dsConfig);

        $output->writeln('<info>Shipard Data Source Upgrade v0.1.0</info>');
        $output->writeln('Data source: ' . $dsConfig->getName() . ' (' . $dsConfig->getId() . ')');
        $output->writeln('');

        // Ensure writable directories exist (att, cache)
        foreach (['att', 'cache/thumbnails'] as $subdir) {
            $dirPath = $dsDir . '/' . $subdir;
            if (!is_dir($dirPath)) {
                @mkdir($dirPath, 0755, true);
            }
        }

        // Step 1.5: Ensure per-DS secrets key exists (generated for legacy DS
        // upgraded from before encrypted_text was introduced).
        $secretsKeyFile = DsSecretCipher::keyFilePath($dsDir);
        if (!is_file($secretsKeyFile)) {
            try {
                DsSecretCipher::generateKey($dsDir);
                $output->writeln('  [INFO] Created secrets/secrets.key — no data migration needed');
                $output->writeln('         (no encrypted columns existed in this DS yet).');
            } catch (\RuntimeException $e) {
                $output->writeln('<error>Failed to initialise secrets key: ' . $e->getMessage() . '</error>');
                return Command::FAILURE;
            }
        }

        // Step 2: Resolve modules
        $output->writeln('Resolving modules...');
        $allModules = ModuleLoader::loadAllModules($modulesBasePath);
        $errors = [];
        $resolvedModules = ModuleResolver::resolve($allModules, $dsConfig->getModules(), $errors);

        foreach ($errors as $error) {
            $output->writeln('<error>' . $error . '</error>');
        }

        $directCount = count($dsConfig->getModules());
        $totalCount = count($resolvedModules);
        $depCount = $totalCount - $directCount;
        $output->writeln('  Active modules: ' . $totalCount . ' (' . $directCount . ' direct + ' . $depCount . ' dependencies)');
        $output->writeln('  Module order: ' . implode(', ', array_map(fn($m) => $m->id, $resolvedModules)));
        $output->writeln('');

        // Step 3: Load table definitions and apply extensions
        $rawTables = [];
        $tableDefs = [];

        foreach ($resolvedModules as $module) {
            [$group, $name] = explode('.', $module->id, 2);
            $modulePath = $modulesBasePath . '/' . $group . '/' . $name;

            foreach ($module->tables as $tableFile) {
                $filePath = $modulePath . '/tables/' . $tableFile . '.jsonc';
                $raw = JsoncParser::parseFile($filePath);
                $rawTables[$tableFile] = $raw;
                $tableDefs[$tableFile] = TableDefinition::fromArray($raw);
            }
        }

        foreach ($resolvedModules as $module) {
            [$group, $name] = explode('.', $module->id, 2);
            $modulePath = $modulesBasePath . '/' . $group . '/' . $name;

            foreach ($module->extensions as $extFile) {
                $filePath = $modulePath . '/extensions/' . $extFile . '.jsonc';
                $extData = JsoncParser::parseFile($filePath);
                $ext = ExtensionDefinition::fromArray($extData);

                if (isset($tableDefs[$ext->table])) {
                    $tableDefs[$ext->table] = TableMerger::merge($tableDefs[$ext->table], $ext);
                }
            }
        }

        // Step 4: Validate
        $validation = SchemaValidator::validate(array_values($rawTables));
        if (!empty($validation['errors'])) {
            foreach ($validation['errors'] as $error) {
                $output->writeln('<error>' . $error . '</error>');
            }
            return Command::FAILURE;
        }
        foreach ($validation['warnings'] as $warning) {
            $output->writeln('<comment>' . $warning . '</comment>');
        }

        // Step 5: Compile configuration
        $output->writeln('Compiling configuration...');
        $languages = ['cs', 'en'];
        $outputPath = $dsDir . '/config/configuration';
        ConfigCompiler::compile($resolvedModules, $modulesBasePath, $languages, $outputPath);

        $configItemCount = 0;
        foreach ($resolvedModules as $module) {
            $configItemCount += count($module->config);
        }

        $output->writeln('  Config items: ' . $configItemCount);
        $output->writeln('  Languages: ' . implode(', ', $languages));
        foreach ($languages as $lang) {
            $output->writeln('  Written to: config/configuration/compiled.' . $lang . '.json');
        }
        $output->writeln('');

        // Step 6: Sync database schema
        $output->writeln('Checking database...');
        $created = 0;
        $altered = 0;
        $unchanged = 0;

        foreach ($tableDefs as $tableName => $tableDef) {
            $existingColumns = $dsConnection->getTableColumns($tableName);
            $existingIndexes = $dsConnection->getTableIndexes($tableName);
            $ops = SchemaComparator::compare($tableDef, $existingColumns, $existingIndexes);

            if (empty($ops)) {
                $output->writeln('  [OK]     ' . $tableName);
                $unchanged++;
                continue;
            }

            $hasCreate = false;
            foreach ($ops as $op) {
                if ($op['op'] === 'create_table') {
                    $hasCreate = true;
                    break;
                }
            }

            if ($hasCreate) {
                $sql = SqlGenerator::generateCreateTable($tableName, $tableDef);
                $dsConnection->executeSQL($sql);

                foreach ($tableDef->indexes as $index) {
                    $idxSql = SqlGenerator::generateCreateIndex($tableName, $index);
                    $dsConnection->executeSQL($idxSql);
                }

                $output->writeln('  [CREATE] ' . $tableName);
                foreach ($tableDef->columns as $col) {
                    if ($col->type === 'encrypted_text') {
                        $this->logEncryptedColumnAdded($output, $tableName, $col->id);
                    }
                }
                $created++;
            } else {
                $changes = [];
                $newEncryptedColumns = [];
                foreach ($ops as $op) {
                    if ($op['op'] === 'add_column') {
                        $sql = SqlGenerator::generateAddColumn($tableName, $op['column']);
                        $dsConnection->executeSQL($sql);
                        $changes[] = 'added column: ' . $op['column']->id . ' (' . $op['column']->type . ')';
                        if ($op['column']->type === 'encrypted_text') {
                            $newEncryptedColumns[] = $op['column']->id;
                        }
                    } elseif ($op['op'] === 'modify_column') {
                        $sql = SqlGenerator::generateModifyColumn($tableName, $op['column']);
                        $dsConnection->executeSQL($sql);
                        $changes[] = 'modified column: ' . $op['column']->id;
                    } elseif ($op['op'] === 'create_index') {
                        $sql = SqlGenerator::generateCreateIndex($tableName, $op['index']);
                        $dsConnection->executeSQL($sql);
                        $changes[] = 'added index: ' . $op['index']->id;
                    }
                }
                $output->writeln('  [ALTER]  ' . $tableName . ' — ' . implode(', ', $changes));
                foreach ($newEncryptedColumns as $columnId) {
                    $this->logEncryptedColumnAdded($output, $tableName, $columnId);
                }
                $altered++;
            }
        }

        $output->writeln('');
        $output->writeln('Upgrade complete. ' . $created . ' tables created, ' . $altered . ' tables altered, ' . $unchanged . ' tables unchanged.');

        $this->provisionUnits($resolvedModules, $dsConnection, $output);
        $this->provisionItemKinds($resolvedModules, $dsConnection, $output);
        $this->provisionFiscalYears($resolvedModules, $dsDir, $dsConnection, $output);
        $this->provisionVatPeriods($resolvedModules, $dsConnection, $output);
        $this->provisionDocCoreNumberSeries($resolvedModules, $dsDir, $dsConnection, $output);
        $this->provisionMailRouter($dsConfig, $dsConnection, $output);
        $this->provisionAiAnalyzer($dsConfig, $dsConnection, $output);

        $secretsWarnings = DsSecretCipher::healthCheck($dsConfig);
        foreach ($secretsWarnings as $warning) {
            $output->writeln('<comment>  [WARN] ' . $warning . '</comment>');
        }

        return Command::SUCCESS;
    }

    private function logEncryptedColumnAdded(OutputInterface $output, string $table, string $column): void
    {
        $output->writeln(sprintf("  [INFO] Adding encrypted_text column '%s.%s'.", $table, $column));
        $output->writeln('         Application layer must use DsSecretCipher for read/write.');
        $output->writeln('         Plaintext values will not be readable from DB directly.');
    }

    private function provisionMailRouter(
        DataSourceConfig $dsConfig,
        DataSourceConnection $dsConnection,
        OutputInterface $output,
    ): void {
        $output->writeln('');
        $output->writeln('Provisioning mail router...');

        $provisioner = new MailRouterProvisioner($dsConnection);
        $result = $provisioner->provision($dsConfig->getId());

        $user = $result['user'];
        $mailbox = $result['mailbox'];

        $userTag = $user['created'] ? '[CREATE]' : '[OK]    ';
        $output->writeln("  {$userTag} user '_mail_router' (id={$user['id']})");

        if ($mailbox['created']) {
            $output->writeln("  [CREATE] mailbox 'default' (id={$mailbox['id']})");
        } elseif (isset($mailbox['skipped_reason'])) {
            $output->writeln("  <comment>[SKIP]   mailbox 'default' — {$mailbox['skipped_reason']}</comment>");
        } else {
            $output->writeln("  [OK]     mailbox 'default' (id={$mailbox['id']})");
        }
    }

    private function provisionAiAnalyzer(
        DataSourceConfig $dsConfig,
        DataSourceConnection $dsConnection,
        OutputInterface $output,
    ): void {
        $output->writeln('');
        $output->writeln('Provisioning AI analyzer...');

        $provisioner = new AIAnalyzerProvisioner($dsConnection);
        $result = $provisioner->provision();

        $user = $result['user'];
        $backend = $result['backend'];
        $profile = $result['profile'];

        $userTag = $user['created'] ? '[CREATE]' : '[OK]    ';
        $output->writeln("  {$userTag} user '_ai_analyzer' (id={$user['id']})");

        if ($backend['created']) {
            $output->writeln("  [CREATE] backend 'default' (id={$backend['id']})");
            $output->writeln("           <comment>API key not set — run 'bin/shpd-ds ai-analyzer-set-key' to enable.</comment>");
        } elseif (isset($backend['skipped_reason'])) {
            $output->writeln("  <comment>[SKIP]   backend 'default' — {$backend['skipped_reason']}</comment>");
        } else {
            $output->writeln("  [OK]     backend 'default' (id={$backend['id']})");
        }

        if ($profile['created']) {
            $output->writeln("  [CREATE] profile 'czech_invoices' (id={$profile['id']})");
        } elseif (isset($profile['skipped_reason'])) {
            $output->writeln("  <comment>[SKIP]   profile 'czech_invoices' — {$profile['skipped_reason']}</comment>");
        } else {
            $output->writeln("  [OK]     profile 'czech_invoices' (id={$profile['id']})");
        }
    }

    /**
     * @param list<\Shipard\Core\Module\ModuleDefinition> $resolvedModules
     */
    private function provisionUnits(
        array $resolvedModules,
        DataSourceConnection $dsConnection,
        OutputInterface $output,
    ): void {
        $output->writeln('');
        $output->writeln('Provisioning units...');

        if (!$this->isModuleActive($resolvedModules, 'core.units')) {
            $output->writeln('  <comment>[SKIP] core.units module not active</comment>');
            return;
        }

        $seedFile = $this->getModulesBasePath() . '/core/units/config/unitsSeed.jsonc';
        $provisioner = new UnitsProvisioner($dsConnection, $seedFile);
        $result = $provisioner->provision();

        $units = $result['units'];
        $output->writeln(sprintf(
            '  [OK]    units — created: %d, existing: %d',
            $units['created'],
            $units['existing'],
        ));
    }

    /**
     * @param list<\Shipard\Core\Module\ModuleDefinition> $resolvedModules
     */
    private function provisionItemKinds(
        array $resolvedModules,
        DataSourceConnection $dsConnection,
        OutputInterface $output,
    ): void {
        $output->writeln('');
        $output->writeln('Provisioning economy.items kinds...');

        if (!$this->isModuleActive($resolvedModules, 'economy.items')) {
            $output->writeln('  <comment>[SKIP] economy.items module not active</comment>');
            return;
        }

        $seedFile = $this->getModulesBasePath() . '/economy/items/config/itemKindsSeed.jsonc';
        $provisioner = new ItemKindsProvisioner($dsConnection, $seedFile);
        $result = $provisioner->provision();

        $kinds = $result['kinds'];
        $output->writeln(sprintf(
            '  [OK]    item kinds — created: %d, existing: %d',
            $kinds['created'],
            $kinds['existing'],
        ));
    }

    /**
     * @param list<\Shipard\Core\Module\ModuleDefinition> $resolvedModules
     */
    private function provisionFiscalYears(
        array $resolvedModules,
        string $dsDir,
        DataSourceConnection $dsConnection,
        OutputInterface $output,
    ): void {
        $output->writeln('');
        $output->writeln('Provisioning economy.codebooks fiscal years...');

        if (!$this->isModuleActive($resolvedModules, 'economy.codebooks')) {
            $output->writeln('  <comment>[SKIP] economy.codebooks module not active</comment>');
            return;
        }

        $compiledFile = $dsDir . '/config/configuration/compiled.cs.json';
        if (!is_file($compiledFile)) {
            $output->writeln('  <comment>[SKIP] config not compiled yet</comment>');
            return;
        }

        $config = ConfigRuntime::load($dsDir, 'cs');
        $provisioner = new FiscalYearsProvisioner($dsConnection, $config);
        $result = $provisioner->provision();

        $years = $result['fiscalYears'];
        $output->writeln(sprintf(
            '  [OK]    fiscal years — created: %d, existing: %d',
            $years['created'],
            $years['existing'],
        ));
    }

    /**
     * @param list<\Shipard\Core\Module\ModuleDefinition> $resolvedModules
     */
    private function provisionVatPeriods(
        array $resolvedModules,
        DataSourceConnection $dsConnection,
        OutputInterface $output,
    ): void {
        $output->writeln('');
        $output->writeln('Provisioning economy.codebooks vat periods...');

        if (!$this->isModuleActive($resolvedModules, 'economy.codebooks')) {
            $output->writeln('  <comment>[SKIP] economy.codebooks module not active</comment>');
            return;
        }

        $provisioner = new VatPeriodsProvisioner($dsConnection);
        $result = $provisioner->provision();

        $periods = $result['vatPeriods'];
        $output->writeln(sprintf(
            '  [OK]    vat periods — created: %d, existing: %d',
            $periods['created'],
            $periods['existing'],
        ));
    }

    /**
     * @param list<\Shipard\Core\Module\ModuleDefinition> $resolvedModules
     */
    private function provisionDocCoreNumberSeries(
        array $resolvedModules,
        string $dsDir,
        DataSourceConnection $dsConnection,
        OutputInterface $output,
    ): void {
        $output->writeln('');
        $output->writeln('Provisioning docs.core number series...');

        if (!$this->isModuleActive($resolvedModules, 'docs.core')) {
            $output->writeln('  <comment>[SKIP] docs.core module not active</comment>');
            return;
        }

        $compiledFile = $dsDir . '/config/configuration/compiled.cs.json';
        if (!is_file($compiledFile)) {
            $output->writeln('  <comment>[SKIP] config not compiled yet</comment>');
            return;
        }

        $config = ConfigRuntime::load($dsDir, 'cs');
        $provisioner = new NumberSeriesProvisioner($dsConnection, $config);
        $result = $provisioner->provision();

        $series = $result['numberSeries'];
        $output->writeln(sprintf(
            '  [OK]    number series — created: %d, existing: %d',
            $series['created'],
            $series['existing'],
        ));
    }

    /**
     * @param list<\Shipard\Core\Module\ModuleDefinition> $resolvedModules
     */
    private function isModuleActive(array $resolvedModules, string $moduleId): bool
    {
        foreach ($resolvedModules as $module) {
            if ($module->id === $moduleId) {
                return true;
            }
        }
        return false;
    }
}
