<?php

declare(strict_types=1);

namespace Shipard\Command\DataSource;

use Shipard\Api\TableLoader;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Module\Core\Attachments\AttachmentService;
use Shipard\Module\Core\Mail\FakeIncomingMessageGenerator;
use Shipard\Module\Core\Mail\FakeMailboxGenerator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Generate fake mailboxes and incoming messages for the `core.mail` module.
 *
 * Schránky: 3 fixní (TEST-invoices, TEST-info, TEST-support). Pokud už existují,
 * znovu se nevytváří.
 *
 * Zprávy: 40–80 kusů (volitelné přes --count), rovnoměrně rozdělené mezi schránky.
 * Cca 80 % dostane jednu kopii `modules/core/mail/testdata/sample-invoice.pdf`
 * jako přílohu (uloženou přes AttachmentService).
 *
 * Identifikace test záznamů: `message_id` začíná `TEST-MSG-`, `mailbox_id` začíná `TEST-`.
 */
class SeedMailCommand extends Command
{
    private const MAIL_TABLE_ID = 303;
    private const SAMPLE_PDF_RELATIVE = '/modules/core/mail/testdata/sample-invoice.pdf';
    private const ATTACHMENT_PROBABILITY = 80; // procent

    public function __construct(
        private readonly ?DataSourceConfig $dsConfig = null,
        private readonly ?DataSourceConnection $dsConnection = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('seed-mail')
             ->setDescription('Generate fake mailboxes and incoming messages for core.mail')
             ->addOption('count', 'c', InputOption::VALUE_REQUIRED, 'Number of messages to generate (40-80 doporučeno)', '60')
             ->addOption('attachment-ratio', null, InputOption::VALUE_REQUIRED, 'Percent messages receiving the sample PDF (0-100)', (string) self::ATTACHMENT_PROBABILITY);
    }

    protected function getDataSourceDir(): string
    {
        return getcwd();
    }

    protected function getShipardRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dsDir = $this->getDataSourceDir();

        if ($this->dsConfig === null && !file_exists($dsDir . '/config/main.json')) {
            $output->writeln('<e>Error: Not a Shipard data source directory</e>');
            return Command::FAILURE;
        }

        $count = (int) $input->getOption('count');
        if ($count < 1 || $count > 500) {
            $output->writeln('<e>Error: Count must be between 1 and 500</e>');
            return Command::FAILURE;
        }

        $attachmentRatio = max(0, min(100, (int) $input->getOption('attachment-ratio')));

        $dsConfig = $this->dsConfig ?? new DataSourceConfig($dsDir);
        $dsConnection = $this->dsConnection ?? new DataSourceConnection($dsConfig);

        $shipardRoot = $this->getShipardRoot();
        $modulesPath = $shipardRoot . '/modules';
        $samplePdfPath = $shipardRoot . self::SAMPLE_PDF_RELATIVE;

        if (!file_exists($samplePdfPath)) {
            $output->writeln('<e>Error: Sample PDF not found at ' . $samplePdfPath . '</e>');
            return Command::FAILURE;
        }

        // Table defs — AttachmentService potřebuje mapu tableId → tableName
        $tableDefs = TableLoader::load($dsConfig, new ModulePathResolver([$modulesPath]), 'cs');

        $output->writeln('<info>Seeding core.mail test data...</info>');
        $output->writeln('  Messages:         ' . $count);
        $output->writeln('  Attachment ratio: ' . $attachmentRatio . '%');
        $output->writeln('');

        // --- Schránky ---------------------------------------------------------

        $mailboxIds = $this->ensureMailboxes($dsConnection, $output);
        if ($mailboxIds === []) {
            return Command::FAILURE;
        }

        // --- Zprávy -----------------------------------------------------------

        $startIndex = $this->getNextMessageIndex($dsConnection);
        $messageGen = new FakeIncomingMessageGenerator();
        $mailboxList = array_values($mailboxIds);

        $attachmentService = new AttachmentService($dsConnection, $dsDir, $tableDefs);

        $messagesCreated = 0;
        $attachmentsCreated = 0;
        $failures = 0;

        for ($i = 0; $i < $count; $i++) {
            $index = $startIndex + $i;
            $mailbox = $mailboxList[array_rand($mailboxList)];
            $mailboxPk = $mailbox['id'];
            $defaultPrimaryType = $mailbox['default_primary_type'] ?? 'other';

            try {
                $messageData = $messageGen->generate($index, $mailboxPk, $defaultPrimaryType);
                $messageRowId = $dsConnection->insertRow('core_mail_incoming_messages', $messageData);
                $messagesCreated++;

                if ($attachmentRatio > 0 && random_int(1, 100) <= $attachmentRatio) {
                    // Attachment vyžaduje fresh temp soubor — upload() soubor rename() přesune
                    $tmpPath = $this->copyToTemp($samplePdfPath);
                    $result = $attachmentService->upload(
                        self::MAIL_TABLE_ID,
                        $messageRowId,
                        'sample-invoice.pdf',
                        $tmpPath,
                    );
                    if (!($result['success'] ?? false)) {
                        $output->writeln("\n<comment>Warning: attachment upload failed for message {$messageRowId}: " . ($result['error'] ?? 'unknown') . '</comment>');
                        @unlink($tmpPath);
                        $failures++;
                    } else {
                        $attachmentsCreated++;
                    }
                }
            } catch (\Throwable $e) {
                $output->writeln("\n<e>Error at message index {$index}: " . $e->getMessage() . '</e>');
                $failures++;
            }

            if (($i + 1) % 10 === 0 || $i === $count - 1) {
                $output->write("\r  Progress: " . ($i + 1) . '/' . $count);
            }
        }

        $output->writeln('');
        $output->writeln('');
        $output->writeln('<info>Seed complete.</info>');
        $output->writeln('  Mailboxes (total):    ' . count($mailboxIds));
        $output->writeln('  Messages created:     ' . $messagesCreated);
        $output->writeln('  Attachments created:  ' . $attachmentsCreated);
        if ($failures > 0) {
            $output->writeln('  <comment>Failures: ' . $failures . '</comment>');
        }

        return $failures === 0 ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * Založí chybějící test schránky (podle `mailbox_id` s prefixem `TEST-`).
     * Vrací mapu `mailbox_id` → ['id' => pk, 'default_primary_type' => ...].
     *
     * @return array<string, array{id: int, default_primary_type: ?string}>
     */
    private function ensureMailboxes(DataSourceConnection $dsConnection, OutputInterface $output): array
    {
        $gen = new FakeMailboxGenerator();
        $mailboxes = $gen->generate();
        $result = [];

        foreach ($mailboxes as $data) {
            $existing = $dsConnection->fetchRow(
                'SELECT id, default_primary_type FROM core_mail_mailboxes WHERE mailbox_id = %s',
                $data['mailbox_id'],
            );

            if ($existing !== null) {
                $output->writeln('  Mailbox ' . $data['mailbox_id'] . ' already exists (id=' . $existing['id'] . ')');
                $result[$data['mailbox_id']] = [
                    'id' => (int) $existing['id'],
                    'default_primary_type' => $existing['default_primary_type'] ?? null,
                ];
                continue;
            }

            try {
                $newId = $dsConnection->insertRow('core_mail_mailboxes', $data);
                $output->writeln('  Created mailbox: ' . $data['mailbox_id'] . ' (id=' . $newId . ')');
                $result[$data['mailbox_id']] = [
                    'id' => $newId,
                    'default_primary_type' => $data['default_primary_type'],
                ];
            } catch (\Throwable $e) {
                $output->writeln('<e>  Failed to create mailbox ' . $data['mailbox_id'] . ': ' . $e->getMessage() . '</e>');
            }
        }

        return $result;
    }

    /**
     * Najde nejvyšší existující TEST-MSG-NNNN index a vrátí následující.
     * Pro čerstvý seed vrací 1.
     */
    private function getNextMessageIndex(DataSourceConnection $dsConnection): int
    {
        $row = $dsConnection->fetchRow(
            'SELECT message_id FROM core_mail_incoming_messages WHERE message_id LIKE %s ORDER BY message_id DESC LIMIT 1',
            FakeIncomingMessageGenerator::ID_PREFIX . '%',
        );

        if ($row === null) {
            return 1;
        }

        $numeric = substr($row['message_id'], strlen(FakeIncomingMessageGenerator::ID_PREFIX));
        $lastIndex = (int) $numeric;

        return $lastIndex > 0 ? $lastIndex + 1 : 1;
    }

    /**
     * Vytvoří čerstvou kopii PDF v systémovém tmp adresáři. AttachmentService.upload()
     * soubor rename() přesune do svého úložiště, originál tedy zůstane nedotčen.
     */
    private function copyToTemp(string $sourcePath): string
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'shpd-seed-mail-');
        if ($tmpPath === false) {
            throw new \RuntimeException('Failed to create temp file for attachment seed');
        }
        if (!copy($sourcePath, $tmpPath)) {
            @unlink($tmpPath);
            throw new \RuntimeException("Failed to copy sample PDF to {$tmpPath}");
        }
        return $tmpPath;
    }
}
