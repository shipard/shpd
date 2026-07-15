<?php
declare(strict_types=1);

namespace Shipard\Module\Base\Registry\Mcp;

use Shipard\Api\Mcp\McpInvocationContext;
use Shipard\Api\Mcp\McpTool;
use Shipard\Core\Document\DocStateConfig;

/**
 * Čtecí MCP nástroj: vyhledávání trvalých dokumentů firmy ve Spisovně
 * (`base_registry_documents`) — fulltext přes hlavičku (ft_head) i text
 * příloh (ft_text), filtry druh / šanon dle názvu / partner / platnost.
 * `expiring_within_days` je zkratka pro blížící se expirace; bez parametru
 * se platnost nefiltruje (nástroj horizont nehádá).
 */
final class RegistrySearchTool implements McpTool
{
	private const int DEFAULT_LIMIT = 20;
	private const int MAX_LIMIT = 50;

	public function isReadOnly(): bool
	{
		return true;
	}

	public function name(): string
	{
		return 'registry_search';
	}

	public function description(): string
	{
		return 'Vyhledá trvalé dokumenty firmy ve Spisovně — smlouvy, pojistky, '
			. 'revize/certifikáty, cenové nabídky, úřední písemnosti. Fulltext '
			. '`query` hledá v titulku, č. j., AI shrnutí i v TEXTU PŘÍLOH (PDF). '
			. '`partner` je ID osoby z `persons_search`; `binder_name` je název '
			. 'šanonu (ne ID). `expiring_within_days=N` vrátí dokumenty, kterým '
			. 'platnost končí do N dní (vč. už prošlých). Nepoužívej pro účetní '
			. 'doklady/faktury (`documents_search`), osoby (`persons_search`/'
			. '`persons_get`) ani pro došlou poštu (`mail_list_pending`).';
	}

	public function inputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'query'                => ['type' => 'string', 'description' => 'Fulltext: titulek, č. j., AI shrnutí a text příloh'],
				'doc_kind'             => ['type' => 'string', 'description' => "Druh dokumentu: 'contract' (smlouva), 'insurance' (pojistka), 'quotation' (cenová nabídka), 'certificate' (revize/certifikát), 'official' (úřední písemnost), 'other'"],
				'binder_name'          => ['type' => 'string', 'description' => 'Název šanonu (case-insensitive); nenalezený šanon = prázdný výsledek'],
				'partner'              => ['type' => 'integer', 'description' => 'ID osoby (partnera) z persons_search'],
				'valid_to_before'      => ['type' => 'string', 'description' => 'Konec platnosti do (YYYY-MM-DD)'],
				'valid_to_after'       => ['type' => 'string', 'description' => 'Konec platnosti od (YYYY-MM-DD)'],
				'expiring_within_days' => ['type' => 'integer', 'minimum' => 0, 'description' => 'Jen dokumenty s platností končící do N dní (vč. prošlých)'],
				'state'                => ['type' => 'string', 'enum' => ['filed', 'active', 'all'], 'default' => 'filed', 'description' => 'filed = jen Zařazeno (default); active = vše mimo Koš; all = vč. Koše'],
				'limit'                => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_LIMIT, 'default' => self::DEFAULT_LIMIT],
				'offset'               => ['type' => 'integer', 'minimum' => 0, 'default' => 0],
			],
		];
	}

	public function call(array $arguments, McpInvocationContext $ctx): array
	{
		$limit  = max(1, min(self::MAX_LIMIT, (int) ($arguments['limit'] ?? self::DEFAULT_LIMIT)));
		$offset = max(0, (int) ($arguments['offset'] ?? 0));
		$today  = (string) $ctx->db->fetchSingle('SELECT CURDATE()');

		// Whitelistovaný WHERE builder: každý filtr přidá fragment + parametry.
		$where  = [];
		$params = [];

		$state = (string) ($arguments['state'] ?? 'filed');
		if ($state === 'filed') {
			$where[] = '`d`.`docState` = 40';
		} elseif ($state !== 'all') {
			$where[] = '`d`.`docState` != 90'; // active
		}

		if (!empty($arguments['doc_kind'])) {
			$where[]  = '`d`.`doc_kind` = %s';
			$params[] = (string) $arguments['doc_kind'];
		}
		if (isset($arguments['partner'])) {
			$where[]  = '`d`.`partner` = %i';
			$params[] = (int) $arguments['partner'];
		}
		if (!empty($arguments['valid_to_before'])) {
			$where[]  = '`d`.`valid_to` <= %s';
			$params[] = (string) $arguments['valid_to_before'];
		}
		if (!empty($arguments['valid_to_after'])) {
			$where[]  = '`d`.`valid_to` >= %s';
			$params[] = (string) $arguments['valid_to_after'];
		}
		if (isset($arguments['expiring_within_days'])) {
			$horizon  = date('Y-m-d', strtotime($today . ' +' . max(0, (int) $arguments['expiring_within_days']) . ' days'));
			$where[]  = '`d`.`valid_to` IS NOT NULL AND `d`.`valid_to` <= %s';
			$params[] = $horizon;
		}
		if (!empty($arguments['query'])) {
			$term     = trim((string) $arguments['query']);
			$where[]  = '(MATCH (`d`.`title`, `d`.`ref_number`, `d`.`ai_summary`) AGAINST (%s)'
				. ' OR MATCH (`d`.`extracted_text`) AGAINST (%s))';
			$params[] = $term;
			$params[] = $term;
		}

		if (!empty($arguments['binder_name'])) {
			// LLM zná názvy šanonů, ne id; collation utf8mb4_czech_ci je CI.
			$binderIds = $ctx->db->fetchAll(
				'SELECT `id` FROM `base_registry_binders` WHERE `name` = %s AND `docState` IN %in',
				trim((string) $arguments['binder_name']),
				[10, 40, 80],
			);
			if ($binderIds === []) {
				return [
					'summary' => "Šanon '" . trim((string) $arguments['binder_name'])
						. "' nebyl nalezen — zkontroluj přesný název (živé šanony).",
					'items'      => [],
					'pagination' => ['limit' => $limit, 'offset' => $offset, 'returned' => 0, 'has_more' => false],
				];
			}
			$where[]  = '`d`.`binder` IN %in';
			$params[] = array_map(static fn ($r) => (int) $r['id'], $binderIds);
		}

		$whereSql = $where === [] ? '1' : implode(' AND ', $where);

		$sql = 'SELECT `d`.`id`, `d`.`title`, `d`.`doc_kind`, `d`.`ref_number`, `d`.`partner`,'
			. ' `d`.`valid_from`, `d`.`valid_to`, `d`.`ai_summary`, `d`.`docState`,'
			. ' `p`.`full_name` AS `partner_name`, `b`.`name` AS `binder_name`'
			. ' FROM `base_registry_documents` `d`'
			. ' LEFT JOIN `base_persons_persons` `p` ON `p`.`id` = `d`.`partner`'
			. ' LEFT JOIN `base_registry_binders` `b` ON `b`.`id` = `d`.`binder`'
			. " WHERE {$whereSql}"
			. ' ORDER BY `d`.`valid_to` IS NULL ASC, `d`.`valid_to` ASC, `d`.`id` DESC'
			. ' LIMIT %i OFFSET %i';

		$rows = $ctx->db->fetchAll($sql, ...[...$params, $limit + 1, $offset]);

		$hasMore = count($rows) > $limit;
		if ($hasMore) {
			$rows = array_slice($rows, 0, $limit);
		}

		$kinds    = $ctx->config?->cfgItem('base.registry.docKinds');
		$stateCfg = DocStateConfig::fromCfgItem($ctx->config?->cfgItem('core.system.docStatesArchive'));

		$expiredCount = 0;
		$items = array_map(function (array $r) use ($kinds, $stateCfg, $today, &$expiredCount): array {
			$docState = (int) ($r['docState'] ?? 0);
			$validTo  = $this->toDate($r['valid_to'] ?? null);
			$expired  = $validTo !== null && $validTo < $today;
			if ($expired) {
				$expiredCount++;
			}

			$kind    = (string) ($r['doc_kind'] ?? '');
			$partner = $r['partner']
				? ['id' => (int) $r['partner'], 'full_name' => (string) ($r['partner_name'] ?? '')]
				: null;
			$title   = (string) ($r['title'] ?? '');
			$summary = (string) ($r['ai_summary'] ?? '');

			return [
				'ref'            => ['type' => 'registry_document', 'id' => (int) $r['id']],
				'full_name'      => trim($title . ($partner ? ' — ' . $partner['full_name'] : '')),
				'doc_kind'       => $kind,
				'doc_kind_label' => is_array($kinds) ? (string) ($kinds[$kind]['name'] ?? $kind) : $kind,
				'binder'         => $r['binder_name'] !== null ? (string) $r['binder_name'] : null,
				'partner'        => $partner,
				'ref_number'     => $r['ref_number'] !== null && $r['ref_number'] !== '' ? (string) $r['ref_number'] : null,
				'valid_from'     => $this->toDate($r['valid_from'] ?? null),
				'valid_to'       => $validTo,
				'expired'        => $expired,
				'ai_summary'     => $summary === '' ? null
					: (mb_strlen($summary) > 200 ? mb_substr($summary, 0, 200) . '…' : $summary),
				'state_label'    => $stateCfg->getState($docState)['stateName'] ?? (string) $docState,
			];
		}, $rows);

		$shown = count($items);

		return [
			'summary' => $shown === 0
				? 'Nenalezeny žádné dokumenty ve Spisovně.'
				: "{$shown} dokumentů" . ($expiredCount > 0 ? ", z toho {$expiredCount} s prošlou platností." : '.'),
			'items'      => $items,
			'pagination' => [
				'limit'    => $limit,
				'offset'   => $offset,
				'returned' => $shown,
				'has_more' => $hasMore,
			],
		];
	}

	/** `date` sloupce chodí z Dibi jako DateTime — normalizace na YYYY-MM-DD. */
	private function toDate(mixed $value): ?string
	{
		if ($value instanceof \DateTimeInterface) {
			return $value->format('Y-m-d');
		}
		$s = (string) ($value ?? '');
		return $s === '' ? null : substr($s, 0, 10);
	}
}
