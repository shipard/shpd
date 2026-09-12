<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Vat\Accounting;

/**
 * Výsledek služby zaúčtování přiznání — plán (dry-run), založený doklad,
 * nebo odmítnutí s kódem. Kódy chyb jsou stabilní pro CLI i REST:
 *
 * - `NOT_FOUND`, `INVALID_REPORT_TYPE`, `INVALID_DOC_STATE` — vstup,
 * - `ALREADY_ACCOUNTED` — živý účetní doklad existuje (`context.existingDocId`),
 * - `CONFIG_MISSING` — chybí kompilovaná konfigurace nebo definice tabulek,
 * - `SERIES_MISSING` — řadu cmnbkp nejde jednoznačně určit,
 * - `PLAN_FAILED` — builder hlásí chybu (chybějící účet, rozjeté zaokrouhlení),
 * - `NOTHING_TO_ACCOUNT` — plán bez řádků, doklad se nezakládá,
 * - `SAVE_FAILED` — TableGateway odmítl zápis (validace, zámek období).
 */
final readonly class VatReturnAccountingResult
{
    /**
     * @param array<string, mixed> $context podání, instance, řada, existující doklad…
     * @param list<array{field: string, code: string, message: string}> $errors detaily validace gateway
     */
    public function __construct(
        public bool $ok,
        public ?string $code,
        public string $message,
        public ?int $docId,
        public ?VatReturnAccountingPlan $plan,
        public array $context = [],
        public array $errors = [],
    ) {}

    /**
     * @param array<string, mixed> $context
     * @param list<array{field: string, code: string, message: string}> $errors
     */
    public static function failed(
        string $code,
        string $message,
        array $context = [],
        ?VatReturnAccountingPlan $plan = null,
        array $errors = [],
    ): self {
        return new self(false, $code, $message, null, $plan, $context, $errors);
    }

    /** @param array<string, mixed> $context */
    public static function planned(VatReturnAccountingPlan $plan, array $context): self
    {
        $ok = $plan->isOk();
        return new self(
            $ok,
            $ok ? null : 'PLAN_FAILED',
            $ok ? 'Plán účetního dokladu sestaven.' : 'Účetní doklad nejde sestavit — viz chyby plánu.',
            null,
            $plan,
            $context,
        );
    }

    /** @param array<string, mixed> $context */
    public static function accounted(int $docId, VatReturnAccountingPlan $plan, array $context): self
    {
        return new self(true, null, "Účetní doklad #{$docId} založen jako koncept.", $docId, $plan, $context);
    }

    /** Zprávy builderu + detaily validace v jednom seznamu textů (CLI, toast). */
    public function messageTexts(): array
    {
        $out = [];
        foreach ($this->plan?->messages ?? [] as $m) {
            $out[] = strtoupper((string) $m['severity']) . ': ' . $m['message'];
        }
        foreach ($this->errors as $e) {
            $out[] = 'ERROR: ' . ($e['field'] !== '' ? $e['field'] . ': ' : '') . $e['message'];
        }
        return $out;
    }
}
