<?php

declare(strict_types=1);

namespace Shipard\Core\Render;

use Shipard\Core\Config\RenderConfig;
use Shipard\Core\Config\ServerConfig;
use Shipard\Core\Logging\ErrorLogger;
use Shipard\Core\Render\Engine\GotenbergEngine;
use Shipard\Core\Render\Engine\RenderEngineInterface;

/**
 * Fasáda PDF renderingu pro volající — engine-agnostický kontrakt (D3),
 * volající nikdy nemluví s Gotenbergem přímo. Obsah se vždy pushuje
 * v requestu (D4), profily viz RenderProfile (D5).
 *
 * Degradace (D6): provozní selhání = RenderResult s errorKind, nikdy
 * výjimka; volající přeskočí s poznámkou. První selhání za proces
 * zaloguje warning, další jen debug (vzor IsdocImportService).
 *
 * Viz docs/render.md a tasks/pdf-rendering-service.md (#34).
 */
class RenderClient
{
    private readonly ?RenderEngineInterface $engine;

    /** Jednorázový warn o nedostupné/failující službě (per proces). */
    private static bool $warnedFailure = false;

    public function __construct(
        private readonly ?RenderConfig $config,
        ?RenderEngineInterface $engine = null,
    ) {
        $this->engine = $engine ?? ($config !== null ? new GotenbergEngine($config->url) : null);
    }

    public static function fromServerConfig(ServerConfig $serverConfig): self
    {
        return new self($serverConfig->getRender());
    }

    public function isConfigured(): bool
    {
        return $this->config !== null;
    }

    /**
     * HTML → PDF. Assety (obrázky, CSS, fonty) jako mapa
     * `filename => content`, z HTML referencované relativně.
     *
     * @param array<string, string> $assets
     */
    public function renderHtml(
        string $html,
        array $assets,
        RenderProfile $profile,
        ?PdfOptions $options = null,
    ): RenderResult {
        $options ??= new PdfOptions();

        if (
            !$profile->allowsHeaderFooter()
            && ($options->headerTemplate !== null || $options->footerTemplate !== null)
        ) {
            return RenderResult::failure(
                RenderErrorKind::InvalidInput,
                "profile '{$profile->value}' does not allow header/footer templates",
            );
        }
        if (!$profile->allowsPrintBackground() && $options->printBackground) {
            return RenderResult::failure(
                RenderErrorKind::InvalidInput,
                "profile '{$profile->value}' does not allow printBackground",
            );
        }
        if ($this->config === null || $this->engine === null) {
            return RenderResult::failure(RenderErrorKind::Unconfigured, 'render service is not configured');
        }

        $result = $this->engine->renderHtml(
            $html,
            $assets,
            $options->withDefaults($profile),
            $profile->effectiveTimeoutSec($this->config->timeoutSec),
        );

        return $this->logFailureOnce($result);
    }

    /** Office dokument (docx, xlsx, odt…) → PDF. */
    public function convertOffice(string $fileName, string $content): RenderResult
    {
        if ($fileName === '' || $content === '') {
            return RenderResult::failure(
                RenderErrorKind::InvalidInput,
                'convertOffice requires a non-empty file name and content',
            );
        }
        if ($this->config === null || $this->engine === null) {
            return RenderResult::failure(RenderErrorKind::Unconfigured, 'render service is not configured');
        }

        $result = $this->engine->convertOffice($fileName, $content, $this->config->timeoutSec);

        return $this->logFailureOnce($result);
    }

    /** Health check služby — krátký timeout, používá doctor. */
    public function health(): bool
    {
        return $this->engine !== null && $this->engine->health();
    }

    private function logFailureOnce(RenderResult $result): RenderResult
    {
        if ($result->ok || $result->errorKind === RenderErrorKind::InvalidInput) {
            return $result;
        }

        $ctx = ['errorKind' => $result->errorKind?->value, 'note' => $result->note];
        if (!self::$warnedFailure) {
            self::$warnedFailure = true;
            ErrorLogger::warn('render: service call failed — degrading, callers skip PDF output', $ctx);
        } else {
            ErrorLogger::debug('render: service call failed', $ctx);
        }

        return $result;
    }

    public static function resetWarningForTesting(): void
    {
        self::$warnedFailure = false;
    }
}
