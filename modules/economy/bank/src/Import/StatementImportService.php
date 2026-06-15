<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Bank\Import;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Module\Core\Attachments\AttachmentService;
use Shipard\Module\Economy\Bank\BankStatementDocument;
use Shipard\Module\Economy\Bank\BankTransactionDocument;

/**
 * Orchestrace importu bankovního výpisu: detekce + parse → match našeho účtu →
 * najít/založit výpis → per transakce dedup (external_id / fingerprint) +
 * vznik ve stavu Nová (10) → dohledání partnera → zůstatkový můstek →
 * uložení zdrojového souboru jako příloha.
 *
 * Idempotentní: opětovný import téhož souboru → vše skipped. Per-výpis
 * transakce (jeden vadný výpis neshodí ostatní). Žádné účtování (Fáze 3).
 */
final class StatementImportService
{
    private const TABLE_TX = 'economy_bank_transactions';
    private const TABLE_STMT = 'economy_bank_statements';
    private const STATEMENT_TABLE_ID = 415;
    private const ACTIVE_STATES = [10, 40, 80];

    private readonly PartnerResolver $partnerResolver;

    public function __construct(
        private readonly \Dibi\Connection $db,
        private readonly ConfigRuntime $config,
        private readonly ?AttachmentService $attachments = null,
    ) {
        $this->partnerResolver = new PartnerResolver($db);
    }

    /**
     * @return array<string, mixed> souhrn importu
     * @throws ImportException nerozpoznaný formát / charset
     */
    public function import(
        string $rawContent,
        ?string $accountOverride = null,
        ?string $sourceFilePath = null,
        ?string $sourceFileName = null,
        ?int $userId = null,
    ): array {
        $detector = new StatementFormatDetector($this->config->cfgItem('economy.bank.statementFormats') ?? []);
        $detected = $detector->detect($rawContent);
        $text = $detector->decode($rawContent, $detected['srcCharset']);
        $parser = (new StatementParserRegistry())->parserFor($detected['formatId']);
        $statements = $parser->parse($text);

        $summary = [
            'format'           => $detected['formatId'],
            'statements'       => [],
            'created'          => 0,
            'skipped'          => 0,
            'unmatchedPartner' => 0,
        ];

        foreach ($statements as $parsed) {
            $this->db->begin();
            try {
                $result = $this->importStatement($parsed, $accountOverride);
                $this->db->commit();
            } catch (ImportException $e) {
                $this->db->rollback();
                $summary['statements'][] = [
                    'bankAccountRef' => $parsed->bankAccountRef,
                    'error'          => $e->getMessage(),
                ];
                continue;
            }

            // Příloha (provenience) — po commitu, nekritické.
            if ($result['statementId'] > 0 && $this->attachments !== null && $sourceFilePath !== null) {
                try {
                    $this->attachments->upload(
                        self::STATEMENT_TABLE_ID,
                        $result['statementId'],
                        $sourceFileName ?? basename($sourceFilePath),
                        $sourceFilePath,
                        $userId,
                    );
                    $result['attached'] = true;
                } catch (\Throwable $e) {
                    $result['attached'] = false;
                    $result['attachError'] = $e->getMessage();
                }
            }

            $summary['statements'][] = $result;
            $summary['created'] += $result['created'];
            $summary['skipped'] += $result['skipped'];
            $summary['unmatchedPartner'] += $result['unmatchedPartner'];
        }

        return $summary;
    }

    /** @return array<string, mixed> */
    private function importStatement(ParsedStatement $parsed, ?string $accountOverride): array
    {
        $account = $this->matchBankAccount($parsed->bankAccountRef, $accountOverride);
        $accountId = (int) $account['id'];
        $accountCurrency = strtolower((string) ($account['currency'] ?? ''));

        $currency = $accountCurrency;
        $currencyWarning = null;
        $parsedCcy = $parsed->currency !== null ? strtolower($parsed->currency) : null;
        if ($parsedCcy !== null && $accountCurrency !== '' && $parsedCcy !== $accountCurrency) {
            $currencyWarning = "Měna výpisu ({$parsedCcy}) se liší od měny účtu ({$accountCurrency}); použita měna účtu.";
        } elseif ($parsedCcy !== null && $accountCurrency === '') {
            $currency = $parsedCcy;
        }

        $statementId = $this->findOrCreateStatement($parsed, $accountId, $currency);

        $created = 0;
        $skipped = 0;
        $unmatchedPartner = 0;
        $txErrors = [];
        $seqInDay = [];

        foreach ($parsed->transactions as $tx) {
            $direction = $tx->amount < 0 ? 2 : 1;
            $amount = abs($tx->amount);
            $dateKey = $tx->dateTransaction->format('Y-m-d');
            $seqKey = $dateKey;
            $seqInDay[$seqKey] = ($seqInDay[$seqKey] ?? -1) + 1;

            $fingerprint = $this->fingerprint($accountId, $tx, $direction, $amount, $dateKey, $seqInDay[$seqKey]);

            $existingId = $this->findExisting($accountId, $tx->externalId, $fingerprint);
            if ($existingId !== null) {
                // doplnit chybějící statement / external_id
                $this->backfill($existingId, $statementId, $tx->externalId, $fingerprint);
                $skipped++;
                continue;
            }

            $row = [
                'bank_account'        => $accountId,
                'statement'           => $statementId,
                'direction'           => $direction,
                'amount'              => round($amount, 2),
                'currency'            => $currency,
                'exchange_rate'       => 1,
                'amount_dom'          => round($amount, 2),
                'date_transaction'    => $dateKey,
                'date_value'          => $tx->dateValue?->format('Y-m-d'),
                'counterparty_account' => $tx->counterpartyAccount,
                'counterparty_name'   => $this->cap($tx->counterpartyName, 150),
                'symbol1'             => $this->cap($tx->symbol1, 10),
                'symbol2'             => $this->cap($tx->symbol2, 10),
                'symbol3'             => $this->cap($tx->symbol3, 10),
                'message'             => $this->cap($tx->message, 250),
                'operation'           => $direction === 1 ? 'payment.in' : 'payment.out',
                'external_id'         => $tx->externalId,
                'fingerprint'         => $fingerprint,
                'partner'             => $this->partnerResolver->resolve($tx->counterpartyAccount),
                'accounting_state'    => 0,
                'docState'            => 10,
                'docStateMain'        => 1,
            ];

            $doc = new BankTransactionDocument();
            $doc->setDb($this->db);
            $validation = $doc->validate($row);
            if (!$validation->isValid()) {
                $txErrors[] = ['date' => $dateKey, 'amount' => $amount, 'errors' => $validation->toArray()];
                continue;
            }
            $doc->beforeSave($row);
            $this->db->insert(self::TABLE_TX, $row)->execute();

            if ($row['partner'] === null && $tx->counterpartyAccount !== null) {
                $unmatchedPartner++;
            }
            $created++;
        }

        $reconciliation = $this->reconcile($statementId, $parsed->openingBalance, $parsed->closingBalance);

        return [
            'bankAccountRef'   => $parsed->bankAccountRef,
            'statementId'      => $statementId,
            'created'          => $created,
            'skipped'          => $skipped,
            'unmatchedPartner' => $unmatchedPartner,
            'reconciliation'   => $reconciliation,
            'currencyWarning'  => $currencyWarning,
            'txErrors'         => $txErrors,
        ];
    }

    /**
     * Match našeho účtu (port konceptu `checkBankAccount`) přes account_number
     * / iban / ebanking_id (normalizované). Override z CLI/endpointu má přednost.
     *
     * @return array<string, mixed>
     * @throws ImportException nenalezen
     */
    private function matchBankAccount(string $ref, ?string $override): array
    {
        if ($override !== null && trim($override) !== '') {
            $row = ctype_digit($override)
                ? $this->db->query('SELECT [id], [currency], [code] FROM %n WHERE [id] = %i', self::tableBankAccounts(), (int) $override)->fetch()
                : $this->db->query('SELECT [id], [currency], [code] FROM %n WHERE [code] = %s', self::tableBankAccounts(), $override)->fetch();
            if ($row === false || $row === null) {
                throw new ImportException("Bankovní spojení '{$override}' (--account) nenalezeno.");
            }
            return (array) $row;
        }

        $refVariants = $this->accountVariants($ref);
        $accounts = $this->db->query(
            'SELECT [id], [currency], [code], [account_number], [iban], [ebanking_id]'
            . ' FROM %n WHERE [docState] IN %in',
            self::tableBankAccounts(),
            self::ACTIVE_STATES,
        )->fetchAll();

        foreach ($accounts as $a) {
            $cand = array_merge(
                $this->accountVariants((string) ($a['account_number'] ?? '')),
                $this->accountVariants((string) ($a['iban'] ?? '')),
                $this->accountVariants((string) ($a['ebanking_id'] ?? '')),
            );
            if (array_intersect($refVariants, $cand) !== []) {
                return (array) $a;
            }
        }

        throw new ImportException(
            "Účet z výpisu '{$ref}' není v žádném vlastním bankovním spojení "
            . '(Číslo účtu / IBAN / ID v ebankingu). Výpis přeskočen.',
        );
    }

    private function findOrCreateStatement(ParsedStatement $parsed, int $accountId, string $currency): int
    {
        $start = $parsed->periodStart->format('Y-m-d');
        $end = $parsed->periodEnd->format('Y-m-d');

        // Vodící klíč: (bank_account, period_start, period_end).
        $row = $this->db->query(
            'SELECT [id] FROM %n WHERE [bank_account] = %i AND [period_start] = %s AND [period_end] = %s LIMIT 1',
            self::TABLE_STMT,
            $accountId,
            $start,
            $end,
        )->fetch();
        if ($row !== false && $row !== null) {
            return (int) $row['id'];
        }

        $stmtRow = [
            'bank_account'         => $accountId,
            'statement_number'     => $parsed->statementNumber,
            'period_start'         => $start,
            'period_end'           => $end,
            'opening_balance'      => round($parsed->openingBalance, 2),
            'closing_balance'      => round($parsed->closingBalance, 2),
            'currency'             => $currency,
            'reconciliation_state' => 0,
            'docState'             => 10,
            'docStateMain'         => 1,
        ];

        $doc = new BankStatementDocument();
        $doc->setDb($this->db);
        $validation = $doc->validate($stmtRow);
        if (!$validation->isValid()) {
            $messages = array_map(static fn($e) => $e['message'], $validation->toArray());
            throw new ImportException('Neplatný výpis: ' . implode('; ', $messages));
        }
        $doc->beforeSave($stmtRow);
        $this->db->insert(self::TABLE_STMT, $stmtRow)->execute();

        return (int) $this->db->getInsertId();
    }

    private function findExisting(int $accountId, ?string $externalId, string $fingerprint): ?int
    {
        if ($externalId !== null) {
            $row = $this->db->query(
                'SELECT [id] FROM %n WHERE [bank_account] = %i AND [external_id] = %s LIMIT 1',
                self::TABLE_TX,
                $accountId,
                $externalId,
            )->fetch();
            return ($row !== false && $row !== null) ? (int) $row['id'] : null;
        }

        $row = $this->db->query(
            'SELECT [id] FROM %n WHERE [bank_account] = %i AND [fingerprint] = %s LIMIT 1',
            self::TABLE_TX,
            $accountId,
            $fingerprint,
        )->fetch();
        return ($row !== false && $row !== null) ? (int) $row['id'] : null;
    }

    private function backfill(int $txId, int $statementId, ?string $externalId, string $fingerprint): void
    {
        $set = [];
        $existing = $this->db->query('SELECT [statement], [external_id], [fingerprint] FROM %n WHERE [id] = %i', self::TABLE_TX, $txId)->fetch();
        if ($existing === false || $existing === null) {
            return;
        }
        if (($existing['statement'] ?? null) === null) {
            $set['statement'] = $statementId;
        }
        if (($existing['external_id'] ?? null) === null && $externalId !== null) {
            $set['external_id'] = $externalId;
        }
        if (($existing['fingerprint'] ?? null) === null) {
            $set['fingerprint'] = $fingerprint;
        }
        if ($set !== []) {
            $this->db->update(self::TABLE_TX, $set)->where('[id] = %i', $txId)->execute();
        }
    }

    /** sha256 z normalizovaných polí + seqInDay (W4.2). */
    private function fingerprint(int $accountId, ParsedTransaction $tx, int $direction, float $amount, string $dateKey, int $seqInDay): string
    {
        $parts = [
            $accountId,
            $dateKey,
            $direction,
            number_format($amount, 2, '.', ''),
            $tx->counterpartyAccount ?? '',
            $tx->symbol1 ?? '',
            $tx->symbol2 ?? '',
            $tx->message ?? '',
            $seqInDay,
        ];
        return hash('sha256', implode('|', $parts));
    }

    /** Zůstatkový můstek (W5.1). @return int reconciliation_state (0/1/2) */
    private function reconcile(int $statementId, float $opening, float $closing): int
    {
        $rows = $this->db->query(
            'SELECT [direction], [amount] FROM %n WHERE [statement] = %i AND [docState] != 90',
            self::TABLE_TX,
            $statementId,
        )->fetchAll();

        $delta = 0.0;
        foreach ($rows as $r) {
            $amt = (float) $r['amount'];
            $delta += ((int) $r['direction'] === 2) ? -$amt : $amt;
        }

        $state = abs(($opening + $delta) - $closing) <= 0.005 ? 1 : 2;
        $this->db->update(self::TABLE_STMT, ['reconciliation_state' => $state])
            ->where('[id] = %i', $statementId)
            ->execute();

        return $state;
    }

    private function accountVariants(string $s): array
    {
        $s = trim($s);
        if ($s === '') {
            return [];
        }
        $out = [$this->normalizeAccount($s)];
        if (str_contains($s, '/')) {
            $out[] = $this->normalizeAccount(explode('/', $s, 2)[0]);
        }
        return array_values(array_unique(array_filter($out, static fn($v) => $v !== '')));
    }

    private function normalizeAccount(string $s): string
    {
        return strtoupper((string) preg_replace('/[\s\-\/]/', '', $s));
    }

    private function cap(?string $v, int $len): ?string
    {
        if ($v === null) {
            return null;
        }
        return mb_substr($v, 0, $len, 'UTF-8');
    }

    private static function tableBankAccounts(): string
    {
        return 'economy_codebooks_bank_accounts';
    }
}
