<?php
declare(strict_types=1);

namespace Shipard\Api\Controller;

use Shipard\Api\Response;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\I18n\ConfigLocalizer;
use Shipard\Core\Module\ModuleDefinition;
use Shipard\Core\Module\ModuleLoader;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Module\ModuleResolver;
use Shipard\Core\Utils\JsoncParser;

class NavigationController
{
	private const array GROUP_LABELS = [
		'core'    => ['cs' => 'Systém', 'en' => 'System'],
		'base'    => ['cs' => 'Základní', 'en' => 'Basic'],
		'economy' => ['cs' => 'Ekonomika', 'en' => 'Economy'],
		'docs'    => ['cs' => 'Doklady', 'en' => 'Documents'],
		'tasks'   => ['cs' => 'Úkoly', 'en' => 'Tasks'],
		'world'   => ['cs' => 'Svět', 'en' => 'World'],
	];

	private const array SKIP_PATTERNS = [
		'sessions',
		'rate_limits',
		'api_keys',
	];

	public function navigation(DataSourceConfig $config, ModulePathResolver $resolver, string $language): Response
	{
		$allModules      = ModuleLoader::loadAllModules($resolver);
		$errors          = [];
		$resolvedModules = ModuleResolver::resolve($allModules, $config->getModules(), $errors);

		$hiddenViewers = [];
		$hiddenTables  = [];

		// (1) Tables and viewers explicitly listed in any module's settingsItems
		// are managed via Settings UI — hide them from the main navigation.
		foreach ($resolvedModules as $module) {
			foreach ($module->settingsItems as $item) {
				if ($item['viewer'] !== null) {
					$hiddenViewers[$item['viewer']] = true;
				}
				if ($item['table'] !== null) {
					$hiddenTables[$item['table']] = true;
				}
			}
		}

		// (2) Tables marked `hideFromNavigation: true` in their JSONC definition
		// (typically sub-tables of a parent record — fiscal_months, vat_periods,
		// etc.) should not appear in any navigation surface.
		foreach ($resolvedModules as $module) {
			$modulePath = $resolver->getPath($module->id);
			if ($modulePath === null) continue;
			foreach ($module->tables as $tableName) {
				if ($this->isTableHiddenFromNavigation($modulePath, $tableName)) {
					$hiddenTables[$tableName] = true;
				}
			}
		}

		// (3) Cross-propagate hidden state between viewers and their tables:
		//   - a hidden viewer hides its target table (otherwise the table would
		//     fall back to a generic type="table" item with the table's own label),
		//   - a hidden table hides any viewer that targets it (otherwise the
		//     viewer would still appear as a navigation entry for a table the
		//     designer asked us to hide).
		foreach ($resolvedModules as $module) {
			foreach ($module->viewers as $viewer) {
				$viewerId = $viewer['id'] ?? null;
				$tableName = $viewer['table'] ?? null;
				if ($viewerId === null || $tableName === null) {
					continue;
				}
				if (isset($hiddenViewers[$viewerId])) {
					$hiddenTables[$tableName] = true;
				}
				if (isset($hiddenTables[$tableName])) {
					$hiddenViewers[$viewerId] = true;
				}
			}
		}

		$groups = $this->buildTree($resolvedModules, $resolver, $language, $hiddenViewers, $hiddenTables);

		// Chat je root-level leaf (vlastní pohled, ne viewer). Stejný princip
		// jako Dashboard — Sidebar ho pozná podle `type`. Vkládáme před
		// dashboard unshift, aby dashboard zůstal první (výchozí po loginu).
		array_unshift($groups, [
			'id'    => 'chat',
			'label' => 'Chat',
			'type'  => 'chat',
			'icon'  => 'chat',
		]);

		// Dashboard je root-level leaf — výchozí pohled po loginu. Není to
		// skupina (žádné `children`); Sidebar.svelte ho rozpoznává podle
		// přítomnosti `type` na root položce.
		array_unshift($groups, [
			'id'    => 'dashboard',
			'label' => $language === 'cs' ? 'Dashboard' : 'Dashboard',
			'type'  => 'dashboard',
			'icon'  => 'dashboard',
		]);

		return Response::success($groups);
	}

	/**
	 * @param  ModuleDefinition[]  $resolvedModules
	 * @return array<int, array>
	 */
	private function buildTree(array $resolvedModules, ModulePathResolver $resolver, string $language, array $hiddenViewers = [], array $hiddenTables = []): array
	{
		// Group modules by their group prefix
		$grouped = [];
		foreach ($resolvedModules as $module) {
			[$group] = explode('.', $module->id, 2);
			$grouped[$group][] = $module;
		}

		$tree = [];
		foreach ($grouped as $groupName => $modules) {
			$children = [];

			if (count($modules) === 1) {
				// Single module — tables go directly as children of the group
				$children = $this->buildTableItems($modules[0], $resolver, $language, $hiddenViewers, $hiddenTables);
			} else {
				// Multiple modules — create sub-group per module. When a module
				// contributes a single item (typical for per-type modules like
				// docs.invoicesOut whose only navigation entry is its viewer),
				// hoist that item directly into the group instead of wrapping
				// it in a same-labeled sub-group.
				foreach ($modules as $module) {
					$tableItems = $this->buildTableItems($module, $resolver, $language, $hiddenViewers, $hiddenTables);
					if ($tableItems === []) {
						continue;
					}

					if (count($tableItems) === 1) {
						$children[] = $tableItems[0];
						continue;
					}

					$moduleLabel = $this->localizeModuleName($module, $resolver, $language);
					$children[] = [
						'id'       => $module->id,
						'label'    => $moduleLabel,
						'children' => $tableItems,
					];
				}
			}

			if ($children === []) {
				continue;
			}

			$tree[] = [
				'id'       => $groupName,
				'label'    => $this->resolveGroupLabel($groupName, $language),
				'children' => $children,
			];
		}

		return $tree;
	}

	/**
	 * @return array<int, array>
	 */
	private function buildTableItems(ModuleDefinition $module, ModulePathResolver $resolver, string $language, array $hiddenViewers = [], array $hiddenTables = []): array
	{
		$modulePath = $resolver->getPath($module->id);
		if ($modulePath === null) {
			return [];
		}

		$items = [];
		$tablesCoveredByViewers = [];

		// (1) The module's viewers are the primary navigation entries — each
		// becomes a sidebar item regardless of whether the viewer's target
		// table is owned by this module. This is what lets per-type modules
		// (e.g. docs.invoicesOut → docs_core_heads) appear in navigation
		// without redeclaring the underlying table.
		foreach ($module->viewers as $viewer) {
			if (isset($hiddenViewers[$viewer['id']])) {
				continue;
			}
			$viewerName = $viewer['name:' . $language]
				?? $viewer['name:en']
				?? $viewer['name']
				?? $viewer['id'];
			$viewerItem = [
				'id'       => 'viewer:' . $viewer['id'],
				'label'    => $viewerName,
				'type'     => 'viewer',
				'viewerId' => $viewer['id'],
			];
			if (isset($viewer['icon'])) {
				$viewerItem['icon'] = $viewer['icon'];
			}
			$items[] = $viewerItem;
			if (isset($viewer['table'])) {
				$tablesCoveredByViewers[$viewer['table']] = true;
			}
		}

		// (2) Tables declared by this module that no viewer (in this module)
		// covers — fall back to a generic table item.
		foreach ($module->tables as $tableName) {
			if ($this->shouldSkip($tableName)) {
				continue;
			}
			if (isset($hiddenTables[$tableName])) {
				continue;
			}
			if (isset($tablesCoveredByViewers[$tableName])) {
				continue;
			}

			$tableData = $this->loadTableMeta($modulePath, $tableName, $language);

			$item = [
				'id'    => $tableName,
				'label' => $tableData['name'] ?? $tableName,
				'type'  => 'table',
				'table' => $tableName,
			];
			if (isset($tableData['icon'])) {
				$item['icon'] = $tableData['icon'];
			}
			$items[] = $item;
		}

		return $items;
	}

	private function loadTableMeta(string $modulePath, string $tableName, string $language): array
	{
		$filePath = $modulePath . '/tables/' . $tableName . '.jsonc';
		if (!file_exists($filePath)) {
			return ['name' => $tableName];
		}

		$raw       = JsoncParser::parseFile($filePath);
		$localized = ConfigLocalizer::localize($raw, $language);

		return $localized;
	}

	private function isTableHiddenFromNavigation(string $modulePath, string $tableName): bool
	{
		$filePath = $modulePath . '/tables/' . $tableName . '.jsonc';
		if (!file_exists($filePath)) {
			return false;
		}
		$raw = JsoncParser::parseFile($filePath);
		return !empty($raw['hideFromNavigation']);
	}

	private function localizeModuleName(ModuleDefinition $module, ModulePathResolver $resolver, string $language): string
	{
		$modulePath = $resolver->getPath($module->id);
		if ($modulePath === null) {
			return $module->name;
		}
		$filePath = $modulePath . '/module.jsonc';

		if (!file_exists($filePath)) {
			return $module->name;
		}

		$raw       = JsoncParser::parseFile($filePath);
		$localized = ConfigLocalizer::localize($raw, $language);

		return $localized['name'] ?? $module->name;
	}

	private function resolveGroupLabel(string $groupName, string $language): string
	{
		if (isset(self::GROUP_LABELS[$groupName])) {
			$labels = self::GROUP_LABELS[$groupName];
			return $labels[$language] ?? $labels['en'] ?? ucfirst($groupName);
		}

		return ucfirst($groupName);
	}

	private function shouldSkip(string $tableName): bool
	{
		foreach (self::SKIP_PATTERNS as $pattern) {
			if (str_contains($tableName, $pattern)) {
				return true;
			}
		}
		return false;
	}
}
