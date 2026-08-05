<?php

declare(strict_types=1);

namespace Shipard\Api\Controller;

use Shipard\Api\AuthContext;
use Shipard\Api\Response;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Settings\AvatarStorage;
use Shipard\Core\Settings\BrandingStorage;
use Shipard\Core\Settings\SettingsStore;
use Shipard\Core\Settings\UserSettingsStore;

/**
 * Veřejné info o aplikaci + branding obrázky.
 *
 * Endpoints:
 *   GET    /_app/info             Název, zkrácený název, ikona, logo (VEŘEJNÉ)
 *   GET    /_app/branding/{slot}  Binární obsah slotu (VEŘEJNÉ, immutable cache)
 *   POST   /_app/branding/{slot}  Upload (multipart, pole `file`) — vyžaduje auth
 *   DELETE /_app/branding/{slot}  Smazání souboru i metadat — vyžaduje auth
 *   GET    /_app/avatar           Avatar přihlášeného uživatele — vyžaduje auth
 *   POST   /_app/avatar           Upload avataru (multipart, downscale) — auth
 *   DELETE /_app/avatar           Smazání avataru přihlášeného uživatele — auth
 *
 * Branding GET endpointy jsou vědomě veřejné (login obrazovka, favicon bez
 * tokenu) — nesmí sem přibýt nic citlivého (DB jméno, moduly, uživatelé).
 * Avatar je naopak per-uživatel a celý za auth (i GET).
 */
class AppController
{
    private SettingsStore $settings;
    private BrandingStorage $storage;
    private AvatarStorage $avatars;

    /** @param array<string, \Shipard\Core\Database\TableDefinition> $tables */
    public function __construct(
        private DataSourceConnection $db,
        private DataSourceConfig $config,
        private array $tables = [],
    ) {
        $this->settings = new SettingsStore($db);
        $this->storage  = new BrandingStorage($config->getDataSourceDir());
        $this->avatars  = new AvatarStorage($config->getDataSourceDir());
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
            'app.theme',
        ]);

        $name = is_string($values['app.name']) && trim($values['app.name']) !== ''
            ? $values['app.name']
            : $this->config->getName();

        $shortName = is_string($values['app.shortName']) && trim($values['app.shortName']) !== ''
            ? $values['app.shortName']
            : $name;

        $policy = $this->config->getAuthPolicy();

        return Response::success([
            'name'        => $name,
            'shortName'   => $shortName,
            'icon'        => self::slotInfo('icon', $values['app.icon']),
            'companyLogo' => self::slotInfo('companyLogo', $values['app.companyLogo']),
            // DS-wide výchozí vzhled ({mode, custom} nebo null). Veřejné spolu
            // s brandingem — je to jen barva sidebaru, nic citlivého. Klient
            // z něj počítá efektivní vzhled pro follow-uživatele.
            'theme'       => is_array($values['app.theme']) ? $values['app.theme'] : null,
            // Aktivní modul hosting.core → frontend ukáže ne-adminům
            // portálovou obrazovku (D10). Jen bool — endpoint je veřejný.
            'hasPortal'   => isset($this->tables['hosting_core_data_sources']),
            // Auth politika pro login obrazovku — jen id + label providerů,
            // nikdy clientId/secret/issuer.
            'auth'        => [
                'local'     => $policy->localLogin,
                'providers' => array_map(
                    static fn ($p) => ['id' => $p->id, 'label' => $p->label],
                    $policy->providers,
                ),
            ],
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


    /**
     * GET /_app/avatar — binární obsah avataru PŘIHLÁŠENÉHO uživatele.
     * Vyžaduje auth (na rozdíl od brandingGet) — avatar je per-uživatel a není
     * veřejný. Uživatel se bere z tokenu, ne z URL (žádný {userId} parametr).
     */
    public function avatarGet(AuthContext $auth): Response
    {
        if (!$auth->isAuthenticated || $auth->userId === null) {
            return Response::error('UNAUTHORIZED', 'Authentication required', 401);
        }

        $store    = new UserSettingsStore($this->db, $auth->userId);
        $metadata = $store->get(AvatarStorage::SETTINGS_KEY);
        if (!is_array($metadata) || !is_string($metadata['storedAs'] ?? null)) {
            return Response::error('NOT_FOUND', 'Not found', 404);
        }

        $filePath = $this->avatars->getFilePath($metadata['storedAs']);
        if (!file_exists($filePath)) {
            return Response::error('NOT_FOUND', 'Not found', 404);
        }

        $mime = is_string($metadata['mime'] ?? null) ? $metadata['mime'] : 'image/jpeg';
        // Avatar je per-uživatel — cache jen privátně, ne veřejně (na rozdíl od
        // brandingu). Cache-busting přes ?h={hash} v URL.
        $this->sendFile($filePath, [
            'Content-Type'  => $mime,
            'Cache-Control' => 'private, max-age=31536000, immutable',
            'Content-Length' => (string) (filesize($filePath) ?: 0),
        ]);

        return Response::success(null, 204);
    }

    /**
     * POST /_app/avatar — multipart upload (pole `file`) pro přihlášeného
     * uživatele. Obrázek se při uložení downscaluje na čtvercový avatar.
     */
    public function avatarUpload(AuthContext $auth): Response
    {
        if (!$auth->isAuthenticated || $auth->userId === null) {
            return Response::error('UNAUTHORIZED', 'Authentication required', 401);
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

        $validated = $this->avatars->validateUpload($tmpPath, $size);
        if (is_string($validated)) {
            return Response::error('VALIDATION_ERROR', $validated, 422);
        }

        $stored = $this->avatars->store($auth->userId, $tmpPath);
        // Hash z VÝSLEDNÉHO souboru (po downscale) — cache-buster musí odpovídat
        // tomu, co se reálně servíruje.
        $hash = substr((string) hash_file('sha256', $this->avatars->getFilePath($stored['storedAs'])), 0, 16);

        $metadata = [
            'filename' => (string) ($file['name'] ?? $stored['storedAs']),
            'storedAs' => $stored['storedAs'],
            'mime'     => $stored['mime'],
            'hash'     => $hash,
            'modified' => date('c'),
        ];
        $store = new UserSettingsStore($this->db, $auth->userId);
        $store->set(AvatarStorage::SETTINGS_KEY, $metadata);

        return Response::success($metadata + ['url' => self::avatarInfo($metadata)['url']], 201);
    }

    /**
     * DELETE /_app/avatar — smaže soubor i metadata přihlášeného uživatele.
     */
    public function avatarDelete(AuthContext $auth): Response
    {
        if (!$auth->isAuthenticated || $auth->userId === null) {
            return Response::error('UNAUTHORIZED', 'Authentication required', 401);
        }

        $this->avatars->deleteUserFiles($auth->userId);
        $store = new UserSettingsStore($this->db, $auth->userId);
        $store->delete(AvatarStorage::SETTINGS_KEY);

        return Response::success(null, 204);
    }

    /**
     * Veřejný stav avataru pro API odpovědi: `{url, hash}` nebo null.
     * URL je relativní k API base; nenese {userId} — endpoint bere uživatele
     * z tokenu.
     */
    public static function avatarInfo(mixed $metadata): ?array
    {
        if (!is_array($metadata) || !is_string($metadata['hash'] ?? null)) {
            return null;
        }
        return [
            'url'  => '/_app/avatar?h=' . $metadata['hash'],
            'hash' => $metadata['hash'],
        ];
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
