<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Mcp;

use Shipard\Api\Mcp\McpInvocationContext;
use Shipard\Api\Mcp\McpTool;
use Shipard\Module\Core\Mail\ExtractedDocumentApplier;
use Shipard\Module\Core\Mail\ExtractedDocumentDocument;

/**
 * Zápisový MCP nástroj: z analyzované došlé pošty založí KONCEPT dokladu
 * (faktury) z extrahovaných dokumentů zprávy. Vždy `autoCreateMode='safe'`
 * + `targetDocState=10` (Koncept) — AI nikdy nefinalizuje ani nezakládá
 * master data. Reference vyžadující rozhodnutí → koncept se nezaloží a
 * nástroj nahlásí, co dořešit ručně.
 *
 * Sdílí apply jádro s HTTP endpointem přes {@see ExtractedDocumentApplier}.
 * Závislost jde konstruktorem (nullable — bez ConfigRuntime degraduje na
 * graceful obálku, nepadá).
 */
final class MailDraftDocumentTool implements McpTool
{
	/** Akční statusy extrahovaných dokladů (čekají na akci). */
	private const ACTIONABLE_STATUSES = [
		ExtractedDocumentDocument::STATUS_READY_TO_APPLY,
		ExtractedDocumentDocument::STATUS_PENDING_REVIEW,
		ExtractedDocumentDocument::STATUS_LOW_CONFIDENCE,
	];

	public function __construct(private readonly ?ExtractedDocumentApplier $applier) {}

	public function name(): string
	{
		return 'mail_draft_document';
	}

	public function description(): string
	{
		return 'Z analyzované došlé pošty založí KONCEPT dokladu (faktury) z '
			. 'extrahovaných dokumentů zprávy. Doklad vznikne jako koncept k '
			. 'revizi — AI ho NIKDY nefinalizuje ani neúčtuje. Existující '
			. 'dodavatele a položky napáruje; nové NEzakládá — pokud reference '
			. 'vyžaduje rozhodnutí, koncept k ní nezaloží a nahlásí, co je třeba '
			. 'dořešit ručně v aplikaci. `message_id` získáš z `mail_list_pending` '
			. '(zprávy s `pending_extracted_count > 0`). Nepoužívej na zprávy bez '
			. 'analýzy.';
	}

	public function inputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'message_id' => ['type' => 'integer', 'description' => 'ID zprávy z mail_list_pending'],
				'extracted_document_id' => ['type' => 'integer', 'description' => 'Volitelně: založit jen tento jeden extrahovaný doklad; jinak všechny čekající ze zprávy'],
			],
			'required' => ['message_id'],
		];
	}

	public function call(array $arguments, McpInvocationContext $ctx): array
	{
		if ($this->applier === null) {
			return [
				'summary'    => 'Zakládání konceptů není v tomto kontextu dostupné.',
				'items'      => [],
				'pagination' => null,
			];
		}

		if (!array_key_exists('message_id', $arguments)) {
			throw new \InvalidArgumentException('Missing required parameter: message_id');
		}
		$messageId = (int) $arguments['message_id'];
		$onlyExtractedId = isset($arguments['extracted_document_id'])
			? (int) $arguments['extracted_document_id']
			: null;

		// Vyber kandidáty zprávy. Když je zadán extracted_document_id, vezmi jen
		// ten (a ověř, že patří zprávě); jinak jen akční statusy (10/20/30).
		if ($onlyExtractedId !== null) {
			$rows = $ctx->db->fetchAll(
				'SELECT `id`, `doc_type`, `status` FROM `core_mail_extracted_documents`'
				. ' WHERE `id` = %i AND `message` = %i',
				$onlyExtractedId, $messageId,
			);
		} else {
			$rows = $ctx->db->fetchAll(
				'SELECT `id`, `doc_type`, `status` FROM `core_mail_extracted_documents`'
				. ' WHERE `message` = %i AND `status` IN %in'
				. ' ORDER BY `id` ASC',
				$messageId, self::ACTIONABLE_STATUSES,
			);
		}

		$userId = $ctx->auth->userId;
		$items = [];
		$drafted = 0;
		$needsResolve = 0;
		$skipped = 0;

		foreach ($rows as $row) {
			$extractedId = (int) $row['id'];
			$status = (int) $row['status'];
			$docType = $row['doc_type'] ?: null;
			$extractedRef = ['type' => 'extracted_document', 'id' => $extractedId];

			// Ne-akční status (applied/rejected/superseded/ai_failed) → přeskoč.
			if (!in_array($status, self::ACTIONABLE_STATUSES, true)) {
				$skipped++;
				$items[] = [
					'extracted_ref' => $extractedRef,
					'drafted'       => false,
					'skipped'       => true,
					'reason'        => $this->skipReason($status),
				];
				continue;
			}

			$outcome = $this->applier->apply(
				$extractedId, $userId, null,
				['autoCreateMode' => 'safe', 'targetDocState' => 10],
			);

			if ($outcome->ok) {
				// Idempotent = už založeno dříve; jinak nový koncept.
				if ($outcome->idempotent) {
					$skipped++;
					$items[] = [
						'extracted_ref' => $extractedRef,
						'drafted'       => false,
						'skipped'       => true,
						'reason'        => 'Doklad už byl z tohoto extrahovaného dokumentu založen.',
						'document_ref'  => ['type' => 'document', 'id' => (int) ($outcome->savedDocId ?? 0)],
					];
					continue;
				}
				$drafted++;
				$items[] = [
					'extracted_ref' => $extractedRef,
					'drafted'       => true,
					'document_ref'  => ['type' => 'document', 'id' => (int) ($outcome->savedDocId ?? 0)],
					'doc_type'      => $docType,
				];
				continue;
			}

			if ($outcome->errorCode === 'unresolved_required') {
				$needsResolve++;
				$items[] = [
					'extracted_ref'    => $extractedRef,
					'drafted'          => false,
					'needs_resolution' => true,
					'reason'           => 'Reference vyžadují rozhodnutí — dořeš v aplikaci.',
					'unresolved'       => $this->unresolvedFrom($outcome->canonical),
				];
				continue;
			}

			// Ostatní chyby (validation_failed, conflict, internal_error, …).
			$skipped++;
			$items[] = [
				'extracted_ref' => $extractedRef,
				'drafted'       => false,
				'skipped'       => true,
				'reason'        => $outcome->errorMessage ?? ($outcome->errorCode ?? 'Apply selhal'),
			];
		}

		return [
			'summary' => "Z pošty #{$messageId}: založeno {$drafted} konceptů"
				. ($needsResolve > 0 ? ", {$needsResolve} čeká na ruční dořešení" : '')
				. ($skipped > 0 ? ", {$skipped} přeskočeno" : '')
				. '.',
			'items'      => $items,
			'pagination' => null,
		];
	}

	/**
	 * Kurátorovaný seznam nevyřešených referencí z `_resolve.issues`
	 * (jen severity=error) — cesta + lidský popis, ať agent ví, co chybí.
	 *
	 * @param array<string, mixed>|null $canonical
	 * @return array<int, array{path: string, message: string}>
	 */
	private function unresolvedFrom(?array $canonical): array
	{
		$issues = $canonical['_resolve']['issues'] ?? null;
		if (!is_array($issues)) {
			return [];
		}
		$out = [];
		foreach ($issues as $issue) {
			if (!is_array($issue) || ($issue['severity'] ?? null) !== 'error') {
				continue;
			}
			$out[] = [
				'path'    => (string) ($issue['path'] ?? ''),
				'message' => (string) ($issue['message'] ?? ''),
			];
		}
		return $out;
	}

	private function skipReason(int $status): string
	{
		return match ($status) {
			ExtractedDocumentDocument::STATUS_APPLIED    => 'Už aplikováno.',
			ExtractedDocumentDocument::STATUS_REJECTED   => 'Zamítnuto.',
			ExtractedDocumentDocument::STATUS_SUPERSEDED => 'Nahrazeno novější analýzou.',
			ExtractedDocumentDocument::STATUS_AI_FAILED  => 'AI extrakce selhala — použij reanalýzu.',
			default                                      => 'Není v akčním stavu.',
		};
	}
}
