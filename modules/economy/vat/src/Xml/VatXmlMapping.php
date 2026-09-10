<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Vat\Xml;

use Shipard\Core\Config\ConfigRuntime;

/**
 * Resolver mapovací konfigurace XML pro EPO (`economy.vat.xml.cz`,
 * `config/vat-xml-cz.jsonc`) — **jedna instance = jedna písemnost**
 * (přiznání / kontrolní hlášení / souhrnné hlášení).
 *
 * Writery se ptají jen tady: jaká věta, jaký atribut, kolik desetinných
 * míst, jaký kód formy. Číslo řádku ani jméno atributu v PHP nikde není
 * (rozhodnutí X4) — nové vydání formuláře je změna configu.
 *
 * Chybějící sekce nebo neznámý typ tvrzení je **výjimka**: generovat
 * podání z půlky mapování by znamenalo tiše vynechat řádky.
 */
final class VatXmlMapping
{
    public const CFG_ITEM_CZ = 'economy.vat.xml.cz';

    /** Typ tvrzení (`economy.vat.reportTypes`) → sekce configu. */
    public const DOCUMENT_BY_REPORT_TYPE = ['return' => 'dp3', 'cs' => 'kh1', 'rs' => 'shv'];

    /** @param array<string, mixed> $document sekce configu pro tuto písemnost */
    private function __construct(
        public readonly string $reportType,
        private readonly array $document,
    ) {}

    /**
     * Mapování pro typ tvrzení; `null` když cfgItem chybí (nezkompilovaná
     * konfigurace) — volající degraduje stejně jako u živých reportů.
     */
    public static function forReportType(
        ?ConfigRuntime $config,
        string $reportType,
        string $cfgItem = self::CFG_ITEM_CZ,
    ): ?self {
        $cfg = $config?->cfgItem($cfgItem);
        if (!is_array($cfg)) {
            return null;
        }
        return self::fromArray($cfg, $reportType);
    }

    /** @param array<string, mixed> $cfg celý dekódovaný cfgItem */
    public static function fromArray(array $cfg, string $reportType): self
    {
        $key = self::DOCUMENT_BY_REPORT_TYPE[$reportType] ?? null;
        if ($key === null) {
            throw new \DomainException("XML mapování: neznámý typ tvrzení '{$reportType}'");
        }
        if (!isset($cfg[$key]) || !is_array($cfg[$key])) {
            throw new \DomainException("XML mapování: chybí sekce '{$key}'");
        }
        return new self($reportType, $cfg[$key]);
    }

    public function element(): string
    {
        return (string) $this->document['element'];
    }

    public function verzePis(): string
    {
        return (string) $this->document['verzePis'];
    }

    /** @return array<string, string> atributy věty D s pevnou hodnotou */
    public function constants(): array
    {
        return $this->document['constants'] ?? [];
    }

    public function formaAttribute(): string
    {
        return (string) $this->document['formaAttr'];
    }

    /**
     * Kód formy podání. `<druh>@<druh předchozího>` má přednost před holým
     * druhem — tak vzniká dodatečné/opravné (DP3 „E") a následné/opravné
     * (KH „E"). Neznámý druh je výjimka: forma je povinný atribut a tichá
     * prázdná hodnota by shodila až XSD validace.
     */
    public function forma(string $kind, ?string $previousKind): string
    {
        $forma = $this->document['forma'] ?? [];
        if ($previousKind !== null && isset($forma[$kind . '@' . $previousKind])) {
            return (string) $forma[$kind . '@' . $previousKind];
        }
        if (!isset($forma[$kind])) {
            throw new \DomainException(
                "XML mapování ({$this->reportType}): druh podání '{$kind}' nemá kód formy",
            );
        }
        return (string) $forma[$kind];
    }

    /** Desetinná místa hodnot písemnosti (0 = celé Kč, 2 = haléře). */
    public function valueScale(): int
    {
        return (int) ($this->document['valueScale'] ?? 2);
    }

    /** Desetinná místa koeficientu v procentech. */
    public function percentScale(): int
    {
        return (int) ($this->document['percentScale'] ?? 2);
    }

    /** @return array<string, array<string, mixed>> číslo řádku → mapa slotů */
    public function rows(): array
    {
        return $this->document['rows'] ?? [];
    }

    /** @return list<int> řádky, které se vypisují i s nulou */
    public function alwaysEmit(): array
    {
        return array_map(intval(...), $this->document['alwaysEmit'] ?? []);
    }

    /** @return ?array<string, mixed> textová příloha (věta R přiznání) */
    public function note(): ?array
    {
        return $this->document['note'] ?? null;
    }

    /** @return array<string, array<string, mixed>> sekce KH → mapa atributů */
    public function sections(): array
    {
        return $this->document['sections'] ?? [];
    }

    /** @return ?array<string, mixed> kontrolní věta C hlášení */
    public function vetaC(): ?array
    {
        return $this->document['vetaC'] ?? null;
    }

    /** @return ?array<string, mixed> řádek souhrnného hlášení */
    public function row(): ?array
    {
        return $this->document['row'] ?? null;
    }

    /** @return array<string, mixed> rozdělení polí hlavičky mezi věty D a P */
    public function header(): array
    {
        return $this->document['header'] ?? [];
    }
}
