<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\BookingHistory;

use Dibi\Connection;

/**
 * Zápis seed pravidel `IČO → obsahový štítek` do `core_exchange_tag_rules`
 * (D32).
 *
 * Pravidla přednosti — pravidlo uživatele nebo naučené pravidlo je
 * **nadřazené** importu:
 *
 *  - žádné pravidlo → INSERT s `origin = 'seed'`, `confirmed = 1`,
 *  - existující se stejným štítkem → nic (jen `same` v plánu),
 *  - existující `seed` s jiným štítkem → UPDATE (nový import ví víc),
 *  - existující `user` / `learned` s jiným štítkem → **skip** + záznam
 *    v plánu; import cizí znalost nepřepisuje.
 *
 * Zapisuje se **přímým SQL, ne přes TagRuleDocument**: ten při změně
 * štítku existujícího záznamu překlápí `origin` na `'user'` (uživatel
 * přebil pravidlo, D28). U importu by to z aktualizovaného seedu udělalo
 * ruční pravidlo, které by pak žádný další import nesměl opravit. Stejný
 * důvod, proč přímé SQL používá i ContentTagRuleCaptureHandler.
 *
 * Ne-final kvůli testům — `Connection::query` je final, testy přepisují
 * {@see executeSql()} subclassingem (vzor ContentTagRuleCaptureHandler).
 */
class SeedApplier
{
    private const TABLE = 'core_exchange_tag_rules';

    public function __construct(
        private readonly Connection $db,
    ) {}

    /**
     * Plán bez zápisu — vstup pro `--dry-run` i pro sloupec „Plán zápisu"
     * v reportu.
     *
     * @param list<SeedCandidate> $candidates
     * @return array<string, array{action: string, tag: string, existingTag?: string, existingOrigin?: string}>
     *         klíč = IČO, `action` ∈ insert | update | skip | same
     */
    public function plan(array $candidates): array
    {
        $plan = [];
        foreach ($candidates as $candidate) {
            $existing = $this->existingRule($candidate->companyId);
            if ($existing === null) {
                $plan[$candidate->companyId] = ['action' => 'insert', 'tag' => $candidate->tag];
                continue;
            }
            $entry = [
                'tag'            => $candidate->tag,
                'existingTag'    => $existing['tag'],
                'existingOrigin' => $existing['origin'],
            ];
            if ($existing['tag'] === $candidate->tag) {
                $entry['action'] = 'same';
            } elseif ($existing['origin'] === 'seed') {
                $entry['action'] = 'update';
            } else {
                $entry['action'] = 'skip';
            }
            $plan[$candidate->companyId] = $entry;
        }
        return $plan;
    }

    /**
     * Provede plán. Vrací počty per akce — CLI je vypíše, testy je ověří.
     *
     * @param list<SeedCandidate> $candidates
     * @return array{inserted: int, updated: int, skipped: int, same: int}
     */
    public function apply(array $candidates): array
    {
        $counts = ['inserted' => 0, 'updated' => 0, 'skipped' => 0, 'same' => 0];
        $now = date('Y-m-d H:i:s');

        foreach ($this->plan($candidates) as $companyId => $entry) {
            switch ($entry['action']) {
                case 'insert':
                    $this->executeSql(
                        'INSERT INTO %n ([company_id], [tag], [origin], [confirmed], [hit_count], [created], [modified])'
                        . ' VALUES (%s, %s, %s, 1, 0, %s, %s)',
                        self::TABLE,
                        (string) $companyId,
                        $entry['tag'],
                        'seed',
                        $now,
                        $now,
                    );
                    $counts['inserted']++;
                    break;

                case 'update':
                    $this->executeSql(
                        'UPDATE %n SET [tag] = %s, [modified] = %s WHERE [company_id] = %s AND [origin] = %s',
                        self::TABLE,
                        $entry['tag'],
                        $now,
                        (string) $companyId,
                        'seed',
                    );
                    $counts['updated']++;
                    break;

                case 'skip':
                    $counts['skipped']++;
                    break;

                default:
                    $counts['same']++;
            }
        }
        return $counts;
    }

    /** @return array{tag: string, origin: string}|null */
    private function existingRule(string $companyId): ?array
    {
        $row = $this->db->fetch(
            'SELECT [tag], [origin] FROM %n WHERE [company_id] = %s',
            self::TABLE,
            $companyId,
        );
        if ($row === null || $row === false) {
            return null;
        }
        return ['tag' => (string) $row['tag'], 'origin' => (string) ($row['origin'] ?? '')];
    }

    /** Wrapper nad Connection::query (final) — testy ho přepisují. */
    protected function executeSql(mixed ...$args): void
    {
        $this->db->query(...$args);
    }
}
