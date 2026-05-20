<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Person;

use Dibi\Connection;
use Shipard\Module\Base\Persons\PersonType;
use Shipard\Module\Core\Exchange\Resolve\AddressResolver;
use Shipard\Module\Core\Exchange\Resolve\BankAccountResolver;
use Shipard\Module\Core\Exchange\Resolve\ContactResolver;
use Shipard\Module\Core\Exchange\Resolve\PartyResolver;
use Shipard\Module\Core\Exchange\Resolve\ResolveResult;
use Shipard\Module\Core\Exchange\Resolve\ResolveStatus;

/**
 * Orchestrates header + sub-collection resolution for a canonical
 * Person payload. Delegates per-record matching to the dedicated
 * resolvers and assembles a {@see PersonResolveResult}.
 *
 * For `mergeStrategy = fullSync` and a header match, also enumerates
 * existing DB sub-records that are *not* in the payload — those go
 * into `closingExisting` and the applier marks them with
 * `valid_to = today`. Addresses are closed per `address_type` (payload
 * with only Sídla closes existing Sídla and leaves doručovací
 * addresses alone — see spec §9).
 */
class PersonResolver
{
    private const ACTIVE_STATES = [10, 40, 80];

    public function __construct(
        private readonly Connection $db,
        private readonly PartyResolver $partyResolver,
        private readonly AddressResolver $addressResolver,
        private readonly BankAccountResolver $bankAccountResolver,
        private readonly ContactResolver $contactResolver,
    ) {}

    /**
     * @param array<string, mixed> $canonical
     */
    public function resolve(array $canonical, MergeStrategy $strategy): PersonResolveResult
    {
        $personType = $this->mapPersonType($canonical['personType'] ?? null);

        // Project canonical → PartyResolver shape. PartyResolver reads
        // string fields (companyId/vatId/taxId/name) and ignores the rest.
        // For natural persons, compose `name` from firstName + lastName
        // when fullName is missing — PersonDocument::beforeSave assembles
        // the same string for the DB row.
        $partyShape = [
            'companyId' => $canonical['companyId'] ?? null,
            'vatId'     => $canonical['vatId'] ?? null,
            'taxId'     => $canonical['taxId'] ?? null,
            'name'      => $this->resolvePartyName($canonical),
        ];
        $header = $this->partyResolver->resolve($partyShape, $personType);

        $personId = $header->status === ResolveStatus::Matched ? $header->matchedId : null;

        $issues = [];

        $addressResults = [];
        foreach (($canonical['addresses'] ?? []) as $i => $addr) {
            if (!is_array($addr)) continue;
            $result = $this->addressResolver->resolve($addr, $personId);
            $addressResults[$i] = $result;

            // Emit a warning when the payload supplied a divisionCode that
            // failed to map to world_divisions. AddressResolver still
            // returns a payload with `division = null`; applier saves
            // without the FK.
            $divisionCode = $addr['divisionCode'] ?? null;
            if (is_string($divisionCode) && trim($divisionCode) !== '') {
                $resolvedDivision = $result->createPayload['division'] ?? null;
                if ($result->status === ResolveStatus::CanCreate && $resolvedDivision === null) {
                    $issues[] = [
                        'severity' => 'warning',
                        'path'     => "addresses.{$i}.divisionCode",
                        'code'     => 'division_unknown',
                        'message'  => "Kód administrativní jednotky „{$divisionCode}\" nebyl rozpoznán; adresa bude uložena bez vazby.",
                    ];
                }
            }
        }

        $bankResults = [];
        foreach (($canonical['bankAccounts'] ?? []) as $i => $bank) {
            if (!is_array($bank)) continue;
            $bankResults[$i] = $this->bankAccountResolver->resolvePartnerBank($bank, $personId);
        }

        $contactResults = [];
        foreach (($canonical['contacts'] ?? []) as $i => $contact) {
            if (!is_array($contact)) continue;
            $contactResults[$i] = $this->contactResolver->resolve($contact, $personId);
        }

        $closingExisting = [
            'addresses'    => [],
            'bankAccounts' => [],
            'contacts'     => [],
        ];

        if ($strategy === MergeStrategy::FullSync && $personId !== null) {
            $closingExisting = $this->enumerateClosing(
                $personId,
                $canonical,
                $addressResults,
                $bankResults,
                $contactResults,
            );
        }

        return new PersonResolveResult(
            header: $header,
            addresses: $addressResults,
            bankAccounts: $bankResults,
            contacts: $contactResults,
            closingExisting: $closingExisting,
            issues: $issues,
        );
    }

    private function mapPersonType(mixed $value): ?PersonType
    {
        return match ($value) {
            'company' => PersonType::Company,
            'person'  => PersonType::Person,
            default   => null,
        };
    }

    /**
     * Effective `name` for PartyResolver: prefer `fullName`; otherwise
     * assemble `firstName + lastName` for natural persons.
     *
     * @param array<string, mixed> $canonical
     */
    private function resolvePartyName(array $canonical): ?string
    {
        $name = is_array($canonical['name'] ?? null) ? $canonical['name'] : [];
        $full = $name['fullName'] ?? null;
        if (is_string($full) && trim($full) !== '') {
            return $full;
        }
        if (($canonical['personType'] ?? null) !== 'person') {
            return null;
        }
        $first = is_string($name['firstName'] ?? null) ? trim($name['firstName']) : '';
        $last = is_string($name['lastName'] ?? null) ? trim($name['lastName']) : '';
        $composed = trim($first . ' ' . $last);
        return $composed === '' ? null : $composed;
    }

    /**
     * For each sub-collection: enumerate active DB records of the person,
     * subtract any id matched by sub-resolve, return the remainder. For
     * addresses we partition by `address_type` so a payload that only
     * covers Sídla cannot accidentally close doručovací addresses.
     *
     * @param array<int, ResolveResult> $addressResults
     * @param array<int, ResolveResult> $bankResults
     * @param array<int, ResolveResult> $contactResults
     * @param array<string, mixed> $canonical
     * @return array{addresses: array<int, array<string, mixed>>, bankAccounts: array<int, array<string, mixed>>, contacts: array<int, array<string, mixed>>}
     */
    private function enumerateClosing(
        int $personId,
        array $canonical,
        array $addressResults,
        array $bankResults,
        array $contactResults,
    ): array {
        $matchedAddrIds = $this->extractMatchedIds($addressResults);
        $matchedBankIds = $this->extractMatchedIds($bankResults);
        $matchedContactIds = $this->extractMatchedIds($contactResults);

        $payloadAddrTypes = [];
        foreach (($canonical['addresses'] ?? []) as $addr) {
            if (!is_array($addr)) continue;
            $type = $addr['addressType'] ?? null;
            if (is_int($type)) {
                $payloadAddrTypes[$type] = true;
            }
        }

        return [
            'addresses'    => $payloadAddrTypes === []
                ? []
                : $this->enumerateClosingAddresses($personId, array_keys($payloadAddrTypes), $matchedAddrIds),
            'bankAccounts' => $this->enumerateClosingBankAccounts($personId, $matchedBankIds),
            'contacts'     => $this->enumerateClosingContacts($personId, $matchedContactIds),
        ];
    }

    /**
     * @param array<int, ResolveResult> $results
     * @return list<int>
     */
    private function extractMatchedIds(array $results): array
    {
        $ids = [];
        foreach ($results as $r) {
            if ($r->status === ResolveStatus::Matched && $r->matchedId !== null) {
                $ids[] = $r->matchedId;
            }
        }
        return $ids;
    }

    /**
     * @param list<int> $addrTypes  Address types present in the payload.
     * @param list<int> $matchedIds Address ids matched by AddressResolver.
     * @return array<int, array<string, mixed>>
     */
    private function enumerateClosingAddresses(int $personId, array $addrTypes, array $matchedIds): array
    {
        $rows = $this->db->fetchAll(
            'SELECT [id], [address_type], [display_line], [place_reg_type], [place_reg_id]
             FROM [base_persons_addresses]
             WHERE [person] = %i
               AND [address_type] IN %in
               AND [docState] IN (%i, %i, %i)
               AND ([valid_to] IS NULL OR [valid_to] >= CURDATE())',
            $personId,
            $addrTypes,
            self::ACTIVE_STATES[0], self::ACTIVE_STATES[1], self::ACTIVE_STATES[2],
        );
        return $this->subtractMatched($rows, $matchedIds);
    }

    /**
     * @param list<int> $matchedIds
     * @return array<int, array<string, mixed>>
     */
    private function enumerateClosingBankAccounts(int $personId, array $matchedIds): array
    {
        $rows = $this->db->fetchAll(
            'SELECT [id], [name], [account_number], [iban], [currency]
             FROM [base_persons_bank_accounts]
             WHERE [person] = %i
               AND [docState] IN (%i, %i, %i)
               AND ([valid_to] IS NULL OR [valid_to] >= CURDATE())',
            $personId,
            self::ACTIVE_STATES[0], self::ACTIVE_STATES[1], self::ACTIVE_STATES[2],
        );
        return $this->subtractMatched($rows, $matchedIds);
    }

    /**
     * @param list<int> $matchedIds
     * @return array<int, array<string, mixed>>
     */
    private function enumerateClosingContacts(int $personId, array $matchedIds): array
    {
        $rows = $this->db->fetchAll(
            'SELECT [id], [name], [role], [email]
             FROM [base_persons_contacts]
             WHERE [person] = %i
               AND [docState] IN (%i, %i, %i)
               AND ([valid_to] IS NULL OR [valid_to] >= CURDATE())',
            $personId,
            self::ACTIVE_STATES[0], self::ACTIVE_STATES[1], self::ACTIVE_STATES[2],
        );
        return $this->subtractMatched($rows, $matchedIds);
    }

    /**
     * @param iterable<mixed> $rows
     * @param list<int> $matchedIds
     * @return array<int, array<string, mixed>>
     */
    private function subtractMatched(iterable $rows, array $matchedIds): array
    {
        $skip = array_flip($matchedIds);
        $out = [];
        foreach ($rows as $row) {
            $arr = $row instanceof \Dibi\Row ? $row->toArray() : (array) $row;
            $id = (int) ($arr['id'] ?? 0);
            if ($id === 0 || isset($skip[$id])) continue;
            $out[] = $arr;
        }
        return $out;
    }
}
