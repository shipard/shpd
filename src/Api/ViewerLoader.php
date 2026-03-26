<?php
declare(strict_types=1);

namespace Shipard\Api;

use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Module\ModuleLoader;
use Shipard\Core\Module\ModuleResolver;
use Shipard\Core\Viewer\ViewerRegistry;

class ViewerLoader
{
	/**
	 * Build a ViewerRegistry from resolved modules for a data source.
	 */
	public static function load(DataSourceConfig $config, string $modulesBasePath, string $language): ViewerRegistry
	{
		$allModules      = ModuleLoader::loadAllModules($modulesBasePath);
		$errors          = [];
		$resolvedModules = ModuleResolver::resolve($allModules, $config->getModules(), $errors);

		$registry = new ViewerRegistry();
		$registry->loadFromModules($resolvedModules, $language);

		return $registry;
	}
}
