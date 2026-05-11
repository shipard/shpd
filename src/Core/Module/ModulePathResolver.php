<?php

declare(strict_types=1);

namespace Shipard\Core\Module;

/**
 * Resolves module ids to absolute filesystem paths across one or more module
 * roots. The first root is the main (in-repo) `modules/` directory; subsequent
 * roots are additional third-party / customer module trees.
 *
 * Module discovery is purely directory-based: `{root}/{group}/{module}/` is a
 * module iff it contains `module.jsonc`. The id `{group}.{module}` is derived
 * from directory names; this class does not parse `module.jsonc`.
 */
final class ModulePathResolver
{
    /** @var list<string> */
    private readonly array $roots;

    /** @var array<string, string>  moduleId => absolute module directory */
    private readonly array $map;

    /** @var list<string>  module ids sorted alphabetically */
    private readonly array $sortedIds;

    /**
     * @param list<string> $roots Absolute paths to module root directories.
     *                            First entry is the main root; subsequent
     *                            entries are extra roots.
     *
     * @throws \RuntimeException on module-id collision across roots, or when
     *         a configured root does not exist / is not a directory.
     */
    public function __construct(array $roots)
    {
        foreach ($roots as $root) {
            if (!is_dir($root)) {
                throw new \RuntimeException(
                    "Module root does not exist or is not a directory: '$root'"
                );
            }
        }
        $this->roots = array_values($roots);

        $map = [];
        foreach ($this->roots as $root) {
            foreach ($this->scanRoot($root) as $id => $path) {
                if (isset($map[$id])) {
                    throw new \RuntimeException(
                        "Module '$id' found in multiple roots: "
                      . "'{$map[$id]}' and '$path'"
                    );
                }
                $map[$id] = $path;
            }
        }
        $this->map = $map;

        $ids = array_keys($map);
        sort($ids, SORT_STRING);
        $this->sortedIds = $ids;
    }

    /**
     * Returns the absolute path to the directory containing `module.jsonc` for
     * the given module id, or null if no such module exists in any root.
     */
    public function getPath(string $moduleId): ?string
    {
        return $this->map[$moduleId] ?? null;
    }

    /**
     * Returns all discovered module ids across all roots, sorted alphabetically.
     *
     * @return list<string>
     */
    public function allModuleIds(): array
    {
        return $this->sortedIds;
    }

    /**
     * Returns the configured roots in their original order.
     *
     * @return list<string>
     */
    public function getRoots(): array
    {
        return $this->roots;
    }

    /**
     * Walks a single root and yields `{group}.{module} => absolutePath` for
     * every directory of the form `{root}/{group}/{module}/` that contains a
     * `module.jsonc` and whose group/module names match the required regexes.
     *
     * @return iterable<string, string>
     */
    private function scanRoot(string $root): iterable
    {
        $groupRe  = '/^[a-z][a-z0-9]*$/';
        $moduleRe = '/^[a-z][a-zA-Z0-9]*$/';

        foreach ($this->listSubdirs($root) as $group) {
            if (!preg_match($groupRe, $group)) continue;
            $groupDir = $root . '/' . $group;
            foreach ($this->listSubdirs($groupDir) as $module) {
                if (!preg_match($moduleRe, $module)) continue;
                $moduleDir = $groupDir . '/' . $module;
                if (!is_file($moduleDir . '/module.jsonc')) continue;
                yield "$group.$module" => $moduleDir;
            }
        }
    }

    /** @return list<string> */
    private function listSubdirs(string $dir): array
    {
        $entries = @scandir($dir);
        if ($entries === false) return [];
        $out = [];
        foreach ($entries as $e) {
            if ($e === '.' || $e === '..') continue;
            if (is_dir($dir . '/' . $e)) $out[] = $e;
        }
        return $out;
    }
}
