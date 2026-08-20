<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\BookingHistory;

/**
 * Metriky kvality zdroje účetní historie (D33) — streamovaný akumulátor:
 * {@see add()} se volá pro každý záznam v jediném průchodu souborem,
 * getter se čte až potom.
 *
 * Kvalita nezná reverz ani taxonomii — jen fakta o souboru: kolik textů je
 * degenerovaných (a jakého druhu), kolik záznamů nemá IČO nebo účet a jak
 * se objem rozkládá po účtech. Reverzní pohled („objemné účty bez štítku")
 * si report složí z {@see accounts()} a {@see AccountTagMap}.
 */
final class BookingHistoryQuality
{
    private int $records = 0;
    private int $rows = 0;
    private int $docs = 0;
    private float $amount = 0.0;

    /** Záznamy bez totalAmount — objemové metriky jsou o ně slepé. */
    private int $recordsWithoutAmount = 0;

    private int $degenerateRecords = 0;
    private int $degenerateRows = 0;

    /** @var array<string, array{records: int, rows: int}> druh degenerace → počty */
    private array $degenerateByKind = [];

    private int $noCompanyIdRecords = 0;
    private int $noCompanyIdRows = 0;
    private int $noAccountRecords = 0;
    private int $noAccountRows = 0;

    /** @var array<string, array{records: int, rows: int, docs: int, amount: float, degenerateRows: int}> */
    private array $accounts = [];

    public function add(BookingHistoryRecord $record): void
    {
        $this->records++;
        $this->rows += $record->rowCount;
        $this->docs += $record->docCount;
        if ($record->totalAmount === null) {
            $this->recordsWithoutAmount++;
        } else {
            $this->amount += $record->totalAmount;
        }

        if ($record->companyId === null) {
            $this->noCompanyIdRecords++;
            $this->noCompanyIdRows += $record->rowCount;
        }

        $degeneracy = $record->degeneracy();
        if ($degeneracy !== null) {
            $this->degenerateRecords++;
            $this->degenerateRows += $record->rowCount;
            $kind = $this->degenerateByKind[$degeneracy] ?? ['records' => 0, 'rows' => 0];
            $kind['records']++;
            $kind['rows'] += $record->rowCount;
            $this->degenerateByKind[$degeneracy] = $kind;
        }

        if ($record->account === null) {
            $this->noAccountRecords++;
            $this->noAccountRows += $record->rowCount;
            return; // bez účtu není do čeho účtovat per-account statistiku
        }

        $stats = $this->accounts[$record->account] ?? [
            'records' => 0, 'rows' => 0, 'docs' => 0, 'amount' => 0.0, 'degenerateRows' => 0,
        ];
        $stats['records']++;
        $stats['rows'] += $record->rowCount;
        $stats['docs'] += $record->docCount;
        $stats['amount'] += $record->totalAmount ?? 0.0;
        if ($degeneracy !== null) {
            $stats['degenerateRows'] += $record->rowCount;
        }
        $this->accounts[$record->account] = $stats;
    }

    public function records(): int
    {
        return $this->records;
    }

    public function rows(): int
    {
        return $this->rows;
    }

    /**
     * Součet `docCount` napříč záznamy. **Horní odhad** počtu dokladů —
     * jeden doklad má víc řádků s různými agregačními klíči, takže se
     * počítá vícekrát. Report to tak i popisuje.
     */
    public function docs(): int
    {
        return $this->docs;
    }

    public function amount(): float
    {
        return $this->amount;
    }

    public function recordsWithoutAmount(): int
    {
        return $this->recordsWithoutAmount;
    }

    public function degenerateRecords(): int
    {
        return $this->degenerateRecords;
    }

    public function degenerateRows(): int
    {
        return $this->degenerateRows;
    }

    public function contentfulRows(): int
    {
        return $this->rows - $this->degenerateRows;
    }

    /** Podíl degenerovaných řádků (0–1); prázdný soubor = 0. */
    public function degenerateShare(): float
    {
        return $this->rows > 0 ? $this->degenerateRows / $this->rows : 0.0;
    }

    /** @return array<string, array{records: int, rows: int}> */
    public function degenerateByKind(): array
    {
        return $this->degenerateByKind;
    }

    /** @return array{records: int, rows: int} */
    public function missingCompanyId(): array
    {
        return ['records' => $this->noCompanyIdRecords, 'rows' => $this->noCompanyIdRows];
    }

    /** @return array{records: int, rows: int} */
    public function missingAccount(): array
    {
        return ['records' => $this->noAccountRecords, 'rows' => $this->noAccountRows];
    }

    /** @return array<string, array{records: int, rows: int, docs: int, amount: float, degenerateRows: int}> */
    public function accounts(): array
    {
        return $this->accounts;
    }

    /**
     * Účty seřazené podle objemu (fallback řádky, když zdroj částky neumí).
     *
     * @return list<array{account: string, records: int, rows: int, docs: int, amount: float, degenerateRows: int}>
     */
    public function topAccounts(int $limit = 20): array
    {
        $rows = [];
        foreach ($this->accounts as $account => $stats) {
            $rows[] = ['account' => (string) $account] + $stats;
        }
        usort($rows, static function (array $a, array $b): int {
            return [$b['amount'], $b['rows']] <=> [$a['amount'], $a['rows']];
        });
        return $limit > 0 ? array_slice($rows, 0, $limit) : $rows;
    }
}
