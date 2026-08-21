<?php
declare(strict_types=1);

namespace Shipard\Module\Economy\Accounting\Mcp;

use Shipard\Api\Mcp\McpInvocationContext;
use Shipard\Api\Mcp\McpTool;
use Shipard\Core\Reports\DbFiscalPeriodProvider;
use Shipard\Core\Reports\ReportDefinition;

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
		return 'Vrátí katalog účetních reportů nad účetním deníkem (hlavní kniha, '
			. 'výsledovka, rozvaha): id reportu, název, podporované granularity '
			. 'období, schéma parametrů a dostupné fiskální roky s počtem měsíců. '
			. 'Použij jako první krok, když uživatel chce čísla z účetnictví po '
			. 'obdobích — data pak vrací nástroj report_run. NEpoužívej pro seznam '
			. 'dokladů či faktur (od toho je documents_search).';
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

		return [
			'summary' => sprintf(
				'K dispozici %d reportů; fiskální roky: %s.',
				count($definitions),
				$fiscalYears === []
					? 'žádné'
					: implode(', ', array_map(
						static fn (array $y): string => "{$y['name']} ({$y['months']} měsíců)",
						$fiscalYears,
					)),
			),
			'items' => array_map(static fn (ReportDefinition $d): array => [
				'reportId'            => $d->id,
				'name'                => $d->name,
				'periodGranularities' => $d->periodGranularities,
				'params'              => $d->params,
				'fiscalYears'         => $fiscalYears,
			], $definitions),
		];
	}
}
