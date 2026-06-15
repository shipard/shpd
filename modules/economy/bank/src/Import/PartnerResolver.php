<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Bank\Import;

/**
 * Dohledání partnera (osoby) podle čísla protiúčtu — port POSLEDNÍHO bloku
 * `checkRowPerson` ze starého Shipardu (saldo-nezávislé). Hledá v bankovních
 * spojeních kontaktů (`base_persons_bank_accounts`) dle normalizovaného
 * čísla účtu / IBANu. Právě jedna shoda → vrátí id osoby; jinak null.
 *
 * Žádné saldo (VS → otevřené faktury) — to je pozdější fáze.
 */
final class PartnerResolver
{
    private const ACTIVE_STATES = [10, 40, 80];

    public function __construct(private readonly \Dibi\Connection $db)
    {
    }

    public function resolve(?string $counterpartyAccount): ?int
    {
        if ($counterpartyAccount === null) {
            return null;
        }
        $variants = $this->variants($counterpartyAccount);
        if ($variants === []) {
            return null;
        }

        $rows = $this->db->query(
            'SELECT [person], [account_number], [iban] FROM [base_persons_bank_accounts]'
            . ' WHERE [docState] IN %in',
            self::ACTIVE_STATES,
        )->fetchAll();

        $matches = [];
        foreach ($rows as $row) {
            $cand = array_merge(
                $this->variants((string) ($row['account_number'] ?? '')),
                $this->variants((string) ($row['iban'] ?? '')),
            );
            if (array_intersect($variants, $cand) !== []) {
                $matches[(int) $row['person']] = true;
            }
        }

        // Právě jedna osoba → spárovat; víc/žádná → nechat prázdné.
        return count($matches) === 1 ? (int) array_key_first($matches) : null;
    }

    /** Normalizované varianty čísla účtu (plné + část před lomítkem). */
    private function variants(string $s): array
    {
        $s = trim($s);
        if ($s === '') {
            return [];
        }
        $out = [$this->normalize($s)];
        if (str_contains($s, '/')) {
            $out[] = $this->normalize(explode('/', $s, 2)[0]);
        }
        return array_values(array_unique(array_filter($out, static fn($v) => $v !== '')));
    }

    private function normalize(string $s): string
    {
        return strtoupper((string) preg_replace('/[\s\-\/]/', '', $s));
    }
}
