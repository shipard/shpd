<?php

declare(strict_types=1);

namespace Shipard\Module\Base\Registry\Dataset;

use Shipard\Module\Base\Registry\RegistryApplier;
use Shipard\Module\Core\Exchange\Dataset\DatasetException;
use Shipard\Module\Core\Exchange\Dataset\SectionSeeder;
use Shipard\Module\Core\Exchange\Dataset\SeedContext;
use Shipard\Module\Core\Exchange\Dataset\SeedReport;
use Shipard\Module\Core\Exchange\Resolve\PartyResolver;
use Shipard\Module\Core\Exchange\Resolve\ResolveStatus;

/**
 * `registry/*.jsonc` (obálka `shpd.dataset.registryDocument.v1`) →
 * `base_registry_documents` + přílohy ze sidecar složky.
 *
 * Bez zprávy: data staví `RegistryApplier::buildDocumentData()` (partner
 * match-only přes PartyResolver, šanon podle názvu — chybějící se založí),
 * obálka pak přebije docState, sourceKind, promoted sloupce a `created`.
 * Insert přes Document hooky (vzor `FileFromMessageService`).
 */
final class RegistrySeeder implements SectionSeeder
{
    private const TABLE = 'base_registry_documents';
    private const BINDERS_TABLE = 'base_registry_binders';
    private const TABLE_ID = 428;

    /** @var array<string, int> */
    private array $binderCache = [];

    public function __construct(
        private readonly RegistryApplier $applier,
        private readonly PartyResolver $partyResolver,
    ) {}

    public function section(): string
    {
        return 'registry';
    }

    public function seed(SeedContext $ctx, SeedReport $report): void
    {
        foreach ($ctx->reader->listFiles('registry') as $rel) {
            try {
                $env = $ctx->reader->readJsonc($rel);
                $id = $this->insert($ctx, $env);
                $this->attachments($ctx, $report, $rel, $env, $id);
                $report->ok('registry');
            } catch (\Throwable $e) {
                $report->failed('registry', "{$rel}: {$e->getMessage()}");
            }
        }
    }

    /**
     * @param array<string, mixed> $env
     */
    private function insert(SeedContext $ctx, array $env): int
    {
        $doc = is_array($env['document'] ?? null) ? $env['document'] : [];
        $docKind = (string) ($doc['docType'] ?? 'other');
        $partner = $this->resolvePartner(is_array($doc['party'] ?? null) ? $doc['party'] : null);
        $binder = $this->binderId($ctx, isset($env['binder']) && is_string($env['binder']) ? $env['binder'] : null);

        $data = $this->applier->buildDocumentData($doc, $docKind, $partner, $binder, 0, null);
        $data['source_kind'] = is_string($env['sourceKind'] ?? null) && $env['sourceKind'] !== '' ? $env['sourceKind'] : 'import';
        $docState = (int) ($env['docState'] ?? 40);
        $data['docState'] = $docState;
        $data['docStateMain'] = $ctx->mainState('core.system.docStatesArchive', $docState, [10 => 1, 80 => 2, 40 => 3, 70 => 4, 90 => 5]);
        foreach (['refNumber' => 'ref_number', 'validFrom' => 'valid_from', 'validTo' => 'valid_to', 'notice' => 'notice'] as $from => $to) {
            if (isset($env[$from]) && $env[$from] !== '') {
                $data[$to] = $env[$from];
            }
        }
        $created = SeedContext::dbDateTime(is_string($env['created'] ?? null) ? $env['created'] : null);
        if ($created !== null) {
            $data['created'] = $created;
        }

        $dibi = $ctx->db;
        $document = $ctx->registry->getDocument(self::TABLE, $data);
        $document->setDb($dibi);
        $document->setConfig($ctx->config);

        $validation = $document->validate($data);
        if (!$validation->isValid()) {
            $first = $validation->getErrors()[0] ?? null;
            throw new DatasetException($first !== null ? "validace {$first->column}: {$first->message}" : 'validace selhala');
        }
        $document->beforeSave($data);
        if ($created !== null) {
            $data['created'] = $created; // beforeSave může razítkovat now
        }

        $dibi->insert(self::TABLE, $data)->execute();
        $id = (int) $dibi->getInsertId();
        $document->afterPersist($data + ['id' => $id]);

        return $id;
    }

    /**
     * @param array<string, mixed> $env
     */
    private function attachments(SeedContext $ctx, SeedReport $report, string $rel, array $env, int $documentId): void
    {
        $dir = SeedContext::sidecarDir($rel);
        foreach ((array) ($env['attachments'] ?? []) as $att) {
            if (!is_array($att) || !is_string($att['file'] ?? null)) {
                continue;
            }
            $relFile = $dir . '/' . $att['file'];
            if (!$ctx->reader->fileExists($relFile)) {
                $report->warning("registry {$rel}: příloha '{$att['file']}' v sadě chybí — vynechána");
                continue;
            }
            $tmp = $ctx->tempCopy($ctx->reader->resolvePath($relFile));
            $result = $ctx->attachments->upload(self::TABLE_ID, $documentId, (string) ($att['name'] ?? $att['file']), $tmp, null);
            if (!($result['success'] ?? false)) {
                $report->warning("registry {$rel}: nahrání přílohy '{$att['file']}' selhalo: " . (string) ($result['error'] ?? 'unknown'));
            }
        }
    }

    /**
     * @param array<string, mixed>|null $party
     */
    private function resolvePartner(?array $party): ?int
    {
        if ($party === null) {
            return null;
        }
        try {
            $r = $this->partyResolver->resolve([
                'companyId' => $party['companyId'] ?? null,
                'name'      => $party['name'] ?? null,
            ]);
        } catch (\Throwable) {
            return null;
        }
        return $r->status === ResolveStatus::Matched ? $r->matchedId : null;
    }

    private function binderId(SeedContext $ctx, ?string $name): ?int
    {
        $name = $name !== null ? trim($name) : '';
        if ($name === '') {
            return null;
        }
        if (isset($this->binderCache[$name])) {
            return $this->binderCache[$name];
        }
        $row = $ctx->db->fetch(
            'SELECT [id] FROM %n WHERE LOWER([name]) = %s AND [docState] <> 90 ORDER BY [id] LIMIT 1',
            self::BINDERS_TABLE, mb_strtolower($name),
        );
        if ($row !== null) {
            return $this->binderCache[$name] = (int) $row['id'];
        }
        $ctx->db->insert(self::BINDERS_TABLE, [
            'name'         => $name,
            'order_pos'    => 0,
            'docState'     => 40,
            'docStateMain' => $ctx->mainState('core.system.docStatesArchive', 40, [40 => 3]),
            'created'      => date('Y-m-d H:i:s'),
        ])->execute();
        return $this->binderCache[$name] = (int) $ctx->db->getInsertId();
    }
}
