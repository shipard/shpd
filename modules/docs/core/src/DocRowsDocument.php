<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\Core;

use Shipard\Core\Document\Document;
use Shipard\Core\Document\ValidationResult;

/**
 * Document for `docs_core_rows`.
 *
 * Most lifecycle stages defer to base Document (no validation, no
 * beforeSave logic — row-level price/VAT recompute happens in the head's
 * DocDocument::beforeSave so that totals and VAT recap stay in sync).
 *
 * The job of this class is the `afterSave` hook: when a row is added,
 * edited, or deleted via the sub-form endpoint, the parent document's
 * totals and VAT recap need to be rebuilt. We do that by re-running
 * DocDocument::beforeSave on the parent and writing the result back.
 *
 * This keeps the orchestration in one place (DocDocument) and ensures
 * the same logic runs whether the user saves the head directly or
 * through a row change.
 */
class DocRowsDocument extends Document
{
    /**
     * Tvrdá validace pohybu řádku (sdílená pravidla viz
     * DocRowOperationRules). Bez head kontextu nebo compiled configu se
     * validace degradovaně přeskočí — záchytnou sítí je pak kontrola
     * všech řádků v DocDocument::validate při přechodu do stavu 40.
     */
    public function validate(array &$data): ValidationResult
    {
        $result = new ValidationResult();

        $docType = $this->loadHeadDocType($data['doc_head'] ?? null);
        $cfg = $this->config?->cfgItem('docs.core.rowOperations');
        if ($docType === null || !is_array($cfg)) {
            return $result;
        }

        foreach (DocRowOperationRules::validateRow($data, $docType, $cfg) as $err) {
            $result->addError($err['column'], $err['message'], $err['code']);
        }
        return $result;
    }

    private function loadHeadDocType(mixed $headId): ?string
    {
        if ($headId === null || $headId === '' || $this->db === null) {
            return null;
        }
        $row = $this->db->fetch(
            'SELECT [doc_type] FROM [docs_core_heads] WHERE [id] = %i',
            (int) $headId,
        );
        $docType = $row !== null ? (string) ($row['doc_type'] ?? '') : '';
        return $docType !== '' ? $docType : null;
    }

    public function afterSave(array $data): void
    {
        $this->recomputeHeader($data);
    }

    public function afterDelete(array $data): void
    {
        $this->recomputeHeader($data);
    }

    /**
     * Recompute parent header totals + VAT recap based on the current set
     * of rows in DB.
     *
     * Implementation note: we instantiate DocsHeadsDocument directly rather
     * than going through TableGateway. The gateway path would re-trigger
     * row syncing, validate(), state transitions etc., but here we want
     * just the computational pieces — totals + recap. Calling the head's
     * beforeSave gives us exactly that.
     */
    private function recomputeHeader(array $rowData): void
    {
        if ($this->db === null) {
            return;
        }
        $headId = (int) ($rowData['doc_head'] ?? 0);
        if ($headId === 0) {
            return;
        }

        $headRow = $this->db->fetch(
            'SELECT * FROM [docs_core_heads] WHERE [id] = %i',
            $headId,
        );
        if ($headRow === null) {
            return;
        }
        $headData = $headRow->toArray();

        // Run the head document's beforeSave to recompute totals and recap.
        // We don't pass originalData — we don't need state-transition logic
        // here, just the recomputation. resolveRowsForCompute will load
        // current rows from the DB since 'rows' isn't in $headData.
        $headDoc = new DocsHeadsDocument();
        $headDoc->setDb($this->db);
        if ($this->config !== null) {
            $headDoc->setConfig($this->config);
        }
        if ($this->dsConfig !== null) {
            $headDoc->setDsConfig($this->dsConfig);
        }

        // Skip validate() — the head may legitimately not satisfy confirm-time
        // validations while in Concept (e.g. no rows yet, no partner). We only
        // want the deterministic compute pipeline.
        $headDoc->beforeSave($headData);

        // Persist computed columns. Recap is replaced wholesale: delete + insert.
        $this->db->begin();
        try {
            $this->db->update('docs_core_heads', [
                'total_base'       => $headData['total_base']       ?? 0,
                'total_vat'        => $headData['total_vat']        ?? 0,
                'total_amount'     => $headData['total_amount']     ?? 0,
                'total_rounding'   => $headData['total_rounding']   ?? 0,
                'total_base_dom'   => $headData['total_base_dom']   ?? 0,
                'total_vat_dom'    => $headData['total_vat_dom']    ?? 0,
                'total_amount_dom' => $headData['total_amount_dom'] ?? 0,
            ])->where('id = %i', $headId)->execute();

            $this->db->delete('docs_core_vat_recap')
                ->where('doc_head = %i', $headId)
                ->execute();

            if (isset($headData['vatRecap']) && is_array($headData['vatRecap'])) {
                foreach ($headData['vatRecap'] as $recap) {
                    if (!is_array($recap)) {
                        continue;
                    }
                    $recap['doc_head'] = $headId;
                    $this->db->insert('docs_core_vat_recap', $recap)->execute();
                }
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }
}
