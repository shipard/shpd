<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Codebooks;

use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Document\Document;
use Shipard\Core\Document\ValidationResult;
use Shipard\Core\Logging\ErrorLogger;

class VatRegistrationDocument extends Document
{
    /** Hodnoty musí korespondovat s `economy.codebooks.vatTaxpayerKinds` cfgItem. */
    private const VALID_TAXPAYER_KINDS = [0, 1];

    /** Hodnoty musí korespondovat s `economy.codebooks.vatPeriodKinds` cfgItem. */
    private const VALID_PERIOD_KINDS = [1, 2];

    public function validate(array &$data): ValidationResult
    {
        $result = new ValidationResult();

        if (empty($data['name'])) {
            $result->addError('name', 'Název je povinný', 'required');
        }
        if (empty($data['region'])) {
            $result->addError('region', 'Region je povinný', 'required');
        }
        if (empty($data['country'])) {
            $result->addError('country', 'Země je povinná', 'required');
        }

        $taxpayerKind = $data['taxpayer_kind'] ?? null;
        if ($taxpayerKind === null || $taxpayerKind === '') {
            $result->addError('taxpayer_kind', 'Druh plátce je povinný', 'required');
        } elseif (!in_array((int) $taxpayerKind, self::VALID_TAXPAYER_KINDS, true)) {
            $result->addError('taxpayer_kind', 'Neznámý druh plátce', 'invalid_value');
        }

        $taxPeriodKind = $data['tax_period_kind'] ?? null;
        if ($taxPeriodKind === null || $taxPeriodKind === '') {
            $result->addError('tax_period_kind', 'Frekvence přiznání DPH je povinná', 'required');
        } elseif (!in_array((int) $taxPeriodKind, self::VALID_PERIOD_KINDS, true)) {
            $result->addError('tax_period_kind', 'Neplatná frekvence přiznání DPH', 'invalid_value');
        }

        $reportPeriodKind = $data['report_period_kind'] ?? null;
        if ($reportPeriodKind === null || $reportPeriodKind === '') {
            $result->addError('report_period_kind', 'Frekvence kontrolního hlášení je povinná', 'required');
        } elseif (!in_array((int) $reportPeriodKind, self::VALID_PERIOD_KINDS, true)) {
            $result->addError('report_period_kind', 'Neplatná frekvence kontrolního hlášení', 'invalid_value');
        }

        if (empty($data['valid_from'])) {
            $result->addError('valid_from', 'Začátek platnosti je povinný', 'required');
        }

        if (!empty($data['valid_from']) && !empty($data['valid_to'])
            && (string) $data['valid_from'] > (string) $data['valid_to']
        ) {
            $result->addError(
                'valid_to',
                'Konec platnosti musí být později nebo stejný den jako začátek.',
                'invalid_range',
            );
        }

        return $result;
    }

    /**
     * Po uložení registrace hned dogenerovat období DPH — bez toho by
     * uživatel po založení registrace čekal na další ds-upgrade. Hook na
     * dokumentu (ne ve formuláři), aby fungoval i pro apply z exchange
     * a budoucí volání z průvodce. Provisioner je idempotentní (překryvový
     * lookup), opakované uložení nic nezduplikuje.
     *
     * afterSave běží po commitu — chyba provisioningu NESMÍ shodit uložení
     * registrace (bublala by uživateli jako 500 nad už uloženými daty);
     * jen log, období dorovná další ds-upgrade.
     */
    public function afterSave(array $data): void
    {
        if ($this->db === null) {
            return;
        }

        try {
            $provisioner = new VatPeriodsProvisioner(new DataSourceConnection($this->db));
            $provisioner->provision();
        } catch (\Throwable $e) {
            ErrorLogger::logException(
                $e,
                'VatPeriodsProvisioner failed after saving VAT registration #' . (int) ($data['id'] ?? 0),
            );
        }
    }
}
