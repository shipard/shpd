<?php

declare(strict_types=1);

namespace Shipard\Api\Controller;

use Shipard\Api\AuthContext;
use Shipard\Api\Response;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Settings\SettingsStore;

/**
 * Panel „O zdroji dat" (tasks/ds-about-panel.md, Issue #41) — read-only
 * agregace identity, charakteristiky a velikostí DS pro
 * `GET /_ui/ds-about`. Jediná mutace je cache skenu příloh
 * v core_system_settings (D4).
 *
 * Tabulky ostatních modulů (osoby, pošta, DPH, doklady, přílohy) na
 * hosting profilu neexistují (D6) — před každým dotazem se ověřuje
 * registry $tables a chybějící blok se vrací jako null/nuly, nikdy 500.
 */
final class DsAboutController
{
    private const ACTIVE_DOC_STATES = [10, 40];

    public const ATTACHMENTS_CACHE_KEY = 'about.attachmentsSize';
    public const ATTACHMENTS_CACHE_TTL = 3600;

    private const TABLE_PERSONS          = 'base_persons_persons';
    private const TABLE_MAILBOXES        = 'core_mail_mailboxes';
    private const TABLE_VAT_REGISTRATIONS = 'economy_codebooks_vat_registrations';
    private const TABLE_DOCS             = 'docs_core_heads';
    private const TABLE_INCOMING_MAIL    = 'core_mail_incoming_messages';
    private const TABLE_ATTACHMENT_FILES = 'core_attachments_files';

    private const ACCOUNT_CHART_VARIANTS = ['default', 'npo', 'none'];

    /**
     * @param array<string, mixed> $tables registry TableDefinition podle
     *        názvu tabulky (TableLoader::load) — jediný zdroj pravdy
     *        o tom, které tabulky na DS existují
     */
    public function __construct(
        private readonly DataSourceConnection $db,
        private readonly ConfigRuntime $config,
        private readonly DataSourceConfig $dsConfig,
        private readonly string $language,
        private readonly array $tables = [],
    ) {}

    /** GET /_ui/ds-about — vidí každý přihlášený uživatel DS (D2). */
    public function about(AuthContext $auth): Response
    {
        if (!$auth->isAuthenticated) {
            return Response::error('UNAUTHORIZED', 'Authentication required', 401);
        }

        $settings = new SettingsStore($this->db);

        return Response::success([
            'identity' => $this->identity($settings),
            'profile'  => $this->profile($settings),
            'storage'  => $this->storage($settings),
        ]);
    }

    // ── Bloky odpovědi ──────────────────────────────────────────────────

    /** @return array{dsName: string, ownPerson: ?array{fullName: ?string, companyId: ?string, taxId: ?string}, mailAddress: ?string} */
    private function identity(SettingsStore $settings): array
    {
        $dsName = $settings->get('app.name');
        if (!is_string($dsName) || trim($dsName) === '') {
            $dsName = $this->dsConfig->getName();
        }

        $ownPerson = null;
        if ($this->hasTable(self::TABLE_PERSONS)) {
            $row = $this->db->fetchRow(
                'SELECT id, full_name, company_id, tax_id FROM ' . self::TABLE_PERSONS
                    . ' WHERE is_own = %i AND docState IN %in ORDER BY id LIMIT 1',
                1,
                self::ACTIVE_DOC_STATES,
            );
            if ($row !== null) {
                $ownPerson = [
                    'fullName'  => self::nonEmpty($row['full_name'] ?? null),
                    'companyId' => self::nonEmpty($row['company_id'] ?? null),
                    'taxId'     => self::nonEmpty($row['tax_id'] ?? null),
                ];
            }
        }

        $mailAddress = null;
        if ($this->hasTable(self::TABLE_MAILBOXES)) {
            $mailAddress = self::nonEmpty($this->db->fetchSingle(
                'SELECT email_address FROM ' . self::TABLE_MAILBOXES
                    . ' WHERE is_default = %i AND docState IN %in ORDER BY id LIMIT 1',
                1,
                self::ACTIVE_DOC_STATES,
            ));
        }

        return [
            'dsName'      => $dsName,
            'ownPerson'   => $ownPerson,
            'mailAddress' => $mailAddress,
        ];
    }

    /** @return array{vatPayer: bool, taxpayerKind: ?int, taxpayerKindLabel: ?string, accountChart: ?string, dsId: string, created: string} */
    private function profile(SettingsStore $settings): array
    {
        $vatPayer     = false;
        $taxpayerKind = null;
        if ($this->hasTable(self::TABLE_VAT_REGISTRATIONS)) {
            // valid_from je NOT NULL, valid_to může být NULL (registrace bez konce).
            $row = $this->db->fetchRow(
                'SELECT taxpayer_kind FROM ' . self::TABLE_VAT_REGISTRATIONS
                    . ' WHERE docState IN %in AND valid_from <= CURDATE()'
                    . ' AND (valid_to IS NULL OR valid_to >= CURDATE())'
                    . ' ORDER BY valid_from DESC, id DESC LIMIT 1',
                self::ACTIVE_DOC_STATES,
            );
            if ($row !== null) {
                $vatPayer     = true;
                $taxpayerKind = (int) ($row['taxpayer_kind'] ?? 0);
            }
        }

        $chart = $settings->get('economy.accountChart');
        if (!is_string($chart) || !in_array($chart, self::ACCOUNT_CHART_VARIANTS, true)) {
            $chart = null; // nerozhodnuto (nebo neznámá hodnota — panel ji nevymýšlí)
        }

        return [
            'vatPayer'          => $vatPayer,
            'taxpayerKind'      => $taxpayerKind,
            'taxpayerKindLabel' => $taxpayerKind === null ? null : $this->taxpayerKindLabel($taxpayerKind),
            'accountChart'      => $chart,
            'dsId'              => $this->dsConfig->getId(),
            'created'           => $this->dsConfig->getCreated(),
        ];
    }

    /** @return array{databaseBytes: int, attachments: array{bytes: int, files: int, computedAt: string}, counts: array{documents: int, incomingMail: int, attachmentFiles: int}} */
    private function storage(SettingsStore $settings): array
    {
        // Za celou databázi aktuálního připojení — ne table_schema z konfigurace,
        // ať to sedí i na dev DS.
        $databaseBytes = $this->db->fetchSingle(
            'SELECT SUM(data_length + index_length) FROM information_schema.tables WHERE table_schema = DATABASE()',
        );

        return [
            'databaseBytes' => (int) ($databaseBytes ?? 0),
            'attachments'   => $this->attachmentsSize($settings),
            'counts'        => [
                'documents'       => $this->countRows(self::TABLE_DOCS),
                'incomingMail'    => $this->countRows(self::TABLE_INCOMING_MAIL),
                'attachmentFiles' => $this->countRows(self::TABLE_ATTACHMENT_FILES),
            ],
        ];
    }

    // ── Přílohy: sken + cache (D4) ──────────────────────────────────────

    /**
     * Velikost `<dsDir>/att/` s cache v SettingsStore (TTL 1 h). Po expiraci
     * se přepočítá synchronně v requestu. Neexistující adresář = nuly bez
     * zápisu cache a bez zakládání adresáře (žádné mutace FS).
     *
     * @return array{bytes: int, files: int, computedAt: string}
     */
    private function attachmentsSize(SettingsStore $settings): array
    {
        $cached = $settings->get(self::ATTACHMENTS_CACHE_KEY);
        if (is_array($cached) && is_string($cached['computedAt'] ?? null)) {
            $computedAt = strtotime($cached['computedAt']);
            if ($computedAt !== false && time() - $computedAt < self::ATTACHMENTS_CACHE_TTL) {
                return [
                    'bytes'      => (int) ($cached['bytes'] ?? 0),
                    'files'      => (int) ($cached['files'] ?? 0),
                    'computedAt' => $cached['computedAt'],
                ];
            }
        }

        $now = date(DATE_ATOM);
        $dir = $this->dsConfig->getDataSourceDir() . '/att';
        if (!is_dir($dir)) {
            return ['bytes' => 0, 'files' => 0, 'computedAt' => $now];
        }

        [$bytes, $files] = $this->scanDirectory($dir);
        $result = ['bytes' => $bytes, 'files' => $files, 'computedAt' => $now];
        $settings->set(self::ATTACHMENTS_CACHE_KEY, $result);

        return $result;
    }

    /** @return array{0: int, 1: int} [bytes, files] */
    private function scanDirectory(string $dir): array
    {
        $bytes = 0;
        $files = 0;
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            );
            foreach ($iterator as $entry) {
                /** @var \SplFileInfo $entry */
                if ($entry->isFile()) {
                    $bytes += (int) $entry->getSize();
                    $files++;
                }
            }
        } catch (\UnexpectedValueException) {
            // Nečitelný podadresář — vrátíme, co se stihlo sečíst; panel
            // je informační, nemá kvůli právům na jednom adresáři spadnout.
        }

        return [$bytes, $files];
    }

    // ── Pomocné ─────────────────────────────────────────────────────────

    private function hasTable(string $table): bool
    {
        return isset($this->tables[$table]);
    }

    private function countRows(string $table): int
    {
        if (!$this->hasTable($table)) {
            return 0;
        }
        return (int) $this->db->fetchSingle('SELECT COUNT(*) FROM ' . $table);
    }

    /**
     * Lokalizovaný název druhu plátce z cfgItem
     * `economy.codebooks.vatTaxpayerKinds` (klíče = hodnoty enumInt).
     * Fallback chain `name:{lang}` → `name:en` → `name` → null.
     */
    private function taxpayerKindLabel(int $kind): ?string
    {
        $cfg = $this->config->cfgItem('economy.codebooks.vatTaxpayerKinds');
        if (!is_array($cfg)) {
            return null;
        }
        $entry = $cfg[$kind] ?? $cfg[(string) $kind] ?? null;
        if (!is_array($entry)) {
            return null;
        }
        $label = $entry['name:' . $this->language] ?? $entry['name:en'] ?? $entry['name'] ?? null;
        return is_string($label) && $label !== '' ? $label : null;
    }

    private static function nonEmpty(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        return $value === '' ? null : $value;
    }
}
