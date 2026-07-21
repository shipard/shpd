<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Bank;

use Dibi\Connection;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Document\DocumentEventDispatcher;
use Shipard\Core\Document\DocumentRegistry;
use Shipard\Module\Core\Exchange\Common\ApplyResult;
use Shipard\Module\Core\Exchange\Schema\SchemaLoader;
use Shipard\Module\Core\Exchange\Schema\SchemaValidator;
use Shipard\Module\Economy\Bank\Import\ImportException;
use Shipard\Module\Economy\Bank\Import\ParsedStatement;
use Shipard\Module\Economy\Bank\Import\ParsedTransaction;
use Shipard\Module\Economy\Bank\Import\StatementImportService;

/**
 * Applier kanonického formátu `shpd.bank.statement.v1` — migrace bankovních
 * výpisů ze starého Shipardu (docs/bank.md §7). Žádná vlastní create logika:
 * payload se přemapuje na ParsedStatement/ParsedTransaction[] a deleguje na
 * sdílené apply jádro StatementImportService::applyParsedStatement (totéž,
 * které pohání souborový import). „Hotové" výpisy (`targetState = 40`) vznikají
 * rovnou ve stavu 40 → BankTransactionEventHandler je zaúčtuje na clearing.
 *
 *   /validate — schéma + sémantika (pořadí periody), bez zápisu.
 *   /preview  — validate + existence účtu + počty would-create/skip, bez zápisu.
 *   /apply    — validate → ParsedStatement → applyParsedStatement.
 *
 * Vzor: DocumentApplier. Mapuje 1:1 na ApplyResult / REST envelope.
 */
class BankStatementApplier
{
    public const FORMAT_ID = 'shpd.bank.statement';
    public const FORMAT_VERSION = '1';

    /**
     * Stavy, ve kterých je účet odkazovatelný (vyloučen jen smazaný 90) —
     * konzistentní s apply (`StatementImportService::LINKABLE_STATES`):
     * výpis pro archivní účet je legitimní historické datum.
     */
    private const LINKABLE_STATES = [10, 40, 70, 80];

    public function __construct(
        private readonly Connection $db,
        private readonly SchemaValidator $schemaValidator,
        private readonly StatementImportService $importService,
    ) {}

    /**
     * Produkční factory — SchemaValidator + StatementImportService (s event
     * dispatcherem, aby vznik ve stavu 40 zaúčtoval).
     *
     * @param array<string, TableDefinition> $tables
     */
    public static function create(
        Connection $db,
        ConfigRuntime $config,
        DataSourceConfig $dsConfig,
        DocumentRegistry $registry,
        array $tables,
        ?DocumentEventDispatcher $eventDispatcher = null,
    ): self {
        return new self(
            $db,
            new SchemaValidator(SchemaLoader::default()),
            StatementImportService::create($db, $config, $dsConfig, $registry, $tables, $eventDispatcher, null),
        );
    }

    // ── Public entry points ─────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $canonical
     */
    public function validate(array $canonical): ApplyResult
    {
        $schemaIssues = $this->schemaValidator->validate($canonical, self::FORMAT_ID, self::FORMAT_VERSION);
        if ($schemaIssues !== []) {
            return ApplyResult::error(
                'schema_invalid',
                'Struktura výpisu neodpovídá schématu.',
                $this->withIssues($canonical, $schemaIssues),
                400,
            );
        }

        $issues = $this->semanticIssues($canonical);
        $enriched = $this->withIssues($canonical, $issues);
        if ($issues !== []) {
            return ApplyResult::error('validation_failed', 'Validace výpisu selhala.', $enriched, 422);
        }
        return ApplyResult::ok($enriched);
    }

    /**
     * @param array<string, mixed> $canonical
     */
    public function preview(array $canonical): ApplyResult
    {
        $schemaIssues = $this->schemaValidator->validate($canonical, self::FORMAT_ID, self::FORMAT_VERSION);
        if ($schemaIssues !== []) {
            return ApplyResult::error(
                'schema_invalid',
                'Struktura výpisu neodpovídá schématu.',
                $this->withIssues($canonical, $schemaIssues),
                400,
            );
        }

        $issues = $this->semanticIssues($canonical);
        $bankAccountId = (int) ($canonical['bankAccountId'] ?? 0);
        $accountExists = $this->accountLinkable($bankAccountId);
        if (!$accountExists) {
            $issues[] = [
                'severity' => 'error',
                'path'     => 'bankAccountId',
                'code'     => 'bank_account_not_found',
                'message'  => "Bankovní účet #{$bankAccountId} neexistuje nebo je smazán.",
            ];
        }

        [$toCreate, $toSkip] = $this->previewCounts(
            $bankAccountId,
            is_array($canonical['transactions'] ?? null) ? $canonical['transactions'] : [],
        );

        $enriched = $this->withIssues($canonical, $issues);
        $enriched['_preview'] = [
            'bankAccountExists' => $accountExists,
            'toCreate'          => $toCreate,
            'toSkip'            => $toSkip,
        ];
        // preview vždy úspěšný — klient si payload vykreslí a rozhodne.
        return ApplyResult::ok($enriched);
    }

    /**
     * @param array<string, mixed> $canonical
     */
    public function apply(array $canonical): ApplyResult
    {
        $schemaIssues = $this->schemaValidator->validate($canonical, self::FORMAT_ID, self::FORMAT_VERSION);
        if ($schemaIssues !== []) {
            return ApplyResult::error(
                'schema_invalid',
                'Struktura výpisu neodpovídá schématu.',
                $this->withIssues($canonical, $schemaIssues),
                400,
            );
        }

        $issues = $this->semanticIssues($canonical);
        if ($issues !== []) {
            return ApplyResult::error('validation_failed', 'Validace výpisu selhala.', $this->withIssues($canonical, $issues), 422);
        }

        $options = is_array($canonical['applyOptions'] ?? null) ? $canonical['applyOptions'] : [];
        $targetState = (int) ($options['targetState'] ?? 10);
        $createMissingPartner = (bool) ($options['createMissingPartner'] ?? false);

        try {
            $stmt = $this->toParsedStatement($canonical);
            $summary = $this->importService->applyParsedStatement(
                $stmt,
                (int) ($canonical['bankAccountId'] ?? 0),
                $targetState,
                $createMissingPartner,
            );
        } catch (ImportException $e) {
            return ApplyResult::error('apply_failed', $e->getMessage(), $canonical, 422);
        } catch (\Throwable $e) {
            return ApplyResult::error('internal_error', $e->getMessage(), $canonical, 500);
        }

        $statementId = (int) ($summary['statementId'] ?? 0);
        $enriched = $canonical;
        $enriched['_result'] = [
            'savedStatementId' => $statementId,
            'created'          => $summary['created'] ?? 0,
            'skipped'          => $summary['skipped'] ?? 0,
            'unmatchedPartner' => $summary['unmatchedPartner'] ?? 0,
            'reconciliation'   => $summary['reconciliation'] ?? 0,
            'currencyWarning'  => $summary['currencyWarning'] ?? null,
            'txErrors'         => $summary['txErrors'] ?? [],
        ];
        return ApplyResult::ok($enriched, $statementId);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Sémantické kontroly nad rámec schématu: pořadí periody a parsovatelnost
     * dat (schéma `format: date` je v opis advisory).
     *
     * @param array<string, mixed> $canonical
     * @return list<array{severity: string, path: string, code: string, message: string}>
     */
    private function semanticIssues(array $canonical): array
    {
        $issues = [];
        $stmt = is_array($canonical['statement'] ?? null) ? $canonical['statement'] : [];
        $start = $this->parseDate((string) ($stmt['periodStart'] ?? ''));
        $end = $this->parseDate((string) ($stmt['periodEnd'] ?? ''));

        if ($start === null) {
            $issues[] = $this->issue('statement.periodStart', 'invalid_date', 'Začátek období není platné datum.');
        }
        if ($end === null) {
            $issues[] = $this->issue('statement.periodEnd', 'invalid_date', 'Konec období není platné datum.');
        }
        if ($start !== null && $end !== null && $end < $start) {
            $issues[] = $this->issue('statement.periodEnd', 'period_order', 'Konec období je před začátkem.');
        }
        return $issues;
    }

    /**
     * @param array<string, mixed> $canonical
     */
    private function toParsedStatement(array $canonical): ParsedStatement
    {
        $s = is_array($canonical['statement'] ?? null) ? $canonical['statement'] : [];
        $txs = [];
        foreach ((is_array($canonical['transactions'] ?? null) ? $canonical['transactions'] : []) as $t) {
            if (!is_array($t)) {
                continue;
            }
            $txs[] = new ParsedTransaction(
                externalId: isset($t['externalId']) ? (string) $t['externalId'] : null,
                amount: (float) ($t['amount'] ?? 0),
                dateTransaction: new \DateTimeImmutable((string) $t['dateTransaction']),
                dateValue: !empty($t['dateValue']) ? new \DateTimeImmutable((string) $t['dateValue']) : null,
                counterpartyAccount: isset($t['counterpartyAccount']) ? (string) $t['counterpartyAccount'] : null,
                counterpartyName: isset($t['counterpartyName']) ? (string) $t['counterpartyName'] : null,
                paymentReference: isset($t['paymentReference']) ? (string) $t['paymentReference'] : null,
                specificSymbol: isset($t['specificSymbol']) ? (string) $t['specificSymbol'] : null,
                constantSymbol: isset($t['constantSymbol']) ? (string) $t['constantSymbol'] : null,
                message: isset($t['message']) ? (string) $t['message'] : null,
                raw: [],
                operation: isset($t['operation']) ? (string) $t['operation'] : null,
                exchangeRate: isset($t['exchangeRate']) ? (float) $t['exchangeRate'] : null,
                partnerId: isset($t['partnerId']) ? (int) $t['partnerId'] : null,
            );
        }

        return new ParsedStatement(
            bankAccountRef: (string) ($canonical['bankAccountId'] ?? ''),
            statementNumber: isset($s['statementNumber']) ? (string) $s['statementNumber'] : null,
            externalId: isset($s['externalId']) ? (string) $s['externalId'] : null,
            periodStart: new \DateTimeImmutable((string) $s['periodStart']),
            periodEnd: new \DateTimeImmutable((string) $s['periodEnd']),
            openingBalance: (float) ($s['openingBalance'] ?? 0),
            closingBalance: (float) ($s['closingBalance'] ?? 0),
            currency: isset($s['currency']) ? (string) $s['currency'] : null,
            transactions: $txs,
        );
    }

    private function accountLinkable(int $id): bool
    {
        if ($id <= 0) {
            return false;
        }
        $row = $this->db->query(
            'SELECT [id] FROM [economy_codebooks_bank_accounts] WHERE [id] = %i AND [docState] IN %in',
            $id,
            self::LINKABLE_STATES,
        )->fetch();
        return $row !== false && $row !== null;
    }

    /**
     * Odhad počtů pro preview (bez zápisu). Dedup migrace stojí na
     * `external_id` (runner ho odvodí z old ndx); transakce bez něj se
     * počítají jako nové (fingerprint závisí na pořadí v rámci dne a tady
     * se nepočítá).
     *
     * @param array<int, mixed> $transactions
     * @return array{0: int, 1: int} [toCreate, toSkip]
     */
    private function previewCounts(int $bankAccountId, array $transactions): array
    {
        $toCreate = 0;
        $toSkip = 0;
        foreach ($transactions as $t) {
            $externalId = is_array($t) && isset($t['externalId']) ? (string) $t['externalId'] : '';
            if ($externalId !== '' && $bankAccountId > 0 && $this->externalIdExists($bankAccountId, $externalId)) {
                $toSkip++;
            } else {
                $toCreate++;
            }
        }
        return [$toCreate, $toSkip];
    }

    private function externalIdExists(int $bankAccountId, string $externalId): bool
    {
        $row = $this->db->query(
            'SELECT [id] FROM [economy_bank_transactions] WHERE [bank_account] = %i AND [external_id] = %s LIMIT 1',
            $bankAccountId,
            $externalId,
        )->fetch();
        return $row !== false && $row !== null;
    }

    private function parseDate(string $value): ?\DateTimeImmutable
    {
        if ($value === '') {
            return null;
        }
        try {
            return new \DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array{severity: string, path: string, code: string, message: string}
     */
    private function issue(string $path, string $code, string $message): array
    {
        return ['severity' => 'error', 'path' => $path, 'code' => $code, 'message' => $message];
    }

    /**
     * @param array<string, mixed> $canonical
     * @param list<array{severity: string, path: string, code: string, message: string}> $issues
     * @return array<string, mixed>
     */
    private function withIssues(array $canonical, array $issues): array
    {
        $resolve = is_array($canonical['_resolve'] ?? null) ? $canonical['_resolve'] : [];
        $resolve['issues'] = array_merge(
            is_array($resolve['issues'] ?? null) ? $resolve['issues'] : [],
            $issues,
        );
        $canonical['_resolve'] = $resolve;
        return $canonical;
    }
}
