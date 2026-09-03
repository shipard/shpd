<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Vat\Checks;

use Shipard\Core\Alerts\AlertCheck;
use Shipard\Core\Alerts\AlertFinding;

/**
 * Koncepty instancí tvrzení (docState 10) — typicky založené on-demand při
 * uložení dokladu, pro který instance chyběla (issue #55, D9). Finding per
 * instance s akcí otevřít formulář; po potvrzení (V pořádku) alert zmizí.
 */
final class DraftReportPeriodsCheck extends AlertCheck
{
    private const TABLE_ID = 441;

    public function run(): array
    {
        $rows = $this->db->fetchAll(
            'SELECT [p].[id], [p].[report_type], [p].[name], [p].[date_begin], [p].[date_end],'
            . ' [r].[name] AS [registration_name]'
            . ' FROM [economy_vat_report_periods] [p]'
            . ' LEFT JOIN [economy_codebooks_vat_registrations] [r] ON [r].[id] = [p].[vat_registration]'
            . ' WHERE [p].[docState] = 10 ORDER BY [p].[date_begin] DESC, [p].[id] DESC',
        );
        if ($rows === []) {
            return [];
        }

        $isCs = $this->language === 'cs';
        $typeLabels = $this->typeLabels();

        $findings = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $type = (string) $row['report_type'];
            $typeLabel = $typeLabels[$type] ?? $type;
            $name = (string) $row['name'];
            $range = $this->iso($row['date_begin']) . ' – ' . $this->iso($row['date_end']);
            $registration = (string) ($row['registration_name'] ?? '');

            $findings[] = new AlertFinding(
                findingKey: 'period:' . $id,
                title: $isCs
                    ? "Koncept tvrzení: {$typeLabel} {$name}"
                    : "Draft report period: {$typeLabel} {$name}",
                message: $isCs
                    ? "Instance ({$range}, {$registration}) vznikla automaticky při uložení dokladu."
                        . ' Zkontrolujte rozsah a potvrďte ji, nebo doklady přepřiřaďte.'
                    : "The instance ({$range}, {$registration}) was created on demand while saving a document."
                        . ' Review the range and confirm it, or reassign the documents.',
                severity: 'warning',
                subjectTableId: self::TABLE_ID,
                subjectRowId: $id,
                actions: [[
                    'id'      => 'open_period',
                    'label'   => $isCs ? 'Otevřít tvrzení' : 'Open period',
                    'kind'    => 'open_form',
                    'target'  => [
                        'table' => 'economy_vat_report_periods',
                        'mode'  => 'edit',
                        'id'    => $id,
                    ],
                    'primary' => true,
                ]],
            );
        }
        return $findings;
    }

    /** @return array<string, string> */
    private function typeLabels(): array
    {
        $cfg = $this->config->cfgItem('economy.vat.reportTypes');
        $labels = [];
        if (is_array($cfg)) {
            foreach ($cfg as $key => $entry) {
                if (is_array($entry) && isset($entry['name'])) {
                    $labels[(string) $key] = (string) $entry['name'];
                }
            }
        }
        return $labels;
    }

    private function iso(mixed $value): string
    {
        return $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : substr((string) $value, 0, 10);
    }
}
