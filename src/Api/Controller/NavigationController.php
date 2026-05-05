<?php
declare(strict_types=1);

namespace Shipard\Api\Controller;

use Shipard\Api\Response;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\I18n\ConfigLocalizer;
use Shipard\Core\Module\ModuleDefinition;
use Shipard\Core\Module\ModuleLoader;
use Shipard\Core\Module\ModuleResolver;
use Shipard\Core\Utils\JsoncParser;

class NavigationController
{
	private const array GROUP_LABELS = [
		'core'    => ['cs' => 'Systém', 'en' => 'System'],
		'base'    => ['cs' => 'Základní', 'en' => 'Basic'],
		'economy' => ['cs' => 'Ekonomika', 'en' => 'Economy'],
		'world'   => ['cs' => 'Svět', 'en' => 'World'],
	];

	private const array SKIP_PATTERNS = [
		'sessions',
		'rate_limits',
		'api_keys',
	];

	public function navigation(DataSourceConfig $config, string $modulesBasePath, string $language): Response
	{
		$allModules      = ModuleLoader::loadAllModules($modulesBasePath);
		$errors          = [];
		$resolvedModules = ModuleResolver::resolve($allModules, $config->getModules(), $errors);

		$hiddenViewers = [];
		$hiddenTables  = [];
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

		$groups = $this->buildTree($resolvedModules, $modulesBasePath, $language, $hiddenViewers, $hiddenTables);

		return Response::success($groups);
	}

	/**
	 * @param  ModuleDefinition[]  $resolvedModules
	 * @return array<int, array>
	 */
	private function buildTree(array $resolvedModules, string $modulesBasePath, string $language, array $hiddenViewers = [], array $hiddenTables = []): array
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
				$children = $this->buildTableItems($modules[0], $modulesBasePath, $language, $hiddenViewers, $hiddenTables);
			} else {
				// Multiple modules — create sub-group per module
				foreach ($modules as $module) {
					$tableItems = $this->buildTableItems($module, $modulesBasePath, $language, $hiddenViewers, $hiddenTables);
					if ($tableItems === []) {
						continue;
					}

					$moduleLabel = $this->localizeModuleName($module, $modulesBasePath, $language);
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
	private function buildTableItems(ModuleDefinition $module, string $modulesBasePath, string $language, array $hiddenViewers = [], array $hiddenTables = []): array
	{
		[$group, $name] = explode('.', $module->id, 2);
		$modulePath = $modulesBasePath . '/' . $group . '/' . $name;

		// Build a map of tables covered by viewers: table => viewer data
		$viewerByTable = [];
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
			$viewerByTable[$viewer['table']] = $viewerItem;
		}

		$items = [];
		foreach ($module->tables as $tableName) {
			if ($this->shouldSkip($tableName)) {
				continue;
			}
			if (isset($hiddenTables[$tableName])) {
				continue;
			}

			// If a viewer covers this table, use the viewer item instead
			if (isset($viewerByTable[$tableName])) {
				$items[] = $viewerByTable[$tableName];
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

	private function localizeModuleName(ModuleDefinition $module, string $modulesBasePath, string $language): string
	{
		[$group, $name] = explode('.', $module->id, 2);
		$filePath = $modulesBasePath . '/' . $group . '/' . $name . '/module.jsonc';

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
