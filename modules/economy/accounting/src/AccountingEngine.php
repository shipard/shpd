<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Accounting;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Module\Docs\Core\OwnCompanyResolver;

/**
 * Generátor účetního deníku z dokladu — obecný interpret deklarativního
 * účtovacího předpisu (cfgItem `economy.accounting.rules.{country}`).
 *
 * Vstup: id hlavičky dokladu (head + rows + vat_recap si načte z DB —
 * Fáze 1 garantuje, že computed sloupce vč. _dom jsou v DB aktuální).
 * Výstup: přepsané řádky deníku (DELETE + INSERT, idempotentní) +
 * aktualizované accounting_state / accounting_messages na hlavičce.
 * Vše v jedné transakci.
 *
 * Filozofie chyb: účtování nikdy neblokuje doklad. Nedohledaný účet →
 * chybový řádek deníku (account NULL, maska s '?', is_error) + message;
 * fatálnější problémy (chybí předpis, fiskální období) → prázdný deník.
 * Výsledek vždy accounting_state 1 (OK) / 2 (cokoliv v messages).
 *
 * Algoritmus a sémantika kroků předpisu: docs/accounting.md sekce 4, 5, 7.3.
 *
 * Reverse charge: primární řádek rekapitulace nese odpočet (účtuje se na
 * stranu kroku), oddaňovací pár (`is_reverse_pair = 1`) na stranu opačnou —
 * deník je vyrovnaný a obě strany dostanou analytiku svého vat kódu.
 */
final class AccountingEngine
{
    /** Délka čísla účtu pro chybovou masku (504 → '504???'). */
    private const ACCOUNT_NUMBER_LENGTH = 6;

    private const ITEM_TYPE_ACC_ENTRY = 2;

    /** Per-run dohledávač účtů dle masky (cache se nuluje s novým během). */
    private AccountMaskResolver $maskResolver;

    /** @var list<array{code: string, message: string, rowId: int|null}> */
    private array $messages = [];

    public function __construct(
        private readonly \Dibi\Connection $db,
        private readonly ?ConfigRuntime $config,
    ) {}

    /**
     * Přeúčtuje doklad: smaže starý deník, vygeneruje nový, uloží stav.
     *
     * @return array{state: int, messages: list<array{code: string, message: string, rowId: int|null}>}
     */
    public function accountDocument(int $docHeadId): array
    {
        $this->messages = [];
        $this->maskResolver = new AccountMaskResolver($this->db);

        $headRow = $this->db->fetch(
            'SELECT * FROM [docs_core_heads] WHERE [id] = %i',
            $docHeadId,
        );
        if ($headRow === null) {
            throw new \DomainException("Doklad #{$docHeadId} nenalezen");
        }
        $head = $headRow->toArray();

        $steps = $this->resolveSteps((string) ($head['doc_type'] ?? ''));
        if ($steps === null) {
            $this->addMessage(
                'rules_not_found',
                sprintf(
                    'Účtovací předpis pro typ dokladu %s nenalezen',
                    (string) ($head['doc_type'] ?? '?'),
                ),
            );
            return $this->writeResult($docHeadId, []);
        }

        if (empty($head['fiscal_year']) || empty($head['fiscal_month'])) {
            $this->addMessage(
                'fiscal_period_missing',
                'Doklad nemá přiřazený fiskální rok/měsíc — zkontroluj účetní datum a číselník období',
            );
            return $this->writeResult($docHeadId, []);
        }

        $rows = $this->loadRows($docHeadId);
        $recap = $this->loadVatRecap($docHeadId);

        $lines = [];
        foreach ($steps as $step) {
            foreach ($this->buildStepLines($step, $head, $rows, $recap) as $line) {
                $lines[] = $line;
            }
        }

        $grouped = $this->groupLines($lines);

        if ($grouped === []) {
            $this->addMessage('empty_journal', 'Z dokladu nevznikl žádný řádek deníku');
            return $this->writeResult($docHeadId, []);
        }

        $sumDr = 0.0;
        $sumCr = 0.0;
        foreach ($grouped as $line) {
            $sumDr += $line['money_dr'];
            $sumCr += $line['money_cr'];
        }
        if (round($sumDr, 2) !== round($sumCr, 2)) {
            $this->addMessage(
                'unbalanced',
                sprintf('Deník není vyrovnaný: MD %.2f ≠ DAL %.2f', $sumDr, $sumCr),
            );
        }

        return $this->writeResult($docHeadId, $grouped, $head);
    }

    // ── Předpis ─────────────────────────────────────────────────────────────

    /**
     * Kroky předpisu pro docType — předpis dle země vlastní firmy,
     * fallback cz. Vrací null, když předpis nebo docType sekce chybí.
     *
     * @return list<array<string, mixed>>|null
     */
    private function resolveSteps(string $docType): ?array
    {
        $rules = $this->resolveRules();
        if ($rules === null || $docType === '') {
            return null;
        }
        foreach ($rules['documents'] ?? [] as $doc) {
            if (is_array($doc) && ($doc['docType'] ?? null) === $docType) {
                $steps = $doc['accounting'] ?? null;
                return is_array($steps) ? array_values($steps) : null;
            }
        }
        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveRules(): ?array
    {
        if ($this->config === null) {
            return null;
        }
        $country = $this->resolveOwnCompanyCountry();
        $rules = $this->config->cfgItem("economy.accounting.rules.{$country}");
        if (!is_array($rules)) {
            $rules = $this->config->cfgItem('economy.accounting.rules.cz');
        }
        return is_array($rules) ? $rules : null;
    }

    private function resolveOwnCompanyCountry(): string
    {
        $address = (new OwnCompanyResolver($this->db))->getOwnHeadquartersAddress();
        $country = strtolower(trim((string) ($address['country'] ?? '')));
        return $country !== '' ? $country : 'cz';
    }

    // ── Kroky → kandidátní řádky ────────────────────────────────────────────

    /**
     * @param array<string, mixed> $step
     * @param array<string, mixed> $head
     * @param list<array<string, mixed>> $rows
     * @param list<array<string, mixed>> $recap
     * @return list<array<string, mixed>>
     */
    private function buildStepLines(array $step, array $head, array $rows, array $recap): array
    {
        $src = (string) ($step['src'] ?? '');
        return match ($src) {
            'rows' => $this->buildRowLines($step, $head, $rows),
            'vat'  => $this->buildVatLines($step, $head, $recap),
            'head' => $this->buildHeadLines($step, $head),
            default => [],
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildRowLines(array $step, array $head, array $rows): array
    {
        $allowedOps = null;
        if (isset($step['operation'])) {
            $allowedOps = [(string) $step['operation']];
        } elseif (isset($step['operations']) && is_array($step['operations'])) {
            $allowedOps = array_map(strval(...), $step['operations']);
        }

        $lines = [];
        foreach ($rows as $row) {
            $operation = (string) ($row['operation'] ?? '');
            if ($allowedOps !== null && !in_array($operation, $allowedOps, true)) {
                continue;
            }
            if (!$this->matchesQuery($step, $row)) {
                continue;
            }

            $dom = (float) ($row['vat_base_dom'] ?? 0);
            $cur = (float) ($row['vat_base'] ?? 0);
            if (!$this->passesSignAndReverse($step, $dom, $cur)) {
                continue;
            }
            if ($dom === 0.0 && $cur === 0.0) {
                continue;
            }

            $rowId = (int) $row['id'];
            $account = isset($step['accountSrc']) && $step['accountSrc'] === 'item'
                ? $this->resolveItemAccount($row, $rowId)
                : $this->resolveCategoryAccount($step, $row, $head, $rowId);

            $lines[] = $this->makeLine(
                $step,
                $head,
                $account,
                $dom,
                $cur,
                text: (string) ($step['text'] ?? $row['description'] ?? ''),
                operation: $operation !== '' ? $operation : null,
                rowId: $rowId,
            );
        }
        return $lines;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildVatLines(array $step, array $head, array $recap): array
    {
        $lines = [];
        foreach ($recap as $r) {
            // Odvozené pole pro query kroků i mapování účtů (cz-110 → cz).
            // Nepersistuje se — existuje jen po dobu matchování; umožňuje
            // omezit fallback masky na tuzemské kódy (viz accounts sekce
            // předpisu), aby zahraniční kód bez mapování selhal hlasitě.
            $r['vat_code_country'] = self::vatCodeCountry((string) ($r['vat_code'] ?? ''));

            if (!$this->matchesQuery($step, $r)) {
                continue;
            }
            $dom = (float) ($r['tax_dom'] ?? 0);
            $cur = (float) ($r['tax'] ?? 0);
            if (!$this->passesSignAndReverse($step, $dom, $cur)) {
                continue;
            }
            if ($dom === 0.0 && $cur === 0.0) {
                continue;
            }

            $defaultText = sprintf(
                'DPH %s %s%%',
                (string) ($r['vat_code'] ?? ''),
                rtrim(rtrim(number_format((float) ($r['vat_pct'] ?? 0), 2, '.', ''), '0'), '.'),
            );

            // Oddaňovací pár reverse charge jde na opačnou stranu než krok —
            // primární řádek nese odpočet (strana kroku), pár samovyměření.
            // Nezávislé na reverseSign (ten otáčí znaménko částky).
            $lineStep = $step;
            if (!empty($r['is_reverse_pair'])) {
                $lineStep['side'] = ((int) ($step['side'] ?? 0)) === 0 ? 1 : 0;
            }

            $lines[] = $this->makeLine(
                $lineStep,
                $head,
                $this->resolveCategoryAccount($step, $r, $head, null),
                $dom,
                $cur,
                text: (string) ($step['text'] ?? $defaultText),
                operation: null,
                rowId: null,
            );
        }
        return $lines;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildHeadLines(array $step, array $head): array
    {
        if (!$this->matchesQuery($step, $head)) {
            return [];
        }

        $col = (string) ($step['col'] ?? 'total');
        [$dom, $cur] = $col === 'rounding'
            ? [(float) ($head['total_rounding_dom'] ?? 0), (float) ($head['total_rounding'] ?? 0)]
            : [(float) ($head['total_amount_dom'] ?? 0), (float) ($head['total_amount'] ?? 0)];

        if (!$this->passesSignAndReverse($step, $dom, $cur)) {
            return [];
        }
        if ($dom === 0.0 && $cur === 0.0) {
            return [];
        }

        return [$this->makeLine(
            $step,
            $head,
            $this->resolveCategoryAccount($step, $head, $head, null),
            $dom,
            $cur,
            text: (string) ($step['text'] ?? $head['doc_text'] ?? ''),
            operation: null,
            rowId: null,
        )];
    }

    /** Část vat kódu před první pomlčkou, lowercase (`cz-110` → `cz`). */
    private static function vatCodeCountry(string $vatCode): string
    {
        $dash = strpos($vatCode, '-');
        return strtolower($dash === false ? $vatCode : substr($vatCode, 0, $dash));
    }

    /**
     * Filtr `sign` ('+' jen kladné, '-' jen záporné, hodnotí se domácí
     * částka; při nulové domácí rozhoduje cur) a `reverseSign` (otočení
     * znaménka obou částek — modifikuje $dom/$cur přes referenci).
     */
    private function passesSignAndReverse(array $step, float &$dom, float &$cur): bool
    {
        $probe = $dom !== 0.0 ? $dom : $cur;
        $sign = $step['sign'] ?? null;
        if ($sign === '+' && $probe <= 0.0) {
            return false;
        }
        if ($sign === '-' && $probe >= 0.0) {
            return false;
        }
        if (!empty($step['reverseSign'])) {
            $dom = -$dom;
            $cur = -$cur;
        }
        return true;
    }

    /**
     * Obecný filtr `query` {sloupec: hodnota} nad zdrojovým záznamem —
     * volné porovnání (DB vrací stringy, předpis píše čísla).
     */
    private function matchesQuery(array $step, array $record): bool
    {
        $query = $step['query'] ?? null;
        if (!is_array($query)) {
            return true;
        }
        foreach ($query as $col => $expected) {
            if (($record[$col] ?? null) != $expected) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param array{id: int, number: string, is_error?: bool}|array{number: string, is_error: bool} $account
     */
    private function makeLine(
        array $step,
        array $head,
        array $account,
        float $dom,
        float $cur,
        string $text,
        ?string $operation,
        ?int $rowId,
    ): array {
        $side = (int) ($step['side'] ?? 0);
        return [
            'side'           => $side,
            'account'        => $account['id'] ?? null,
            'account_number' => $account['number'],
            'is_error'       => !empty($account['is_error']),
            'operation'      => $operation,
            'partner'        => isset($head['partner']) && $head['partner'] !== null
                ? (int) $head['partner'] : null,
            'text'           => mb_substr($text, 0, 200),
            'money_dr'       => $side === 0 ? round($dom, 2) : 0.0,
            'money_cr'       => $side === 1 ? round($dom, 2) : 0.0,
            'money_dr_cur'   => $side === 0 ? round($cur, 2) : 0.0,
            'money_cr_cur'   => $side === 1 ? round($cur, 2) : 0.0,
            'rowId'          => $rowId,
        ];
    }

    // ── Dohledávání účtů ────────────────────────────────────────────────────

    /**
     * Účet přímo z položky řádku (pohyb acc.entry). Měkká kontrola:
     * položka musí být typ 2 (Účetní položka) a mít vyplněný účet —
     * jinak chybový řádek (konfigurace položky se může změnit nezávisle
     * na dokladu).
     *
     * @return array{id?: int, number: string, is_error?: bool}
     */
    private function resolveItemAccount(array $row, int $rowId): array
    {
        $itemId = (int) ($row['item'] ?? 0);
        $item = $itemId > 0
            ? $this->db->fetch(
                'SELECT [item_type], [accounting_account] FROM [economy_items] WHERE [id] = %i',
                $itemId,
            )
            : null;

        $accountId = $item !== null ? (int) ($item['accounting_account'] ?? 0) : 0;
        if ($item === null
            || (int) ($item['item_type'] ?? 0) !== self::ITEM_TYPE_ACC_ENTRY
            || $accountId === 0
        ) {
            $this->addMessage(
                'item_account_missing',
                'Položka řádku není typu Účetní položka nebo nemá vyplněný účet',
                $rowId,
            );
            return ['number' => str_repeat('?', self::ACCOUNT_NUMBER_LENGTH), 'is_error' => true];
        }

        $account = $this->db->fetch(
            'SELECT [id], [number] FROM [economy_accounting_accounts] WHERE [id] = %i',
            $accountId,
        );
        if ($account === null) {
            $this->addMessage(
                'item_account_missing',
                'Účet uvedený na položce řádku v rozvrhu neexistuje',
                $rowId,
            );
            return ['number' => str_repeat('?', self::ACCOUNT_NUMBER_LENGTH), 'is_error' => true];
        }

        return ['id' => (int) $account['id'], 'number' => (string) $account['number']];
    }

    /**
     * Kategorie kroku → maska (první záznam v accounts se shodnou cat a
     * vyhovující query nad zdrojovým záznamem) → účet v rozvrhu.
     *
     * @return array{id?: int, number: string, is_error?: bool}
     */
    private function resolveCategoryAccount(array $step, array $record, array $head, ?int $rowId): array
    {
        $cat = (string) ($step['cat'] ?? '');
        $rules = $this->resolveRules();

        $mask = null;
        foreach ($rules['accounts'] ?? [] as $entry) {
            if (!is_array($entry) || ($entry['cat'] ?? null) !== $cat) {
                continue;
            }
            if (!$this->matchesQuery($entry, $record)) {
                continue;
            }
            $mask = (string) ($entry['accountMask'] ?? '');
            break;
        }

        if ($mask === null || $mask === '') {
            $this->addMessage(
                'account_not_found',
                "Předpis nemá masku pro kategorii '{$cat}'",
                $rowId,
            );
            // Display-only: syntetika z poslední (nejobecnější) masky
            // kategorie, ať chybový řádek ukazuje aspoň oblast (343???).
            $hint = '';
            foreach ($rules['accounts'] ?? [] as $entry) {
                if (is_array($entry) && ($entry['cat'] ?? null) === $cat) {
                    $hint = substr((string) ($entry['accountMask'] ?? ''), 0, 3);
                }
            }
            return [
                'number'   => str_pad($hint, self::ACCOUNT_NUMBER_LENGTH, '?'),
                'is_error' => true,
            ];
        }

        $account = $this->maskResolver->resolve($mask, (string) ($head['accounting_date'] ?? ''));
        if ($account === null) {
            $this->addMessage(
                'account_not_found',
                "Účet nenalezen pro masku {$mask}",
                $rowId,
            );
            return [
                'number'   => str_pad($mask, self::ACCOUNT_NUMBER_LENGTH, '?'),
                'is_error' => true,
            ];
        }

        return $account;
    }

    // ── Seskupení a zápis ───────────────────────────────────────────────────

    /**
     * Seskupení klíčem (side, account_number, partner, operation) —
     * shodné řádky se sčítají (dom i cur), text z prvního řádku skupiny.
     *
     * @param list<array<string, mixed>> $lines
     * @return list<array<string, mixed>>
     */
    private function groupLines(array $lines): array
    {
        $grouped = [];
        foreach ($lines as $line) {
            $key = implode('|', [
                $line['side'],
                $line['account_number'],
                $line['partner'] ?? '',
                $line['operation'] ?? '',
            ]);
            if (!isset($grouped[$key])) {
                $grouped[$key] = $line;
                continue;
            }
            $grouped[$key]['money_dr']     = round($grouped[$key]['money_dr'] + $line['money_dr'], 2);
            $grouped[$key]['money_cr']     = round($grouped[$key]['money_cr'] + $line['money_cr'], 2);
            $grouped[$key]['money_dr_cur'] = round($grouped[$key]['money_dr_cur'] + $line['money_dr_cur'], 2);
            $grouped[$key]['money_cr_cur'] = round($grouped[$key]['money_cr_cur'] + $line['money_cr_cur'], 2);
            $grouped[$key]['is_error']     = $grouped[$key]['is_error'] || $line['is_error'];
        }
        return array_values($grouped);
    }

    /**
     * DELETE starého deníku + INSERT nových řádků + update hlavičky,
     * vše v jedné transakci. $grouped může být prázdné (chybové stavy
     * bez deníku).
     *
     * @param list<array<string, mixed>> $grouped
     * @return array{state: int, messages: list<array{code: string, message: string, rowId: int|null}>}
     */
    private function writeResult(int $docHeadId, array $grouped, array $head = []): array
    {
        $state = $this->messages === [] ? 1 : 2;

        $this->db->begin();
        try {
            $this->db->delete('economy_accounting_journal')
                ->where('doc_head = %i', $docHeadId)
                ->execute();

            foreach ($grouped as $line) {
                $this->db->insert('economy_accounting_journal', [
                    'source_kind'     => 'doc',
                    'doc_head'        => $docHeadId,
                    'doc_type'        => $head['doc_type'] ?? null,
                    'doc_number'      => $head['doc_number'] ?? null,
                    'accounting_date' => $head['accounting_date'] ?? null,
                    'fiscal_year'     => $head['fiscal_year'] ?? null,
                    'fiscal_month'    => $head['fiscal_month'] ?? null,
                    'account'         => $line['account'],
                    'account_number'  => $line['account_number'],
                    'is_error'        => $line['is_error'] ? 1 : 0,
                    'operation'       => $line['operation'],
                    'money_dr'        => $line['money_dr'],
                    'money_cr'        => $line['money_cr'],
                    'currency'        => $head['doc_currency'] ?? null,
                    'money_dr_cur'    => $line['money_dr_cur'],
                    'money_cr_cur'    => $line['money_cr_cur'],
                    'partner'         => $line['partner'],
                    'text'            => $line['text'],
                    // Platební identita — konstantní přes celý doklad (z hlavičky).
                    'payment_reference' => $head['payment_reference'] ?? null,
                    'specific_symbol'   => $head['specific_symbol'] ?? null,
                    'constant_symbol'   => $head['constant_symbol'] ?? null,
                    'due_date'          => $head['due_date'] ?? null,
                ])->execute();
            }

            $this->db->update('docs_core_heads', [
                'accounting_state'    => $state,
                'accounting_messages' => $this->messages === []
                    ? null
                    : json_encode($this->messages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ])->where('id = %i', $docHeadId)->execute();

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }

        return ['state' => $state, 'messages' => $this->messages];
    }

    /**
     * Smaže deník dokladu a vynuluje stav účtování — výstup ze stavu 40
     * (V opravě, Storno) a beforeDelete cleanup.
     */
    public function clearDocument(int $docHeadId): void
    {
        $this->db->begin();
        try {
            $this->db->delete('economy_accounting_journal')
                ->where('doc_head = %i', $docHeadId)
                ->execute();
            $this->db->update('docs_core_heads', [
                'accounting_state'    => 0,
                'accounting_messages' => null,
            ])->where('id = %i', $docHeadId)->execute();
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    // ── Načítání zdrojových dat ─────────────────────────────────────────────

    /**
     * @return list<array<string, mixed>>
     */
    private function loadRows(int $docHeadId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT * FROM [docs_core_rows]
             WHERE [doc_head] = %i AND [row_kind] = 1
             ORDER BY [order_pos]',
            $docHeadId,
        );
        return array_map(fn($r) => $r->toArray(), $rows);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadVatRecap(int $docHeadId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT * FROM [docs_core_vat_recap]
             WHERE [doc_head] = %i
             ORDER BY [order_pos]',
            $docHeadId,
        );
        return array_map(fn($r) => $r->toArray(), $rows);
    }

    private function addMessage(string $code, string $message, ?int $rowId = null): void
    {
        $this->messages[] = ['code' => $code, 'message' => $message, 'rowId' => $rowId];
    }
}
