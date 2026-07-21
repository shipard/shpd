<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Bank\Import;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Economy\Bank\Import\ParsedTransaction;
use Shipard\Module\Economy\Bank\Import\StatementImportService;

/**
 * Formát otisku transakce. Otisk bez external_id (souborové importy) je
 * bajtově přibitý — mění-li se, přestanou matchovat už uložené fingerprinty
 * a dedup souborových re-importů se rozpadne. External_id (migrace, API)
 * vstupuje do otisku, aby obsahově identické opakované platby napříč
 * výpisy nekolidovaly na unq_fingerprint.
 */
class StatementImportServiceFingerprintTest extends TestCase
{
    private function fingerprint(ParsedTransaction $tx, int $seqInDay = 0): string
    {
        $ref = new \ReflectionClass(StatementImportService::class);
        $service = $ref->newInstanceWithoutConstructor();
        return $ref->getMethod('fingerprint')->invoke(
            $service,
            5,
            $tx,
            2,
            164.00,
            '2026-06-10',
            $seqInDay,
        );
    }

    private function tx(?string $externalId): ParsedTransaction
    {
        return new ParsedTransaction(
            externalId: $externalId,
            amount: -164.00,
            dateTransaction: new \DateTimeImmutable('2026-06-10'),
            message: 'Cena za vedení účtu',
        );
    }

    public function testLegacyFormatWithoutExternalIdIsByteStable(): void
    {
        // Natvrdo přibitý vstup hashe — NEODVOZOVAT z implementace. Změna
        // formátu = ztráta dedupu proti otiskům uloženým v DB.
        $expected = hash('sha256', '5|2026-06-10|2|164.00||||Cena za vedení účtu|0');
        $this->assertSame($expected, $this->fingerprint($this->tx(null)));
    }

    public function testExternalIdAppendedToFingerprint(): void
    {
        $expected = hash('sha256', '5|2026-06-10|2|164.00||||Cena za vedení účtu|0|fee-old-1');
        $this->assertSame($expected, $this->fingerprint($this->tx('fee-old-1')));
    }

    public function testIdenticalContentDifferentExternalIdGivesDifferentFingerprint(): void
    {
        $first = $this->fingerprint($this->tx('fee-old-1'));
        $second = $this->fingerprint($this->tx('fee-old-2'));
        $this->assertNotSame($first, $second, 'external_id činí otisk unikátní z konstrukce');
        $this->assertNotSame($this->fingerprint($this->tx(null)), $first);
    }
}
