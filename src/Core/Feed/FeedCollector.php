<?php

declare(strict_types=1);

namespace Shipard\Core\Feed;

use Shipard\Core\Alerts\AlertCheckRegistry;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Logging\ErrorLogger;
use Shipard\Module\Core\Alerts\Feed\AlertsSource;
use Shipard\Module\Core\Exchange\Dashboard\ContentTagSuggestionsSource;
use Shipard\Module\Core\Mail\Feed\MailDigestSource;
use Shipard\Module\Core\Mail\Feed\MailSuggestionsSource;

/**
 * Sběr karet domovského feedu — „one calculation, N presentations".
 *
 * Extrahováno z `DashboardController` (UI shells Fáze 3): dashboard, AI shrnutí
 * i badge stavů sekcí stojí nad týmiž kartami. Zdroje zůstávají napevno
 * registrované (dashboard.md D10), registrace se řídí přítomností klíčových
 * tabulek na DS (D8). Bezstavová služba bez konstruktoru — wiring přes
 * `new FeedCollector()` v controlleru (v repu není DI kontejner).
 *
 * Detaily: `docs/dashboard.md`.
 */
final class FeedCollector
{
    /** Strop počtu karet feedu; při ořezu controller přidá info kartu „a další…". */
    public const int MAX_CARDS = 30;

    /** Prioritní žebříček pásem karet (nižší = výše). Sekundárně timestamp DESC. */
    private const array KIND_ORDER = ['urgent' => 0, 'review' => 1, 'ready' => 2, 'info' => 3];

    /**
     * Posbírá karty ze zdrojů feedu, seřadí a stropuje (`sortAndCap`).
     *
     * Vrácené karty NESOU interní pole `amount`/`currency` (podklad pro
     * readySummary) — prezentační vrstva je před odesláním klientovi odstraní
     * přes `stripInternalFields()`.
     *
     * @param array<string, \Shipard\Core\Database\TableDefinition> $tables
     *        Runtime definice tabulek — řídí registraci zdrojů (D8).
     *        Prázdná mapa = fail-closed.
     * @return array{0: list<array<string,mixed>>, 1: bool}
     *         [karty (seřazené, stropnuté, s interními poli), zda došlo k ořezu]
     */
    public function collect(
        DataSourceConnection $db,
        ?ConfigRuntime $config,
        string $lang,
        ?AlertCheckRegistry $alertRegistry = null,
        array $tables = [],
    ): array {
        $ctx = new FeedContext($db, $config, $lang, self::MAX_CARDS);

        // Zdroje se registrují podle přítomnosti klíčové tabulky na DS (D8) —
        // feed nesmí padat na DS bez core.mail / core.alerts (hosting DS).
        // Mapování tabulka → zdroj drží collector; zdroje zůstávají napevno
        // registrované (dashboard.md D10), žádné nové rozhraní na FeedSource.
        /** @var list<FeedSource> $sources */
        $sources = [];
        if (isset($tables['core_mail_incoming_messages'])) {
            $sources[] = new MailSuggestionsSource();
            $sources[] = new MailDigestSource();
        }
        if (isset($tables['core_alerts_alerts'])) {
            $sources[] = new AlertsSource($alertRegistry);
        }
        // Karta „Nová kategorie" (content-tag-ui D25) — potřebuje analýzy
        // (štítky návrhů), položky (pokrytí štítků) i osnovu (volba účtu
        // goods.stock + materializace).
        if (isset($tables['core_mail_message_analyses'], $tables['economy_items'], $tables['economy_accounting_accounts'])) {
            $sources[] = new ContentTagSuggestionsSource();
        }

        $cards = [];
        foreach ($sources as $src) {
            // Per-source izolace (D8): výjimka jednoho zdroje se zaloguje
            // a feed pokračuje ostatními zdroji.
            try {
                foreach ($src->collectCards($ctx) as $card) {
                    $cards[] = $card;
                }
            } catch (\Throwable $e) {
                ErrorLogger::logException($e, 'Dashboard feed source failed: ' . $src::class);
            }
        }

        return $this->sortAndCap($cards, self::MAX_CARDS);
    }

    /**
     * Odstraní interní pole `amount`/`currency` (podklad pro readySummary)
     * ze všech karet — do kartového kontraktu (docs/dashboard.md §4) nepatří.
     *
     * @param  list<array<string,mixed>> $cards
     * @return list<array<string,mixed>>
     */
    public function stripInternalFields(array $cards): array
    {
        foreach ($cards as &$card) {
            unset($card['amount'], $card['currency']);
        }
        return $cards;
    }

    /**
     * Seřadí karty dle prioritního žebříčku (`KIND_ORDER`), uvnitř pásma dle
     * `timestamp` sestupně (nejnovější první; karty bez timestampu naspod), a
     * ořízne na `$max`.
     *
     * @param  list<array<string,mixed>> $cards
     * @return array{0: list<array<string,mixed>>, 1: bool}  [seřazené+oříznuté, zda došlo k ořezu]
     */
    public function sortAndCap(array $cards, int $max): array
    {
        usort($cards, static function (array $a, array $b): int {
            $oa = self::KIND_ORDER[$a['kind'] ?? ''] ?? 99;
            $ob = self::KIND_ORDER[$b['kind'] ?? ''] ?? 99;
            if ($oa !== $ob) {
                return $oa <=> $ob;
            }
            $ta = (string) ($a['timestamp'] ?? '');
            $tb = (string) ($b['timestamp'] ?? '');
            if ($ta === $tb) {
                return 0;
            }
            if ($ta === '') {
                return 1;
            }
            if ($tb === '') {
                return -1;
            }
            return strcmp($tb, $ta); // ATOM formát řadí lexikálně = chronologicky
        });

        $truncated = count($cards) > $max;
        if ($truncated) {
            $cards = array_slice($cards, 0, $max);
        }
        return [$cards, $truncated];
    }

    /**
     * Počty karet dle kind (jen actionable pásma — urgent/review/ready).
     * Info karty (vč. „a další…") se nezapočítávají.
     *
     * @param  list<array<string,mixed>> $cards
     * @return array{urgent:int, review:int, ready:int}
     */
    public function countByKind(array $cards): array
    {
        $counts = ['urgent' => 0, 'review' => 0, 'ready' => 0];
        foreach ($cards as $card) {
            $kind = (string) ($card['kind'] ?? '');
            if (isset($counts[$kind])) {
                $counts[$kind]++;
            }
        }
        return $counts;
    }
}
