<?php

declare(strict_types=1);

namespace Shipard\Command\DataSource;

use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class SeedClearCommand extends Command
{
    public function __construct(
        private readonly ?DataSourceConfig $dsConfig = null,
        private readonly ?DataSourceConnection $dsConnection = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('seed-clear')
             ->setDescription('Remove all seeded test data (persons with TEST- prefix and their contacts/bank accounts)');
    }

    protected function getDataSourceDir(): string
    {
        return getcwd();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dsDir = $this->getDataSourceDir();

        if ($this->dsConfig === null && !file_exists($dsDir . '/config/main.json')) {
            $output->writeln('<e>Error: Not a Shipard data source directory</e>');
            return Command::FAILURE;
        }

        $dsConfig = $this->dsConfig ?? new DataSourceConfig($dsDir);
        $dsConnection = $this->dsConnection ?? new DataSourceConnection($dsConfig);

        // Find all TEST- person IDs
        $seedPersons = $dsConnection->fetchAll(
            'SELECT id, person_id FROM base_persons_persons WHERE person_id LIKE %s',
            'TEST-%',
        );

        if (empty($seedPersons)) {
            $output->writeln('No seeded test data found.');
            return Command::SUCCESS;
        }

        $personIds = array_map(fn(array $row): int => (int) $row['id'], $seedPersons);
        $idList = implode(',', $personIds);

        $output->writeln('<info>Clearing seeded test data...</info>');
        $output->writeln('  Found ' . count($seedPersons) . ' TEST- persons.');

        $dsConnection->execute('START TRANSACTION');

        try {
            // Delete child records first (contacts, bank accounts)
            $dsConnection->execute('DELETE FROM base_persons_contacts WHERE person IN (' . $idList . ')');
            $contactsDeleted = $this->getAffectedRows($dsConnection);
            $output->writeln('  Deleted contacts: ' . $contactsDeleted);

            $dsConnection->execute('DELETE FROM base_persons_bank_accounts WHERE person IN (' . $idList . ')');
            $bankAccountsDeleted = $this->getAffectedRows($dsConnection);
            $output->writeln('  Deleted bank accounts: ' . $bankAccountsDeleted);

            // Delete addresses if the table exists
            try {
                $dsConnection->execute('DELETE FROM base_persons_addresses WHERE person IN (' . $idList . ')');
                $addressesDeleted = $this->getAffectedRows($dsConnection);
                if ($addressesDeleted > 0) {
                    $output->writeln('  Deleted addresses: ' . $addressesDeleted);
                }
            } catch (\Throwable) {
                // Table may not exist yet — that's fine
            }

            // Delete persons
            $dsConnection->execute("DELETE FROM base_persons_persons WHERE person_id LIKE 'TEST-%'");
            $output->writeln('  Deleted persons: ' . count($seedPersons));

            $dsConnection->execute('COMMIT');
        } catch (\Throwable $e) {
            $dsConnection->execute('ROLLBACK');
            $output->writeln('<e>Error: ' . $e->getMessage() . '</e>');
            $output->writeln('<e>Transaction rolled back — no data was deleted.</e>');
            return Command::FAILURE;
        }

        $output->writeln('');
        $output->writeln('<info>Seed data cleared.</info>');

        return Command::SUCCESS;
    }

    /**
     * Get affected rows count after DELETE.
     * Falls back to 0 if not available (depends on Dibi driver).
     */
    private function getAffectedRows(DataSourceConnection $dsConnection): int
    {
        try {
            $row = $dsConnection->fetchRow('SELECT ROW_COUNT() AS cnt');
            return $row !== null ? (int) $row['cnt'] : 0;
        } catch (\Throwable) {
            return 0;
        }
    }
}
