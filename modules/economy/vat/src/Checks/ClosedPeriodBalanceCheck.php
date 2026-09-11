<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Vat\Checks;

use Shipard\Core\Alerts\AlertCheck;
use Shipard\Core\Alerts\AlertFinding;
use Shipard\Module\Economy\Vat\ClosedPeriodBalanceService;

/**
 * Alert `economy.vat.closed_period_balance` (#55 D31): za podané přiznání
 * zůstává na analytice 343 nenulový zůstatek — chybí zaúčtování přiznání,
 * nebo se DPH po podání změnila. Finding per (instance, účet); zmizí až
 * po nápravě (zaúčtování, vrácení změny, nebo dodatečné podání + jeho
 * zaúčtování). Obálka nad ClosedPeriodBalanceService.
 */
class ClosedPeriodBalanceCheck extends AlertCheck
{
    private const TABLE_ID = 441;

    public function run(): array
    {
        $isCs = $this->language === 'cs';
        $findings = [];
        foreach ($this->findings() as $f) {
            $id = (int) $f['period_id'];
            $name = (string) $f['period_name'];
            $account = (string) $f['account'];
            $amount = ClosedPeriodBalanceService::formatAmount((float) $f['balance']);

            $findings[] = new AlertFinding(
                findingKey: 'period:' . $id . ':account:' . $account,
                title: $isCs
                    ? "Nevypořádaná DPH za podané přiznání {$name}: {$account}"
                    : "Unsettled VAT for filed return {$name}: {$account}",
                message: $isCs
                    ? "Za podané přiznání {$name} zůstává na {$account} {$amount} Kč — chybí zaúčtování"
                        . ' přiznání, nebo se DPH po podání změnila.'
                    : "Filed return {$name} leaves {$amount} CZK on {$account} — the return is not posted,"
                        . ' or VAT changed after filing.',
                severity: 'warning',
                subjectTableId: self::TABLE_ID,
                subjectRowId: $id,
                actions: [[
                    'id'       => 'open_period',
                    'label'    => $isCs ? 'Otevřít tvrzení' : 'Open period',
                    'kind'     => 'open_viewer',
                    'viewerId' => 'economy.vat.reportPeriods',
                    'recordId' => $id,
                    'primary'  => true,
                ]],
                context: ['account' => $account, 'balance' => (float) $f['balance']],
            );
        }
        return $findings;
    }

    /**
     * Seam pro testy.
     *
     * @return list<array{period_id: int, period_name: string, date_end: string, account: string, balance: float}>
     */
    protected function findings(): array
    {
        return (new ClosedPeriodBalanceService($this->db))->findings();
    }
}
