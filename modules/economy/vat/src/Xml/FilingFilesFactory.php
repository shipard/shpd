<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Vat\Xml;

use Shipard\Api\TableLoader;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Config\ServerConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Render\RenderClient;
use Shipard\Module\Core\Attachments\AttachmentService;

/**
 * Složení `FilingFilesService` tam, kde volající nemá po ruce službu
 * příloh ani tiskovou službu — dokumentová vrstva při přechodu do stavu
 * Podáno, REST endpoint i CLI.
 *
 * Definice tabulek se načítají líně: `AttachmentService` je potřebuje, aby
 * z `tableId` poznala cílovou tabulku, a to je jediný důvod, proč tu
 * scan modulů je. Běží jen při generování souborů, ne při každém uložení.
 *
 * Bez serverové konfigurace (testy, izolovaný běh) se degraduje:
 * `RenderClient` bez URL vrátí `unconfigured` a vznikne jen XML.
 */
final class FilingFilesFactory
{
    public static function create(
        \Dibi\Connection $db,
        ?ConfigRuntime $config,
        ?DataSourceConfig $dsConfig,
        ?FilingPdfRenderer $pdf = null,
    ): FilingFilesService {
        $serverConfig = self::serverConfig();

        $attachments = null;
        if ($dsConfig !== null) {
            $attachments = new AttachmentService(
                new DataSourceConnection($db),
                $dsConfig->getDataSourceDir(),
                TableLoader::load($dsConfig, self::resolver($serverConfig)),
            );
        }

        $pdf ??= new FilingPdfService(
            $db,
            $serverConfig !== null ? RenderClient::fromServerConfig($serverConfig) : new RenderClient(null),
            $config,
            $dsConfig?->getDefaultLanguage() ?? 'cs',
        );

        return new FilingFilesService($db, $config, $attachments, $pdf);
    }

    private static function serverConfig(): ?ServerConfig
    {
        try {
            $serverConfig = new ServerConfig();
            $serverConfig->load();
            return $serverConfig;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Kořeny modulů ze serverové konfigurace; bez ní stačí modulový
     * adresář repozitáře — vzor `VatFilingComposeCommand::buildResolver()`.
     */
    private static function resolver(?ServerConfig $serverConfig): ModulePathResolver
    {
        $fallback = dirname(__DIR__, 4);
        return $serverConfig !== null
            ? ModulePathResolver::fromServerConfig($serverConfig, $fallback)
            : new ModulePathResolver([$fallback]);
    }
}
