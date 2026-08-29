<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Preprocess;

use Shipard\Core\Logging\ErrorLogger;

/**
 * Matchování došlé zprávy proti pravidlům předzpracování
 * (`core_mail_preprocess_rules`, tasks/mail-preprocess.md D1/D2/D11).
 *
 * Matchují výhradně potvrzená pravidla (docState 40). Podmínky pravidla
 * se skládají AND přes vyplněné sloupce; pravidlo bez jediné podmínky
 * nematchuje nikdy (obrana — validaci vynucuje PreprocessRuleDocument).
 * Regexy jsou case-insensitive, vyhodnocují se nad předmětem resp. nad
 * spojeným `body_html + body_plain`. Nevalidní regex = pravidlo se
 * přeskočí s warningem, intake nikdy nespadne.
 *
 * Výstupem je plán: sjednocení akcí všech matchnutých pravidel v pořadí
 * dle `id`. Plán se ukládá na zprávu jako snapshot — runner vykonává jej,
 * ne aktuální stav pravidel (D12).
 */
final class PreprocessRuleMatcher
{
    /** docState potvrzeného pravidla (core.system.docStatesArchive). */
    public const DOC_STATE_CONFIRMED = 40;

    private const TABLE = 'core_mail_preprocess_rules';

    public function __construct(
        private readonly \Dibi\Connection $db,
    ) {
    }

    /**
     * @return list<array{ruleId: string, ruleNdx: int, actions: list<array<string, mixed>>}>|null
     */
    public function match(string $senderEmail, string $subject, ?string $bodyHtml, ?string $bodyPlain): ?array
    {
        $rows = $this->db->fetchAll(
            'SELECT id, rule_id, sender_email, sender_domain, subject_regex, body_regex, actions'
            . ' FROM %n WHERE docState = %i ORDER BY id ASC',
            self::TABLE,
            self::DOC_STATE_CONFIRMED,
        );

        $rules = [];
        foreach ($rows as $row) {
            $rules[] = (array) $row;
        }

        return self::plan($rules, $senderEmail, $subject, $bodyHtml, $bodyPlain);
    }

    /**
     * Čistá varianta bez DB — sestaví plán z předaných pravidel.
     *
     * @param list<array<string, mixed>> $rules Řádky pravidel (id, rule_id,
     *        sender_email, sender_domain, subject_regex, body_regex, actions).
     * @return list<array{ruleId: string, ruleNdx: int, actions: list<array<string, mixed>>}>|null
     */
    public static function plan(
        array $rules,
        string $senderEmail,
        string $subject,
        ?string $bodyHtml,
        ?string $bodyPlain,
    ): ?array {
        $body = trim((string) $bodyHtml . "\n" . (string) $bodyPlain);
        $plan = [];

        foreach ($rules as $rule) {
            if (!self::ruleMatches($rule, $senderEmail, $subject, $body)) {
                continue;
            }

            $actions = self::decodeActions($rule['actions'] ?? null);
            if ($actions === null) {
                ErrorLogger::warn('Preprocess rule has no valid actions — skipped', [
                    'rule' => (string) ($rule['rule_id'] ?? $rule['id'] ?? '?'),
                ]);
                continue;
            }

            $plan[] = [
                'ruleId' => (string) ($rule['rule_id'] ?? ''),
                'ruleNdx' => (int) ($rule['id'] ?? 0),
                'actions' => $actions,
            ];
        }

        return $plan === [] ? null : $plan;
    }

    /**
     * AND přes vyplněné podmínky; false i pro pravidlo bez podmínek.
     *
     * @param array<string, mixed> $rule
     */
    public static function ruleMatches(array $rule, string $senderEmail, string $subject, string $body): bool
    {
        $email = strtolower(trim($senderEmail));
        $domain = str_contains($email, '@') ? substr($email, (int) strrpos($email, '@') + 1) : '';
        $conditions = 0;

        $wanted = self::nonEmpty($rule['sender_email'] ?? null);
        if ($wanted !== null) {
            $conditions++;
            if ($email !== strtolower($wanted)) {
                return false;
            }
        }

        $wanted = self::nonEmpty($rule['sender_domain'] ?? null);
        if ($wanted !== null) {
            $conditions++;
            $wantedDomain = strtolower(ltrim($wanted, '@'));
            if ($domain !== $wantedDomain && !str_ends_with($domain, '.' . $wantedDomain)) {
                return false;
            }
        }

        $wanted = self::nonEmpty($rule['subject_regex'] ?? null);
        if ($wanted !== null) {
            $conditions++;
            if (self::guardedRegex($rule, $wanted, $subject) !== true) {
                return false;
            }
        }

        $wanted = self::nonEmpty($rule['body_regex'] ?? null);
        if ($wanted !== null) {
            $conditions++;
            if (self::guardedRegex($rule, $wanted, $body) !== true) {
                return false;
            }
        }

        return $conditions > 0;
    }

    /**
     * Case-insensitive PCRE bez oddělovačů. Vrací null u nevalidního vzoru.
     * Při vadném UTF-8 v subjektu se zkusí bez `u` (tělo e-mailu může
     * nést rozbité kódování, regex má matchovat i tak).
     */
    public static function regexMatches(string $pattern, string $subject): ?bool
    {
        $escaped = str_replace('~', '\~', $pattern);

        $result = @preg_match('~' . $escaped . '~iu', $subject);
        if ($result === false && preg_last_error() === PREG_BAD_UTF8_ERROR) {
            $result = @preg_match('~' . $escaped . '~i', $subject);
        }
        if ($result === false) {
            return null;
        }

        return $result === 1;
    }

    /** Chybová hláška nevalidního regexu, null = vzor je v pořádku (pro validaci pravidla). */
    public static function compileError(string $pattern): ?string
    {
        $escaped = str_replace('~', '\~', $pattern);
        if (@preg_match('~' . $escaped . '~iu', '') === false) {
            return preg_last_error_msg();
        }
        return null;
    }

    /**
     * @return list<array<string, mixed>>|null null = chybějící / nevalidní JSON
     */
    public static function decodeActions(mixed $raw): ?array
    {
        if (is_array($raw)) {
            $decoded = $raw;
        } else {
            $decoded = json_decode((string) $raw, true);
        }
        if (!is_array($decoded) || $decoded === [] || !array_is_list($decoded)) {
            return null;
        }
        foreach ($decoded as $action) {
            if (!is_array($action) || trim((string) ($action['action'] ?? '')) === '') {
                return null;
            }
        }
        return $decoded;
    }

    /** @param array<string, mixed> $rule */
    private static function guardedRegex(array $rule, string $pattern, string $subject): ?bool
    {
        $matched = self::regexMatches($pattern, $subject);
        if ($matched === null) {
            ErrorLogger::warn('Preprocess rule has an invalid regex — skipped', [
                'rule' => (string) ($rule['rule_id'] ?? $rule['id'] ?? '?'),
                'pattern' => $pattern,
            ]);
        }
        return $matched;
    }

    private static function nonEmpty(mixed $value): ?string
    {
        $s = trim((string) ($value ?? ''));
        return $s !== '' ? $s : null;
    }
}
