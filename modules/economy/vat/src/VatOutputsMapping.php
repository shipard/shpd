<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Vat;

/**
 * Resolver mapovací konfigurace `economy.vat.reports.{country}`
 * (config/vat-reports-cz.jsonc): pro kód DPH vrátí jeho zařazení do
 * výstupů — řádek DPHDP3, sekci DPHKH1, kód plnění DPHSHV. Neznámý kód
 * je tvrdá chyba (mapování musí pokrývat celý číselník — hlídá
 * VatReportsMappingCompletenessTest), explicitní vyloučení je `null`.
 */
final class VatOutputsMapping
{
    /** @param array<string, mixed> $config Dekódovaný cfgItem (`vatOutputs` + `dp3Rows`). */
    public function __construct(private readonly array $config)
    {
        if (!isset($config['vatOutputs']) || !is_array($config['vatOutputs'])) {
            throw new \InvalidArgumentException("VAT outputs mapping: missing 'vatOutputs' section");
        }
    }

    /** @return array{dp3: ?array<string, mixed>, kh: ?array<string, mixed>, sh: ?array<string, mixed>} */
    public function forCode(string $vatCode): array
    {
        $record = $this->config['vatOutputs'][$vatCode] ?? null;
        if (!is_array($record)) {
            throw new \DomainException(
                "Kód DPH '{$vatCode}' nemá záznam v mapování výstupů (economy.vat.reports)",
            );
        }
        return $record;
    }

    /** @return ?array{row: int, col?: string} */
    public function dp3(string $vatCode): ?array
    {
        return $this->forCode($vatCode)['dp3'];
    }

    /** @return ?array{group: string, kodPredPl?: int} */
    public function kh(string $vatCode): ?array
    {
        return $this->forCode($vatCode)['kh'];
    }

    /** @return ?array{kod: int} */
    public function sh(string $vatCode): ?array
    {
        return $this->forCode($vatCode)['sh'];
    }

    /** Popisek řádku DPHDP3 (kompilovaný config je už lokalizovaný). */
    public function dp3RowLabel(int $row): ?string
    {
        $entry = $this->config['dp3Rows'][(string) $row] ?? null;
        if (!is_array($entry)) {
            return null;
        }
        $label = (string) ($entry['label'] ?? '');
        return $label !== '' ? $label : null;
    }
}
