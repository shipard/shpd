<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Dataset;

use Shipard\Core\Utils\JsoncParser;

/**
 * Čtení datové sady ze složky nebo `.zip` archivu.
 *
 * Zip se rozbalí do dočasné složky (uklidí ji `close()` / destruktor).
 * Pokud archiv obsahuje jedinou složku na nejvyšší úrovni a manifest až
 * v ní (typický výsledek zazipování složky), použije se ona jako kořen.
 *
 * Všechny relativní cesty procházejí `resolvePath()` — odmítá absolutní
 * cesty a `..` segmenty, takže sada nemůže odkazovat mimo svůj kořen.
 */
final class DatasetReader
{
    public const MANIFEST_FILE = 'manifest.jsonc';

    private ?DatasetManifest $manifest = null;

    private function __construct(
        private readonly string $rootDir,
        private ?string $tempDir,
    ) {}

    public static function open(string $path): self
    {
        if (is_dir($path)) {
            $root = rtrim($path, '/');
            if (!is_file($root . '/' . self::MANIFEST_FILE)) {
                throw DatasetException::notFound(self::MANIFEST_FILE . " in '{$path}'");
            }
            return new self($root, null);
        }

        if (is_file($path)) {
            $tempDir = self::extractZip($path);
            $root = self::locateRoot($tempDir);
            if ($root === null) {
                self::removeTree($tempDir);
                throw DatasetException::invalidArchive('no ' . self::MANIFEST_FILE . ' found');
            }
            return new self($root, $tempDir);
        }

        throw DatasetException::notFound("dataset '{$path}'");
    }

    public function getRootDir(): string
    {
        return $this->rootDir;
    }

    public function getManifest(): DatasetManifest
    {
        return $this->manifest ??= DatasetManifest::fromArray($this->readJsonc(self::MANIFEST_FILE));
    }

    /**
     * Soubory v relativní složce seřazené podle názvu (deterministické pořadí
     * seedu). Vrací relativní cesty vůči kořenu sady; chybějící složka = [].
     *
     * @return list<string>
     */
    public function listFiles(string $relDir, string $suffix = '.jsonc'): array
    {
        $dir = $this->resolvePath($relDir, mustExist: false);
        if (!is_dir($dir)) {
            return [];
        }
        $out = [];
        foreach (scandir($dir, SCANDIR_SORT_ASCENDING) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (!is_file($dir . '/' . $entry)) {
                continue;
            }
            if ($suffix !== '' && !str_ends_with($entry, $suffix)) {
                continue;
            }
            $out[] = rtrim($relDir, '/') . '/' . $entry;
        }
        sort($out, SORT_STRING);
        return $out;
    }

    /**
     * Podsložky v relativní složce, seřazené. Používá se pro sidecar složky
     * příloh (`registry/0001-x.files/`).
     *
     * @return list<string>
     */
    public function listDirs(string $relDir): array
    {
        $dir = $this->resolvePath($relDir, mustExist: false);
        if (!is_dir($dir)) {
            return [];
        }
        $out = [];
        foreach (scandir($dir, SCANDIR_SORT_ASCENDING) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || !is_dir($dir . '/' . $entry)) {
                continue;
            }
            $out[] = rtrim($relDir, '/') . '/' . $entry;
        }
        sort($out, SORT_STRING);
        return $out;
    }

    public function fileExists(string $relPath): bool
    {
        return is_file($this->resolvePath($relPath, mustExist: false));
    }

    /**
     * @return array<string, mixed>
     */
    public function readJsonc(string $relPath): array
    {
        $full = $this->resolvePath($relPath);
        try {
            $data = JsoncParser::parseFile($full);
        } catch (\Throwable $e) {
            throw DatasetException::invalidFile($relPath, $e->getMessage());
        }
        if (!is_array($data)) {
            throw DatasetException::invalidFile($relPath, 'top level must be a JSON object');
        }
        return $data;
    }

    /**
     * Stejný soubor jako objektový strom (`stdClass`) — pro bloby, které se
     * mají přenést verbatim (`{}` nesmí zdegenerovat na `[]`).
     */
    public function readJsoncObjects(string $relPath): \stdClass
    {
        $full = $this->resolvePath($relPath);
        try {
            $data = JsoncParser::parseFile($full, assoc: false);
        } catch (\Throwable $e) {
            throw DatasetException::invalidFile($relPath, $e->getMessage());
        }
        if (!$data instanceof \stdClass) {
            throw DatasetException::invalidFile($relPath, 'top level must be a JSON object');
        }
        return $data;
    }

    /**
     * Absolutní cesta k souboru v sadě. Odmítá cesty mimo kořen.
     */
    public function resolvePath(string $relPath, bool $mustExist = true): string
    {
        $normalized = self::normalizeRelative($relPath);
        $full = $normalized === '' ? $this->rootDir : $this->rootDir . '/' . $normalized;
        if ($mustExist && !file_exists($full)) {
            throw DatasetException::notFound("file '{$relPath}'");
        }
        return $full;
    }

    public function close(): void
    {
        if ($this->tempDir !== null) {
            self::removeTree($this->tempDir);
            $this->tempDir = null;
        }
    }

    public function __destruct()
    {
        $this->close();
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    /**
     * Odstraní `./`, prázdné segmenty; odmítne absolutní cestu, `..` a NUL.
     */
    public static function normalizeRelative(string $relPath): string
    {
        if (str_contains($relPath, "\0") || str_starts_with($relPath, '/') || str_starts_with($relPath, '\\')) {
            throw DatasetException::invalidPath($relPath);
        }
        $segments = [];
        foreach (explode('/', str_replace('\\', '/', $relPath)) as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                throw DatasetException::invalidPath($relPath);
            }
            $segments[] = $seg;
        }
        return implode('/', $segments);
    }

    private static function extractZip(string $zipPath): string
    {
        $zip = new \ZipArchive();
        $result = $zip->open($zipPath, \ZipArchive::RDONLY);
        if ($result !== true) {
            throw DatasetException::invalidArchive("cannot open '{$zipPath}' (code {$result})");
        }

        $tempDir = sys_get_temp_dir() . '/shpd_dataset_' . bin2hex(random_bytes(6));
        if (!mkdir($tempDir, 0700, true)) {
            $zip->close();
            throw DatasetException::invalidArchive("cannot create temp dir '{$tempDir}'");
        }

        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = (string) $zip->getNameIndex($i);
                if ($entry === '' || str_ends_with($entry, '/')) {
                    continue; // adresářový záznam
                }
                try {
                    $rel = self::normalizeRelative($entry);
                } catch (DatasetException) {
                    throw DatasetException::invalidArchive("unsafe entry name '{$entry}'");
                }
                if ($rel === '') {
                    continue;
                }
                $target = $tempDir . '/' . $rel;
                $dir = dirname($target);
                if (!is_dir($dir) && !mkdir($dir, 0700, true)) {
                    throw DatasetException::invalidArchive("cannot create '{$dir}'");
                }
                $stream = $zip->getStream($entry);
                if ($stream === false) {
                    throw DatasetException::invalidArchive("cannot read entry '{$entry}'");
                }
                $out = fopen($target, 'wb');
                if ($out === false) {
                    fclose($stream);
                    throw DatasetException::invalidArchive("cannot write '{$target}'");
                }
                stream_copy_to_stream($stream, $out);
                fclose($stream);
                fclose($out);
            }
        } catch (\Throwable $e) {
            self::removeTree($tempDir);
            throw $e;
        } finally {
            $zip->close();
        }

        return $tempDir;
    }

    /**
     * Kořen = složka s manifestem: buď přímo temp dir, nebo jeho jediná
     * podsložka (zazipovaná složka sady).
     */
    private static function locateRoot(string $tempDir): ?string
    {
        if (is_file($tempDir . '/' . self::MANIFEST_FILE)) {
            return $tempDir;
        }
        $entries = array_values(array_filter(
            scandir($tempDir) ?: [],
            static fn (string $e): bool => $e !== '.' && $e !== '..',
        ));
        if (count($entries) === 1 && is_dir($tempDir . '/' . $entries[0])
            && is_file($tempDir . '/' . $entries[0] . '/' . self::MANIFEST_FILE)) {
            return $tempDir . '/' . $entries[0];
        }
        return null;
    }

    public static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $file) {
            /** @var \SplFileInfo $file */
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($dir);
    }
}
