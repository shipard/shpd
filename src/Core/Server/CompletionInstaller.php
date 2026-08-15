<?php

declare(strict_types=1);

namespace Shipard\Core\Server;

/**
 * Instalace bash completion skriptů do /etc/bash_completion.d. Symfony
 * Console 7 completion generuje sama (`<binárka> completion bash`), tady
 * se řeší jen resolve binárky v PATH a idempotentní atomický zápis.
 * Zápis vyžaduje root; volající (completion-install, server-init) si
 * root hlídá sám.
 */
class CompletionInstaller
{
    /** Binárky, kterým se completion instaluje. */
    public const BINARIES = ['shpd-server', 'shpd-ds'];

    public function __construct(
        private readonly string $completionDir = '/etc/bash_completion.d',
    ) {}

    /**
     * @return array{status: 'installed'|'up-to-date'|'skipped'|'error', message: string}
     */
    public function install(string $binaryName): array
    {
        $binaryPath = $this->resolveBinaryPath($binaryName);
        if ($binaryPath === null) {
            return ['status' => 'skipped', 'message' => $binaryName . ' not found in PATH'];
        }

        $script = $this->generateScript($binaryPath);
        if ($script === null || trim($script) === '') {
            return ['status' => 'skipped', 'message' => 'completion generation failed for ' . $binaryPath];
        }

        $target = $this->completionDir . '/' . $binaryName;
        $existing = is_file($target) ? (string) @file_get_contents($target) : null;
        if ($existing === $script) {
            return ['status' => 'up-to-date', 'message' => $target];
        }

        if (!is_dir($this->completionDir)) {
            return ['status' => 'skipped', 'message' => $this->completionDir . ' does not exist (bash-completion not installed?)'];
        }

        // Atomický zápis — rozepsaný soubor nesmí být nikdy source-ován.
        $tmp = $target . '.tmp';
        if (@file_put_contents($tmp, $script) === false) {
            return ['status' => 'error', 'message' => 'cannot write ' . $tmp];
        }
        @chmod($tmp, 0644);
        if (!@rename($tmp, $target)) {
            @unlink($tmp);
            return ['status' => 'error', 'message' => 'cannot move ' . $tmp . ' to ' . $target];
        }

        return ['status' => 'installed', 'message' => $target];
    }

    protected function resolveBinaryPath(string $name): ?string
    {
        $out = [];
        $rc = 1;
        @exec('command -v ' . escapeshellarg($name) . ' 2>/dev/null', $out, $rc);
        $path = trim($out[0] ?? '');
        return ($rc === 0 && $path !== '') ? $path : null;
    }

    protected function generateScript(string $binaryPath): ?string
    {
        $out = [];
        $rc = 1;
        @exec(escapeshellarg($binaryPath) . ' completion bash 2>/dev/null', $out, $rc);
        return $rc === 0 ? implode("\n", $out) . "\n" : null;
    }
}
