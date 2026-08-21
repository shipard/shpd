<?php

declare(strict_types=1);

namespace Shipard\Core\Reports;

/**
 * Celkový stav výsledku reportu — vždy odvozený z nejvyšší severity zpráv
 * (`ReportStatus::fromMessages()`), nikdy zadávaný ručně. Viz docs/reports.md
 * §3.3 (D15).
 */
enum ReportStatus: string
{
    case Ok = 'ok';
    case Warnings = 'warnings';
    case Errors = 'errors';

    /** @param ReportMessage[] $messages */
    public static function fromMessages(array $messages): self
    {
        $status = self::Ok;
        foreach ($messages as $message) {
            if ($message->severity === ReportMessageSeverity::Error) {
                return self::Errors;
            }
            if ($message->severity === ReportMessageSeverity::Warning) {
                $status = self::Warnings;
            }
        }
        return $status;
    }
}
