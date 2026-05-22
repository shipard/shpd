<?php
declare(strict_types=1);

namespace Shipard\Module\Base\Persons;

use Shipard\Core\Document\DocStateConfig;
use Shipard\Core\Viewer\TableViewer;

class PersonsViewer extends TableViewer
{
	protected ?string $docStatesCfgItem = 'core.system.docStatesArchive';

	/** Maps stateStyle to a span class for the state badge in t2. */
	private const STATE_SPAN_CLASS = [
		'concept'   => 'warning',
		'confirmed' => 'primary',
		'done'      => 'success',
		'edit'      => 'warning',
		'archive'   => 'muted',
		'trash'     => 'muted',
		'cancelled' => 'danger',
	];

	public function selectRows(?string $search, array $filters, int $pageNumber): array
	{
		$sql = 'SELECT `id`, `person_id`, `person_type`, `full_name`, `company_id`, `tax_id`,'
			. ' `email`, `phone`, `is_own`, `docState`, `docStateMain`'
			. ' FROM `' . $this->table . '`';

		$conditions = [];
		$params     = [];

		// viewGroup filter drives which doc-state tab is active.
		// Default: 'active' — show Koncept, V opravě and V pořádku.
		$viewGroup = 'active';
		foreach ($filters as $filter) {
			if ($filter['id'] === 'viewGroup') {
				$viewGroup = (string) $filter['value'];
			}
		}

		if ($viewGroup !== 'all') {
			[$vgSql, $vgParams] = $this->buildViewGroupFilter($this->docStatesCfgItem, $viewGroup);
			if ($vgSql !== '') {
				$conditions[] = $vgSql;
				$params       = array_merge($params, $vgParams);
			}
		}

		// Fulltext search across key columns
		if ($search !== null && $search !== '') {
			[$searchSql, $searchParams] = $this->buildSearchCondition(
				['full_name', 'company_id', 'email', 'person_id'],
				$search,
			);
			if ($searchSql !== '') {
				$conditions[] = $searchSql;
				$params       = array_merge($params, $searchParams);
			}
		}

		if ($conditions !== []) {
			$sql .= ' WHERE ' . implode(' AND ', $conditions);
		}

		// docStateMain first: Koncepty nahoře, pak V opravě, pak V pořádku; within each group, alphabetically
		$sql .= ' ORDER BY `docStateMain` ASC, `last_name` ASC, `first_name` ASC, `id` ASC';

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

		// Ikona řádku: fyzická osoba (1) / neurčeno (0) → user,
		// právnická osoba (2) → company (building).
		$personType = (int) ($rowData['person_type'] ?? 0);
		$row['icon'] = $personType === 2 ? 'company' : 'user';

		// Line 2: badge "Vlastní" for is_own, then IČO/DIČ, plus state badge for non-default states
		$t2 = [];
		if (!empty($rowData['is_own'])) {
			$t2[] = ['text' => 'Vlastní', 'class' => 'primary'];
		}
		if (!empty($rowData['company_id'])) {
			$t2[] = ['text' => 'IČO: ' . $rowData['company_id']];
		}
		if (!empty($rowData['tax_id'])) {
			$t2[] = ['text' => 'DIČ: ' . $rowData['tax_id']];
		}

		$docState  = (int) ($rowData['docState'] ?? 10);
		$cfg       = DocStateConfig::fromCfgItem($this->config?->cfgItem($this->docStatesCfgItem));
		$stateData = $cfg->getState($docState);
		$stateStyle = $stateData['stateStyle'] ?? 'concept';

		// Badge non-default states so the user sees them at a glance
		if ($docState !== 10) {
			$t2[] = [
				'text'  => $stateData['stateName'] ?? '',
				'class' => self::STATE_SPAN_CLASS[$stateStyle] ?? 'muted',
			];
		}

		$row['t2'] = $t2 !== [] ? $t2 : null;

		// Line 3: e-mail or phone
		$row['t3'] = !empty($rowData['email'])
			? $rowData['email']
			: (!empty($rowData['phone']) ? $rowData['phone'] : null);

		// Row-level state style for CSS coloring in the viewer list
		$row['stateStyle'] = $stateStyle;

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
			'id'      => 'overview',
			'label'   => $this->defaultOverviewLabel(),
			'content' => $this->buildOverviewContent($record),
		];

		// Tab 2: Contacts
		$contacts = $this->db->fetchAll(
			'SELECT `name`, `role`, `email`, `phone` FROM `base_persons_contacts` WHERE `person` = %i ORDER BY `order_pos`',
			$recordId,
		);
		$tabs[] = [
			'id'    => 'contacts',
			'label' => $this->detailTabLabel('base.persons.viewerDetailLabels', 'contacts', 'Contacts'),
			'content' => [
				'type'    => 'table',
				'columns' => [
					['id' => 'name',  'label' => 'Název'],
					['id' => 'role',  'label' => 'Funkce'],
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
			'id'    => 'addresses',
			'label' => $this->detailTabLabel('base.persons.viewerDetailLabels', 'addresses', 'Addresses'),
			'content' => [
				'type'    => 'table',
				'columns' => [
					['id' => 'name',         'label' => 'Název'],
					['id' => 'display_line', 'label' => 'Adresa'],
				],
				'rows' => $addresses,
			],
		];

		return ['tabs' => $tabs];
	}

	public function getToolbarActions(?array $selectedRow): array
	{
		// Base gives localized create/edit; append the registry-import action
		// only on the unselected (list) toolbar — picking it on a selected row
		// would be confusing UX.
		$actions = parent::getToolbarActions($selectedRow);

		if ($selectedRow !== null) {
			return $actions;
		}

		$personDefs = ($this->config?->cfgItem('base.persons.viewerDefaults') ?? [])['toolbarActions'] ?? [];
		$def = $personDefs['import_from_registry'] ?? ['name' => 'From registry', 'variant' => 'secondary'];

		$actions[] = [
			'id'      => 'import_from_registry',
			'label'   => $def['name']    ?? 'From registry',
			'variant' => $def['variant'] ?? 'secondary',
			'icon'    => 'cloud-download',
		];

		return $actions;
	}

	// -------------------------------------------------------------------------

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

		return ['type' => 'properties', 'groups' => $groups];
	}

	/** @param array<int, array{label: string, value: string}> $items */
	private function addItem(array &$items, string $label, mixed $value): void
	{
		if ($value !== null && $value !== '') {
			$items[] = ['label' => $label, 'value' => (string) $value];
		}
	}
}
