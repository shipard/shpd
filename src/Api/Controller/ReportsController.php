<?php

declare(strict_types=1);

namespace Shipard\Api\Controller;

use Shipard\Api\Response;
use Shipard\Core\Reports\FiscalPeriodProvider;
use Shipard\Core\Reports\ReportNotFoundException;
use Shipard\Core\Reports\ReportRegistry;
use Shipard\Core\Reports\ReportRunner;
use Shipard\Core\Reports\ReportPeriodProvider;

/**
 * Endpoints:
 *   GET /_reports              — katalog deklarovaných reportů (lokalizované názvy)
 *   GET /_reports/{reportId}   — spuštění reportu; query = parametry
 *                                (fiscalYear, monthFrom, monthTo + per-report)
 *
 * Výsledek se `status: errors` je HTTP 200 — chyba dat není chyba requestu,
 * konzument čte `status` (D15). 400 jen na nevalidní parametry, 404 na
 * neznámé id reportu.
 */
class ReportsController
{
    public function __construct(
        private readonly ReportRegistry $registry,
        private readonly ReportRunner $runner,
        private readonly ?FiscalPeriodProvider $periodProvider = null,
        private readonly ?ReportPeriodProvider $vatPeriodProvider = null,
    ) {}

    /** GET /_reports */
    public function catalog(): Response
    {
        $items = [];
        $hasVatPeriod = false;
        foreach ($this->registry->getAll() as $definition) {
            $items[] = [
                'id'                  => $definition->id,
                'name'                => $definition->name,
                'periodSource'        => $definition->periodSource,
                'vatReportType'       => $definition->vatReportType,
                'periodGranularities' => $definition->periodGranularities,
                'params'              => $definition->params,
            ];
            $hasVatPeriod = $hasVatPeriod || $definition->periodSource === 'vatPeriod';
        }

        $periods = ['fiscalYears' => $this->periodProvider?->regularYears() ?? []];
        // Registrace DPH s instancemi tvrzení jen když je nějaký vatPeriod
        // report registrovaný — jinak by dotaz padal na DS bez economy.vat tabulek.
        if ($hasVatPeriod && $this->vatPeriodProvider !== null) {
            $periods['vatRegistrations'] = $this->vatPeriodProvider->registrationsWithPeriods();
        }

        return Response::success(['items' => $items, 'periods' => $periods]);
    }

    /**
     * GET /_reports/{reportId}
     *
     * @param array<string, mixed> $rawParams Query parametry requestu.
     */
    public function run(string $reportId, array $rawParams): Response
    {
        try {
            $result = $this->runner->run($reportId, $rawParams);
        } catch (ReportNotFoundException $e) {
            return Response::error('REPORT_NOT_FOUND', $e->getMessage(), 404);
        } catch (\InvalidArgumentException $e) {
            return Response::error('BAD_REQUEST', $e->getMessage(), 400);
        }

        // ReportResult::toArray() beze změn (D4) — jen standardní API obálka.
        return Response::success($result->toArray());
    }
}
