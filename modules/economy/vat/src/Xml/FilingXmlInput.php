<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Vat\Xml;

/**
 * Vstup generátoru XML — **celý obsah podání jako hodnota** (issue #55, X1).
 *
 * Writer nesahá do databáze; všechno, co se vypíše, je tady, a všechno je
 * to z persistovaného snapshotu (`economy_vat_filings` + `_return_rows` /
 * `_cs_rows` / `_rs_rows`). Odtud plyne determinismus: podání ve stavu
 * Podáno vydá při opakovaném generování týž soubor bez ohledu na to, co se
 * mezitím stalo s doklady, koeficientem nebo registrací.
 *
 * Sestavuje ho `FilingFilesService` (a v testech fixtury).
 */
final readonly class FilingXmlInput
{
    /**
     * @param string $reportType   `return` | `cs` | `rs`
     * @param string $filingKind   druh podání (`economy.vat.filingKinds`)
     * @param ?string $previousKind druh předchozího podání — rozlišuje
     *        opravné od dodatečného/opravného (kód formy „E")
     * @param array<string, mixed> $header hodnoty hlavičky bez `_schema`
     * @param array<int, array{base: float, full: float, reduced: float}> $returnRows
     *        podané hodnoty řádků přiznání per číslo řádku
     * @param array<string, float> $coefficients koeficienty pro ř. 52 / 53
     *        (`coefficient`, `settlementCoefficient`) jako podíl ⟨0; 1⟩
     * @param list<array<string, mixed>> $controlRows řádky kontrolního hlášení
     * @param array<string, float> $controlReturnBase základy per řádek přiznání
     *        pro kontrolní větu C
     * @param list<array<string, mixed>> $recapRows řádky souhrnného hlášení
     * @param ?string $note text do textové přílohy dodatečného přiznání
     */
    public function __construct(
        public string $reportType,
        public string $filingKind,
        public ?string $previousKind,
        public array $header,
        public FilingPeriod $period,
        public string $dateIssue,
        public ?string $dateFiled = null,
        public ?string $dateFound = null,
        public array $returnRows = [],
        public array $coefficients = [],
        public array $controlRows = [],
        public array $controlReturnBase = [],
        public array $recapRows = [],
        public ?string $note = null,
    ) {}

    /** Datum podání do věty D: podané podání nese svoje, koncept den sestavení. */
    public function filingDate(): string
    {
        return $this->dateFiled ?? $this->dateIssue;
    }

    /**
     * Hodnota slotu řádku přiznání (`base` / `full` / `reduced`).
     */
    public function rowValue(int $row, string $slot): float
    {
        return (float) ($this->returnRows[$row][$slot] ?? 0.0);
    }
}
