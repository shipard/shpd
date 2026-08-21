<?php

declare(strict_types=1);

namespace Shipard\Command\DataSource;

use Shipard\Core\Reports\ReportDiff;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Porovná dva `ReportResult` JSONy (výstup `report-run` nebo exportér staré
 * strany — kontrakt docs/reports.md §7.4) přes `ReportDiff`. Nepotřebuje DS —
 * čistá práce se soubory, funguje odkudkoli.
 *
 * Exit code: 0 shoda, 1 rozdíly, 2 chyba vstupu / strict violation.
 * D15: strana se `status: errors` porovnání nezastaví (statusy se tisknou),
 * `--strict` ji odmítne s exit 2.
 */
class ReportDiffCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('report-diff')
             ->setDescription('Porovná dva ReportResult JSON soubory (kontrolní diff, D14)')
             ->addArgument('fileA', InputArgument::REQUIRED, 'Cesta k JSON strany A (- = stdin)')
             ->addArgument('fileB', InputArgument::REQUIRED, 'Cesta k JSON strany B (- = stdin)')
             ->addOption('strict', null, InputOption::VALUE_NONE, 'Odmítni stranu se status "errors" (exit 2)')
             ->addOption('json', null, InputOption::VALUE_NONE, 'Strojový výstup — struktura ReportDiff jako JSON');
    }

    /** Oddělené kvůli testům — čtení stdin nejde pod CommandTester nasimulovat. */
    protected function readStdin(): string
    {
        return (string) stream_get_contents(STDIN);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $err = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;

        $pathA = (string) $input->getArgument('fileA');
        $pathB = (string) $input->getArgument('fileB');
        if ($pathA === '-' && $pathB === '-') {
            $err->writeln('<error>Only one of the inputs may be stdin (-)</error>');
            return Command::INVALID;
        }

        $a = $this->loadResult($pathA, $err);
        $b = $this->loadResult($pathB, $err);
        if ($a === null || $b === null) {
            return Command::INVALID;
        }

        $statusA = (string) ($a['status'] ?? 'ok');
        $statusB = (string) ($b['status'] ?? 'ok');
        if ((bool) $input->getOption('strict') && ($statusA === 'errors' || $statusB === 'errors')) {
            $err->writeln(sprintf(
                '<error>--strict: refusing to diff a result with status "errors" (A: %s, B: %s)</error>',
                $statusA, $statusB,
            ));
            return Command::INVALID;
        }

        $diff = (new ReportDiff())->diff($a, $b);

        if ((bool) $input->getOption('json')) {
            $output->writeln((string) json_encode($diff, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return $diff['identical'] ? Command::SUCCESS : Command::FAILURE;
        }

        $this->renderHuman($diff, $a, $b, $output);
        return $diff['identical'] ? Command::SUCCESS : Command::FAILURE;
    }

    /** @return ?array<string, mixed> null = chyba vstupu (vypsaná). */
    private function loadResult(string $path, OutputInterface $err): ?array
    {
        if ($path === '-') {
            $content = $this->readStdin();
        } else {
            if (!is_file($path) || !is_readable($path)) {
                $err->writeln("<error>Cannot read input file: {$path}</error>");
                return null;
            }
            $content = (string) file_get_contents($path);
        }

        $decoded = json_decode($content, true);
        if (!is_array($decoded)) {
            $err->writeln("<error>Input is not a valid JSON object: {$path}</error>");
            return null;
        }
        return $decoded;
    }

    /**
     * @param array<string, mixed> $diff
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    private function renderHuman(array $diff, array $a, array $b, OutputInterface $output): void
    {
        $output->writeln(sprintf(
            'A: %s, status: %s', (string) ($a['reportId'] ?? '?'), $diff['statusA'],
        ));
        $output->writeln(sprintf(
            'B: %s, status: %s', (string) ($b['reportId'] ?? '?'), $diff['statusB'],
        ));

        foreach (['columnsOnlyInA' => 'A', 'columnsOnlyInB' => 'B'] as $key => $side) {
            if ($diff[$key] !== []) {
                $output->writeln(sprintf(
                    '<comment>Warning: columns only in %s (not compared): %s</comment>',
                    $side, implode(', ', $diff[$key]),
                ));
            }
        }

        if ($diff['identical']) {
            $output->writeln('<info>Identical — no differences within tolerance.</info>');
            return;
        }

        if ($diff['differences'] !== []) {
            $accounts = array_unique(array_column($diff['differences'], 'account'));
            $output->writeln(sprintf(
                '<error>%d value difference(s) across %d row(s):</error>',
                count($diff['differences']), count($accounts),
            ));
            $table = new Table($output);
            $table->setHeaders(['Účet/řádek', 'Sloupec', 'Pole', 'A', 'B', 'Delta']);
            foreach ($diff['differences'] as $d) {
                $table->addRow([
                    $d['account'], $d['column'], $d['field'],
                    $this->money($d['a']), $this->money($d['b']), $this->money($d['delta']),
                ]);
            }
            $table->render();
        }

        if ($diff['onlyInA'] !== []) {
            $output->writeln('<error>Accounts only in A:</error> ' . implode(', ', $diff['onlyInA']));
        }
        if ($diff['onlyInB'] !== []) {
            $output->writeln('<error>Accounts only in B:</error> ' . implode(', ', $diff['onlyInB']));
        }
    }

    private function money(float $value): string
    {
        return number_format($value, 2, ',', ' ');
    }
}
