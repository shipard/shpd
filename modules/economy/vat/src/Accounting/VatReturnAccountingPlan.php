<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Vat\Accounting;

/**
 * Výsledek builderu: řádky účetního dokladu přiznání a zprávy. Řádek nese
 * účet (id + číslo), stranu (`0` MD / `1` DAL — `docs.core.accSides`),
 * kladnou částku, popis a u saldo řádku identitu pro párování platby FÚ
 * (partner, VS/SS/KS, splatnost). Chyba ve zprávách = doklad se nezakládá.
 */
final readonly class VatReturnAccountingPlan
{
    public const SEVERITY_ERROR   = 'error';
    public const SEVERITY_WARNING = 'warning';
    public const SEVERITY_INFO    = 'info';

    /**
     * @param list<array{account: int, account_number: string, acc_side: int, amount: float,
     *        description: string, partner?: ?int, payment_reference?: string,
     *        specific_symbol?: string, constant_symbol?: string, due_date?: string}> $rows
     * @param list<array{code: string, severity: string, message: string}> $messages
     * @param array{liabilityDelta: float, nondeductible: float, rounding: float, debit: float, credit: float} $summary
     */
    public function __construct(
        public array $rows,
        public array $messages,
        public array $summary,
    ) {}

    /** Bez chyby — doklad lze založit (varování nevadí). */
    public function isOk(): bool
    {
        return $this->bySeverity(self::SEVERITY_ERROR) === [];
    }

    /** @return list<array{code: string, severity: string, message: string}> */
    public function errors(): array
    {
        return $this->bySeverity(self::SEVERITY_ERROR);
    }

    /** @return list<array{code: string, severity: string, message: string}> */
    public function warnings(): array
    {
        return $this->bySeverity(self::SEVERITY_WARNING);
    }

    /** @return list<array{code: string, severity: string, message: string}> */
    private function bySeverity(string $severity): array
    {
        return array_values(array_filter(
            $this->messages,
            static fn (array $m): bool => ($m['severity'] ?? '') === $severity,
        ));
    }
}
