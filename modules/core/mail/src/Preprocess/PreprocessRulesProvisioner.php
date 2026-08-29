<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Preprocess;

use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Utils\JsoncParser;

/**
 * Idempotentní provisioning systémových pravidel předzpracování
 * z katalogu `config/systemPreprocessRules.jsonc` (D2). Volá se
 * z `ds-upgrade` **bezpodmínečně** — i pod `skipProvisioning` (pravidla
 * jsou systémový kontrakt modulu, ne migrovaná data; import-mode DS
 * přijímá poštu stejně).
 *
 * Per `rule_id`: chybí → INSERT (docState 40, resp. 70 u `phase > 1`);
 * existuje ve 40 → UPDATE obsahových polí, když se liší; jiný stav
 * (koncept, archiv, smazáno) → beze změny — archivované systémové
 * pravidlo se nekřísí, hit statistiky a `created` se nikdy nepřepisují.
 */
final class PreprocessRulesProvisioner
{
    public const CATALOG_PATH = __DIR__ . '/../../config/systemPreprocessRules.jsonc';

    private const TABLE = 'core_mail_preprocess_rules';
    private const DOC_STATE_CONFIRMED = 40;
    private const DOC_STATE_MAIN_CONFIRMED = 3;
    private const DOC_STATE_ARCHIVED = 70;
    private const DOC_STATE_MAIN_ARCHIVED = 4;

    private const CONTENT_COLUMNS = ['sender_email', 'sender_domain', 'subject_regex', 'body_regex', 'actions', 'notice'];

    public function __construct(
        private readonly DataSourceConnection $db,
        private readonly ?string $catalogPath = null,
    ) {
    }

    /**
     * @return array{created: list<string>, updated: list<string>, unchanged: list<string>, skipped: list<string>}
     */
    public function provision(): array
    {
        $result = ['created' => [], 'updated' => [], 'unchanged' => [], 'skipped' => []];
        $now = date('Y-m-d H:i:s');

        foreach (self::loadCatalog($this->catalogPath) as $rule) {
            $ruleId = (string) $rule['rule_id'];
            $content = self::contentOf($rule);

            $row = $this->db->fetchRow(
                'SELECT id, docState, sender_email, sender_domain, subject_regex, body_regex, actions, notice'
                . ' FROM %n WHERE rule_id = %s',
                self::TABLE,
                $ruleId,
            );

            if ($row === null) {
                $archived = ((int) ($rule['phase'] ?? 1)) > 1;
                $this->db->insertRow(self::TABLE, $content + [
                    'rule_id' => $ruleId,
                    'origin' => 'system',
                    'hit_count' => 0,
                    'last_hit_at' => null,
                    'created' => $now,
                    'created_by' => null,
                    'modified' => $now,
                    'docState' => $archived ? self::DOC_STATE_ARCHIVED : self::DOC_STATE_CONFIRMED,
                    'docStateMain' => $archived ? self::DOC_STATE_MAIN_ARCHIVED : self::DOC_STATE_MAIN_CONFIRMED,
                ]);
                $result['created'][] = $ruleId;
                continue;
            }

            if ((int) $row['docState'] !== self::DOC_STATE_CONFIRMED) {
                $result['skipped'][] = $ruleId;
                continue;
            }

            $current = [];
            foreach (self::CONTENT_COLUMNS as $column) {
                $current[$column] = $row[$column] ?? null;
            }
            if (self::sameContent($current, $content)) {
                $result['unchanged'][] = $ruleId;
                continue;
            }

            $this->db->updateWhere(self::TABLE, $content + ['modified' => $now], 'id = %i', (int) $row['id']);
            $result['updated'][] = $ruleId;
        }

        return $result;
    }

    /**
     * Katalog s validací tvaru — chyba v katalogu je chyba repozitáře,
     * ať spadne hlasitě při ds-upgrade, ne tiše při intake.
     *
     * @return list<array<string, mixed>>
     */
    public static function loadCatalog(?string $path = null): array
    {
        $catalog = JsoncParser::parseFile($path ?? self::CATALOG_PATH);
        $rules = $catalog['rules'] ?? null;
        if (!is_array($rules)) {
            throw new \RuntimeException('systemPreprocessRules.jsonc: missing "rules" list');
        }

        $seen = [];
        foreach ($rules as $i => $rule) {
            $ruleId = trim((string) ($rule['rule_id'] ?? ''));
            if ($ruleId === '' || !preg_match('~^[a-z0-9][a-z0-9-]{1,58}$~', $ruleId)) {
                throw new \RuntimeException("systemPreprocessRules.jsonc: rule #{$i} has an invalid rule_id");
            }
            if (isset($seen[$ruleId])) {
                throw new \RuntimeException("systemPreprocessRules.jsonc: duplicate rule_id '{$ruleId}'");
            }
            $seen[$ruleId] = true;

            $hasCondition = false;
            foreach (['sender_email', 'sender_domain', 'subject_regex', 'body_regex'] as $column) {
                if (trim((string) ($rule[$column] ?? '')) !== '') {
                    $hasCondition = true;
                }
            }
            if (!$hasCondition) {
                throw new \RuntimeException("systemPreprocessRules.jsonc: rule '{$ruleId}' has no match condition");
            }
            foreach (['subject_regex', 'body_regex'] as $column) {
                $pattern = trim((string) ($rule[$column] ?? ''));
                if ($pattern !== '' && ($error = PreprocessRuleMatcher::compileError($pattern)) !== null) {
                    throw new \RuntimeException("systemPreprocessRules.jsonc: rule '{$ruleId}' {$column} is invalid: {$error}");
                }
            }
            if (PreprocessRuleMatcher::decodeActions($rule['actions'] ?? null) === null) {
                throw new \RuntimeException("systemPreprocessRules.jsonc: rule '{$ruleId}' has no valid actions");
            }
        }

        return array_values($rules);
    }

    /**
     * Obsahová pole v DB tvaru (NULL za prázdné, akce jako JSON string).
     *
     * @param array<string, mixed> $rule
     * @return array<string, string|null>
     */
    private static function contentOf(array $rule): array
    {
        $content = [];
        foreach (['sender_email', 'sender_domain', 'subject_regex', 'body_regex', 'notice'] as $column) {
            $value = trim((string) ($rule[$column] ?? ''));
            if ($column === 'sender_email' || $column === 'sender_domain') {
                $value = strtolower($value);
            }
            $content[$column] = $value !== '' ? $value : null;
        }
        $content['actions'] = (string) json_encode(
            PreprocessRuleMatcher::decodeActions($rule['actions']),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
        return $content;
    }

    /** Rekurzivní ksort asociativních polí — pořadí klíčů v JSON není obsahová změna. */
    private static function normalizeJson(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        foreach ($value as $k => $v) {
            $value[$k] = self::normalizeJson($v);
        }
        if (!array_is_list($value)) {
            ksort($value);
        }
        return $value;
    }

    /**
     * @param array<string, mixed> $current
     * @param array<string, string|null> $wanted
     */
    private static function sameContent(array $current, array $wanted): bool
    {
        foreach ($wanted as $column => $value) {
            $existing = $current[$column] ?? null;
            if ($column === 'actions') {
                $a = self::normalizeJson(json_decode((string) $existing, true));
                $b = self::normalizeJson(json_decode((string) $value, true));
                if ($a !== $b) {
                    return false;
                }
                continue;
            }
            if ((string) ($existing ?? '') !== (string) ($value ?? '')) {
                return false;
            }
        }
        return true;
    }
}
