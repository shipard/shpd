<?php

declare(strict_types=1);

namespace Shipard\Module\Base\Registry;

use Dibi\Connection;
use Shipard\Module\Core\Exchange\Dataset\ExportedFile;
use Shipard\Module\Core\Exchange\Dataset\ExportedRecord;
use Shipard\Module\Core\Exchange\Dataset\RecordExporter;
use Shipard\Module\Core\Exchange\Dataset\ValueNormalizer as V;

/**
 * `base_registry_documents` (+ přílohy) → obálka `shpd.dataset.registryDocument.v1`
 * nad `shpd.registry.document.v1` (R3 v tasks/dataset-phase1.md).
 *
 * Vnitřní `document` je canonical, jaký produkuje AI analýza (docType,
 * title, summary, party, kindFields = metadata); obálka nese to, co
 * canonical nemá a `RegistryApplier` bere ze zprávy: docState, sourceKind,
 * šanon (název), promoted sloupce, poznámku, `created` a přílohy. Přílohy
 * jdou do sidecar složky záznamu (R2), `attachments[].file` = jméno souboru
 * v ní.
 */
final class RegistryExporter implements RecordExporter
{
    public const FORMAT = 'shpd.dataset.registryDocument.v1';

    private const REGISTRY_TABLE_ID = 428;

    /** @var list<string> */
    private array $warnings = [];

    public function __construct(
        private readonly Connection $db,
        private readonly string $dsPath,
    ) {}

    public function section(): string
    {
        return 'registry';
    }

    public function exportAll(): array
    {
        return $this->exportRows($this->db->fetchAll(
            $this->selectSql() . ' WHERE [d.docState] <> 90 ' . $this->orderSql(),
        ));
    }

    public function exportByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        return $this->exportRows($this->db->fetchAll(
            $this->selectSql() . ' WHERE [d.id] IN %in AND [d.docState] <> 90 ' . $this->orderSql(),
            $ids,
        ));
    }

    public function getWarnings(): array
    {
        return $this->warnings;
    }

    private function selectSql(): string
    {
        return 'SELECT [d.*], [b.name] AS [binder_name], [p.full_name] AS [partner_name],'
            . ' [p.company_id] AS [partner_company_id], [p.email] AS [partner_email]'
            . ' FROM [base_registry_documents] AS [d]'
            . ' LEFT JOIN [base_registry_binders] AS [b] ON [b.id] = [d.binder]'
            . ' LEFT JOIN [base_persons_persons] AS [p] ON [p.id] = [d.partner]';
    }

    private function orderSql(): string
    {
        return 'ORDER BY [d.created], [d.title], [d.id]';
    }

    /**
     * @param iterable<\Dibi\Row|array<string, mixed>> $rows
     * @return list<ExportedRecord>
     */
    private function exportRows(iterable $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[] = $this->exportDocument(is_array($row) ? $row : $row->toArray());
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $d
     */
    public function exportDocument(array $d): ExportedRecord
    {
        $id = (int) $d['id'];
        [$attachments, $files] = $this->attachments($id);

        $party = V::prune([
            'name'      => V::str($d['partner_name'] ?? null),
            'companyId' => V::str($d['partner_company_id'] ?? null),
            'email'     => V::str($d['partner_email'] ?? null),
        ]);

        $data = [
            'format'   => self::FORMAT,
            'document' => [
                'schema'     => 'shpd.registry.document.v1',
                'docType'    => V::str($d['doc_kind'] ?? null),
                'title'      => V::str($d['title'] ?? null) ?? '',
                'summary'    => V::str($d['ai_summary'] ?? null),
                'party'      => $party ?: null,
                'kindFields' => V::json($d['metadata'] ?? null) ?? [],
            ],
            'docState'    => (int) ($d['docState'] ?? 40),
            'sourceKind'  => V::str($d['source_kind'] ?? null),
            'binder'      => V::str($d['binder_name'] ?? null),
            'refNumber'   => V::str($d['ref_number'] ?? null),
            'validFrom'   => V::date($d['valid_from'] ?? null),
            'validTo'     => V::date($d['valid_to'] ?? null),
            'notice'      => V::str($d['notice'] ?? null),
            'created'     => V::dateTime($d['created'] ?? null),
            'attachments' => $attachments,
        ];

        // kindFields = {} je legitimní (prune by prázdné pole odstranil).
        $pruned = V::prune($data);
        if (!isset($pruned['document']['kindFields'])) {
            $pruned['document']['kindFields'] = new \stdClass();
        }

        return new ExportedRecord($id, V::str($d['title'] ?? null) ?? 'dokument', $pruned, $files);
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: list<ExportedFile>}
     */
    private function attachments(int $documentId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT * FROM [core_attachments_files]
             WHERE [table_id] = %i AND [record_id] = %i AND [is_deleted] = 0
             ORDER BY [att_order], [name], [id]',
            self::REGISTRY_TABLE_ID,
            $documentId,
        );

        $meta = [];
        $files = [];
        $used = [];
        foreach ($rows as $r) {
            $r = is_array($r) ? $r : $r->toArray();
            $name = self::uniqueName(V::str($r['name'] ?? null) ?? V::str($r['file_name'] ?? null) ?? 'file', $used);
            $used[$name] = true;
            $meta[] = V::prune([
                'file'     => $name,
                'name'     => V::str($r['name'] ?? null),
                'mimeType' => V::str($r['mime_type'] ?? null),
                'size'     => V::int($r['file_size'] ?? null),
                'sha256'   => V::str($r['checksum'] ?? null),
            ]);
            $files[] = new ExportedFile(
                sourcePath: $this->dsPath . '/att/' . $r['file_path'] . '/' . $r['file_name'],
                name: $name,
                attachmentId: (int) $r['id'],
            );
        }
        return [$meta, $files];
    }

    /**
     * @param array<string, true> $used
     */
    public static function uniqueName(string $name, array $used): string
    {
        $name = str_replace(['/', '\\', "\0"], '_', $name);
        if (!isset($used[$name])) {
            return $name;
        }
        $dot = strrpos($name, '.');
        $base = $dot === false || $dot === 0 ? $name : substr($name, 0, $dot);
        $ext = $dot === false || $dot === 0 ? '' : substr($name, $dot);
        for ($i = 2; ; $i++) {
            $candidate = "{$base}-{$i}{$ext}";
            if (!isset($used[$candidate])) {
                return $candidate;
            }
        }
    }
}
