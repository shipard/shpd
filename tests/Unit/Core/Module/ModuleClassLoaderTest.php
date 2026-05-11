<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Module;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Module\ModuleClassLoader;
use Shipard\Core\Module\ModulePathResolver;

class ModuleClassLoaderTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/shpd-mcl-test-' . uniqid();
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        ModuleClassLoader::reset();
        // Restore the default loader registered by tests/bootstrap.php so
        // subsequent tests in the same process can still load module classes.
        ModuleClassLoader::register(
            new ModulePathResolver([dirname(__DIR__, 4) . '/modules']),
        );
        $this->rrmdir($this->tmpDir);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rrmdir($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function makeRoot(string $name): string
    {
        $path = $this->tmpDir . '/' . $name;
        mkdir($path, 0755, true);
        return $path;
    }

    /**
     * Creates a module stub at {root}/{group}/{module}/ with module.jsonc
     * and optionally PHP class files. $classFiles maps relative paths under
     * src/ (e.g. "Demo.php" or "Sub/Nested.php") to full PHP source.
     *
     * @param array<string, string> $classFiles
     */
    private function makeModule(string $root, string $group, string $module, array $classFiles = []): string
    {
        $dir = $root . '/' . $group . '/' . $module;
        mkdir($dir, 0755, true);
        file_put_contents($dir . '/module.jsonc', '');

        if ($classFiles !== []) {
            mkdir($dir . '/src', 0755, true);
            foreach ($classFiles as $rel => $source) {
                $full = $dir . '/src/' . $rel;
                $parent = dirname($full);
                if (!is_dir($parent)) mkdir($parent, 0755, true);
                file_put_contents($full, $source);
            }
        }
        return $dir;
    }

    /**
     * Unique 8-char suffix used to compose per-test class names so that
     * defining classes across tests in the same process doesn't collide.
     */
    private function uniqueSuffix(): string
    {
        return bin2hex(random_bytes(4));
    }

    public function testLoadsClassFromMainRoot(): void
    {
        $sfx = $this->uniqueSuffix();
        $main = $this->makeRoot('main');
        $this->makeModule($main, 'vendor', "mod$sfx", [
            'Demo.php' => "<?php\nnamespace Shipard\\Module\\Vendor\\Mod$sfx;\nclass Demo { public static function ping(): string { return 'pong'; } }\n",
        ]);

        ModuleClassLoader::register(new ModulePathResolver([$main]));

        $class = "Shipard\\Module\\Vendor\\Mod$sfx\\Demo";
        $this->assertTrue(class_exists($class));
        $this->assertSame('pong', $class::ping());
    }

    public function testLoadsClassFromExtraRoot(): void
    {
        $sfx = $this->uniqueSuffix();
        $main  = $this->makeRoot('main');
        $extra = $this->makeRoot('extra');
        $this->makeModule($extra, 'vendor', "mod$sfx", [
            'Demo.php' => "<?php\nnamespace Shipard\\Module\\Vendor\\Mod$sfx;\nclass Demo { public static function ping(): string { return 'pong-extra'; } }\n",
        ]);

        ModuleClassLoader::register(new ModulePathResolver([$main, $extra]));

        $class = "Shipard\\Module\\Vendor\\Mod$sfx\\Demo";
        $this->assertTrue(class_exists($class));
        $this->assertSame('pong-extra', $class::ping());
    }

    public function testReturnsSilentlyForUnknownClass(): void
    {
        $sfx = $this->uniqueSuffix();
        $main = $this->makeRoot('main');
        $this->makeModule($main, 'vendor', "mod$sfx");
        ModuleClassLoader::register(new ModulePathResolver([$main]));

        $class = "Shipard\\Module\\Vendor\\Mod$sfx\\NotOnDisk";
        $this->assertFalse(class_exists($class));
    }

    public function testIgnoresClassesOutsideModuleNamespace(): void
    {
        $main = $this->makeRoot('main');
        ModuleClassLoader::register(new ModulePathResolver([$main]));

        // Class with completely unrelated namespace — autoloader returns
        // silently, no error. class_exists() ends up false because no other
        // autoloader handles it either.
        $class = 'SomeOtherVendor\\Foo\\Bar_' . $this->uniqueSuffix();
        $this->assertFalse(class_exists($class));
    }

    public function testIgnoresClassesWithTooFewSegments(): void
    {
        $main = $this->makeRoot('main');
        ModuleClassLoader::register(new ModulePathResolver([$main]));

        // No class segment after group/module.
        $sfx = $this->uniqueSuffix();
        $this->assertFalse(class_exists("Shipard\\Module\\Vendor\\Mod$sfx"));
        // Only one segment after Shipard\Module\.
        $this->assertFalse(class_exists("Shipard\\Module\\Lonely_$sfx"));
    }

    public function testHandlesNestedClass(): void
    {
        $sfx = $this->uniqueSuffix();
        $main = $this->makeRoot('main');
        $this->makeModule($main, 'vendor', "mod$sfx", [
            'Sub/Nested/Deep.php' =>
                "<?php\nnamespace Shipard\\Module\\Vendor\\Mod$sfx\\Sub\\Nested;\n"
              . "class Deep { public static function tag(): string { return 'deep'; } }\n",
        ]);

        ModuleClassLoader::register(new ModulePathResolver([$main]));

        $class = "Shipard\\Module\\Vendor\\Mod$sfx\\Sub\\Nested\\Deep";
        $this->assertTrue(class_exists($class));
        $this->assertSame('deep', $class::tag());
    }

    public function testRegisterIsIdempotent(): void
    {
        // Start from a clean state — previous test's tearDown re-registered
        // the default loader, so reset() here to measure pure register() effect.
        ModuleClassLoader::reset();

        $main = $this->makeRoot('main');
        $before = count(spl_autoload_functions() ?: []);

        ModuleClassLoader::register(new ModulePathResolver([$main]));
        $afterFirst = count(spl_autoload_functions() ?: []);

        ModuleClassLoader::register(new ModulePathResolver([$main]));
        $afterSecond = count(spl_autoload_functions() ?: []);

        $this->assertSame($before + 1, $afterFirst);
        $this->assertSame($afterFirst, $afterSecond, 'register() must not stack handlers');
    }

    public function testReRegisterSwapsResolver(): void
    {
        $sfx = $this->uniqueSuffix();
        $rootA = $this->makeRoot('rootA');
        $rootB = $this->makeRoot('rootB');

        // Class lives only in rootB.
        $this->makeModule($rootB, 'vendor', "mod$sfx", [
            'Demo.php' => "<?php\nnamespace Shipard\\Module\\Vendor\\Mod$sfx;\nclass Demo {}\n",
        ]);

        ModuleClassLoader::register(new ModulePathResolver([$rootA]));
        $this->assertFalse(class_exists("Shipard\\Module\\Vendor\\Mod$sfx\\Demo"));

        ModuleClassLoader::register(new ModulePathResolver([$rootB]));
        $this->assertTrue(class_exists("Shipard\\Module\\Vendor\\Mod$sfx\\Demo"));
    }

    public function testInvalidGroupOrModuleNameIgnored(): void
    {
        $sfx = $this->uniqueSuffix();
        $main = $this->makeRoot('main');
        ModuleClassLoader::register(new ModulePathResolver([$main]));

        // lcfirst('InvalidGroup') = 'invalidGroup' — fails the group regex
        // [a-z][a-z0-9]* (no uppercase allowed in the group segment).
        $this->assertFalse(class_exists("Shipard\\Module\\InvalidGroup\\Foo_$sfx\\Bar"));

        // lcfirst('1bad') = '1bad' — fails digit-leading check (and the
        // namespace component itself is illegal in PHP, but the loader
        // shouldn't crash on the input).
        $this->assertFalse(class_exists("Shipard\\Module\\Foo_$sfx\\Bar_$sfx\\Baz"));
    }
}
