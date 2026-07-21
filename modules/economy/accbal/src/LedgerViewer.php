<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Accbal;

use Shipard\Core\Viewer\TableViewer;

/**
 * Viewer saldo pohybů (economy_accbal_ledger).
 *
 * Read-only derivát deníku (jako JournalViewer): žádné new/edit/delete,
 * žádné docState taby. Reziduum počítá z allocations přes LEFT JOIN —
 * ve Fázi 2b jsou allocations prázdné, takže vše je plně „otevřené".
 *
 * ViewGroups (chip lišta nahoře) = saldokonta z economy_accbal_balances,
 * identita přes `code`. Filtry: partner, variabilní symbol, jen otevřené.
 */
class LedgerViewer extends TableViewer
{
    protected ?string $docStatesCfgItem = null;

    /**
     * Per-request cache saldokont pro viewGroups — meta volá getViewGroups()
     * i getDefaultViewGroup(), dotaz do DB stačí jednou.
     *
     * @var list<array{id: string, label: string}>|null
     */
    private ?array $viewGroups = null;

    /** Reziduum pohybu = amount − Σ allocations, kde figuruje (jako request i payment). */
    private const RESIDUAL_SQL =
        'l.`amount`'
        . ' - COALESCE((SELECT SUM(ar.`amount`) FROM `economy_accbal_allocations` ar WHERE ar.`request_entry` = l.`id`), 0)'
        . ' - COALESCE((SELECT SUM(ap.`amount`) FROM `economy_accbal_allocations` ap WHERE ap.`payment_entry` = l.`id`), 0)';

    public function selectRows(?string $search, array $filters, int $pageNumber): array
    {
        $sql = 'SELECT l.`id`, l.`balance`, l.`bal_side`, l.`source_kind`, l.`doc_head`,'
            . ' l.`bank_transaction`, l.`journal_row`, l.`account_number`, l.`partner`,'
            . ' l.`payment_reference`, l.`due_date`, l.`currency`, l.`amount`, l.`amount_hc`,'
            . ' l.`text`, b.`name` AS balance_name, p.`full_name` AS partner_name,'
            . ' (' . self::RESIDUAL_SQL . ') AS residual'
            . ' FROM `' . $this->table . '` l'
            . ' LEFT JOIN `economy_accbal_balances` b ON b.`id` = l.`balance`'
            . ' LEFT JOIN `base_persons_persons` p ON p.`id` = l.`partner`';

        $conditions = [];
        $params = [];
        $onlyOpen = false;

        foreach ($filters as $filter) {
            $id = $filter['id'] ?? null;
            $value = $filter['value'] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            if ($id === 'viewGroup') {
                // 'all' = bez podmínky; 'active' defenzivně také — stale
                // frontend z otevřené session může po nasazení poslat
                // starý docState default.
                $vg = (string) $value;
                if ($vg !== 'all' && $vg !== 'active') {
                    $conditions[] = 'b.`code` = %s';
                    $params[] = $vg;
                }
            } elseif ($id === 'partner') {
                $conditions[] = 'p.`full_name` LIKE %s';
                $params[] = '%' . (string) $value . '%';
            } elseif ($id === 'payment_reference') {
                $conditions[] = 'l.`payment_reference` LIKE %s';
                $params[] = (string) $value . '%';
            } elseif ($id === 'only_open' && (string) $value === '1') {
                $onlyOpen = true;
            }
        }

        if ($conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }
        if ($onlyOpen) {
            $sql .= ' HAVING residual <> 0';
        }

        $sql .= ' ORDER BY l.`balance` ASC, l.`bal_side` ASC, l.`id` ASC';

        [$offset, $limit] = $this->buildPaginationLimit($pageNumber);
        $sql .= ' LIMIT ' . $offset . ', ' . $limit;

        return $this->db->fetchAll($sql, ...$params);
    }

    public function renderRow(array $rowData): array
    {
        $balSide = (int) ($rowData['bal_side'] ?? 0);
        $account = (string) ($rowData['account_number'] ?? '');
        $balanceName = trim((string) ($rowData['balance_name'] ?? ''));

        $row = [
            'id'         => (int) $rowData['id'],
            't1'         => $balanceName !== '' ? $balanceName : $account,
            'i1'         => $account,
            'stateStyle' => $balSide === 0 ? 'primary' : 'done',
        ];

        $t2 = [];
        $t2[] = [
            'text'  => $balSide === 0 ? ($this->language === 'cs' ? 'Předpis' : 'Request')
                                      : ($this->language === 'cs' ? 'Úhrada' : 'Payment'),
            'class' => 'muted',
        ];
        $partnerName = trim((string) ($rowData['partner_name'] ?? ''));
        if ($partnerName !== '') {
            $t2[] = ['text' => $partnerName, 'class' => 'muted'];
        }
        $vs = trim((string) ($rowData['payment_reference'] ?? ''));
        if ($vs !== '') {
            $t2[] = ['text' => 'VS ' . $vs, 'class' => 'muted'];
        }
        $due = $this->formatDate($rowData['due_date'] ?? null);
        if ($due !== null) {
            $t2[] = ['text' => ($this->language === 'cs' ? 'splatnost ' : 'due ') . $due, 'class' => 'muted'];
        }
        $row['t2'] = $t2;

        $curCode = strtoupper((string) ($rowData['currency'] ?? ''));
        $i2 = [['text' => $this->formatMoney($rowData['amount'] ?? 0) . ' ' . $curCode, 'class' => 'amount']];
        $residual = (float) ($rowData['residual'] ?? 0);
        if (abs($residual) > 0.0001) {
            $i2[] = [
                'text'  => ($this->language === 'cs' ? 'zbývá ' : 'open ') . $this->formatMoney($residual) . ' ' . $curCode,
                'class' => 'muted',
            ];
        }
        $row['i2'] = $i2;

        return $row;
    }

    public function renderDetail(int $recordId): array
    {
        $r = $this->db->fetchRow(
            'SELECT l.*, b.`name` AS balance_name, p.`full_name` AS partner_name,'
            . ' (' . self::RESIDUAL_SQL . ') AS residual'
            . ' FROM `' . $this->table . '` l'
            . ' LEFT JOIN `economy_accbal_balances` b ON b.`id` = l.`balance`'
            . ' LEFT JOIN `base_persons_persons` p ON p.`id` = l.`partner`'
            . ' WHERE l.`id` = %i',
            $recordId,
        );
        if ($r === null) {
            return ['tabs' => []];
        }

        $cs = $this->language === 'cs';

        $moveItems = [];
        $this->addItem($moveItems, $cs ? 'Saldokonto' : 'Balance', $r['balance_name'] ?? null);
        $this->addItem(
            $moveItems,
            $cs ? 'Role' : 'Role',
            (int) ($r['bal_side'] ?? 0) === 0 ? ($cs ? 'Předpis' : 'Request') : ($cs ? 'Úhrada' : 'Payment'),
        );
        $this->addItem($moveItems, $cs ? 'Účet' : 'Account', $r['account_number'] ?? null);
        $this->addItem($moveItems, 'Partner', $r['partner_name'] ?? null);
        $this->addItem($moveItems, 'Text', $r['text'] ?? null);

        $curCode = strtoupper((string) ($r['currency'] ?? ''));
        $hcCode = strtoupper((string) ($r['home_currency'] ?? ''));
        $amountItems = [];
        $this->addItem($amountItems, ($cs ? 'Částka ' : 'Amount ') . $curCode, $this->formatMoney($r['amount'] ?? 0));
        if ($hcCode !== '' && $hcCode !== $curCode) {
            $this->addItem($amountItems, ($cs ? 'Částka ' : 'Amount ') . $hcCode, $this->formatMoney($r['amount_hc'] ?? 0));
        }
        $this->addItem($amountItems, $cs ? 'Zbývá' : 'Open', $this->formatMoney($r['residual'] ?? 0));

        $payItems = [];
        $this->addItem($payItems, $cs ? 'Variabilní symbol' : 'Payment reference', $r['payment_reference'] ?? null);
        $this->addItem($payItems, $cs ? 'Specifický symbol' : 'Specific symbol', $r['specific_symbol'] ?? null);
        $this->addItem($payItems, $cs ? 'Konstantní symbol' : 'Constant symbol', $r['constant_symbol'] ?? null);
        $this->addItem($payItems, $cs ? 'Splatnost' : 'Due date', $this->formatDate($r['due_date'] ?? null));

        $groups = [
            ['title' => $cs ? 'Pohyb' : 'Movement', 'items' => $moveItems],
            ['title' => $cs ? 'Částky' : 'Amounts', 'items' => $amountItems],
        ];
        if ($payItems !== []) {
            $groups[] = ['title' => $cs ? 'Platba' : 'Payment', 'items' => $payItems];
        }

        $detail = ['tabs' => [[
            'id'      => 'overview',
            'label'   => $this->defaultOverviewLabel(),
            'content' => ['type' => 'properties', 'groups' => $groups],
        ]]];

        $actions = [];
        $docHead = (int) ($r['doc_head'] ?? 0);
        $bankTx = (int) ($r['bank_transaction'] ?? 0);
        if ($docHead > 0) {
            $actions[] = [
                'id' => 'open_doc', 'label' => $cs ? 'Otevřít doklad' : 'Open document',
                'kind' => 'open_viewer', 'viewerId' => 'docs.core.heads', 'recordId' => $docHead,
                'variant' => 'secondary',
            ];
        }
        if ($bankTx > 0) {
            $actions[] = [
                'id' => 'open_tx', 'label' => $cs ? 'Otevřít transakci' : 'Open transaction',
                'kind' => 'open_viewer', 'viewerId' => 'economy.bank.transactions', 'recordId' => $bankTx,
                'variant' => 'secondary',
            ];
        }
        $journalRow = (int) ($r['journal_row'] ?? 0);
        if ($journalRow > 0) {
            $actions[] = [
                'id' => 'open_journal', 'label' => $cs ? 'Otevřít řádek deníku' : 'Open journal row',
                'kind' => 'open_viewer', 'viewerId' => 'economy.accounting.journal', 'recordId' => $journalRow,
                'variant' => 'secondary',
            ];
        }
        if ($actions !== []) {
            $detail['actions'] = $actions;
        }

        return $detail;
    }

    public function getFilters(): array
    {
        $cs = $this->language === 'cs';

        return [
            ['id' => 'partner', 'label' => 'Partner', 'type' => 'text'],
            ['id' => 'payment_reference', 'label' => $cs ? 'Variabilní symbol' : 'Payment reference', 'type' => 'text'],
            ['id' => 'only_open', 'label' => $cs ? 'Jen otevřené' : 'Open only', 'type' => 'checkbox'],
        ];
    }

    /**
     * Skupiny = saldokonta dle sort_order; identita `code` (stabilní napříč
     * DS, čitelná v URL, bez kolize s rezervovanými 'active'/'archive'/
     * 'trash'/'all'), label short_name s fallbackem na name — stejná
     * konvence jako sidebar (accbal-nav-items).
     */
    public function getViewGroups(): array
    {
        if ($this->viewGroups === null) {
            $balances = $this->db->fetchAll(
                'SELECT `code`, `name`, `short_name` FROM `economy_accbal_balances`'
                . ' WHERE `docState` != 90 ORDER BY `sort_order` ASC, `name` ASC',
            );
            $this->viewGroups = [];
            foreach ($balances as $b) {
                $shortName = trim((string) ($b['short_name'] ?? ''));
                $this->viewGroups[] = [
                    'id'    => (string) $b['code'],
                    'label' => $shortName !== '' ? $shortName : (string) $b['name'],
                ];
            }
        }
        return $this->viewGroups;
    }

    public function getDefaultViewGroup(): string
    {
        $groups = $this->getViewGroups();
        return $groups !== [] ? $groups[0]['id'] : 'all';
    }

    public function getToolbarActions(?array $selectedRow): array
    {
        return [];
    }

    /** @param array<int, array{label: string, value: string}> $items */
    private function addItem(array &$items, string $label, mixed $value): void
    {
        if ($value !== null && $value !== '') {
            $items[] = ['label' => $label, 'value' => (string) $value];
        }
    }

    private function formatMoney(mixed $amount): string
    {
        return number_format((float) ($amount ?? 0), 2, ',', ' ');
    }

    private function formatDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('j. n. Y');
        }
        $ts = is_string($value) ? strtotime($value) : false;
        return $ts !== false ? date('j. n. Y', $ts) : null;
    }
}
