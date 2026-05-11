<?php

declare(strict_types=1);

namespace Shipard\Core\Module;

/**
 * Autoloader for module PHP classes. Maps `Shipard\Module\{Group}\{Module}\…`
 * to `{root}/{group}/{module}/src/…` using a ModulePathResolver to find the
 * root for each module.
 *
 * Registered as a single spl_autoload_register handler. Calling register()
 * multiple times replaces the resolver but does not stack handlers.
 */
final class ModuleClassLoader
{
    private const NS_PREFIX = 'Shipard\\Module\\';

    private static ?ModulePathResolver $resolver = null;
    private static bool $registered = false;

    /**
     * Registers (or re-registers) the autoloader with the given resolver.
     * Idempotent — subsequent calls swap the resolver in place.
     */
    public static function register(ModulePathResolver $resolver): void
    {
        self::$resolver = $resolver;
        if (!self::$registered) {
            spl_autoload_register([self::class, 'loadClass']);
            self::$registered = true;
        }
    }

    /**
     * Resets the loader to its initial state. Intended for tests only.
     */
    public static function reset(): void
    {
        if (self::$registered) {
            spl_autoload_unregister([self::class, 'loadClass']);
            self::$registered = false;
        }
        self::$resolver = null;
    }

    public static function loadClass(string $class): void
    {
        if (self::$resolver === null) return;
        if (!str_starts_with($class, self::NS_PREFIX)) return;

        $remainder = substr($class, strlen(self::NS_PREFIX));
        $parts = explode('\\', $remainder);

        // Need at least Group, Module, and one class name segment.
        if (count($parts) < 3) return;

        $group  = lcfirst($parts[0]);
        $module = lcfirst($parts[1]);
        if (!preg_match('/^[a-z][a-z0-9]*$/', $group)) return;
        if (!preg_match('/^[a-z][a-zA-Z0-9]*$/', $module)) return;

        $modulePath = self::$resolver->getPath("$group.$module");
        if ($modulePath === null) return;

        $relative = implode('/', array_slice($parts, 2)) . '.php';
        $file     = $modulePath . '/src/' . $relative;

        if (is_file($file)) {
            require $file;
        }
    }
}
