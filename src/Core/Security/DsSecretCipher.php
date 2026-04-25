<?php

declare(strict_types=1);

namespace Shipard\Core\Security;

use SensitiveParameter;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Security\Exception\InvalidCiphertextException;
use Shipard\Core\Security\Exception\SecretsKeyInsecureException;
use Shipard\Core\Security\Exception\SecretsKeyMissingException;

/**
 * Per-DS AES-256-GCM cipher for encrypted_text columns.
 *
 * Reads {ds_path}/secrets/secrets.key on first use and caches the instance
 * per ds_path. Uses libsodium AEAD (sodium_crypto_aead_aes256gcm_*).
 *
 * See tasks/ds-encrypted-secrets.md for full design.
 */
final class DsSecretCipher
{
    public const KEY_BYTES = 32;
    private const FORMAT_PREFIX = 'v1';
    private const SECRETS_DIRNAME = 'secrets';
    private const KEY_FILENAME = 'secrets.key';

    /** @var array<string, self> */
    private static array $cache = [];

    private function __construct(
        #[SensitiveParameter]
        private readonly string $key,
    ) {}

    /**
     * Get cipher for a data source. First call reads secrets.key from disk
     * and validates permissions; subsequent calls return the cached instance.
     *
     * @throws SecretsKeyMissingException
     * @throws SecretsKeyInsecureException
     */
    public static function forConfig(DataSourceConfig $config): self
    {
        $dsPath = $config->getDataSourceDir();
        if (isset(self::$cache[$dsPath])) {
            return self::$cache[$dsPath];
        }

        self::assertSodiumAesAvailable();

        $keyFile = self::keyFilePath($dsPath);

        if (!is_file($keyFile)) {
            throw new SecretsKeyMissingException(
                "secrets.key missing at {$keyFile}. "
                . "Run 'shpd-ds ds-upgrade' or contact admin."
            );
        }

        $perms = fileperms($keyFile) & 0777;
        if ($perms !== 0600) {
            throw new SecretsKeyInsecureException(sprintf(
                "secrets.key at %s has insecure permissions %04o (must be 0600). "
                . "Fix: chmod 0600 %s",
                $keyFile, $perms, $keyFile,
            ));
        }

        $key = @file_get_contents($keyFile);
        if ($key === false) {
            throw new SecretsKeyMissingException(
                "Failed to read secrets.key at {$keyFile}"
            );
        }

        if (strlen($key) !== self::KEY_BYTES) {
            throw new SecretsKeyInsecureException(sprintf(
                "secrets.key at %s has invalid length %d (expected %d bytes)",
                $keyFile, strlen($key), self::KEY_BYTES,
            ));
        }

        return self::$cache[$dsPath] = new self($key);
    }

    /**
     * Construct a cipher from raw key bytes. Used by ds-secrets-rotate to
     * encrypt under a freshly generated key before swapping the on-disk file.
     *
     * @internal
     * @throws \InvalidArgumentException when key length is wrong
     */
    public static function fromKey(#[SensitiveParameter] string $key): self
    {
        if (strlen($key) !== self::KEY_BYTES) {
            throw new \InvalidArgumentException(sprintf(
                'Key must be %d bytes, got %d',
                self::KEY_BYTES, strlen($key),
            ));
        }
        self::assertSodiumAesAvailable();
        return new self($key);
    }

    /**
     * Encrypt a plaintext string. A fresh nonce is generated on every call,
     * so two calls with identical plaintext produce different ciphertexts.
     */
    public function encrypt(#[SensitiveParameter] string $plaintext): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_AES256GCM_NPUBBYTES);
        $ciphertextWithTag = sodium_crypto_aead_aes256gcm_encrypt(
            $plaintext,
            '',
            $nonce,
            $this->key,
        );

        $tagLen = SODIUM_CRYPTO_AEAD_AES256GCM_ABYTES;
        $ciphertext = substr($ciphertextWithTag, 0, -$tagLen);
        $tag = substr($ciphertextWithTag, -$tagLen);

        return self::FORMAT_PREFIX
            . ':' . base64_encode($nonce)
            . ':' . base64_encode($tag)
            . ':' . base64_encode($ciphertext);
    }

    /**
     * Decrypt a ciphertext string. Throws on any failure (wrong key, tampered
     * data, malformed format). Never returns partial or garbage plaintext.
     *
     * @throws InvalidCiphertextException
     */
    public function decrypt(string $ciphertext): string
    {
        $parts = explode(':', $ciphertext, 4);
        if (count($parts) !== 4 || $parts[0] !== self::FORMAT_PREFIX) {
            throw new InvalidCiphertextException(
                'Malformed ciphertext: missing or unknown version prefix'
            );
        }

        $nonce = base64_decode($parts[1], true);
        $tag = base64_decode($parts[2], true);
        $ct = base64_decode($parts[3], true);

        if ($nonce === false || $tag === false || $ct === false) {
            throw new InvalidCiphertextException(
                'Malformed ciphertext: invalid base64'
            );
        }

        if (strlen($nonce) !== SODIUM_CRYPTO_AEAD_AES256GCM_NPUBBYTES) {
            throw new InvalidCiphertextException(
                'Malformed ciphertext: invalid nonce length'
            );
        }
        if (strlen($tag) !== SODIUM_CRYPTO_AEAD_AES256GCM_ABYTES) {
            throw new InvalidCiphertextException(
                'Malformed ciphertext: invalid tag length'
            );
        }

        try {
            $plaintext = sodium_crypto_aead_aes256gcm_decrypt(
                $ct . $tag,
                '',
                $nonce,
                $this->key,
            );
        } catch (\SodiumException $e) {
            throw new InvalidCiphertextException(
                'Decryption failed: ' . $e->getMessage(),
                0,
                $e,
            );
        }

        if ($plaintext === false) {
            throw new InvalidCiphertextException(
                'Decryption failed: integrity check failed (wrong key or tampered data)'
            );
        }

        return $plaintext;
    }

    /**
     * Check that the key file and its parent directory are present with
     * the expected permissions and key size. Returns a list of warning
     * strings; empty list means everything is OK.
     *
     * Does not validate that any specific ciphertext in the database can
     * be decrypted — for that use the ds-secrets-health CLI command.
     *
     * @return list<string>
     */
    public static function healthCheck(DataSourceConfig $config): array
    {
        $warnings = [];
        $dsPath = $config->getDataSourceDir();
        $secretsDir = self::secretsDirPath($dsPath);
        $keyFile = self::keyFilePath($dsPath);

        if (!is_dir($secretsDir)) {
            $warnings[] = "secrets/ directory missing at {$secretsDir}";
            return $warnings;
        }

        $dirPerms = fileperms($secretsDir) & 0777;
        if ($dirPerms !== 0700) {
            $warnings[] = sprintf(
                'secrets/ directory has permissions %04o (should be 0700). Fix: chmod 0700 %s',
                $dirPerms, $secretsDir,
            );
        }

        if (!is_file($keyFile)) {
            $warnings[] = "secrets.key missing at {$keyFile}";
            return $warnings;
        }

        $filePerms = fileperms($keyFile) & 0777;
        if ($filePerms !== 0600) {
            $warnings[] = sprintf(
                'secrets.key has permissions %04o (should be 0600). Fix: chmod 0600 %s',
                $filePerms, $keyFile,
            );
        }

        $size = filesize($keyFile);
        if ($size !== self::KEY_BYTES) {
            $warnings[] = sprintf(
                'secrets.key has size %d bytes (expected %d)',
                $size, self::KEY_BYTES,
            );
        }

        return $warnings;
    }

    public static function keyFilePath(string $dsPath): string
    {
        return self::secretsDirPath($dsPath) . '/' . self::KEY_FILENAME;
    }

    public static function secretsDirPath(string $dsPath): string
    {
        return $dsPath . '/' . self::SECRETS_DIRNAME;
    }

    /**
     * Generate a fresh secrets.key in {dsPath}/secrets/. Atomic: creates the
     * directory with 0700 if missing, writes the key to a tmp file with 0600,
     * fsyncs, then renames into place. Fails if secrets.key already exists.
     */
    public static function generateKey(string $dsPath): void
    {
        self::assertSodiumAesAvailable();

        $secretsDir = self::secretsDirPath($dsPath);
        $keyFile = self::keyFilePath($dsPath);
        $tmpFile = $keyFile . '.tmp';

        if (is_file($keyFile)) {
            throw new \RuntimeException("secrets.key already exists at {$keyFile}");
        }

        if (!is_dir($secretsDir)) {
            if (!@mkdir($secretsDir, 0700, true) && !is_dir($secretsDir)) {
                throw new \RuntimeException("Failed to create {$secretsDir}");
            }
        }
        @chmod($secretsDir, 0700);

        if (is_file($tmpFile)) {
            @unlink($tmpFile);
        }

        $fp = @fopen($tmpFile, 'wb');
        if ($fp === false) {
            throw new \RuntimeException("Failed to open {$tmpFile} for writing");
        }
        @chmod($tmpFile, 0600);

        try {
            $key = random_bytes(self::KEY_BYTES);
            $written = fwrite($fp, $key);
            if ($written !== self::KEY_BYTES) {
                throw new \RuntimeException(sprintf(
                    'Short write to %s (%d/%d bytes)',
                    $tmpFile, (int) $written, self::KEY_BYTES,
                ));
            }
            if (!fflush($fp)) {
                throw new \RuntimeException("fflush failed on {$tmpFile}");
            }
            if (!fsync($fp)) {
                throw new \RuntimeException("fsync failed on {$tmpFile}");
            }
        } finally {
            fclose($fp);
        }

        if (!@rename($tmpFile, $keyFile)) {
            @unlink($tmpFile);
            throw new \RuntimeException("Failed to rename {$tmpFile} to {$keyFile}");
        }
        @chmod($keyFile, 0600);

        unset(self::$cache[$dsPath]);
    }

    /**
     * @internal For tests only.
     */
    public static function resetCache(): void
    {
        self::$cache = [];
    }

    private static function assertSodiumAesAvailable(): void
    {
        if (!function_exists('sodium_crypto_aead_aes256gcm_is_available')
            || !sodium_crypto_aead_aes256gcm_is_available()
        ) {
            throw new \RuntimeException(
                'libsodium AES-256-GCM is not available on this CPU/build. '
                . 'Hardware AES support is required.'
            );
        }
    }
}
