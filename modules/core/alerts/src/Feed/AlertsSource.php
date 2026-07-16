<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Alerts\Feed;

use Shipard\Core\Feed\FeedContext;
use Shipard\Core\Feed\FeedSource;

/**
 * Feed zdroj deterministických alertů — aktivní alerty (`alert_state=10`,
 * Snoozed/Resolved/Dismissed NE) jako karty feedu.
 *
 * `severity` → `kind`: error(30)→urgent, warning(20)→review, info(10)→info.
 * `actions[]` alertu se **propíšou beze změny** — jsou už `open_form`/
 * `open_viewer` a už lokalizované (check je vyrábí lokalizované při reconcile).
 * Alert karty tedy jen navigují; snooze/dismiss zůstává ve viewer detailu.
 *
 * `title` = titulek alertu, `subtitle` = zpráva alertu (fallback check_id),
 * `timestamp` = `last_seen_at` (fallback `first_seen_at`), `id` = `"alert:{id}"`.
 */
final class AlertsSource implements FeedSource
{
    private const TABLE = 'core_alerts_alerts';

    /** alert_state Active — viz core.alerts.alertStates. */
    private const STATE_ACTIVE = 10;

    private const SEVERITY_ERROR   = 30;
    private const SEVERITY_WARNING = 20;
    private const SEVERITY_INFO    = 10;

    public function collectCards(FeedContext $ctx): array
    {
        $rows = $ctx->db->fetchAll(
            'SELECT `id`, `check_id`, `title`, `message`, `severity`, `actions`,'
            . ' `first_seen_at`, `last_seen_at`'
            . ' FROM `' . self::TABLE . '`'
            . ' WHERE `alert_state` = %i'
            . ' ORDER BY `severity` DESC, `last_seen_at` DESC, `id` DESC'
            . ' LIMIT %i',
            self::STATE_ACTIVE,
            $ctx->maxCards,
        );

        $cards = [];
        foreach ($rows as $row) {
            $cards[] = $this->buildCard($row);
        }
        return $cards;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function buildCard(array $row): array
    {
        $id       = (int) $row['id'];
        $severity = (int) ($row['severity'] ?? self::SEVERITY_WARNING);

        [$kind, $stateStyle, $icon] = match ($severity) {
            self::SEVERITY_ERROR => ['urgent', 'error', 'alert'],
            self::SEVERITY_INFO  => ['info', 'concept', 'info'],
            default              => ['review', 'edit', 'warning'],
        };

        $message  = trim((string) ($row['message'] ?? ''));
        $checkId  = (string) ($row['check_id'] ?? '');
        $subtitle = $message !== '' ? $message : $checkId;

        return [
            'id'         => 'alert:' . $id,
            'source'     => 'alerts',
            'kind'       => $kind,
            'icon'       => $icon,
            'stateStyle' => $stateStyle,
            'category'   => FeedSource::CATEGORY_OTHER,
            'title'      => (string) ($row['title'] ?? ''),
            'subtitle'   => $subtitle,
            'timestamp'  => $this->toAtom($row['last_seen_at'] ?? null) ?? $this->toAtom($row['first_seen_at'] ?? null),
            'context'    => [
                'alertId'  => $id,
                'checkId'  => $checkId,
                'severity' => $severity,
            ],
            'actions'    => $this->passthroughActions($row['actions'] ?? null),
        ];
    }

    /**
     * Dekóduje `actions` sloupec alertu a propíše ho beze změny (jen sanitizace
     * na list of objektů). Actions jsou už `open_form`/`open_viewer` a
     * lokalizované; feed je jen renderuje.
     *
     * @return list<array<string,mixed>>
     */
    private function passthroughActions(mixed $raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }
        $decoded = is_string($raw) ? json_decode($raw, true) : (is_array($raw) ? $raw : null);
        if (!is_array($decoded)) {
            return [];
        }

        $out = [];
        foreach ($decoded as $action) {
            if (is_array($action) && isset($action['kind'])) {
                $out[] = $action;
            }
        }
        return $out;
    }

    /** DB datetime → ATOM; null/prázdné → null. */
    private function toAtom(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::ATOM);
        }
        try {
            return (new \DateTimeImmutable((string) $value))->format(\DateTimeInterface::ATOM);
        } catch (\Exception) {
            return null;
        }
    }
}
