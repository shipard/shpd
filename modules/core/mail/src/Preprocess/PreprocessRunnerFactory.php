<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Preprocess;

use Shipard\Api\TableLoader;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Config\ServerConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Logging\ErrorLogger;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Render\RenderClient;
use Shipard\Module\Core\Attachments\AttachmentService;
use Shipard\Module\Core\Exchange\Enrich\RowHistoryEnricher;
use Shipard\Module\Core\Exchange\Schema\SchemaLoader;
use Shipard\Module\Core\Exchange\Schema\SchemaValidator;
use Shipard\Module\Core\Mail\IsdocImportService;
use Shipard\Module\Core\Mail\Preprocess\Action\FetchLinkedDocumentAction;
use Shipard\Module\Core\Mail\Preprocess\Action\RenderBodyToPdfAction;
use Shipard\Module\Core\Mail\Preprocess\Http\CurlHttpFetcher;

/**
 * Produkční wiring runneru pro CLI `mail-preprocess`: přílohy, registr
 * akcí, rendering klient (#34), ISDOC import (stejná sestava jako intake
 * v public/index.php), spawner pro sweep a matcher pro --force.
 */
final class PreprocessRunnerFactory
{
    /**
     * @param ServerConfig|null $serverConfig Zdroj `render` sekce; null
     *        (server config nenačten) = nenakonfigurovaná služba, render
     *        akce selhávají s `unconfigured` — zpráva doteče do stavu 40.
     */
    public static function create(
        DataSourceConfig $dsConfig,
        DataSourceConnection $db,
        string $dsDir,
        ModulePathResolver $resolver,
        ?ServerConfig $serverConfig = null,
    ): PreprocessRunner {
        $tables = TableLoader::load($dsConfig, $resolver, $dsConfig->getDefaultLanguage());
        $attachments = new AttachmentService($db, $dsDir, $tables);
        $dibi = $db->getDibiConnection();
        $render = $serverConfig !== null ? RenderClient::fromServerConfig($serverConfig) : new RenderClient(null);

        $isdocImportFactory = static function () use ($db, $dibi, $dsDir): IsdocImportService {
            try {
                $enricher = RowHistoryEnricher::create($dibi);
            } catch (\Throwable $e) {
                ErrorLogger::logException($e, 'PreprocessRunnerFactory: RowHistoryEnricher unavailable — ISDOC import runs without enrichment');
                $enricher = null;
            }
            return new IsdocImportService(
                $db,
                new SchemaValidator(SchemaLoader::default()),
                $enricher,
                $dsDir,
            );
        };

        $spawner = new PreprocessSpawner($dsDir);

        return new PreprocessRunner(
            $db,
            $attachments,
            self::defaultActions($db, $attachments, $render),
            $isdocImportFactory,
            static function (int $messageId) use ($spawner): void {
                $spawner->spawn($messageId);
            },
            new PreprocessRuleMatcher($dibi),
        );
    }

    /**
     * Registr akcí, které runner umí (zrcadlo
     * PreprocessRuleDocument::IMPLEMENTED_ACTIONS). Testovací seam:
     * RenderClient s injektovaným fake enginem.
     */
    public static function defaultActions(DataSourceConnection $db, AttachmentService $attachments, RenderClient $render): ActionRegistry
    {
        return new ActionRegistry()
            ->register(FetchLinkedDocumentAction::KEY, new FetchLinkedDocumentAction($attachments, new CurlHttpFetcher()))
            ->register(RenderBodyToPdfAction::KEY, new RenderBodyToPdfAction($attachments, $render));
    }
}
