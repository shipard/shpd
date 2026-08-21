<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\BookingHistory;

/**
 * Kandidát seed pravidla `IČO → obsahový štítek` (D32) i s podporou, ze
 * které vznikl — report ji vypisuje, aby bylo vidět, na jak silném signálu
 * pravidlo stojí.
 *
 * `share` je podíl řádků dominantního štítku mezi řádky, kterým reverz
 * **přiřadil** štítek. `coverage` říká, jakou část všech řádků toho IČO
 * reverz vůbec pokryl — nízké coverage při vysokém share znamená pravidlo
 * postavené na malém výseku historie dodavatele. Od D37 je coverage
 * **třetí práh**, ne jen informace.
 *
 * `rejectedBy` nese důvod zamítnutí (dnes jen `coverage`), nebo null
 * u kandidáta, který prošel. Zamítnutí pokrytím se **nezahazuje**:
 * kandidát zůstává v náhledu reportu se stavem „pod prahem pokrytí",
 * protože je to nejčastější věc, kterou chce člověk přehlasovat ručně.
 */
final readonly class SeedCandidate
{
    public const REJECTED_COVERAGE = 'coverage';

    public function __construct(
        public string $companyId,
        public string $tag,
        public int $rows,
        public int $docs,
        public int $resolvedRows,
        public int $totalRows,
        public float $share,
        public float $coverage,
        public ?string $rejectedBy = null,
    ) {}

    public function isAccepted(): bool
    {
        return $this->rejectedBy === null;
    }
}
