<?php

declare(strict_types=1);

namespace Shipard\Core\Reports;

enum ReportMessageSeverity: string
{
    case Error = 'error';
    case Warning = 'warning';
    case Info = 'info';
}
