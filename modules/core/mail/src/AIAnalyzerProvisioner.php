<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail;

use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Utils\JsoncParser;

/**
 * Idempotentní provisioning systémového uživatele `_ai_analyzer`, default
 * AI backendu a default profilu. Volá se z:
 *   - `ds-upgrade` (auto-hook na konci, vedle MailRouterProvisioner)
 *   - `ai-analyzer-bootstrap` (manuální spuštění z Fáze C)
 *   - `ai-analyzer-setup` (musí zajistit existenci uživatele před tokenem)
 *
 * Spec: tasks/mail-phase3a.md §6.1, §6.2, §11.
 */
class AIAnalyzerProvisioner
{
    public const ANALYZER_LOGIN = '_ai_analyzer';
    public const DEFAULT_BACKEND_ID = 'default';
    public const DEFAULT_PROFILE_ID = 'czech_invoices';
    private const DEFAULT_PROFILE_TEMPLATE = __DIR__ . '/../profiles/default_czech_invoices.jsonc';

    public function __construct(
        private readonly DataSourceConnection $db,
    ) {}

    /**
     * @return array{
     *     user: array{id: int, created: bool},
     *     backend: array{id: int, created: bool},
     *     profile: array{id: int, created: bool, skipped_reason?: string}
     * }
     */
    public function provision(): array
    {
        $user = $this->ensureAnalyzerUser();
        $backend = $this->ensureDefaultBackend();
        $profile = $this->ensureDefaultProfile($backend['id']);

        return [
            'user' => $user,
            'backend' => $backend,
            'profile' => $profile,
        ];
    }

    /**
     * @return array{id: int, created: bool}
     */
    public function ensureAnalyzerUser(): array
    {
        $row = $this->db->fetchRow(
            'SELECT id FROM core_system_users WHERE login = %s',
            self::ANALYZER_LOGIN,
        );

        if ($row !== null) {
            return ['id' => (int) $row['id'], 'created' => false];
        }

        $randomPassword = bin2hex(random_bytes(32));
        $id = $this->db->insertRow('core_system_users', [
            'login' => self::ANALYZER_LOGIN,
            'password_hash' => password_hash($randomPassword, PASSWORD_DEFAULT),
            'full_name' => 'AI Analyzer (system)',
            'email' => null,
            'is_active' => 1,
            'is_system' => 1,
        ]);

        return ['id' => $id, 'created' => true];
    }

    /**
     * Vytvoří (pokud chybí) default backend `default` (Anthropic Claude).
     * `api_key` zůstává NULL, `is_active` = false — admin doplní klíč přes
     * `ai-analyzer-set-key` (Fáze C).
     *
     * @return array{id: int, created: bool, skipped_reason?: string}
     */
    public function ensureDefaultBackend(): array
    {
        $row = $this->db->fetchRow(
            'SELECT id FROM core_mail_ai_backends WHERE backend_id = %s',
            self::DEFAULT_BACKEND_ID,
        );

        if ($row !== null) {
            return ['id' => (int) $row['id'], 'created' => false];
        }

        $existingDefault = $this->db->fetchRow(
            'SELECT id, backend_id FROM core_mail_ai_backends WHERE is_default = %i',
            1,
        );

        if ($existingDefault !== null) {
            return [
                'id' => (int) $existingDefault['id'],
                'created' => false,
                'skipped_reason' => "Another backend is already marked as default: {$existingDefault['backend_id']}",
            ];
        }

        $now = date('Y-m-d H:i:s');
        $id = $this->db->insertRow('core_mail_ai_backends', [
            'backend_id' => self::DEFAULT_BACKEND_ID,
            'name' => 'Anthropic Claude',
            'provider' => 'anthropic',
            'model' => 'claude-sonnet-4-5',
            'api_key' => null,
            'base_url' => null,
            'max_tokens' => 4096,
            'temperature' => 0,
            'is_default' => 1,
            'is_active' => 0,
            'docState' => 40,
            'docStateMain' => 3,
            'created' => $now,
            'modified' => $now,
        ]);

        return ['id' => $id, 'created' => true];
    }

    /**
     * Vytvoří default profil `czech_invoices` ze šablony
     * `modules/core/mail/profiles/default_czech_invoices.jsonc`. Když
     * profil tohoto kódu existuje, beze změny ho přeskočí (admin může mít
     * upravený prompt).
     *
     * @return array{id: int, created: bool, skipped_reason?: string}
     */
    public function ensureDefaultProfile(int $backendId): array
    {
        $row = $this->db->fetchRow(
            'SELECT id FROM core_mail_ai_profiles WHERE profile_id = %s',
            self::DEFAULT_PROFILE_ID,
        );

        if ($row !== null) {
            return ['id' => (int) $row['id'], 'created' => false];
        }

        $existingDefault = $this->db->fetchRow(
            'SELECT id, profile_id FROM core_mail_ai_profiles WHERE is_default = %i',
            1,
        );

        if ($existingDefault !== null) {
            return [
                'id' => (int) $existingDefault['id'],
                'created' => false,
                'skipped_reason' => "Another profile is already marked as default: {$existingDefault['profile_id']}",
            ];
        }

        $template = $this->loadProfileTemplate();
        $now = date('Y-m-d H:i:s');

        $id = $this->db->insertRow('core_mail_ai_profiles', [
            'profile_id' => (string) $template['profile_id'],
            'name' => (string) $template['name'],
            'backend' => $backendId,
            'supported_doc_types' => json_encode(
                $template['supported_doc_types'],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ),
            'language' => (string) $template['language'],
            'prompt_version' => (string) $template['prompt_version'],
            'prompt_template' => (string) $template['prompt_template'],
            'output_schema' => json_encode(
                $template['output_schema'],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ),
            'confidence_thresholds' => json_encode(
                $template['confidence_thresholds'],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ),
            'is_default' => 1,
            'is_active' => 1,
            'docState' => 40,
            'docStateMain' => 3,
            'created' => $now,
            'modified' => $now,
        ]);

        return ['id' => $id, 'created' => true];
    }

    /**
     * @return array{
     *     profile_id: string,
     *     name: string,
     *     language: string,
     *     prompt_version: string,
     *     prompt_template: string,
     *     supported_doc_types: array<int, string>,
     *     output_schema: array<string, mixed>,
     *     confidence_thresholds: array<string, float>
     * }
     */
    protected function loadProfileTemplate(): array
    {
        return JsoncParser::parseFile(self::DEFAULT_PROFILE_TEMPLATE);
    }
}
