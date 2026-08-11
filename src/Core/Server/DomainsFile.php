<?php

declare(strict_types=1);

namespace Shipard\Core\Server;

use Shipard\Core\Config\ServerConfig;

/**
 * Mapa host → ds_id v domains.json — sdílená logika domain-* commandů.
 *
 * Efektivní cesty: explicitní override (testy) > server.json
 * (`domainsFile` / `dataSources`, stejné klíče respektuje HTTP resolver
 * v public/index.php) > default konstanta.
 *
 * Zápis je hlasitý: agent hosting-sync běží z cronu jako shipard
 * a /etc/shipard je root-managed — tichý pád file_put_contents dřív
 * znamenal „Added" + SUCCESS a nedostupný DS (UNKNOWN_HOST, nález č. 3
 * z adopce, tasks/hosting-fix-secrets-owner-and-gw-errors.md).
 */
final class DomainsFile
{
    public const string DEFAULT_DOMAINS_FILE = '/etc/shipard/domains.json';
    public const string DEFAULT_DATA_SOURCES_DIR = '/opt/shipard/data-sources';
    public const string DEFAULT_SERVER_CONFIG = '/etc/shipard/server.json';

    private function __construct()
    {
    }

    public static function effectiveDomainsFile(
        ?string $override,
        string $serverConfigPath = self::DEFAULT_SERVER_CONFIG,
    ): string {
        if ($override !== null) {
            return $override;
        }
        return self::serverConfig($serverConfigPath)?->getDomainsFile()
            ?? self::DEFAULT_DOMAINS_FILE;
    }

    public static function effectiveDataSourcesDir(
        ?string $override,
        string $serverConfigPath = self::DEFAULT_SERVER_CONFIG,
    ): string {
        if ($override !== null) {
            return $override;
        }
        return self::serverConfig($serverConfigPath)?->getDataSourcesDir()
            ?? self::DEFAULT_DATA_SOURCES_DIR;
    }

    /**
     * Načte mapu; neexistující soubor = prázdná mapa. Nečitelný soubor
     * nebo nevalidní JSON = výjimka.
     *
     * @return array<string, string>
     */
    public static function load(string $path): array
    {
        if (!file_exists($path)) {
            return [];
        }

        $content = @file_get_contents($path);
        if ($content === false) {
            throw new \RuntimeException(
                "Cannot read domains file {$path} (running as " . self::currentUser() . ') — check permissions.'
            );
        }

        $data = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
            throw new \RuntimeException("Invalid JSON in domains file: {$path}");
        }

        return $data;
    }

    /**
     * Atomický zápis (tmp + rename) — soubor čte DataSourceResolver při
     * každém HTTP requestu, roztržený zápis by položil živý provoz.
     * Každý krok kontrolovaný: selhání = výjimka, nikdy tichý úspěch.
     *
     * @param array<string, string> $map
     */
    public static function save(string $path, array $map): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException(self::writeError("create directory {$dir}", $dir));
        }

        $json = (string) json_encode($map, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $tmp = $path . '.tmp';

        if (@file_put_contents($tmp, $json) !== strlen($json)) {
            @unlink($tmp);
            throw new \RuntimeException(self::writeError("write {$tmp}", $dir));
        }

        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException(self::writeError("rename {$tmp} to {$path}", $dir));
        }
    }

    private static function writeError(string $action, string $dir): string
    {
        return "Failed to {$action} (running as " . self::currentUser() . "). "
            . "Check permissions on {$dir}; on servers with the provisioning agent "
            . "point 'domainsFile' in server.json to a path writable by the shipard user.";
    }

    private static function currentUser(): string
    {
        if (!function_exists('posix_geteuid')) {
            return 'unknown';
        }
        $uid = posix_geteuid();
        return (posix_getpwuid($uid)['name'] ?? (string) $uid);
    }

    private static function serverConfig(string $path): ?ServerConfig
    {
        try {
            $config = new ServerConfig($path);
            $config->load();
            return $config;
        } catch (\RuntimeException) {
            // Chybějící/nevalidní server.json (dev) → defaulty.
            return null;
        }
    }
}
