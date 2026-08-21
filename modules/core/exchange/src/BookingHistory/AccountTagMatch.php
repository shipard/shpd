<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\BookingHistory;

/**
 * Výsledek reverzu účet → obsahový štítek ({@see AccountTagMap::resolve()}).
 * `tag` je neprázdný jen u druhů `exact` a `synthetic`; ostatní druhy nesou
 * důvod, proč štítek nevznikl — report je vykazuje odděleně, protože
 * „účet mimo nabídku" a „kolizní účet" jsou jiné vady zdroje.
 *
 * `degradedExact` říká, že účet **v nabídce byl** s jednoznačným štítkem,
 * ale kontrola názvu položky ho zamítla (D36) a výsledek pochází teprve
 * z hrubší syntetické úrovně. Není to druh výsledku, ale okolnost jeho
 * vzniku — proto flag, ne `kind`: report chce vědět obojí zvlášť.
 */
final readonly class AccountTagMatch
{
    /** Přesná shoda čísla účtu s nabídkou. */
    public const KIND_EXACT = 'exact';
    /** Shoda syntetiky (první 3 číslice) — jednoznačná napříč nabídkou. */
    public const KIND_SYNTHETIC = 'synthetic';
    /** Účet v nabídce je, ale nese víc štítků. */
    public const KIND_AMBIGUOUS = 'ambiguous';
    /** Účet ani jeho syntetika v nabídce nejsou. */
    public const KIND_UNMAPPED = 'unmapped';
    /** Záznam nemá účet (`account: null`). */
    public const KIND_NO_ACCOUNT = 'noAccount';

    /** @param list<string> $candidates štítky ve hře u druhů ambiguous/synthetic */
    public function __construct(
        public ?string $tag,
        public string $kind,
        public array $candidates = [],
        public bool $degradedExact = false,
    ) {}

    public function isHit(): bool
    {
        return $this->tag !== null;
    }
}
