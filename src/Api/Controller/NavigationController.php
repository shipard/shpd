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

		$groups = $this->buildTree($resolvedModules, $modulesBasePath, $language);

		return Response::success($groups);
	}

	/**
	 * @param  ModuleDefinition[]  $resolvedModules
	 * @return array<int, array>
	 */
	private function buildTree(array $resolvedModules, string $modulesBasePath, string $language): array
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
				$children = $this->buildTableItems($modules[0], $modulesBasePath, $language);
			} else {
				// Multiple modules — create sub-group per module
				foreach ($modules as $module) {
					$tableItems = $this->buildTableItems($module, $modulesBasePath, $language);
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
	private function buildTableItems(ModuleDefinition $module, string $modulesBasePath, string $language): array
	{
		[$group, $name] = explode('.', $module->id, 2);
		$modulePath = $modulesBasePath . '/' . $group . '/' . $name;

		// Build a map of tables covered by viewers: table => viewer data
		$viewerByTable = [];
		foreach ($module->viewers as $viewer) {
			$viewerName = $viewer['name:' . $language]
				?? $viewer['name:en']
				?? $viewer['name']
				?? $viewer['id'];
			$viewerByTable[$viewer['table']] = [
				'id'       => 'viewer:' . $viewer['id'],
				'label'    => $viewerName,
				'type'     => 'viewer',
				'viewerId' => $viewer['id'],
			];
		}

		$items = [];
		foreach ($module->tables as $tableName) {
			if ($this->shouldSkip($tableName)) {
				continue;
			}

			// If a viewer covers this table, use the viewer item instead
			if (isset($viewerByTable[$tableName])) {
				$items[] = $viewerByTable[$tableName];
				continue;
			}

			$label = $this->loadTableLabel($modulePath, $tableName, $language);

			$items[] = [
				'id'    => $tableName,
				'label' => $label,
				'type'  => 'table',
				'table' => $tableName,
			];
		}

		return $items;
	}

	private function loadTableLabel(string $modulePath, string $tableName, string $language): string
	{
		$filePath = $modulePath . '/tables/' . $tableName . '.jsonc';
		if (!file_exists($filePath)) {
			return $tableName;
		}

		$raw       = JsoncParser::parseFile($filePath);
		$localized = ConfigLocalizer::localize($raw, $language);

		return $localized['name'] ?? $tableName;
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
