<?php
declare(strict_types=1);

namespace Shipard\Module\Base\Persons\Mcp;

use Shipard\Api\Mcp\McpInvocationContext;
use Shipard\Api\Mcp\McpTool;
use Shipard\Module\Base\Persons\PersonType;

/**
 * Čtecí MCP nástroj: detail jedné osoby/firmy z lokální evidence — karta
 * (identita + adresy + banka + kontakty) a počet napojených dokladů. Seznam
 * dokladů NEvkládá (od toho je `documents_search` s `partner`). Varianta A
 * z designu Fáze 2.
 */
final class PersonsGetTool implements McpTool
{
	public function isReadOnly(): bool
	{
		return true;
	}

	public function name(): string
	{
		return 'persons_get';
	}

	public function description(): string
	{
		return 'Vrátí detail jedné osoby/firmy z lokální evidence — identitu '
			. '(jméno, IČO, DIČ, kód osoby), adresy, bankovní spojení, kontakty '
			. 'a počet napojených dokladů. ID osoby získáš z `persons_search`. '
			. 'Pro seznam jejích dokladů zavolej `documents_search` s `partner` '
			. '= toto ID.';
	}

	public function inputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'id' => ['type' => 'integer', 'description' => 'ID osoby z persons_search'],
			],
			'required' => ['id'],
		];
	}

	public function call(array $arguments, McpInvocationContext $ctx): array
	{
		if (!array_key_exists('id', $arguments)) {
			throw new \InvalidArgumentException('Missing required parameter: id');
		}
		$id = (int) $arguments['id'];

		$person = $ctx->db->fetchRow(
			'SELECT `id`, `full_name`, `person_id`, `person_type`, `company_id`,'
			. ' `tax_id`, `vat_id`, `email`, `birth_date`'
			. ' FROM `base_persons_persons` WHERE `id` = %i AND `docState` != 90',
			$id,
		);

		if ($person === null) {
			return [
				'summary'    => "Osoba #{$id} nenalezena.",
				'items'      => [],
				'pagination' => null,
			];
		}

		$addrTypes = $ctx->config?->cfgItem('base.persons.addressTypes') ?? [];

		$addresses = array_map(static function (array $r) use ($addrTypes): array {
			$type = (int) ($r['address_type'] ?? 0);
			return [
				'address_type' => $addrTypes[(string) $type]['name'] ?? null,
				'display_line' => $r['display_line'] ?: null,
				'street'       => $r['street'] ?: null,
				'city'         => $r['city'] ?: null,
				'zip'          => $r['zip'] ?: null,
				'country'      => $r['country'] ?: null,
			];
		}, $ctx->db->fetchAll(
			'SELECT `address_type`, `display_line`, `street`, `city`, `zip`, `country`'
			. ' FROM `base_persons_addresses`'
			. ' WHERE `person` = %i AND `docState` != 90 AND `valid_to` IS NULL'
			. ' ORDER BY `order_pos` ASC, `id` ASC',
			$id,
		));

		$banks = array_map(static fn(array $r): array => [
			'name'           => $r['name'] ?: null,
			'account_number' => $r['account_number'] ?: null,
			'iban'           => $r['iban'] ?: null,
			'bic'            => $r['bic'] ?: null,
			'currency'       => $r['currency'] ?: null,
		], $ctx->db->fetchAll(
			'SELECT `name`, `account_number`, `iban`, `bic`, `currency`'
			. ' FROM `base_persons_bank_accounts`'
			. ' WHERE `person` = %i AND `docState` != 90 AND `valid_to` IS NULL'
			. ' ORDER BY `order_pos` ASC, `id` ASC',
			$id,
		));

		$contacts = array_map(static fn(array $r): array => [
			'name'  => $r['name'] ?: null,
			'role'  => $r['role'] ?: null,
			'email' => $r['email'] ?: null,
			'phone' => $r['phone'] ?: null,
		], $ctx->db->fetchAll(
			'SELECT `name`, `role`, `email`, `phone`'
			. ' FROM `base_persons_contacts`'
			. ' WHERE `person` = %i AND `docState` != 90 AND `valid_to` IS NULL'
			. ' ORDER BY `order_pos` ASC, `id` ASC',
			$id,
		));

		$docCount = (int) $ctx->db->fetchSingle(
			'SELECT COUNT(*) FROM `docs_core_heads`'
			. ' WHERE `partner` = %i AND `docState` != 90',
			$id,
		);

		$fullName  = (string) ($person['full_name'] ?? '');
		$ico       = $person['company_id'] ?: null;
		$dic       = $person['vat_id'] ?: null;
		$typeLabel = (int) ($person['person_type'] ?? 0) === PersonType::Company->value ? 'company' : 'person';

		return [
			'summary' => "{$fullName} — {$typeLabel}" . ($ico ? ", IČO {$ico}" : '') . ", {$docCount} dokladů.",
			'items'   => [[
				'ref'             => ['type' => 'person', 'id' => $id],
				'full_name'       => $fullName,
				'person_type'     => $typeLabel,
				'company_id'      => $ico,
				'vat_id'          => $dic,
				'tax_id'          => $person['tax_id'] ?: null,
				'email'           => $person['email'] ?: null,
				'birth_date'      => $person['birth_date'] ?: null,
				'addresses'       => $addresses,
				'bank_accounts'   => $banks,
				'contacts'        => $contacts,
				'documents_count' => $docCount,
			]],
			'pagination' => null,
		];
	}
}
