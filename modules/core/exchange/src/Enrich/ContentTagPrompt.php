<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Enrich;

/**
 * Společný stavební kámen promptů obsahové klasifikace: výpis taxonomie
 * jako enumu s popisky.
 *
 * Sdílejí ho {@see ContentTagClassifier} (jeden doklad při analýze) a
 * `BookingHistoryClassifier` (dávka distinct textů z účetní historie).
 * Zbytek promptů se liší podstatně — jeden klasifikuje doklad jako celek
 * včetně výjimek na řádcích, druhý krátké texty v dávce — takže sdílený je
 * jen tenhle blok. Enum je v obou případech **jediná autorita**: klíč mimo
 * taxonomii se zahazuje (model si štítky vymýšlí).
 */
final class ContentTagPrompt
{
    /**
     * @param array<string, mixed> $taxonomy cfgItem core.exchange.contentTags
     */
    public static function taxonomyBlock(array $taxonomy): string
    {
        $lines = ['Taxonomy (tag: description):'];
        foreach ($taxonomy as $key => $entry) {
            $name = is_array($entry) ? (string) ($entry['name'] ?? '') : '';
            $lines[] = "  {$key}: {$name}";
        }
        return implode("\n", $lines);
    }
}
