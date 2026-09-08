<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Vat;

use Shipard\Core\Database\DataSourceConnection;

/**
 * Jediná autorita „jaký koeficient odpočtu platí pro registraci v roce N"
 * (#59 D13b). Pořadí: explicitní zálohový koeficient roku N → vypořádací
 * koeficient roku N−1 (§ 76 odst. 6 ZDPH) → 1,0000 (plný nárok, stav firmy
 * bez osvobozených plnění). Čte jen záznamy ve stavu V pořádku (40).
 *
 * Živé přiznání (ř. 52) i budoucí roční vypořádání (ř. 53) jdou přes tuto
 * třídu. DB přístup je v protected metodě kvůli testům.
 */
class DeductionCoefficientResolver
{
    public const SOURCE_PROVISIONAL = 'provisional';
    public const SOURCE_PREVIOUS_SETTLED = 'previous_settled';
    public const SOURCE_DEFAULT = 'default';

    public const DEFAULT_COEFFICIENT = 1.0;

    private const DOC_STATE_OK = 40;

    public function __construct(private readonly ?DataSourceConnection $db) {}

    /**
     * @return array{value: float, source: 'provisional'|'previous_settled'|'default'}
     */
    public function provisional(int $registrationId, int $year): array
    {
        $current = $this->loadRow($registrationId, $year);
        if ($current !== null && $current['coefficient_provisional'] !== null) {
            return ['value' => (float) $current['coefficient_provisional'], 'source' => self::SOURCE_PROVISIONAL];
        }

        $previous = $this->loadRow($registrationId, $year - 1);
        if ($previous !== null && $previous['coefficient_settled'] !== null) {
            return ['value' => (float) $previous['coefficient_settled'], 'source' => self::SOURCE_PREVIOUS_SETTLED];
        }

        return ['value' => self::DEFAULT_COEFFICIENT, 'source' => self::SOURCE_DEFAULT];
    }

    /**
     * Záznam registrace a roku ve stavu 40, null když není.
     *
     * @return ?array{coefficient_provisional: ?float, coefficient_settled: ?float}
     */
    protected function loadRow(int $registrationId, int $year): ?array
    {
        if ($this->db === null) {
            return null;
        }
        $row = $this->db->fetchRow(
            'SELECT [coefficient_provisional], [coefficient_settled] FROM [economy_vat_deduction_coefficients]'
            . ' WHERE [vat_registration] = %i AND [year] = %i AND [docState] = %i LIMIT 1',
            $registrationId, $year, self::DOC_STATE_OK,
        );
        if ($row === null) {
            return null;
        }
        return [
            'coefficient_provisional' => $row['coefficient_provisional'] !== null ? (float) $row['coefficient_provisional'] : null,
            'coefficient_settled'     => $row['coefficient_settled'] !== null ? (float) $row['coefficient_settled'] : null,
        ];
    }
}
