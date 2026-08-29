<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Preprocess;

use Shipard\Core\Document\Document;
use Shipard\Core\Document\ValidationError;
use Shipard\Core\Document\ValidationResult;
use Shipard\Module\Core\Mail\Preprocess\Action\FetchLinkedDocumentAction;
use Shipard\Module\Core\Mail\Preprocess\Action\RenderBodyToPdfAction;

/**
 * Document třída pro `core_mail_preprocess_rules` (tasks/mail-preprocess.md §7).
 *
 * Zodpovědnosti:
 *   - aspoň jedna podmínka shody vyplněná (D11) — form-level chyba
 *   - formát podmínek: e-mail s `@`, doména bez `@` a s tečkou, regexy
 *     kompilovatelné (PCRE bez oddělovačů)
 *   - `actions` = neprázdný JSON seznam akcí; klíč akce musí být
 *     implementovaný runnerem (rezervované akce validace odmítne), povinné
 *     parametry per akci
 *   - `rule_id`: formát, unikátnost mezi živými pravidly (10/40/80);
 *     u uživatelských se generuje `user-…`
 *   - normalizace (trim, lowercase e-mail/doména, prázdné → NULL,
 *     akce re-serializované), defaulty a audit pole v beforeSave
 */
class PreprocessRuleDocument extends Document
{
    /** docState hodnoty (core.system.docStatesArchive), ve kterých pravidlo "žije". */
    private const LIVE_DOC_STATES = [10, 40, 80];

    /**
     * Akce, které runner umí vykonat (zrcadlo PreprocessRunnerFactory::
     * defaultActions). convertOfficeToPdf je v katalogu cfgItem
     * preprocessActions s phase 2 (rezervováno, D18) — validace ho odmítne.
     */
    public const IMPLEMENTED_ACTIONS = [FetchLinkedDocumentAction::KEY, RenderBodyToPdfAction::KEY];

    private const MATCH_COLUMNS = ['sender_email', 'sender_domain', 'subject_regex', 'body_regex'];
    private const RULE_ID_PATTERN = '~^[a-z0-9][a-z0-9-]{1,58}$~';

    public function validate(array &$data): ValidationResult
    {
        $result = new ValidationResult();
        self::normalizeMatchColumns($data);

        $hasCondition = false;
        foreach (self::MATCH_COLUMNS as $column) {
            if (($data[$column] ?? null) !== null) {
                $hasCondition = true;
            }
        }
        if (!$hasCondition) {
            $result->addError(
                ValidationError::FIELD_FORM,
                'Vyplňte aspoň jednu podmínku shody (e-mail, doména, regex předmětu nebo těla)',
                'match_required',
            );
        }

        $email = $data['sender_email'] ?? null;
        if ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $result->addError('sender_email', 'E-mail odesílatele není platná adresa', 'invalid_format');
        }

        $domain = $data['sender_domain'] ?? null;
        if ($domain !== null && (str_contains($domain, '@') || !str_contains($domain, '.'))) {
            $result->addError('sender_domain', 'Doména se zadává bez @ a musí obsahovat tečku', 'invalid_format');
        }

        foreach (['subject_regex', 'body_regex'] as $column) {
            $pattern = $data[$column] ?? null;
            if ($pattern !== null && ($error = PreprocessRuleMatcher::compileError($pattern)) !== null) {
                $result->addError($column, "Neplatný regulární výraz: {$error}", 'invalid_regex');
            }
        }

        $this->validateActions($data['actions'] ?? null, $result);

        $ruleId = trim((string) ($data['rule_id'] ?? ''));
        if ($ruleId !== '') {
            if (!preg_match(self::RULE_ID_PATTERN, $ruleId)) {
                $result->addError('rule_id', 'Klíč pravidla: 2–60 znaků, malá písmena, číslice a pomlčky', 'invalid_format');
            } elseif ($this->db !== null) {
                $existing = $this->findLiveDuplicate($ruleId, (int) ($data['id'] ?? 0));
                if ($existing !== null) {
                    $result->addError('rule_id', "Živé pravidlo s tímto klíčem již existuje (id {$existing})", 'duplicate_rule_id');
                }
            }
        }

        return $result;
    }

    public function beforeSave(array &$data, ?array $originalData = null): void
    {
        $now = date('Y-m-d H:i:s');
        $isNew = empty($data['id']);

        self::normalizeMatchColumns($data);

        $actions = PreprocessRuleMatcher::decodeActions($data['actions'] ?? null);
        if ($actions !== null) {
            $data['actions'] = (string) json_encode($actions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        if (isset($data['notice'])) {
            $notice = trim((string) $data['notice']);
            $data['notice'] = $notice !== '' ? $notice : null;
        }

        if ($isNew) {
            if (empty($data['origin'])) {
                $data['origin'] = 'user';
            }
            if (!isset($data['hit_count'])) {
                $data['hit_count'] = 0;
            }
            if (empty($data['created'])) {
                $data['created'] = $now;
            }
        }

        $ruleId = trim((string) ($data['rule_id'] ?? ''));
        $data['rule_id'] = $ruleId !== '' ? $ruleId : $this->generateRuleId((string) ($data['notice'] ?? ''));

        $data['modified'] = $now;
    }

    /** Vygenerovaný klíč uživatelského pravidla: `user-<slug poznámky>` nebo `user-<hex>`, unikátní mezi živými. */
    private function generateRuleId(string $notice): string
    {
        $slug = trim((string) preg_replace('~[^a-z0-9]+~', '-', strtolower(self::asciiFold($notice))), '-');
        $slug = substr($slug, 0, 40);
        $base = 'user-' . ($slug !== '' ? $slug : bin2hex(random_bytes(4)));

        $candidate = $base;
        $attempt = 0;
        while ($this->db !== null && $this->findLiveDuplicate($candidate, 0) !== null && $attempt < 5) {
            $candidate = $base . '-' . bin2hex(random_bytes(2));
            $attempt++;
        }
        return $candidate;
    }

    private static function asciiFold(string $text): string
    {
        $folded = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        return is_string($folded) ? $folded : $text;
    }

    /** Trim, lowercase e-mail/doména, prázdné → NULL — sdílí validate i beforeSave. */
    private static function normalizeMatchColumns(array &$data): void
    {
        foreach (self::MATCH_COLUMNS as $column) {
            if (!array_key_exists($column, $data)) {
                continue;
            }
            $value = trim((string) ($data[$column] ?? ''));
            if ($column === 'sender_email' || $column === 'sender_domain') {
                $value = strtolower(ltrim($value, '@'));
            }
            $data[$column] = $value !== '' ? $value : null;
        }
    }

    private function validateActions(mixed $raw, ValidationResult $result): void
    {
        $actions = PreprocessRuleMatcher::decodeActions($raw);
        if ($actions === null) {
            $result->addError(
                'actions',
                'Akce musí být neprázdný JSON seznam objektů s klíčem "action"',
                'invalid_json',
            );
            return;
        }

        foreach ($actions as $i => $action) {
            $key = trim((string) $action['action']);
            if (!in_array($key, self::IMPLEMENTED_ACTIONS, true)) {
                $result->addError(
                    'actions',
                    "Akce #" . ($i + 1) . ": \"{$key}\" není podporovaná (dostupné: " . implode(', ', self::IMPLEMENTED_ACTIONS) . ')',
                    'unknown_action',
                );
                continue;
            }

            if ($key === FetchLinkedDocumentAction::KEY) {
                $regex = trim((string) ($action['linkHrefRegex'] ?? ''));
                if ($regex === '') {
                    $result->addError('actions', "Akce #" . ($i + 1) . ": linkHrefRegex je povinný", 'param_required');
                } elseif (($error = PreprocessRuleMatcher::compileError($regex)) !== null) {
                    $result->addError('actions', "Akce #" . ($i + 1) . ": linkHrefRegex není platný regex: {$error}", 'invalid_regex');
                }
                if (FetchLinkedDocumentAction::normalizeDomains($action['allowedDomains'] ?? null) === []) {
                    $result->addError('actions', "Akce #" . ($i + 1) . ": allowedDomains je povinný neprázdný seznam", 'param_required');
                }
            }
        }
    }

    /** Vrátí id živého pravidla se stejným rule_id, nebo null. */
    private function findLiveDuplicate(string $ruleId, int $excludeId): ?int
    {
        $row = $this->db->fetch(
            'SELECT %n FROM %n WHERE %n = %s AND %n IN %in AND %n != %i LIMIT 1',
            'id',
            'core_mail_preprocess_rules',
            'rule_id',
            $ruleId,
            'docState',
            self::LIVE_DOC_STATES,
            'id',
            $excludeId,
        );

        return $row !== null ? (int) ((array) $row)['id'] : null;
    }
}
