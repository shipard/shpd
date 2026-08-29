<?php

declare(strict_types=1);

namespace Shipard\Core\Process;

/**
 * Fire-and-forget spuštění procesu mimo životní cyklus requestu.
 *
 * Používá `setsid -f` (util-linux): setsid se forkne, potomek dostane
 * vlastní session a rodič `setsid` okamžitě skončí — `proc_close` tak
 * neblokuje a potomek přežije konec PHP requestu / php-fpm workera.
 * Argv pole = žádný shell, žádné escapování.
 *
 * Návrat false = potomek nejspíš neodstartoval (setsid chybí, proc_open
 * selhal). Volající to má brát jako provozní stav a mít záchranu (sweep),
 * ne výjimku.
 */
final class DetachedProcess
{
    /**
     * @param list<string> $argv Program a argumenty (bez shellu).
     * @param string|null $cwd Pracovní adresář potomka.
     * @param string|null $logFile Kam připojit stdout+stderr; null = /dev/null.
     */
    public static function spawn(array $argv, ?string $cwd = null, ?string $logFile = null): bool
    {
        if ($argv === []) {
            return false;
        }

        $out = $logFile !== null ? ['file', $logFile, 'a'] : ['file', '/dev/null', 'w'];
        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => $out,
            2 => $out,
        ];

        $process = @proc_open(['setsid', '-f', ...$argv], $descriptors, $pipes, $cwd);
        if (!is_resource($process)) {
            return false;
        }

        // setsid -f se vrací hned po forku; nenulový kód = fork/exec selhal.
        return proc_close($process) === 0;
    }
}
