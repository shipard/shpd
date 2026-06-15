<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Bank;

use Shipard\Api\AuthContext;
use Shipard\Api\Response;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Database\TableDefinition;
use Shipard\Module\Core\Attachments\AttachmentService;
use Shipard\Module\Economy\Bank\Import\ImportException;
use Shipard\Module\Economy\Bank\Import\StatementImportService;

/**
 * REST controller bankovního modulu.
 *
 * POST /_bank/import-statement (multipart/form-data) — nahrání souboru výpisu,
 * import přes StatementImportService, vrácení souhrnu. Volitelné pole
 * `account` (kód / id) override detekce vlastního účtu. Zdrojový soubor se
 * uloží jako příloha výpisu.
 */
final class BankController
{
    /** @param array<string, TableDefinition> $tables */
    public function __construct(
        private readonly DataSourceConnection $db,
        private readonly ?ConfigRuntime $config,
        private readonly string $dsPath,
        private readonly array $tables,
    ) {
    }

    public function importStatement(AuthContext $auth): Response
    {
        if ($this->config === null) {
            return Response::error('INTERNAL_ERROR', 'Konfigurace není k dispozici', 500);
        }

        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            $code = $_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE;
            $message = match ($code) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Soubor je příliš velký',
                UPLOAD_ERR_NO_FILE => 'Žádný soubor nebyl nahrán',
                UPLOAD_ERR_PARTIAL => 'Soubor byl nahrán jen částečně',
                default => 'Chyba při nahrávání souboru',
            };
            return Response::error('UPLOAD_ERROR', $message, 400);
        }

        $file = $_FILES['file'];
        $tmpPath = (string) $file['tmp_name'];
        $originalName = (string) ($file['name'] ?? 'statement');

        $raw = file_get_contents($tmpPath);
        if ($raw === false || $raw === '') {
            return Response::error('UPLOAD_ERROR', 'Nahraný soubor je prázdný nebo nečitelný.', 400);
        }

        $account = isset($_POST['account']) && trim((string) $_POST['account']) !== ''
            ? (string) $_POST['account']
            : null;
        $userId = $auth->isAuthenticated ? $auth->userId : null;

        $attachments = new AttachmentService($this->db, $this->dsPath, $this->tables);
        $service = new StatementImportService($this->db->getDibiConnection(), $this->config, $attachments);

        try {
            $summary = $service->import($raw, $account, $tmpPath, $originalName, $userId);
        } catch (ImportException $e) {
            return Response::error('IMPORT_ERROR', $e->getMessage(), 422);
        }

        return Response::success($summary);
    }
}
