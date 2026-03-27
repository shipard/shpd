<?php

declare(strict_types=1);

namespace Shipard\Core\Seed;

class FakeBankAccountGenerator
{
    /** Czech bank codes with BIC/SWIFT */
    private const BANKS = [
        ['code' => '0100', 'bic' => 'KOMBCZPP', 'name' => 'Komerční banka'],
        ['code' => '0300', 'bic' => 'CEKOCZPP', 'name' => 'ČSOB'],
        ['code' => '0600', 'bic' => 'AGBACZPP', 'name' => 'Moneta'],
        ['code' => '0800', 'bic' => 'GIBACZPX', 'name' => 'Česká spořitelna'],
        ['code' => '2010', 'bic' => 'FIOBCZPP', 'name' => 'Fio banka'],
        ['code' => '2700', 'bic' => 'BACXCZPP', 'name' => 'UniCredit Bank'],
        ['code' => '5500', 'bic' => 'RZBCCZPP', 'name' => 'Raiffeisenbank'],
    ];

    /**
     * Generate 1..maxCount bank accounts for a given person.
     *
     * @return list<array<string, mixed>> Arrays ready for INSERT into base_persons_bank_accounts
     */
    public function generate(int $personId, int $maxCount = 2): array
    {
        $count = random_int(1, $maxCount);
        $accounts = [];

        for ($i = 0; $i < $count; $i++) {
            $bank = self::BANKS[array_rand(self::BANKS)];
            $accountBase = (string) random_int(1000000, 9999999999);
            $accountNumber = $accountBase . '/' . $bank['code'];

            // Generate a plausible (but not valid) Czech IBAN: CZ + 2 check digits + 4 bank code + 16 account
            $accountPadded = str_pad($accountBase, 16, '0', STR_PAD_LEFT);
            $iban = 'CZ00' . $bank['code'] . $accountPadded;

            $accountName = $i === 0 ? 'Hlavní účet' : 'Účet ' . ($i + 1);

            $accounts[] = [
                'person'         => $personId,
                'name'           => $accountName,
                'account_number' => $accountNumber,
                'iban'           => $iban,
                'bic'            => $bank['bic'],
                'currency'       => 'CZK',
                'source'         => 0, // Manual entry
                'order_pos'      => $i,
            ];
        }

        return $accounts;
    }
}
