<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Dataset\Seed;

use Shipard\Module\Core\Exchange\Common\ApplyResult;
use Shipard\Module\Core\Exchange\Dataset\DatasetException;
use Shipard\Module\Core\Exchange\Dataset\SectionSeeder;
use Shipard\Module\Core\Exchange\Dataset\SeedContext;
use Shipard\Module\Core\Exchange\Dataset\SeedReport;

/**
 * Společný průběh pro sekce importované standardními appliery
 * (persons, items, docs): soubor → canonical → úprava applyOptions pro
 * merge režim → `apply()` → hlášení. Podtřída dodá applier volání a
 * případnou kontrolu duplicity v merge režimu.
 */
abstract class ApplierSeeder implements SectionSeeder
{
    public function seed(SeedContext $ctx, SeedReport $report): void
    {
        $section = $this->section();
        foreach ($ctx->reader->listFiles($section) as $rel) {
            try {
                $canonical = $ctx->reader->readJsonc($rel);
            } catch (DatasetException $e) {
                $report->failed($section, $e->getMessage());
                continue;
            }

            if ($ctx->merge) {
                $conflict = $this->mergeConflict($ctx, $canonical);
                if ($conflict !== null) {
                    $report->failed($section, "{$rel}: {$conflict}");
                    continue;
                }
                $canonical = $this->forMerge($canonical);
            }

            try {
                $result = $this->apply($canonical);
            } catch (\Throwable $e) {
                $report->failed($section, "{$rel}: {$e->getMessage()}");
                continue;
            }
            if (!$result->success || $result->savedId === null) {
                $report->failed($section, "{$rel}: " . self::describeFailure($result));
                continue;
            }

            try {
                $this->afterApply($ctx, $canonical, $result->savedId);
            } catch (\Throwable $e) {
                $report->warning("{$section} {$rel}: uloženo (#{$result->savedId}), ale dorovnání selhalo: {$e->getMessage()}");
            }
            foreach (self::warningIssues($result) as $w) {
                $report->warning("{$section} {$rel}: {$w}");
            }
            $report->ok($section);
        }
    }

    /**
     * @param array<string, mixed> $canonical
     */
    abstract protected function apply(array $canonical): ApplyResult;

    /**
     * Merge režim (`--no-reset`): applieři doplňují do existujících záznamů.
     *
     * @param array<string, mixed> $canonical
     * @return array<string, mixed>
     */
    protected function forMerge(array $canonical): array
    {
        $canonical['applyOptions'] = ($canonical['applyOptions'] ?? []) + [];
        $canonical['applyOptions']['mergeStrategy'] = 'mergeAdd';
        return $canonical;
    }

    /**
     * @param array<string, mixed> $canonical
     * @return string|null důvod, proč záznam v merge režimu nelze vložit
     */
    protected function mergeConflict(SeedContext $ctx, array $canonical): ?string
    {
        return null;
    }

    /**
     * @param array<string, mixed> $canonical
     */
    protected function afterApply(SeedContext $ctx, array $canonical, int $savedId): void
    {
    }

    /**
     * Varování applieru (`_resolve.issues` se severity warning) — např.
     * `account_not_found` na řádku, když DS nemá účtový rozvrh. Sloučené
     * podle kódu, aby výstup nezaplavil doklad s padesáti řádky.
     *
     * @return list<string>
     */
    public static function warningIssues(ApplyResult $result): array
    {
        $issues = $result->canonical['_resolve']['issues'] ?? null;
        if (!is_array($issues)) {
            return [];
        }
        $byCode = [];
        foreach ($issues as $i) {
            if (!is_array($i) || ($i['severity'] ?? '') !== 'warning') {
                continue;
            }
            $code = (string) ($i['code'] ?? 'warning');
            $byCode[$code] ??= ['n' => 0, 'message' => (string) ($i['message'] ?? ''), 'path' => (string) ($i['path'] ?? '')];
            $byCode[$code]['n']++;
        }
        $out = [];
        foreach ($byCode as $code => $w) {
            $out[] = $code . ($w['n'] > 1 ? " ×{$w['n']}" : ($w['path'] !== '' ? " @{$w['path']}" : '')) . ($w['message'] !== '' ? " — {$w['message']}" : '');
        }
        return $out;
    }

    public static function describeFailure(ApplyResult $result): string
    {
        $msg = ($result->errorCode ?? 'error') . ' — ' . ($result->errorMessage ?? '');
        $issues = $result->canonical['_resolve']['issues'] ?? null;
        if (is_array($issues) && $issues !== []) {
            $parts = [];
            foreach (array_slice($issues, 0, 3) as $i) {
                if (!is_array($i)) {
                    continue;
                }
                $parts[] = trim((string) ($i['path'] ?? '') . ' ' . (string) ($i['code'] ?? ''));
            }
            if ($parts !== []) {
                $msg .= ' [' . implode(', ', $parts) . ']';
            }
        }
        return $msg;
    }
}
