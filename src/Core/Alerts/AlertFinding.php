<?php

declare(strict_types=1);

namespace Shipard\Core\Alerts;

/**
 * Jeden konkrétní nález vrácený z `AlertCheck::run()`.
 *
 * Reconciler dvojicí (check_id, findingKey) sjednocuje výsledky dvou běhů —
 * stejná dvojice = stejná logická událost, jen aktualizovat. Pro singleton
 * checky (problém je buď, nebo není) použij prázdný `findingKey`.
 *
 * Lokalizace: `title`/`message`/`actions[].label` musí check vrátit **už
 * v jazyce DS** (`DataSourceConfig::getDefaultLanguage()`) — alerty se
 * neproženou ConfigLocalizerem za běhu vieweru.
 */
final readonly class AlertFinding
{
    public const SEVERITIES = ['info', 'warning', 'error'];

    /**
     * @param array<int, array<string, mixed>> $actions Pole akcí pro UI.
     *        Schéma: [{id, label, kind, target, primary?}], max 1 primary.
     * @param ?array<string, mixed> $context Volné meta pole (debugging,
     *        countery, IDs). Žádné citlivé hodnoty.
     */
    public function __construct(
        public string $findingKey,
        public string $title,
        public string $message = '',
        public string $severity = 'warning',
        public ?int $subjectTableId = null,
        public ?int $subjectRowId = null,
        public array $actions = [],
        public ?array $context = null,
    ) {
        if ($title === '') {
            throw new \InvalidArgumentException('AlertFinding: title must not be empty');
        }

        if (!in_array($severity, self::SEVERITIES, true)) {
            throw new \InvalidArgumentException(
                "AlertFinding: severity must be one of " . implode('|', self::SEVERITIES)
                . ", got '{$severity}'",
            );
        }

        $primaryCount = 0;
        foreach ($actions as $i => $action) {
            if (!is_array($action)) {
                throw new \InvalidArgumentException(
                    "AlertFinding: actions[{$i}] must be an array",
                );
            }
            if (!empty($action['primary'])) {
                $primaryCount++;
            }
        }
        if ($primaryCount > 1) {
            throw new \InvalidArgumentException(
                "AlertFinding: at most one action may be primary, got {$primaryCount}",
            );
        }

        if ($subjectTableId === null xor $subjectRowId === null) {
            throw new \InvalidArgumentException(
                'AlertFinding: subjectTableId and subjectRowId must be both set or both null',
            );
        }
    }
}
