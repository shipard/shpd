<?php

declare(strict_types=1);

namespace Shipard\Command\DataSource;

use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Seed\FakeBankAccountGenerator;
use Shipard\Core\Seed\FakeContactGenerator;
use Shipard\Core\Seed\FakePersonGenerator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class SeedPersonsCommand extends Command
{
    public function __construct(
        private readonly ?DataSourceConfig $dsConfig = null,
        private readonly ?DataSourceConnection $dsConnection = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('seed-persons')
             ->setDescription('Generate fake persons with optional contacts and bank accounts')
             ->addOption('count', 'c', InputOption::VALUE_REQUIRED, 'Number of persons to generate', '50')
             ->addOption('with-contacts', null, InputOption::VALUE_NONE, 'Also generate contacts for each person')
             ->addOption('with-bank-accounts', null, InputOption::VALUE_NONE, 'Also generate bank accounts for each person')
             ->addOption('company-ratio', null, InputOption::VALUE_REQUIRED, 'Ratio of companies (0-100)', '40');
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

        $count = (int) $input->getOption('count');
        $withContacts = $input->getOption('with-contacts');
        $withBankAccounts = $input->getOption('with-bank-accounts');
        $companyRatio = max(0, min(100, (int) $input->getOption('company-ratio')));

        if ($count < 1 || $count > 10000) {
            $output->writeln('<e>Error: Count must be between 1 and 10000</e>');
            return Command::FAILURE;
        }

        $dsConfig = $this->dsConfig ?? new DataSourceConfig($dsDir);
        $dsConnection = $this->dsConnection ?? new DataSourceConnection($dsConfig);

        // Check for existing seed data
        $existing = $dsConnection->fetchRow(
            'SELECT COUNT(*) AS cnt FROM base_persons_persons WHERE person_id LIKE %s',
            'TEST-%',
        );

        if ($existing !== null && (int) $existing['cnt'] > 0) {
            $output->writeln('<comment>Warning: Found ' . $existing['cnt'] . ' existing TEST- persons. Run seed-clear first to avoid duplicates.</comment>');
            $output->writeln('');
        }

        $personGen = new FakePersonGenerator();
        $contactGen = new FakeContactGenerator();
        $bankGen = new FakeBankAccountGenerator();

        $output->writeln('<info>Seeding ' . $count . ' persons...</info>');
        $output->writeln('  Company ratio: ' . $companyRatio . '%');
        $output->writeln('  Contacts: ' . ($withContacts ? 'yes' : 'no'));
        $output->writeln('  Bank accounts: ' . ($withBankAccounts ? 'yes' : 'no'));
        $output->writeln('');

        // Find the highest existing TEST- index to continue from
        $lastSeed = $dsConnection->fetchRow(
            'SELECT person_id FROM base_persons_persons WHERE person_id LIKE %s ORDER BY person_id DESC LIMIT 1',
            'TEST-%',
        );

        $startIndex = 1;
        if ($lastSeed !== null) {
            $parts = explode('-', $lastSeed['person_id']);
            if (count($parts) === 2) {
                $startIndex = (int) $parts[1] + 1;
            }
        }

        $personsCreated = 0;
        $contactsCreated = 0;
        $bankAccountsCreated = 0;

        $dsConnection->execute('START TRANSACTION');

        try {
            for ($i = 0; $i < $count; $i++) {
                $index = $startIndex + $i;
                $personType = (random_int(1, 100) <= $companyRatio) ? 2 : 1;

                $personData = $personGen->generate($index, $personType);
                $personId = $dsConnection->insertRow('base_persons_persons', $personData);
                $personsCreated++;

                if ($withContacts) {
                    $contacts = $contactGen->generate($personId);
                    foreach ($contacts as $contact) {
                        $dsConnection->insertRow('base_persons_contacts', $contact);
                        $contactsCreated++;
                    }
                }

                if ($withBankAccounts) {
                    $bankAccounts = $bankGen->generate($personId);
                    foreach ($bankAccounts as $account) {
                        $dsConnection->insertRow('base_persons_bank_accounts', $account);
                        $bankAccountsCreated++;
                    }
                }

                // Progress output every 10 persons
                if (($i + 1) % 10 === 0 || $i === $count - 1) {
                    $output->write("\r  Progress: " . ($i + 1) . '/' . $count);
                }
            }

            $dsConnection->execute('COMMIT');
        } catch (\Throwable $e) {
            $dsConnection->execute('ROLLBACK');
            $output->writeln('');
            $output->writeln('<e>Error: ' . $e->getMessage() . '</e>');
            $output->writeln('<e>Transaction rolled back — no data was written.</e>');
            return Command::FAILURE;
        }

        $output->writeln('');
        $output->writeln('');
        $output->writeln('<info>Seed complete.</info>');
        $output->writeln('  Persons:       ' . $personsCreated);
        if ($withContacts) {
            $output->writeln('  Contacts:      ' . $contactsCreated);
        }
        if ($withBankAccounts) {
            $output->writeln('  Bank accounts: ' . $bankAccountsCreated);
        }

        return Command::SUCCESS;
    }
}
