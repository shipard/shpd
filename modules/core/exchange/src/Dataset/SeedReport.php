<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Dataset;

/**
 * Průběžný souhrn seedu: počty per sekce (ok / failed / skipped),
 * chyby (blokují nulový exit code) a varování.
 */
final class SeedReport
{
    /** @var array<string, array{ok: int, failed: int, skipped: int}> */
    private array $sections = [];

    /** @var list<string> */
    private array $errors = [];

    /** @var list<string> */
    private array $warnings = [];

    public function ok(string $section): void
    {
        $this->bump($section, 'ok');
    }

    public function failed(string $section, string $message): void
    {
        $this->bump($section, 'failed');
        $this->errors[] = "{$section}: {$message}";
    }

    public function skipped(string $section, string $why): void
    {
        $this->bump($section, 'skipped');
        $this->warnings[] = "{$section}: {$why}";
    }

    public function warning(string $message): void
    {
        $this->warnings[] = $message;
    }

    /** Sekce bez záznamů se má v souhrnu objevit s nulami. */
    public function touch(string $section): void
    {
        $this->sections[$section] ??= ['ok' => 0, 'failed' => 0, 'skipped' => 0];
    }

    /**
     * @return array<string, array{ok: int, failed: int, skipped: int}>
     */
    public function counts(): array
    {
        return $this->sections;
    }

    public function processed(string $section): int
    {
        $c = $this->sections[$section] ?? ['ok' => 0, 'failed' => 0, 'skipped' => 0];
        return $c['ok'] + $c['failed'] + $c['skipped'];
    }

    /** @return list<string> */
    public function errors(): array
    {
        return $this->errors;
    }

    /** @return list<string> */
    public function warnings(): array
    {
        return $this->warnings;
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    private function bump(string $section, string $key): void
    {
        $this->touch($section);
        $this->sections[$section][$key]++;
    }
}
