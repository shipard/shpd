<?php

declare(strict_types=1);

namespace Shipard\Core\Hosting;

use SensitiveParameter;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Hosting\Exception\AiGwKeyInsecureException;
use Shipard\Core\Hosting\Exception\AiGwKeyMissingException;
use Shipard\Core\Security\DsSecretCipher;
use Shipard\Core\Security\SecretsFileWriter;

/**
 * Klíč organizace pro AI gateway (D5). API klíč Anthropicu žije v
 * {ds_path}/secrets/ai-gw-anthropic.key (0600) vedle secrets.key — nikdy
 * v DB ani settings, stejné zacházení jako privátní klíč OP (OpKeyStore).
 * Plní CLI `hosting-ai-gw-init --set-key`, čte HostingAiGatewayController.
 */
final class AiGwKeyStore
{
    public const string KEY_FILENAME = 'ai-gw-anthropic.key';

    /** @var array<string, string> dsPath → key */
    private static array $cache = [];

    private function __construct()
    {
    }

    public static function keyFilePath(string $dsPath): string
    {
        return DsSecretCipher::secretsDirPath($dsPath) . '/' . self::KEY_FILENAME;
    }

    public static function exists(string $dsPath): bool
    {
        return is_file(self::keyFilePath($dsPath));
    }

    /**
     * Load the org key for a data source. First call reads the file from
     * disk and validates permissions; subsequent calls return the cached
     * value.
     *
     * @throws AiGwKeyMissingException
     * @throws AiGwKeyInsecureException
     */
    public static function read(DataSourceConfig $config): string
    {
        $dsPath = $config->getDataSourceDir();
        if (isset(self::$cache[$dsPath])) {
            return self::$cache[$dsPath];
        }

        $keyFile = self::keyFilePath($dsPath);

        if (!is_file($keyFile)) {
            throw new AiGwKeyMissingException(
                "ai-gw-anthropic.key missing at {$keyFile}. Run 'shpd-ds hosting-ai-gw-init --set-key'."
            );
        }

        $perms = fileperms($keyFile) & 0777;
        if ($perms !== 0600) {
            throw new AiGwKeyInsecureException(sprintf(
                'ai-gw-anthropic.key at %s has insecure permissions %04o (must be 0600). '
                . 'Fix: chmod 0600 %s',
                $keyFile, $perms, $keyFile,
            ));
        }

        $key = @file_get_contents($keyFile);
        if ($key === false) {
            throw new AiGwKeyMissingException(
                "Failed to read ai-gw-anthropic.key at {$keyFile}"
            );
        }

        $key = trim($key);
        if ($key === '') {
            throw new AiGwKeyMissingException(
                "ai-gw-anthropic.key at {$keyFile} is empty"
            );
        }

        return self::$cache[$dsPath] = $key;
    }

    /**
     * Write the org key to {dsPath}/secrets/ai-gw-anthropic.key via
     * SecretsFileWriter (atomic, 0600, ownership aligned with the DS root).
     * Overwrite is allowed — rotation goes through the same CLI
     * (`hosting-ai-gw-init --set-key`). Returns ownership warnings.
     *
     * @return list<string>
     */
    public static function write(string $dsPath, #[SensitiveParameter] string $key): array
    {
        $key = trim($key);
        if ($key === '') {
            throw new \InvalidArgumentException('AiGwKeyStore: key must not be empty.');
        }

        $warnings = SecretsFileWriter::write($dsPath, self::KEY_FILENAME, $key);

        unset(self::$cache[$dsPath]);

        return $warnings;
    }

    /**
     * @internal For tests only.
     */
    public static function resetCache(): void
    {
        self::$cache = [];
    }
}
