<?php

declare(strict_types=1);

namespace Shipard\Module\Base\Registry;

use Shipard\Core\Document\Document;
use Shipard\Core\Document\ValidationResult;

/**
 * Document třída pro `base_registry_documents` (dokumenty Spisovny).
 *
 * Zodpovědnosti:
 *   - validace (title, doc_kind proti cfgItem, valid_from <= valid_to,
 *     metadata jako validní JSON objekt)
 *   - audit pole (created/created_by u nového, modified vždy)
 *   - promote sync: `metadata` je zdroj pravdy druhově specifických polí,
 *     `docKinds[doc_kind].promote` mapuje vybrané klíče na promoted sloupce
 *     (ref_number, valid_from, valid_to). Dirty promoted sloupec z formuláře
 *     má přednost a propisuje se zpět do metadata; jinak neprázdná hodnota
 *     v metadata plní sloupec (cesta pro import/AI).
 */
class RegistryDocumentDocument extends Document
{
    public function validate(array &$data): ValidationResult
    {
        $result = new ValidationResult();

        if (trim((string) ($data['title'] ?? '')) === '') {
            $result->addError('title', 'Název je povinný', 'required');
        }

        $kind = trim((string) ($data['doc_kind'] ?? ''));
        if ($kind === '') {
            $result->addError('doc_kind', 'Druh dokumentu je povinný', 'required');
        } elseif ($this->config !== null) {
            $kinds = $this->config->cfgItem('base.registry.docKinds');
            if (is_array($kinds) && !isset($kinds[$kind])) {
                $result->addError('doc_kind', "Neznámý druh dokumentu: {$kind}", 'unknown_kind');
            }
        }

        $validFrom = $this->normalizeDate($data['valid_from'] ?? null);
        $validTo = $this->normalizeDate($data['valid_to'] ?? null);
        if ($validFrom !== null && $validTo !== null && $validFrom > $validTo) {
            $result->addError(
                'valid_to',
                'Platnost do nesmí předcházet platnosti od',
                'invalid_range',
            );
        }

        if (isset($data['metadata']) && is_string($data['metadata']) && trim($data['metadata']) !== '') {
            $decoded = json_decode($data['metadata'], true);
            if (!is_array($decoded)) {
                $result->addError('metadata', 'Metadata musí být validní JSON objekt', 'invalid_json');
            }
        }

        return $result;
    }

    public function beforeSave(array &$data, ?array $originalData = null): void
    {
        $now = date('Y-m-d H:i:s');

        if (empty($data['id'])) {
            if (empty($data['created'])) {
                $data['created'] = $now;
            }
        }
        $data['modified'] = $now;

        $this->syncPromotedColumns($data, $originalData);
    }

    /**
     * Deterministický promote sync dle `docKinds[doc_kind].promote`.
     * Pro každý pár metaKey → column:
     *   1. promoted sloupec dirty proti originalData → metadata[metaKey] = column
     *      (formulář má přednost);
     *   2. jinak metadata[metaKey] neprázdné → column = metadata[metaKey]
     *      (import/AI cesta, metadata je zdroj).
     */
    private function syncPromotedColumns(array &$data, ?array $originalData): void
    {
        $promote = $this->resolvePromoteMap((string) ($data['doc_kind'] ?? ''));

        // Normalizace metadata na string proběhne, jen když save metadata nese;
        // partial save (např. docState-only) nesmí sloupec přepsat.
        $hasMetadata = array_key_exists('metadata', $data);

        if ($promote === []) {
            if ($hasMetadata) {
                $data['metadata'] = $this->encodeMetadata($this->decodeMetadata($data['metadata']));
            }
            return;
        }

        $touchesPromoted = array_intersect(array_values($promote), array_keys($data)) !== [];
        if (!$hasMetadata && !$touchesPromoted) {
            return;
        }

        $metadata = $this->decodeMetadata(
            $hasMetadata ? $data['metadata'] : ($originalData['metadata'] ?? null),
        );

        foreach ($promote as $metaKey => $column) {
            $current = $this->normalizeValue($data[$column] ?? null);
            $original = $this->normalizeValue($originalData[$column] ?? null);
            $isDirty = array_key_exists($column, $data) && $current !== $original;

            if ($isDirty) {
                if ($current === null) {
                    unset($metadata[$metaKey]);
                } else {
                    $metadata[$metaKey] = $current;
                }
            } elseif (isset($metadata[$metaKey])
                && $this->normalizeValue($metadata[$metaKey]) !== null
            ) {
                $data[$column] = $this->normalizeValue($metadata[$metaKey]);
            }
        }

        $data['metadata'] = $this->encodeMetadata($metadata);
    }

    /** @return array<string, string> metaKey → column */
    private function resolvePromoteMap(string $kind): array
    {
        if ($kind === '' || $this->config === null) {
            return [];
        }
        $kinds = $this->config->cfgItem('base.registry.docKinds');
        $promote = is_array($kinds) ? ($kinds[$kind]['promote'] ?? []) : [];
        return is_array($promote) ? $promote : [];
    }

    private function decodeMetadata(mixed $metadata): array
    {
        if (is_array($metadata)) {
            return $metadata;
        }
        if (is_string($metadata) && trim($metadata) !== '') {
            $decoded = json_decode($metadata, true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }

    private function encodeMetadata(array $metadata): ?string
    {
        if ($metadata === []) {
            return null;
        }
        return json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Normalizace hodnoty pro porovnání a zápis: datum → 'Y-m-d' string,
     * prázdný string → null, ostatní skaláry beze změny.
     */
    private function normalizeValue(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        if (is_string($value)) {
            $value = trim($value);
            return $value === '' ? null : $value;
        }
        return $value;
    }

    private function normalizeDate(mixed $value): ?string
    {
        $value = $this->normalizeValue($value);
        return $value === null ? null : (string) $value;
    }
}
