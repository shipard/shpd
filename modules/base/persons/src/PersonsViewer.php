<?php
declare(strict_types=1);

namespace Shipard\Module\Base\Persons;

use Shipard\Core\Viewer\TableViewer;

class PersonsViewer extends TableViewer
{
	public function selectRows(?string $search, array $filters, int $pageNumber): array
	{
		$sql = 'SELECT `id`, `person_id`, `person_type`, `full_name`, `company_id`, `tax_id`, `email`, `phone`, `is_closed`'
			. ' FROM `' . $this->table . '`';

		$conditions = [];
		$params = [];

		// Default: hide closed records unless filter is active
		$showClosed = false;
		foreach ($filters as $filter) {
			if ($filter['id'] === 'is_closed' && $filter['value']) {
				$showClosed = true;
			}
		}
		if (!$showClosed) {
			$conditions[] = '`is_closed` = 0';
		}

		// Search
		if ($search !== null && $search !== '') {
			[$searchSql, $searchParams] = $this->buildSearchCondition(
				['full_name', 'company_id', 'email', 'person_id'],
				$search,
			);
			if ($searchSql !== '') {
				$conditions[] = $searchSql;
				$params = array_merge($params, $searchParams);
			}
		}

		if ($conditions !== []) {
			$sql .= ' WHERE ' . implode(' AND ', $conditions);
		}

		$sql .= ' ORDER BY `last_name` ASC, `first_name` ASC, `id` ASC';

		[$offset, $limit] = $this->buildPaginationLimit($pageNumber);
		$sql .= ' LIMIT ' . $offset . ', ' . $limit;

		return $this->db->fetchAll($sql, ...$params);
	}

	public function renderRow(array $rowData): array
	{
		$row = [
			'id' => (int) $rowData['id'],
			't1' => $rowData['full_name'] ?? '',
			'i1' => $rowData['person_id'] ?? null,
		];

		// Build t2 spans
		$t2 = [];
		if (!empty($rowData['company_id'])) {
			$t2[] = ['text' => 'IČO: ' . $rowData['company_id']];
		}
		if (!empty($rowData['tax_id'])) {
			$t2[] = ['text' => 'DIČ: ' . $rowData['tax_id']];
		}
		if (!empty($rowData['is_closed'])) {
			$t2[] = ['text' => 'Uzavřeno', 'class' => 'danger'];
		}
		$row['t2'] = $t2 !== [] ? $t2 : null;

		// Build t3: email or phone
		$row['t3'] = !empty($rowData['email'])
			? $rowData['email']
			: (!empty($rowData['phone']) ? $rowData['phone'] : null);

		return $row;
	}

	public function renderDetail(int $recordId): array
	{
		$record = $this->db->fetchRow(
			'SELECT * FROM `' . $this->table . '` WHERE `id` = %i',
			$recordId,
		);

		if ($record === null) {
			return ['tabs' => []];
		}

		$tabs = [];

		// Tab 1: Overview
		$tabs[] = [
			'id' => 'overview',
			'label' => 'Přehled',
			'content' => $this->buildOverviewContent($record),
		];

		// Tab 2: Contacts
		$contacts = $this->db->fetchAll(
			'SELECT `name`, `role`, `email`, `phone` FROM `base_persons_contacts` WHERE `person` = %i ORDER BY `order_pos`',
			$recordId,
		);
		$tabs[] = [
			'id' => 'contacts',
			'label' => 'Kontakty',
			'content' => [
				'type' => 'table',
				'columns' => [
					['id' => 'name', 'label' => 'Název'],
					['id' => 'role', 'label' => 'Funkce'],
					['id' => 'email', 'label' => 'E-mail'],
					['id' => 'phone', 'label' => 'Telefon'],
				],
				'rows' => $contacts,
			],
		];

		// Tab 3: Addresses
		$addresses = $this->db->fetchAll(
			'SELECT `name`, `display_line` FROM `base_persons_addresses` WHERE `person` = %i ORDER BY `order_pos`',
			$recordId,
		);
		$tabs[] = [
			'id' => 'addresses',
			'label' => 'Adresy',
			'content' => [
				'type' => 'table',
				'columns' => [
					['id' => 'name', 'label' => 'Název'],
					['id' => 'display_line', 'label' => 'Adresa'],
				],
				'rows' => $addresses,
			],
		];

		return ['tabs' => $tabs];
	}

	public function getToolbarActions(?array $selectedRow): array
	{
		$actions = [
			['id' => 'create', 'label' => 'Přidat', 'variant' => 'primary'],
		];

		if ($selectedRow !== null) {
			$actions[] = ['id' => 'edit', 'label' => 'Otevřít', 'variant' => 'secondary'];
		}

		return $actions;
	}

	public function getFilters(): array
	{
		return [
			['id' => 'is_closed', 'label' => 'Zobrazit uzavřené', 'type' => 'checkbox'],
		];
	}

	private function buildOverviewContent(array $record): array
	{
		$personTypeLabels = [0 => 'Neurčeno', 1 => 'Fyzická osoba', 2 => 'Právnická osoba'];

		$identityItems = [];
		$this->addItem($identityItems, 'Kód osoby', $record['person_id'] ?? null);
		$this->addItem($identityItems, 'Typ osoby', $personTypeLabels[(int) ($record['person_type'] ?? 0)] ?? null);
		$this->addItem($identityItems, 'IČO', $record['company_id'] ?? null);
		$this->addItem($identityItems, 'DIČ', $record['tax_id'] ?? null);
		$this->addItem($identityItems, 'DIČ pro DPH', $record['vat_id'] ?? null);

		$contactItems = [];
		$this->addItem($contactItems, 'E-mail', $record['email'] ?? null);
		$this->addItem($contactItems, 'Telefon', $record['phone'] ?? null);
		$this->addItem($contactItems, 'Web', $record['web'] ?? null);

		$personalItems = [];
		$this->addItem($personalItems, 'Datum narození', $record['birth_date'] ?? null);
		$this->addItem($personalItems, 'Rodné číslo', $record['national_id'] ?? null);

		$groups = [];
		if ($identityItems !== []) {
			$groups[] = ['title' => 'Identifikace', 'items' => $identityItems];
		}
		if ($contactItems !== []) {
			$groups[] = ['title' => 'Kontakt', 'items' => $contactItems];
		}
		if ($personalItems !== []) {
			$groups[] = ['title' => 'Osobní údaje', 'items' => $personalItems];
		}

		return [
			'type' => 'properties',
			'groups' => $groups,
		];
	}

	/**
	 * @param array<int, array{label: string, value: string}> $items
	 */
	private function addItem(array &$items, string $label, mixed $value): void
	{
		if ($value !== null && $value !== '') {
			$items[] = ['label' => $label, 'value' => (string) $value];
		}
	}
}
