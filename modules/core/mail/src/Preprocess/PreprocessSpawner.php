<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Preprocess;

use Shipard\Cli\BinPaths;
use Shipard\Core\Config\ServerConfig;
use Shipard\Core\Logging\ErrorLogger;
use Shipard\Core\Process\DetachedProcess;

/**
 * Detached spuštění runneru `shpd-ds mail-preprocess --message <id>`
 * po commitu intake (tasks/mail-preprocess.md D8). Fire-and-forget:
 * nečeká, stdout/stderr potomka jdou do `preprocess.log` vedle
 * serverového logu (když je adresář zapisovatelný), jinak do /dev/null.
 * Selhání spawnu se jen zaloguje — zprávu ve stavu 10 dohledá
 * `mail-preprocess --sweep`.
 */
final class PreprocessSpawner
{
    /**
     * @param list<string>|null $command Argv prefix pro shpd-ds (test seam);
     *        null = BinPaths::shpdDsCommand().
     */
    public function __construct(
        private readonly string $dsPath,
        private readonly ?array $command = null,
        private readonly ?string $logFile = null,
    ) {
    }

    public function spawn(int $messageId): bool
    {
        $argv = [...($this->command ?? BinPaths::shpdDsCommand()), 'mail-preprocess', '--message', (string) $messageId];

        $ok = DetachedProcess::spawn($argv, $this->dsPath, $this->logFile ?? self::defaultLogFile());
        if (!$ok) {
            ErrorLogger::warn('Preprocess runner spawn failed — message stays pending for the sweep', [
                'message' => $messageId,
                'argv' => $argv,
            ]);
        }

        return $ok;
    }

    private static function defaultLogFile(): ?string
    {
        try {
            $serverConfig = new ServerConfig();
            $serverConfig->load();
            $dir = dirname($serverConfig->getLogFile());
            return is_dir($dir) && is_writable($dir) ? $dir . '/preprocess.log' : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
