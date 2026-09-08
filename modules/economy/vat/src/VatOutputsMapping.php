<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Vat;

use Shipard\Core\Config\ConfigRuntime;

/**
 * Resolver mapovací konfigurace `economy.vat.reports.{country}`
 * (config/vat-reports-cz.jsonc): pro kód DPH vrátí jeho zařazení do
 * výstupů — řádek DPHDP3, sekci DPHKH1, kód plnění DPHSHV. Neznámý kód
 * je tvrdá chyba (mapování musí pokrývat celý číselník — hlídá
 * VatReportsMappingCompletenessTest), explicitní vyloučení je `null`.
 *
 * Sekce `reportTypes` (#58) nese zákonný počátek typu výstupu
 * (`validFrom`, ISO datum) — před ním doklad do výstupu nespadá a instance
 * se negenerují. Neznámý typ nebo špatný formát data je chyba konstruktoru
 * (překlep v configu nesmí projít tiše).
 */
final class VatOutputsMapping
{
    public const CFG_ITEM_CZ = 'economy.vat.reports.cz';

    private const REPORT_TYPES = ['return', 'cs', 'rs'];

    /** @var array<string, string> typ → ISO datum */
    private readonly array $validFromByType;

    /** @param array<string, mixed> $config Dekódovaný cfgItem (`vatOutputs` + `dp3Rows` + `reportTypes`). */
    public function __construct(private readonly array $config)
    {
        if (!isset($config['vatOutputs']) || !is_array($config['vatOutputs'])) {
            throw new \InvalidArgumentException("VAT outputs mapping: missing 'vatOutputs' section");
        }
        $this->validFromByType = self::parseReportTypes($config['reportTypes'] ?? []);
    }

    /**
     * Mapping z kompilovaného configu DS; null, chybí-li cfgItem (config
     * nezkompilovaný) — volající pak pracuje bez mapování jako dnes.
     */
    public static function fromConfig(?ConfigRuntime $config, string $cfgItem = self::CFG_ITEM_CZ): ?self
    {
        $cfg = $config?->cfgItem($cfgItem);
        return is_array($cfg) ? new self($cfg) : null;
    }

    /** Zákonný počátek typu výstupu (ISO datum) nebo null bez omezení. */
    public function validFrom(string $type): ?string
    {
        return $this->validFromByType[$type] ?? null;
    }

    /** @return array<string, string> jen typy s omezením, typ → ISO datum */
    public function validFromByType(): array
    {
        return $this->validFromByType;
    }

    /**
     * @param mixed $section
     * @return array<string, string>
     */
    private static function parseReportTypes(mixed $section): array
    {
        if (!is_array($section)) {
            throw new \InvalidArgumentException("VAT outputs mapping: 'reportTypes' must be an object");
        }
        $out = [];
        foreach ($section as $type => $entry) {
            if (!in_array($type, self::REPORT_TYPES, true)) {
                throw new \InvalidArgumentException("VAT outputs mapping: unknown report type '{$type}' in 'reportTypes'");
            }
            if (!is_array($entry)) {
                throw new \InvalidArgumentException("VAT outputs mapping: reportTypes.{$type} must be an object");
            }
            if (!array_key_exists('validFrom', $entry)) {
                continue;
            }
            $validFrom = $entry['validFrom'];
            if (!is_string($validFrom) || !self::isIsoDate($validFrom)) {
                throw new \InvalidArgumentException(
                    "VAT outputs mapping: reportTypes.{$type}.validFrom must be an ISO date (YYYY-MM-DD)",
                );
            }
            $out[$type] = $validFrom;
        }
        return $out;
    }

    private static function isIsoDate(string $value): bool
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) !== 1) {
            return false;
        }
        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
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
