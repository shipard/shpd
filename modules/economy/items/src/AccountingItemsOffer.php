<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Items;

use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Logging\ErrorLogger;
use Shipard\Core\Settings\SettingsStore;
use Shipard\Core\Utils\JsoncParser;

/**
 * Nabídka účetních položek (accountingItemsDefault/Npo.jsonc) — sdílená
 * čtečka pro SetupController (generátor položek) a ContentTagResolver
 * (derivace fallback účtu pro obsahový štítek). Varianta osnovy se čte
 * ze settings klíče economy.accountChart.
 *
 * Nabídka je kurátorovaná: při více položkách se stejným štítkem vyhrává
 * první v pořadí souboru.
 */
class AccountingItemsOffer
{
    /** @var array<string, array{groups: list<array<string, mixed>>, items: array<string, array<string, mixed>>}|null> */
    private array $seedCache = [];

    private ?string $variantCache = null;
    private bool $variantLoaded = false;

    public function __construct(
        private readonly DataSourceConnection $db,
        private readonly ?ModulePathResolver $modulePathResolver = null,
    ) {
    }

    /** Hodnota economy.accountChart, nebo null = nerozhodnuto. */
    public function variant(): ?string
    {
        if (!$this->variantLoaded) {
            $variant = (new SettingsStore($this->db))->get('economy.accountChart');
            $this->variantCache  = is_string($variant) && $variant !== '' ? $variant : null;
            $this->variantLoaded = true;
        }
        return $this->variantCache;
    }

    /**
     * Seed sady podle varianty osnovy — tvar {groups, items} (Task 11),
     * items klíčované kódem položky. Dva soubory, ne jeden s filtrem —
     * obě osnovy používají stejná čísla pro jiné účty.
     *
     * @return array{groups: list<array<string, mixed>>, items: array<string, array<string, mixed>>}|null
     *         null = neznámá varianta / soubor chybí / nečitelný / bez items
     */
    public function loadSeed(string $variant): ?array
    {
        if (array_key_exists($variant, $this->seedCache)) {
            return $this->seedCache[$variant];
        }
        return $this->seedCache[$variant] = $this->loadSeedFresh($variant);
    }

    /**
     * Fallback účet pro obsahový štítek z nabídky aktivní varianty osnovy —
     * číslo účtu první položky nabídky nesoucí přesně tento štítek; když
     * žádná není, prefix fallback (D3): první položka se štítkem stejné
     * skupiny (`vehicle.consumables` → `vehicle.*`). null = štítek nemá
     * v nabídce mapování (vědomé review, např. admin.other, goods.stock).
     */
    public function defaultAccountForTag(string $tag): ?string
    {
        $variant = $this->variant();
        if ($variant === null) {
            return null;
        }
        $seed = $this->loadSeed($variant);
        if ($seed === null) {
            return null;
        }

        $exact = $this->accountByTagMatch($seed['items'], static fn(string $t): bool => $t === $tag);
        if ($exact !== null) {
            return $exact;
        }

        $dot = strpos($tag, '.');
        if ($dot === false) {
            return null;
        }
        $prefix = substr($tag, 0, $dot + 1);
        return $this->accountByTagMatch(
            $seed['items'],
            static fn(string $t): bool => str_starts_with($t, $prefix),
        );
    }

    /**
     * @param array<string, array<string, mixed>> $items
     * @param callable(string): bool $matches
     */
    private function accountByTagMatch(array $items, callable $matches): ?string
    {
        foreach ($items as $entry) {
            foreach ((array) ($entry['contentTags'] ?? []) as $tag) {
                if (is_string($tag) && $matches($tag)) {
                    $account = (string) ($entry['account'] ?? '');
                    return $account !== '' ? $account : null;
                }
            }
        }
        return null;
    }

    /**
     * @return array{groups: list<array<string, mixed>>, items: array<string, array<string, mixed>>}|null
     */
    private function loadSeedFresh(string $variant): ?array
    {
        $file = match ($variant) {
            'default' => 'accountingItemsDefault.jsonc',
            'npo'     => 'accountingItemsNpo.jsonc',
            default   => null,
        };
        // Bez resolveru se soubory hledají relativně k této třídě — žije
        // uvnitř modulu economy.items, takže dirname(__DIR__) je jeho kořen.
        $modulePath = $this->modulePathResolver?->getPath('economy.items') ?? dirname(__DIR__);
        if ($file === null || $modulePath === null) {
            return null;
        }
        $path = $modulePath . '/config/' . $file;
        if (!is_file($path)) {
            ErrorLogger::error('AccountingItemsOffer: accounting items seed not found', ['file' => $file]);
            return null;
        }

        $seed = JsoncParser::parseFile($path);
        if (!is_array($seed) || !is_array($seed['items'] ?? null)) {
            return null;
        }

        $groups   = [];
        $groupIds = [];
        foreach ((array) ($seed['groups'] ?? []) as $entry) {
            if (is_array($entry) && !empty($entry['id'])) {
                $groups[] = $entry;
                $groupIds[(string) $entry['id']] = true;
            }
        }

        $byCode = [];
        foreach ($seed['items'] as $entry) {
            if (!is_array($entry) || empty($entry['code'])) {
                continue;
            }
            // Neznámá skupina je chyba seedu, ne důvod položku zahodit —
            // offer ji vrátí tak, jak je, klient ji zobrazí v sekci Ostatní.
            $group = (string) ($entry['group'] ?? '');
            if (!isset($groupIds[$group])) {
                ErrorLogger::error('AccountingItemsOffer: seed entry has unknown group', [
                    'file'  => $file,
                    'code'  => (string) $entry['code'],
                    'group' => $group,
                ]);
            }
            $byCode[(string) $entry['code']] = $entry;
        }
        return ['groups' => $groups, 'items' => $byCode];
    }
}
