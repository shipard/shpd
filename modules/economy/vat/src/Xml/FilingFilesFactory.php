<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Vat\Xml;

use Shipard\Api\TableLoader;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Config\ServerConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Module\Core\Attachments\AttachmentService;

/**
 * Složení `FilingFilesService` tam, kde volající nemá po ruce službu
 * příloh — typicky v dokumentové vrstvě (`FilingDocument` při přechodu do
 * stavu Podáno).
 *
 * Definice tabulek se načítají líně: `AttachmentService` je potřebuje, aby
 * z `tableId` poznala cílovou tabulku, a to je jediný důvod, proč tu
 * scan modulů je. Běží jen při generování souborů, ne při každém uložení.
 */
final class FilingFilesFactory
{
    public static function create(
        \Dibi\Connection $db,
        ?ConfigRuntime $config,
        ?DataSourceConfig $dsConfig,
        ?FilingPdfRenderer $pdf = null,
    ): FilingFilesService {
        $attachments = null;
        if ($dsConfig !== null) {
            $attachments = new AttachmentService(
                new DataSourceConnection($db),
                $dsConfig->getDataSourceDir(),
                TableLoader::load($dsConfig, self::resolver()),
            );
        }
        return new FilingFilesService($db, $config, $attachments, $pdf);
    }

    /**
     * Kořeny modulů ze serverové konfigurace; bez ní (testy, izolovaný
     * běh) stačí modulový adresář repozitáře — vzor
     * `VatFilingComposeCommand::buildResolver()`.
     */
    private static function resolver(): ModulePathResolver
    {
        $fallback = dirname(__DIR__, 4);
        try {
            $serverConfig = new ServerConfig();
            $serverConfig->load();
            return ModulePathResolver::fromServerConfig($serverConfig, $fallback);
        } catch (\Throwable) {
            return new ModulePathResolver([$fallback]);
        }
    }
}
