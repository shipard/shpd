<?php
declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Mcp;

use Shipard\Api\Mcp\McpInvocationContext;
use Shipard\Api\Mcp\McpTool;
use Shipard\Core\Document\DocStateConfig;

/**
 * Čtecí MCP nástroj: došlá pošta čekající na pozornost (`docState != 40`,
 * nezpracovaná). U každé zprávy stav AI analýzy (`none/pending/success/failed`)
 * z „current" běhu a počet extrahovaných dokladů čekajících na akci
 * (stavy 10/20/30). `only_actionable` zúží na zprávy s akčními doklady.
 * Varianta C z designu Fáze 2.
 */
final class MailListPendingTool implements McpTool
{
	private const int DEFAULT_LIMIT = 20;
	private const int MAX_LIMIT = 50;

	public function isReadOnly(): bool
	{
		return true;
	}

	public function name(): string
	{
		return 'mail_list_pending';
	}

	public function description(): string
	{
		return 'Vrátí došlou poštu, která ještě čeká na pozornost (není '
			. 'zpracovaná). U každé zprávy uvádí stav AI analýzy a počet '
			. 'extrahovaných dokladů čekajících na akci (potvrzení/zamítnutí). '
			. '`only_actionable=true` zúží na zprávy, kde nějaký extrahovaný '
			. 'doklad čeká na akci — typicky to, co má agent vyřešit.';
	}

	public function inputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'only_actionable' => ['type' => 'boolean', 'default' => false, 'description' => 'Jen zprávy s extrahovanými doklady čekajícími na akci'],
				'limit'           => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_LIMIT, 'default' => self::DEFAULT_LIMIT],
				'offset'          => ['type' => 'integer', 'minimum' => 0, 'default' => 0],
			],
		];
	}

	public function call(array $arguments, McpInvocationContext $ctx): array
	{
		$limit          = max(1, min(self::MAX_LIMIT, (int) ($arguments['limit'] ?? self::DEFAULT_LIMIT)));
		$offset         = max(0, (int) ($arguments['offset'] ?? 0));
		$onlyActionable = !empty($arguments['only_actionable']);

		// „Current" analýza = MAX(analyzed_at) per message, počet akčních dokladů
		// agregován korelovaným subdotazem (žádné N+1). only_actionable filtruje
		// nad derived tabulkou, ať LIMIT/OFFSET (a has_more) sedí.
		$inner = 'SELECT `m`.`id`, `m`.`subject`, `m`.`sender_name`, `m`.`sender_email`,'
			. ' `m`.`sender_person`, `m`.`received_at`, `m`.`mailbox`, `mb`.`name` AS `mailbox_name`,'
			. ' `m`.`docState`,'
			. ' (SELECT `a`.`status` FROM `core_mail_message_analyses` `a`'
			. '    WHERE `a`.`message` = `m`.`id` ORDER BY `a`.`analyzed_at` DESC LIMIT 1) AS `analysis_status_raw`,'
			. ' (SELECT COUNT(*) FROM `core_mail_extracted_documents` `e`'
			. '    WHERE `e`.`message` = `m`.`id` AND `e`.`status` IN (10, 20, 30)) AS `pending_extracted_count`'
			. ' FROM `core_mail_incoming_messages` `m`'
			. ' LEFT JOIN `core_mail_mailboxes` `mb` ON `mb`.`id` = `m`.`mailbox`'
			. ' WHERE `m`.`docState` != 40';

		$sql = "SELECT * FROM ({$inner}) `t`"
			. ($onlyActionable ? ' WHERE `t`.`pending_extracted_count` > 0' : '')
			. ' ORDER BY `t`.`received_at` DESC'
			. ' LIMIT %i OFFSET %i';

		$rows = $ctx->db->fetchAll($sql, $limit + 1, $offset);

		$hasMore = count($rows) > $limit;
		if ($hasMore) {
			$rows = array_slice($rows, 0, $limit);
		}

		$stateCfg = DocStateConfig::fromCfgItem($ctx->config?->cfgItem('core.mail.docStatesIncoming'));

		$actionableMsgs = 0;
		$items = array_map(function (array $r) use ($stateCfg, &$actionableMsgs): array {
			$docState = (int) ($r['docState'] ?? 0);
			$pending  = (int) ($r['pending_extracted_count'] ?? 0);
			if ($pending > 0) {
				$actionableMsgs++;
			}

			return [
				'ref'                     => ['type' => 'mail_message', 'id' => (int) $r['id']],
				'full_name'               => (string) ($r['subject'] ?? ''),
				'subject'                 => $r['subject'] ?: null,
				'sender'                  => [
					'name'   => $r['sender_name'] ?: null,
					'email'  => $r['sender_email'] ?: null,
					'person' => $r['sender_person'] ? ['id' => (int) $r['sender_person']] : null,
				],
				'received_at'             => $r['received_at'] ?: null,
				'mailbox'                 => $r['mailbox_name'] ?: null,
				'state_label'             => $stateCfg->getState($docState)['stateName'] ?? (string) $docState,
				'analysis_status'         => $this->mapAnalysisStatus($r['analysis_status_raw'] ?? null),
				'pending_extracted_count' => $pending,
			];
		}, $rows);

		$shown = count($items);

		return [
			'summary' => $shown === 0
				? 'Žádná čekající pošta.'
				: "{$shown} čekajících zpráv, {$actionableMsgs} s akčními doklady.",
			'items'      => $items,
			'pagination' => [
				'limit'    => $limit,
				'offset'   => $offset,
				'returned' => $shown,
				'has_more' => $hasMore,
			],
		];
	}

	/** NULL (žádný běh) → none; 1 → pending; 2 → success; 3 → failed. */
	private function mapAnalysisStatus(mixed $raw): string
	{
		return match ((int) $raw) {
			1       => 'pending',
			2       => 'success',
			3       => 'failed',
			default => 'none',
		};
	}
}
