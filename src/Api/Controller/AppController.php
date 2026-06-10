<?php

declare(strict_types=1);

namespace Shipard\Api\Controller;

use Shipard\Api\AuthContext;
use Shipard\Api\Response;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Settings\BrandingStorage;
use Shipard\Core\Settings\SettingsStore;

/**
 * Veřejné info o aplikaci + branding obrázky.
 *
 * Endpoints:
 *   GET    /_app/info             Název, zkrácený název, ikona, logo (VEŘEJNÉ)
 *   GET    /_app/branding/{slot}  Binární obsah slotu (VEŘEJNÉ, immutable cache)
 *   POST   /_app/branding/{slot}  Upload (multipart, pole `file`) — vyžaduje auth
 *   DELETE /_app/branding/{slot}  Smazání souboru i metadat — vyžaduje auth
 *
 * GET endpointy jsou vědomě veřejné (login obrazovka, favicon bez tokenu) —
 * nesmí sem přibýt nic citlivého (DB jméno, moduly, uživatelé).
 */
class AppController
{
    private SettingsStore $settings;
    private BrandingStorage $storage;

    public function __construct(
        private DataSourceConnection $db,
        private DataSourceConfig $config,
    ) {
        $this->settings = new SettingsStore($db);
        $this->storage  = new BrandingStorage($config->getDataSourceDir());
    }

    /**
     * GET /_app/info
     *
     * `app.name` má přednost před `main.json` name; shortName padá na name.
     */
    public function info(): Response
    {
        $values = $this->settings->getMany([
            'app.name',
            'app.shortName',
            'app.icon',
            'app.companyLogo',
        ]);

        $name = is_string($values['app.name']) && trim($values['app.name']) !== ''
            ? $values['app.name']
            : $this->config->getName();

        $shortName = is_string($values['app.shortName']) && trim($values['app.shortName']) !== ''
            ? $values['app.shortName']
            : $name;

        return Response::success([
            'name'        => $name,
            'shortName'   => $shortName,
            'icon'        => self::slotInfo('icon', $values['app.icon']),
            'companyLogo' => self::slotInfo('companyLogo', $values['app.companyLogo']),
        ]);
    }

    /**
     * Veřejný stav slotu pro API odpovědi: `{url, hash}` nebo null.
     * URL je relativní k API base — frontend si prefix doplní sám.
     */
    public static function slotInfo(string $slot, mixed $metadata): ?array
    {
        if (!is_array($metadata) || !is_string($metadata['hash'] ?? null)) {
            return null;
        }
        return [
            'url'  => '/_app/branding/' . $slot . '?h=' . $metadata['hash'],
            'hash' => $metadata['hash'],
        ];
    }

    /**
     * GET /_app/branding/{slot} — binární obsah, immutable cache (URL nese
     * ?h={hash} pro cache-busting).
     */
    public function brandingGet(string $slot): Response
    {
        if (!BrandingStorage::isValidSlot($slot)) {
            return Response::error('NOT_FOUND', 'Not found', 404);
        }

        $metadata = $this->settings->get(BrandingStorage::SLOT_SETTINGS_KEYS[$slot]);
        if (!is_array($metadata) || !is_string($metadata['storedAs'] ?? null)) {
            return Response::error('NOT_FOUND', 'Not found', 404);
        }

        $filePath = $this->storage->getFilePath($metadata['storedAs']);
        if (!file_exists($filePath)) {
            return Response::error('NOT_FOUND', 'Not found', 404);
        }

        $mime = is_string($metadata['mime'] ?? null) ? $metadata['mime'] : 'application/octet-stream';
        $this->sendFile($filePath, $this->buildBrandingHeaders($mime, filesize($filePath) ?: null));

        // sendFile exits — jen pro typovou úplnost
        return Response::success(null, 204);
    }

    /**
     * Hlavičky pro servírování brandingu. SVG dostává CSP + nosniff —
     * ochrana proti XSS při přímé navigaci na URL. Public kvůli testům
     * (sendFile ukončuje proces).
     */
    public function buildBrandingHeaders(string $mime, ?int $size): array
    {
        $headers = [
            'Content-Type'  => $mime,
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ];
        if ($size !== null) {
            $headers['Content-Length'] = (string) $size;
        }
        if ($mime === 'image/svg+xml') {
            $headers['Content-Security-Policy'] = "default-src 'none'";
            $headers['X-Content-Type-Options']  = 'nosniff';
        }
        return $headers;
    }

    /**
     * POST /_app/branding/{slot} — multipart upload (pole `file`).
     */
    public function brandingUpload(string $slot, AuthContext $auth): Response
    {
        if (!$auth->isAuthenticated) {
            return Response::error('UNAUTHORIZED', 'Authentication required', 401);
        }
        if (!BrandingStorage::isValidSlot($slot)) {
            return Response::error('NOT_FOUND', 'Not found', 404);
        }

        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            $errorCode = $_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE;
            $errorMessage = match ($errorCode) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Soubor je příliš velký',
                UPLOAD_ERR_NO_FILE => 'Žádný soubor nebyl nahrán',
                UPLOAD_ERR_PARTIAL => 'Soubor byl nahrán jen částečně',
                default => 'Chyba při nahrávání souboru',
            };
            return Response::error('UPLOAD_ERROR', $errorMessage, 400);
        }

        $file    = $_FILES['file'];
        $tmpPath = (string) $file['tmp_name'];
        $size    = (int) ($file['size'] ?? 0);

        $validated = $this->storage->validateUpload($slot, $tmpPath, $size);
        if (is_string($validated)) {
            return Response::error('VALIDATION_ERROR', $validated, 422);
        }

        // Hash před store() — rename tmp soubor přesune.
        $hash     = substr((string) hash_file('sha256', $tmpPath), 0, 16);
        $storedAs = $this->storage->store($slot, $tmpPath, $validated['ext']);

        $metadata = [
            'filename' => (string) ($file['name'] ?? $storedAs),
            'storedAs' => $storedAs,
            'mime'     => $validated['mime'],
            'size'     => $size,
            'hash'     => $hash,
            'modified' => date('c'),
        ];
        $this->settings->set(BrandingStorage::SLOT_SETTINGS_KEYS[$slot], $metadata);

        return Response::success($metadata + ['url' => self::slotInfo($slot, $metadata)['url']], 201);
    }

    /**
     * DELETE /_app/branding/{slot} — smaže soubor i metadata.
     */
    public function brandingDelete(string $slot, AuthContext $auth): Response
    {
        if (!$auth->isAuthenticated) {
            return Response::error('UNAUTHORIZED', 'Authentication required', 401);
        }
        if (!BrandingStorage::isValidSlot($slot)) {
            return Response::error('NOT_FOUND', 'Not found', 404);
        }

        $this->storage->deleteSlotFiles($slot);
        $this->settings->delete(BrandingStorage::SLOT_SETTINGS_KEYS[$slot]);

        return Response::success(null, 204);
    }

    /** @param array<string, string> $headers */
    private function sendFile(string $filePath, array $headers): never
    {
        while (ob_get_level()) {
            ob_end_clean();
        }
        foreach ($headers as $name => $value) {
            header($name . ': ' . $value);
        }
        readfile($filePath);
        exit;
    }
}
