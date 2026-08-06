<?php

declare(strict_types=1);

namespace Shipard\Command\DataSource;

use SensitiveParameter;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Security\DsSecretCipher;
use Shipard\Core\Security\Exception\SecretsKeyInsecureException;
use Shipard\Core\Security\Exception\SecretsKeyMissingException;
use Shipard\Module\Core\Ai\AIBackendDocument;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Nastaví API klíč existujícímu AI backendu. Klíč šifruje přes DsSecretCipher
 * (Document hook AIBackendDocument::beforeSave) a nastaví is_active=1.
 *
 * Plaintext klíč přijímáme přes --api-key. Příkaz neloguje hodnotu — naším
 * threat-modelem je "plaintext nesmí ležet v DB ani logu" (CLAUDE.md
 * "Citlivá data", docs/operations/secrets.md).
 *
 * Spec: tasks/mail-phase3a.md §6.4.
 */
class AiAnalyzerSetKeyCommand extends Command
{
    public function __construct(
        private readonly ?DataSourceConfig $dsConfig = null,
        private readonly ?DataSourceConnection $dsConnection = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('ai-analyzer-set-key')
             ->setDescription('Set (or rotate) the API key on an AI backend; encrypts via DsSecretCipher')
             ->addOption('backend', null, InputOption::VALUE_REQUIRED, 'Backend code (default: "default")', 'default')
             ->addOption('api-key', null, InputOption::VALUE_REQUIRED, 'Plaintext API key to encrypt and store')
             ->addOption('base-url', null, InputOption::VALUE_REQUIRED, 'Base URL of the API (empty string resets to direct Anthropic)');
    }

    protected function getDataSourceDir(): string
    {
        return getcwd();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dsDir = $this->getDataSourceDir();

        if ($this->dsConfig === null && !file_exists($dsDir . '/config/main.json')) {
            $output->writeln('<error>Error: Not a Shipard data source directory</error>');
            return Command::FAILURE;
        }

        $backendCode = (string) $input->getOption('backend');
        $apiKey = $input->getOption('api-key');

        if ($apiKey === null || $apiKey === '') {
            $output->writeln('<error>Error: --api-key is required</error>');
            return Command::FAILURE;
        }

        $dsConfig = $this->dsConfig ?? new DataSourceConfig($dsDir);
        $dsConnection = $this->dsConnection ?? new DataSourceConnection($dsConfig);

        try {
            $cipher = DsSecretCipher::forConfig($dsConfig);
        } catch (SecretsKeyMissingException | SecretsKeyInsecureException $e) {
            $output->writeln('<error>Secrets key error: ' . $e->getMessage() . '</error>');
            $output->writeln('<comment>Run "shpd-ds ds-secrets-health" for diagnostics.</comment>');
            return Command::FAILURE;
        }

        $row = $dsConnection->fetchRow(
            'SELECT id FROM core_ai_backends WHERE backend_id = %s',
            $backendCode,
        );
        if ($row === null) {
            $output->writeln("<error>Error: backend '{$backendCode}' not found.</error>");
            $output->writeln('<comment>Run "shpd-ds ai-analyzer-bootstrap" first.</comment>');
            return Command::FAILURE;
        }

        $backendId = (int) $row['id'];

        // --base-url: hodnota → nastavit (AI gateway, D5/D6); prázdný string
        // → NULL = přímé Anthropic; nezadaná option → sloupec netknout.
        $baseUrl = $input->getOption('base-url');

        $this->encryptAndStoreKey($dsConnection, $backendId, $cipher, (string) $apiKey, $baseUrl);

        $output->writeln("<info>API key updated for backend '{$backendCode}' (id={$backendId}).</info>");
        if ($baseUrl !== null) {
            $output->writeln($baseUrl !== ''
                ? "Base URL set to: {$baseUrl}"
                : 'Base URL cleared (direct Anthropic).');
        }
        $output->writeln('Backend is now active and ready for AI analyzer claims.');

        return Command::SUCCESS;
    }

    /**
     * Šifrování přes Document beforeSave — ten je single source of truth pro
     * encrypted_text columns, viz docs/operations/secrets.md a CLAUDE.md.
     * Nikdy nešifrujeme inline v CLI, abychom se nerozcházeli s aplikační
     * vrstvou (nonce semantics, error mapping).
     */
    private function encryptAndStoreKey(
        DataSourceConnection $dsConnection,
        int $backendId,
        DsSecretCipher $cipher,
        #[SensitiveParameter]
        string $apiKey,
        ?string $baseUrl = null,
    ): void {
        $doc = new AIBackendDocument();
        $doc->setSecretCipher($cipher);

        $now = date('Y-m-d H:i:s');
        $data = [
            'id' => $backendId,
            'api_key' => $apiKey,
        ];
        $doc->beforeSave($data);

        // beforeSave nahradil plaintext ciphertextem; pokud byl prázdný, klíč
        // bude unset — pak by se nic neděje. Tady ale máme guard --api-key
        // required, takže ciphertext je v $data['api_key'].
        if (!array_key_exists('api_key', $data)) {
            throw new \RuntimeException('Internal error: api_key disappeared after encryption.');
        }

        $update = [
            'api_key' => $data['api_key'],
            'is_active' => 1,
            'modified' => $now,
        ];
        if ($baseUrl !== null) {
            $update['base_url'] = $baseUrl !== '' ? $baseUrl : null;
        }

        $dsConnection->updateWhere(
            'core_ai_backends',
            $update,
            'id = %i',
            $backendId,
        );
    }
}
