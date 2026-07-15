<?php

declare(strict_types=1);

namespace Shipard\Module\Base\Registry;

use Shipard\Core\Alerts\AlertCheck;
use Shipard\Core\Alerts\AlertFinding;

/**
 * Hlídání expirací dokumentů Spisovny (registry-mvp §9).
 *
 * Vybírá zařazené dokumenty (docState 40 + 80 „V opravě"; koncepty 10 se
 * nehlídají, 70/90 jsou legitimní umlčení alertu) s `valid_to` v horizontu
 * `expiration.warnDaysBefore` svého druhu (base.registry.docKinds). Severity:
 * po termínu = error, do min(warnDaysBefore) = warning, do max = info.
 *
 * `finding_key` = "doc_{id}" — stabilní napříč běhy i změnou severity;
 * prodloužení platnosti nebo přechod do 70/90 finding nevrátí a reconciler
 * alert uzavře.
 *
 * Registrace: modules/base/registry/module.jsonc → alertChecks
 * (id base.registry.expirations).
 */
class RegistryExpirationAlertCheck extends AlertCheck
{
    /** Stable tableId of base_registry_documents — see tables/base_registry_documents.jsonc. */
    private const SUBJECT_TABLE_ID = 428;

    private const WATCHED_STATES = [40, 80];

    public function run(): array
    {
        $kinds = $this->expirationKinds();
        if ($kinds === []) {
            return [];
        }

        $today = $this->now()->setTime(0, 0);
        $globalMaxWarn = max(array_column($kinds, 'max'));
        $horizon = $today->modify('+' . $globalMaxWarn . ' days')->format('Y-m-d');

        $rows = $this->db->fetchAll(
            'SELECT d.[id], d.[title], d.[doc_kind], d.[valid_to], p.[full_name] AS partner_name
             FROM [base_registry_documents] d
             LEFT JOIN [base_persons_persons] p ON p.[id] = d.[partner]
             WHERE d.[docState] IN %in
               AND d.[valid_to] IS NOT NULL
               AND d.[doc_kind] IN %in
               AND d.[valid_to] <= %s
             ORDER BY d.[valid_to] ASC',
            self::WATCHED_STATES,
            array_keys($kinds),
            $horizon,
        );

        $findings = [];
        foreach ($rows as $row) {
            $arr = $row instanceof \Dibi\Row ? $row->toArray() : (array) $row;
            $kind = (string) ($arr['doc_kind'] ?? '');
            $spec = $kinds[$kind] ?? null;
            if ($spec === null) {
                continue;
            }
            $validTo = $this->normalizeDate($arr['valid_to'] ?? '');
            if ($validTo === '') {
                continue;
            }
            $days = $this->daysUntil($validTo, $today);
            if ($days > $spec['max']) {
                // globální SQL horizont je max přes všechny druhy — řádek
                // mimo horizont svého druhu ještě nehlásíme
                continue;
            }
            $findings[] = $this->buildFinding($arr, $spec, $validTo, $days);
        }
        return $findings;
    }

    protected function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable();
    }

    /**
     * Druhy s neprázdnou `expiration.warnDaysBefore`.
     *
     * @return array<string, array{min: int, max: int, label: string}>
     */
    private function expirationKinds(): array
    {
        $cfg = $this->config->cfgItem('base.registry.docKinds');
        if (!is_array($cfg)) {
            return [];
        }
        $kinds = [];
        foreach ($cfg as $kind => $def) {
            $warn = is_array($def) ? ($def['expiration']['warnDaysBefore'] ?? null) : null;
            if (!is_array($warn) || $warn === []) {
                continue;
            }
            $warn = array_map(intval(...), $warn);
            $kinds[(string) $kind] = [
                'min'   => min($warn),
                'max'   => max($warn),
                'label' => (string) ($def['name'] ?? $kind),
            ];
        }
        return $kinds;
    }

    /**
     * @param array<string, mixed> $row
     * @param array{min: int, max: int, label: string} $spec
     */
    private function buildFinding(array $row, array $spec, string $validTo, int $days): AlertFinding
    {
        $id = (int) $row['id'];
        $severity = $days < 0 ? 'error' : ($days <= $spec['min'] ? 'warning' : 'info');
        $partner = (string) ($row['partner_name'] ?? '');

        return new AlertFinding(
            findingKey: 'doc_' . $id,
            title: $spec['label'] . ': ' . (string) $row['title'],
            message: $this->buildMessage($validTo, $days, $partner),
            severity: $severity,
            subjectTableId: self::SUBJECT_TABLE_ID,
            subjectRowId: $id,
            actions: [
                [
                    'id'      => 'open_doc',
                    'label'   => $this->language === 'cs' ? 'Otevřít dokument' : 'Open document',
                    'kind'    => 'open_form',
                    'variant' => 'primary',
                    'primary' => true,
                    'target'  => [
                        'table' => 'base_registry_documents',
                        'mode'  => 'edit',
                        'id'    => $id,
                    ],
                ],
            ],
            context: [
                'valid_to' => $validTo,
                'days'     => $days,
                'doc_kind' => (string) $row['doc_kind'],
            ],
        );
    }

    private function buildMessage(string $validTo, int $days, string $partner): string
    {
        $date = $this->formatDate($validTo);
        if ($this->language === 'cs') {
            $msg = match (true) {
                $days < 0  => sprintf('Platnost skončila před %s (%s).', $this->czechDaysAgo(-$days), $date),
                $days === 0 => sprintf('Platnost končí dnes (%s).', $date),
                default    => sprintf('Platnost vyprší za %s (%s).', $this->czechDaysIn($days), $date),
            };
            return $partner !== '' ? $msg . ' Partner: ' . $partner . '.' : $msg;
        }
        $msg = match (true) {
            $days < 0  => sprintf('Expired %d day%s ago (%s).', -$days, $days === -1 ? '' : 's', $date),
            $days === 0 => sprintf('Expires today (%s).', $date),
            default    => sprintf('Expires in %d day%s (%s).', $days, $days === 1 ? '' : 's', $date),
        };
        return $partner !== '' ? $msg . ' Partner: ' . $partner . '.' : $msg;
    }

    /** "1 dnem" / "2 dny" / "5 dny" — instrumentál po „před" */
    private function czechDaysAgo(int $n): string
    {
        return $n === 1 ? '1 dnem' : $n . ' dny';
    }

    /** "1 den" / "2 dny" / "5 dní" — akuzativ po „za" */
    private function czechDaysIn(int $n): string
    {
        if ($n === 1) {
            return '1 den';
        }
        if ($n >= 2 && $n <= 4) {
            return $n . ' dny';
        }
        return $n . ' dní';
    }

    private function daysUntil(string $validTo, \DateTimeImmutable $today): int
    {
        $ts = strtotime($validTo . ' 00:00:00');
        if ($ts === false) {
            return 0;
        }
        return (int) floor(($ts - $today->getTimestamp()) / 86400);
    }

    private function formatDate(string $date): string
    {
        $ts = strtotime($date);
        if ($ts === false) {
            return $date;
        }
        return $this->language === 'cs' ? date('j. n. Y', $ts) : date('Y-m-d', $ts);
    }

    /** `date` sloupce z Dibi chodí jako \Dibi\DateTime, ne string. */
    private function normalizeDate(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        return (string) $value;
    }
}
