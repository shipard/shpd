<?php

declare(strict_types=1);

namespace Shipard\Core\Form;

final class EnumOptionsHelper
{
    /** cfgItem ID, jejichž options nesou prefix `ALPHA3 — name`. */
    private const ALPHA3_PREFIXED = ['world.base.currencies'];

    /**
     * Složí options z cfgItem dat. Label: pro cfgItem v ALPHA3_PREFIXED
     * (měny) použije `ALPHA3 — name`; jinak holý `name`. Země záměrně
     * bez prefixu (rozhodnutí M1). Lokalizace (`name` vs `name:cs`)
     * — zatím vždy `name`, dořeší se s i18n.
     *
     * @param array<string|int, mixed> $cfgData
     * @param 'enumInt'|'enumString' $colType
     * @return list<array{value: int|string, label: string}>
     */
    public static function fromCfgData(array $cfgData, string $colType, ?string $cfgItemId = null): array
    {
        $prefix = $cfgItemId !== null && in_array($cfgItemId, self::ALPHA3_PREFIXED, true);
        $options = [];
        foreach ($cfgData as $key => $entry) {
            if (!is_array($entry) || !isset($entry['name'])) {
                continue;
            }
            $name = (string) $entry['name'];
            $alpha3 = isset($entry['alpha3']) ? (string) $entry['alpha3'] : '';
            $label = ($prefix && $alpha3 !== '') ? "{$alpha3} — {$name}" : $name;
            $value = $colType === 'enumInt' ? (int) $key : (string) $key;
            $options[] = ['value' => $value, 'label' => $label];
        }
        return $options;
    }
}
