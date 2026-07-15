<?php

declare(strict_types=1);

namespace Shipard\Module\Base\Registry;

use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Logging\ErrorLogger;
use Shipard\Module\Core\Attachments\AttachmentService;
use Shipard\Module\Core\Attachments\TextExtractor;

/**
 * Best-effort naplnění `extracted_text` dokumentu Spisovny z jeho příloh
 * (fulltext index `ft_text`). Sdílí ruční cesta (FileFromMessageService)
 * i AI cesta (RegistryApplier) — volá se až po commitu vzniku dokumentu,
 * selhání extrakce nikdy neblokuje zařazení (jen warn log).
 *
 * Zápis jde záměrně přímým UPDATE mimo Document hooky: `extracted_text`
 * je systémový sloupec a nesmí bumpnout `modified` — na
 * `modified <= applied_at` stojí unapply guard AI cesty.
 */
class ExtractedTextFiller
{
    private const ATTACHMENTS_TABLE = 'core_attachments_files';
    private const REGISTRY_TABLE = 'base_registry_documents';
    private const REGISTRY_TABLE_ID = 428;

    public function __construct(
        private readonly DataSourceConnection $db,
        private readonly AttachmentService $attachments,
        private readonly TextExtractor $extractor = new TextExtractor(),
    ) {}

    /**
     * `$clearWhenNoAttachments`: dokument bez obsahových příloh dostane
     * `extracted_text = NULL` (regenerace po smazání příloh). Když přílohy
     * existují, ale extrakce nic nedá (chybějící pdftotext, scan bez textu),
     * stávající text se nikdy nemaže — best-effort jako při zařazení.
     *
     * @return array{chars: int, attachments: int}
     */
    public function fill(int $documentId, bool $clearWhenNoAttachments = false): array
    {
        try {
            $rows = $this->db->fetchAll(
                'SELECT `file_path`, `file_name`, `mime_type` FROM %n'
                . ' WHERE `table_id` = %i AND `record_id` = %i AND `is_deleted` = 0'
                . ' ORDER BY `att_order` ASC, `name` ASC',
                self::ATTACHMENTS_TABLE,
                self::REGISTRY_TABLE_ID,
                $documentId,
            );

            if ($rows === [] && $clearWhenNoAttachments) {
                $this->db->getDibiConnection()
                    ->update(self::REGISTRY_TABLE, ['extracted_text' => null])
                    ->where('id = %i', $documentId)
                    ->execute();
                return ['chars' => 0, 'attachments' => 0];
            }

            $parts = [];
            foreach ($rows as $att) {
                $path = $this->attachments->getFilePath([
                    'file_path' => (string) $att['file_path'],
                    'file_name' => (string) $att['file_name'],
                ]);
                $text = $this->extractor->extract($path, (string) $att['mime_type']);
                if ($text !== null) {
                    $parts[] = $text;
                }
            }
            if ($parts === []) {
                return ['chars' => 0, 'attachments' => count($rows)];
            }

            $text = mb_substr(implode("\n\n", $parts), 0, TextExtractor::MAX_LENGTH);
            $this->db->getDibiConnection()
                ->update(self::REGISTRY_TABLE, ['extracted_text' => $text])
                ->where('id = %i', $documentId)
                ->execute();
            return ['chars' => mb_strlen($text), 'attachments' => count($rows)];
        } catch (\Throwable $e) {
            ErrorLogger::warn('ExtractedTextFiller: text extraction failed', [
                'documentId' => $documentId,
                'error'      => $e->getMessage(),
            ]);
            return ['chars' => 0, 'attachments' => 0];
        }
    }
}
