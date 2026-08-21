<?php
declare(strict_types=1);

namespace Shipard\Module\Economy\Accounting\Mcp;

use Shipard\Api\Mcp\McpInvocationContext;
use Shipard\Api\Mcp\McpTool;

/**
 * Čtecí MCP nástroj: spustí report a vrátí kompletní `ReportResult` (D11).
 * Generický pro všechny deklarované reporty — parametry validuje
 * `ReportRunner` z deklarace, nevalidní vstup propadá jako
 * `InvalidArgumentException` (→ -32602).
 */
final class ReportRunTool implements McpTool
{
	public function __construct(private readonly ?ReportToolSupport $support = null) {}

	public function isReadOnly(): bool
	{
		return true;
	}

	public function name(): string
	{
		return 'report_run';
	}

	public function description(): string
	{
		return 'Spustí účetní report (hlavní kniha, výsledovka, rozvaha) za zadané '
			. 'období a vrátí strukturovaný výsledek: sloupce, řádky s hodnotami '
			. 'md/d/balance per účet, zprávy a status. Id reportů a dostupné '
			. 'fiskální roky zjistíš nástrojem report_list. DŮLEŽITÉ: výsledek se '
			. 'status "errors" NEJSOU spolehlivá čísla — chybu vždy nahlas '
			. 'uživateli a nepoužívej je mlčky pro výpočty; status "warnings" '
			. 'u odpovědi zmiň. Pro přehledy a srovnání používej detail '
			. '"synthetic" (menší výstup); "analytic" jen při dohledávání '
			. 'konkrétního analytického účtu.';
	}

	public function inputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'reportId'   => ['type' => 'string', 'description' => 'Id reportu z report_list (např. economy.accounting.balanceSheet)'],
				'fiscalYear' => ['type' => 'integer', 'description' => 'Fiskální rok dle názvu, např. 2026 (dostupné roky viz report_list)'],
				'monthFrom'  => ['type' => 'integer', 'minimum' => 1, 'maximum' => 12, 'description' => 'První fiskální měsíc intervalu'],
				'monthTo'    => ['type' => 'integer', 'minimum' => 1, 'maximum' => 12, 'description' => 'Poslední fiskální měsíc intervalu'],
				'detail'     => [
					'type'        => 'string',
					'enum'        => ['analytic', 'synthetic'],
					'default'     => 'synthetic',
					'description' => 'Úroveň detailu; default synthetic = agregace na syntetické účty (menší výstup, pro LLM přehledy), analytic = plné analytiky.',
				],
			],
			'required' => ['reportId', 'fiscalYear', 'monthFrom', 'monthTo'],
		];
	}

	public function call(array $arguments, McpInvocationContext $ctx): array
	{
		if ($this->support === null) {
			throw new \RuntimeException('report_run: missing ReportToolSupport wiring');
		}

		foreach (['reportId', 'fiscalYear', 'monthFrom', 'monthTo'] as $required) {
			if (!array_key_exists($required, $arguments)) {
				throw new \InvalidArgumentException("Missing required parameter: {$required}");
			}
		}

		$reportId   = (string) $arguments['reportId'];
		$definition = $this->support->registry()->get($reportId);
		if ($definition === null) {
			throw new \InvalidArgumentException(
				"Unknown report '{$reportId}' — call report_list for available reports",
			);
		}

		$rawParams = [
			'fiscalYear' => (string) $arguments['fiscalYear'],
			'monthFrom'  => $arguments['monthFrom'],
			'monthTo'    => $arguments['monthTo'],
		];
		// Default pro MCP je synthetic (menší výstup pro LLM; UI/CLI default je
		// analytic) — podsouvá se jen reportům, které parametr deklarují.
		foreach ($definition->params as $param) {
			if ($param['id'] === 'detail') {
				$rawParams['detail'] = (string) ($arguments['detail'] ?? 'synthetic');
				break;
			}
		}

		$result = $this->support->runner($ctx)->run($reportId, $rawParams);

		return [
			'summary' => sprintf(
				'%s %s/%d–%d, status: %s (%d messages)',
				$definition->name,
				$result->params['period']['fiscalYear'],
				$result->params['period']['monthFrom'],
				$result->params['period']['monthTo'],
				$result->status->value,
				count($result->messages),
			),
			'report' => $result->toArray(),
		];
	}
}
