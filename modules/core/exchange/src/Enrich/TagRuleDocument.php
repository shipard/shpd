<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Enrich;

use Shipard\Core\Document\Document;
use Shipard\Core\Document\ValidationResult;

/**
 * Document třída pro `core_exchange_tag_rules` (tasks/content-tag-ui.md D28).
 *
 * Zodpovědnosti:
 *   - validace: IČO povinné (normalizace přes ContentTagResolver), štítek
 *     musí být klíč cfgItem `core.exchange.contentTags` (chybějící compiled
 *     config → degradace bez kontroly, vzor ItemDocument),
 *   - unikátnost company_id (unique index — čistá chyba místo SQL výjimky),
 *   - přeštítkování uživatelem přepíná `origin` na 'user' (learning pak
 *     pravidlo nikdy nezmění, jen statistiky — ContentTagRuleCaptureHandler),
 *   - audit pole (created, modified) a defaulty nového záznamu.
 */
class TagRuleDocument extends Document
{
    public function validate(array &$data): ValidationResult
    {
        $result = new ValidationResult();

        $companyId = ContentTagResolver::normalizeCompanyId((string) ($data['company_id'] ?? ''));
        if ($companyId === '') {
            $result->addError('company_id', 'IČO je povinné', 'required');
            return $result;
        }

        $tag = trim((string) ($data['tag'] ?? ''));
        if ($tag === '') {
            $result->addError('tag', 'Štítek je povinný', 'required');
            return $result;
        }
        $taxonomy = $this->config?->cfgItem('core.exchange.contentTags');
        if (is_array($taxonomy) && !array_key_exists($tag, $taxonomy)) {
            $result->addError('tag', "Neznámý obsahový štítek „{$tag}\"", 'unknown_tag');
        }

        if ($result->isValid() && $this->db !== null) {
            $existing = $this->findDuplicate($companyId, (int) ($data['id'] ?? 0));
            if ($existing !== null) {
                $result->addError(
                    'company_id',
                    "Pravidlo pro toto IČO již existuje (id {$existing})",
                    'duplicate_company_id',
                );
            }
        }

        return $result;
    }

    public function beforeSave(array &$data, ?array $originalData = null): void
    {
        $now = date('Y-m-d H:i:s');
        $isNew = empty($data['id']);

        if (isset($data['company_id'])) {
            $data['company_id'] = ContentTagResolver::normalizeCompanyId((string) $data['company_id']);
        }

        if ($isNew) {
            if (empty($data['origin'])) {
                $data['origin'] = 'user';
            }
            if (!isset($data['confirmed'])) {
                $data['confirmed'] = 1;
            }
            if (!isset($data['hit_count'])) {
                $data['hit_count'] = 0;
            }
            if (empty($data['created'])) {
                $data['created'] = $now;
            }
        } elseif ($originalData !== null
            && isset($data['tag'])
            && (string) $data['tag'] !== (string) ($originalData['tag'] ?? '')
        ) {
            // Uživatel přebil naučený/seed štítek → pravidlo je jeho (D28);
            // learning ho od teď nemění (capture handler user pravidla
            // při konfliktu nechává být).
            $data['origin'] = 'user';
        }
        $data['modified'] = $now;
    }

    /** Vrátí id jiného pravidla se stejným (normalizovaným) IČO, nebo null. */
    private function findDuplicate(string $companyId, int $excludeId): ?int
    {
        $row = $this->db->fetch(
            'SELECT %n FROM %n WHERE %n = %s AND %n != %i LIMIT 1',
            'id',
            'core_exchange_tag_rules',
            'company_id',
            $companyId,
            'id',
            $excludeId,
        );

        return $row !== null ? (int) ((array) $row)['id'] : null;
    }
}
