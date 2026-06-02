<?php

declare(strict_types=1);

namespace Shipard\Tests\Fixtures\Module\Docs\Core;

use Shipard\Module\Docs\Core\DocsHeadsDocument;

/**
 * Test-only subclass that exposes DocDocument's protected methods as public,
 * so unit tests can drive each method in isolation without resorting to
 * reflection.
 */
class TestableDocsHeadsDocument extends DocsHeadsDocument
{
    /** @var array<int, array{sql: string, args: array}> */
    public array $executedSql = [];

    protected function executeSql(mixed ...$args): void
    {
        $this->executedSql[] = [
            'sql'  => (string) ($args[0] ?? ''),
            'args' => array_slice($args, 1),
        ];
    }

    public function applyDateDefaultsPub(array &$data): void
    {
        $this->applyDateDefaults($data);
    }

    public function applyHomeCurrencyPub(array &$data): void
    {
        $this->applyHomeCurrency($data);
    }

    public function resolveAccountingPeriodsPub(array &$data): void
    {
        $this->resolveAccountingPeriods($data);
    }

    public function resolveFiscalYearIdPub(string $accountingDate): ?int
    {
        return $this->resolveFiscalYearId($accountingDate);
    }

    public function resolveFiscalMonthIdPub(string $accountingDate): ?int
    {
        return $this->resolveFiscalMonthId($accountingDate);
    }

    public function resolveVatPeriodIdPub(?string $vatDuzp, ?int $vatRegistrationId): ?int
    {
        return $this->resolveVatPeriodId($vatDuzp, $vatRegistrationId);
    }

    public function calculateRowPricePub(array &$row): void
    {
        $this->calculateRowPrice($row);
    }

    public function calculateRowVatPub(array &$row, int $vatMode): void
    {
        $this->calculateRowVat($row, $vatMode);
    }

    /** @return array<int, array<string, mixed>> */
    public function buildVatRecapitulationPub(array &$data): array
    {
        return $this->buildVatRecapitulation($data);
    }

    public function sumTotalsPub(array &$data, array $recap): void
    {
        $this->sumTotals($data, $recap);
    }

    public function applyTotalRoundingPub(array &$data): void
    {
        $this->applyTotalRounding($data);
    }

    public function applyRoundingPub(float $amount, int $mode): float
    {
        return $this->applyRounding($amount, $mode);
    }

    public function applyExchangeRatePub(array &$data): void
    {
        $this->applyExchangeRate($data);
    }

    public function processStateTransitionPub(array &$data, ?array $originalData): void
    {
        $this->processStateTransition($data, $originalData);
    }

    public function assignDocumentNumberPub(array &$data): void
    {
        $this->assignDocumentNumber($data);
    }

    /** @param array<string, mixed> $importNumber */
    public function applyImportNumberPub(array &$data, array $importNumber): void
    {
        $this->applyImportNumber($data, $importNumber);
    }

    public function numberSeriesResetScopePub(int $seriesId): string
    {
        return $this->numberSeriesResetScope($seriesId);
    }

    public function beforeSavePub(array &$data, ?array $originalData = null): void
    {
        $this->beforeSave($data, $originalData);
    }

    public function releaseDocumentNumberPub(array &$data, ?array $originalData): void
    {
        $this->releaseDocumentNumber($data, $originalData);
    }

    /** @param array<string, mixed> $series */
    public function resolvePatternPub(string $pattern, array $data, array $series): string
    {
        return $this->resolvePattern($pattern, $data, $series);
    }

    public function maintainSnapshotsPub(array &$data, ?array $originalData): void
    {
        $this->maintainSnapshots($data, $originalData);
    }

    public function buildSnapshotsPub(array &$data): void
    {
        $this->buildSnapshots($data);
    }

    /** @return array<string, mixed> */
    public function buildPersonSnapshotPub(int $personId, mixed $addressId, mixed $bankAccountId, mixed $vatRegistrationId): array
    {
        return $this->buildPersonSnapshot($personId, $addressId, $bankAccountId, $vatRegistrationId);
    }

    public function applyVariableSymbolDefaultPub(array &$data): void
    {
        $this->applyVariableSymbolDefault($data);
    }

    public function trackStateChangePub(array &$data, ?array $originalData): void
    {
        $this->trackStateChange($data, $originalData);
    }
}
