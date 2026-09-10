<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Vat;

use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Core\Attachments\AttachmentGuard;
use Shipard\Module\Economy\Vat\Xml\FilingFilesService;

/**
 * Soubory podaného tvrzení jsou doklad o tom, co odešlo na daňový portál
 * (issue #55, X6/X16) — u podání ve stavu Podáno je nelze smazat,
 * přejmenovat ani přeřadit.
 *
 * Guard chrání **jen soubory, které vygeneroval Shipard** (`metadata.kind`
 * s prefixem `epo-`). Ručně nahrané přílohy — potvrzení o přijetí,
 * korespondence s úřadem — jsou dál plně v rukou uživatele; to je ostatně
 * důvod, proč se k podanému tvrzení přílohy přidávat smí.
 */
final class FilingAttachmentGuard implements AttachmentGuard
{
    public function __construct(private readonly DataSourceConnection $db) {}

    public function refuse(array $attachment, string $operation): ?string
    {
        if (!str_starts_with((string) FilingFilesService::kindOf($attachment), FilingFilesService::KIND_PREFIX)) {
            return null;
        }

        $state = (int) $this->db->fetchSingle(
            'SELECT docState FROM ' . FilingDocument::TABLE . ' WHERE id = %i',
            (int) ($attachment['record_id'] ?? 0),
        );
        if ($state !== FilingDocument::DOC_STATE_FILED) {
            return null;
        }

        return match ($operation) {
            self::OPERATION_DELETE => 'Soubory podaného tvrzení nelze smazat — jsou dokladem o tom,'
                . ' co bylo podáno. Opravu podejte jako nové podání.',
            self::OPERATION_RENAME => 'Soubory podaného tvrzení nelze přejmenovat.',
            default                => 'Se soubory podaného tvrzení už nelze hýbat.',
        };
    }
}
