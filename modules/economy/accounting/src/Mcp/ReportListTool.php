<?php
declare(strict_types=1);

namespace Shipard\Module\Economy\Accounting\Mcp;

use Shipard\Api\Mcp\McpInvocationContext;
use Shipard\Api\Mcp\McpTool;
use Shipard\Core\Reports\DbFiscalPeriodProvider;
use Shipard\Core\Reports\DbReportPeriodProvider;

/**
 * Čtecí MCP nástroj: katalog deklarovaných reportů (D11) — id, název,
 * granularity období, schéma parametrů + dostupné fiskální roky, aby model
 * uměl rovnou sestavit validní volání `report_run` bez hádání roků.
 */
final class ReportListTool implements McpTool
{
	public function __construct(private readonly ?ReportToolSupport $support = null) {}

	public function isReadOnly(): bool
	{
		return true;
	}

	public function name(): string
	{
		return 'report_list';
	}

	public function description(): string
	{
		return 'Vrátí katalog reportů (hlavní kniha, výsledovka, rozvaha, živé '
			. 'výstupy DPH): id reportu, název, zdroj období (periodSource '
			. 'fiscal | vatPeriod), granularity, schéma parametrů a dostupné '
			. 'fiskální roky; pro vatPeriod reporty navíc vatReportType a registrace '
			. 'DPH s instancemi tvrzení (vatRegistrations[].periods, každá s type '
			. 'return|cs|rs — report bere jen instance svého typu). Použij jako první krok, když uživatel '
			. 'chce čísla z účetnictví nebo DPH po obdobích — data pak vrací '
			. 'nástroj report_run. NEpoužívej pro seznam dokladů či faktur '
			. '(od toho je documents_search).';
	}

	public function inputSchema(): array
	{
		return ['type' => 'object'];
	}

	public function call(array $arguments, McpInvocationContext $ctx): array
	{
		if ($this->support === null) {
			throw new \RuntimeException('report_list: missing ReportToolSupport wiring');
		}

		$definitions = $this->support->registry()->getAll();
		$fiscalYears = (new DbFiscalPeriodProvider($ctx->db))->regularYears();

		// Registrace DPH jen když je nějaký vatPeriod report registrovaný
		// a tabulky existují — jinak by dotaz padal na DS bez economy.codebooks.
		$hasVatPeriod = false;
		foreach ($definitions as $definition) {
			if ($definition->periodSource === 'vatPeriod') {
				$hasVatPeriod = true;
				break;
			}
		}
		$vatRegistrations = [];
		if ($hasVatPeriod && isset($ctx->tables['economy_vat_report_periods'])) {
			$vatRegistrations = (new DbReportPeriodProvider($ctx->db))->registrationsWithPeriods();
		}

		$items = [];
		foreach ($definitions as $d) {
			$item = [
				'reportId'            => $d->id,
				'name'                => $d->name,
				'periodSource'        => $d->periodSource,
				'periodGranularities' => $d->periodGranularities,
				'params'              => $d->params,
			];
			if ($d->periodSource === 'vatPeriod') {
				$item['vatReportType'] = $d->vatReportType;
			} else {
				$item['fiscalYears'] = $fiscalYears;
			}
			$items[] = $item;
		}

		$out = [
			'summary' => sprintf(
				'K dispozici %d reportů; fiskální roky: %s.%s',
				count($definitions),
				$fiscalYears === []
					? 'žádné'
					: implode(', ', array_map(
						static fn (array $y): string => "{$y['name']} ({$y['months']} měsíců)",
						$fiscalYears,
					)),
				$vatRegistrations === []
					? ''
					: sprintf(' Registrace DPH: %d (viz vatRegistrations).', count($vatRegistrations)),
			),
			'items' => $items,
		];
		if ($vatRegistrations !== []) {
			$out['vatRegistrations'] = $vatRegistrations;
		}
		return $out;
	}
}
