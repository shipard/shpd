<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Person;

use Shipard\Module\Core\Exchange\Resolve\ResolveResult;

/**
 * Aggregate output of {@see PersonResolver::resolve()}. Mirrors the
 * `_resolve` shape from docs/exchange-format-persons.md §10:
 *
 *   - `header`           — header ResolveResult (Matched / CanCreate / Ambiguous).
 *   - `addresses[]`      — per-index ResolveResult for each address in payload.
 *   - `bankAccounts[]`   — per-index ResolveResult for each bank account.
 *   - `contacts[]`       — per-index ResolveResult for each contact.
 *   - `closingExisting`  — sub-records present in DB but absent from payload
 *                          (populated only for `fullSync`; structure mirrors
 *                          sub-collection keys: addresses, bankAccounts,
 *                          contacts).
 *   - `issues[]`         — warnings (e.g. unresolved divisionCode) discovered
 *                          during sub-resolve.
 */
final class PersonResolveResult
{
    /**
     * @param array<int, ResolveResult> $addresses
     * @param array<int, ResolveResult> $bankAccounts
     * @param array<int, ResolveResult> $contacts
     * @param array{addresses: array<int, array<string, mixed>>, bankAccounts: array<int, array<string, mixed>>, contacts: array<int, array<string, mixed>>} $closingExisting
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     */
    public function __construct(
        public readonly ResolveResult $header,
        public readonly array $addresses,
        public readonly array $bankAccounts,
        public readonly array $contacts,
        public readonly array $closingExisting,
        public readonly array $issues,
    ) {}

    /**
     * Serialize to the `_resolve.*` shape. The caller assembles
     * `summary` and merges any external (schema / validator) issues
     * into `issues` before returning to the client.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $addresses = [];
        foreach ($this->addresses as $i => $r) {
            $addresses[] = ['index' => $i] + $r->toArray();
        }
        $banks = [];
        foreach ($this->bankAccounts as $i => $r) {
            $banks[] = ['index' => $i] + $r->toArray();
        }
        $contacts = [];
        foreach ($this->contacts as $i => $r) {
            $contacts[] = ['index' => $i] + $r->toArray();
        }
        return [
            'header'          => $this->header->toArray(),
            'addresses'       => $addresses,
            'bankAccounts'    => $banks,
            'contacts'        => $contacts,
            'closingExisting' => $this->closingExisting,
            'issues'          => $this->issues,
        ];
    }
}
