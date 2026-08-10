<?php

declare(strict_types=1);

namespace Shipard\Core\Hosting;

use Firebase\JWT\JWT;
use SensitiveParameter;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Hosting\Exception\OpKeyInsecureException;
use Shipard\Core\Hosting\Exception\OpKeyMissingException;
use Shipard\Core\Security\DsSecretCipher;
use Shipard\Core\Security\SecretsFileWriter;

/**
 * Podpisový klíč OIDC OP hostingu (D2). Privátní RSA klíč žije v
 * {ds_path}/secrets/oidc-op.key (PEM, 0600) vedle secrets.key — nikdy v DB.
 *
 * Odpovědnosti: načtení + validace klíče, odvození veřejného JWK (n/e),
 * stabilní kid a podpis id_tokenu (RS256, kid v hlavičce). RP protistranu
 * (JWK::parseKeySet vyžaduje kty+kid+n+e, kid v hlavičce je povinný) viz
 * src/Core/Auth/OidcClient.php a docs/hosting.md §5.4.
 */
final class OpKeyStore
{
    public const string ALG = 'RS256';
    public const string KEY_FILENAME = 'oidc-op.key';
    private const int KEY_BITS = 3072;
    private const int KID_HEX_CHARS = 16;

    /** @var array<string, self> */
    private static array $cache = [];

    private function __construct(
        #[SensitiveParameter]
        private readonly string $privatePem,
        private readonly string $kid,
        private readonly string $n,
        private readonly string $e,
    ) {}

    /**
     * Load the OP key for a data source. First call reads oidc-op.key from
     * disk and validates permissions; subsequent calls return the cached
     * instance.
     *
     * @throws OpKeyMissingException
     * @throws OpKeyInsecureException
     */
    public static function forConfig(DataSourceConfig $config): self
    {
        $dsPath = $config->getDataSourceDir();
        if (isset(self::$cache[$dsPath])) {
            return self::$cache[$dsPath];
        }

        $keyFile = self::keyFilePath($dsPath);

        if (!is_file($keyFile)) {
            throw new OpKeyMissingException(
                "oidc-op.key missing at {$keyFile}. Run 'shpd-ds hosting-oidc-init'."
            );
        }

        $perms = fileperms($keyFile) & 0777;
        if ($perms !== 0600) {
            throw new OpKeyInsecureException(sprintf(
                "oidc-op.key at %s has insecure permissions %04o (must be 0600). "
                . "Fix: chmod 0600 %s",
                $keyFile, $perms, $keyFile,
            ));
        }

        $pem = @file_get_contents($keyFile);
        if ($pem === false) {
            throw new OpKeyMissingException(
                "Failed to read oidc-op.key at {$keyFile}"
            );
        }

        return self::$cache[$dsPath] = self::fromPrivatePem($pem, $keyFile);
    }

    public static function keyFilePath(string $dsPath): string
    {
        return DsSecretCipher::secretsDirPath($dsPath) . '/' . self::KEY_FILENAME;
    }

    /**
     * Generate a fresh RSA private key in {dsPath}/secrets/oidc-op.key via
     * SecretsFileWriter (atomic, 0600, ownership aligned with the DS root).
     * Fails if the key already exists. Returns the kid of the new key and
     * ownership warnings.
     *
     * @return array{kid: string, warnings: list<string>}
     */
    public static function generateKey(string $dsPath): array
    {
        $keyFile = self::keyFilePath($dsPath);

        if (is_file($keyFile)) {
            throw new \RuntimeException("oidc-op.key already exists at {$keyFile}");
        }

        $resource = openssl_pkey_new([
            'private_key_bits' => self::KEY_BITS,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        if ($resource === false) {
            throw new \RuntimeException(
                'Failed to generate RSA key: ' . (openssl_error_string() ?: 'unknown error')
            );
        }
        if (!openssl_pkey_export($resource, $pem)) {
            throw new \RuntimeException(
                'Failed to export RSA key: ' . (openssl_error_string() ?: 'unknown error')
            );
        }

        $warnings = SecretsFileWriter::write($dsPath, self::KEY_FILENAME, $pem);

        unset(self::$cache[$dsPath]);

        return [
            'kid' => self::fromPrivatePem($pem, $keyFile)->kid(),
            'warnings' => $warnings,
        ];
    }

    public function kid(): string
    {
        return $this->kid;
    }

    /**
     * Public key as a JWK for the JWKS endpoint. Shape matches what the RP's
     * JWK::parseKeySet needs: kty + kid + n + e; alg equals the id_token
     * header alg (a differing alg would be rejected by php-jwt).
     *
     * @return array{kty: string, use: string, alg: string, kid: string, n: string, e: string}
     */
    public function publicJwk(): array
    {
        return [
            'kty' => 'RSA',
            'use' => 'sig',
            'alg' => self::ALG,
            'kid' => $this->kid,
            'n' => self::base64url($this->n),
            'e' => self::base64url($this->e),
        ];
    }

    /**
     * Sign id_token claims. RS256 with kid in the header — the RP looks the
     * key up by kid and rejects tokens without it.
     */
    public function sign(array $claims): string
    {
        return JWT::encode($claims, $this->privatePem, self::ALG, $this->kid);
    }

    /**
     * @internal For tests only.
     */
    public static function resetCache(): void
    {
        self::$cache = [];
    }

    private static function fromPrivatePem(
        #[SensitiveParameter] string $pem,
        string $keyFile,
    ): self {
        $key = openssl_pkey_get_private($pem);
        if ($key === false) {
            throw new OpKeyInsecureException(
                "oidc-op.key at {$keyFile} is not a valid PEM private key: "
                . (openssl_error_string() ?: 'unknown error')
            );
        }

        $details = openssl_pkey_get_details($key);
        if ($details === false || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_RSA) {
            throw new OpKeyInsecureException(
                "oidc-op.key at {$keyFile} is not an RSA key"
            );
        }

        return new self(
            privatePem: $pem,
            kid: self::deriveKid($details['key']),
            n: $details['rsa']['n'],
            e: $details['rsa']['e'],
        );
    }

    /**
     * kid = first 16 hex chars of sha256 over the DER-encoded public key
     * (SubjectPublicKeyInfo). Purely derived from key material — stable
     * across restarts and restores, no sidecar state. Verify by hand:
     * openssl pkey -in oidc-op.key -pubout -outform DER | sha256sum
     */
    private static function deriveKid(string $publicPem): string
    {
        $body = preg_replace('/-----[^-]+-----|\s+/', '', $publicPem);
        $der = base64_decode((string) $body, true);
        if ($der === false) {
            throw new OpKeyInsecureException('Failed to decode public key PEM');
        }
        return substr(hash('sha256', $der), 0, self::KID_HEX_CHARS);
    }

    private static function base64url(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }
}
