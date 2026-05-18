<?php

declare(strict_types=1);

namespace Shipard\Core\Form\Lookup;

/**
 * Display popis jedné položky v lookup výsledku.
 *
 * `primary` je hlavní řádek (např. „Testování 999"), `secondary` je
 * volitelný caption pod ním (např. „IČO 12345678" nebo „Datum narození
 * 14.05.1990"). Strukturu řeší konkrétní TableLookup — frontend ji jen
 * renderuje.
 */
final class LookupItem
{
    public function __construct(
        public readonly int|string $id,
        public readonly string $primary,
        public readonly ?string $secondary = null,
    ) {}

    /**
     * @return array{id: int|string, primary: string, secondary: string|null}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'primary' => $this->primary,
            'secondary' => $this->secondary,
        ];
    }
}
