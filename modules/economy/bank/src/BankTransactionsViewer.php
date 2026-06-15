<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Bank;

use Shipard\Core\Document\DocStateConfig;
use Shipard\Core\Viewer\TableViewer;

/**
 * Viewer bankovních transakcí (economy_bank_transactions).
 *
 * Stavové taby přes $docStatesCfgItem = economy.bank.txStates. Bez akce
 * „nový" — transakce vzniká importem/migrací; editace (operation / partner /
 * message a stavové přechody) přes Open na vybraném řádku. Tab/akce
 * Zaúčtování je Fáze 3 (zatím nepřidáno).
 *
 * LIST nejoinuje (buildViewGroupFilter pracuje s nekvalifikovaným docState);
 * protistranu ukazuje z denormalizovaného counterparty_name. JOINy jen
 * v renderDetail nad jedním řádkem.
 */
class BankTransactionsViewer extends TableViewer
{
    protected ?string $docStatesCfgItem = 'economy.bank.txStates';

    private const STATE_SPAN_CLASS = [
        'concept'   => 'warning',
        'confirmed' => 'primary',
        'done'      => 'success',
        'edit'      => 'warning',
        'archive'   => 'muted',
        'trash'     => 'muted',
        'cancelled' => 'danger',
    ];

    public function selectRows(?string $search, array $filters, int $pageNumber): array
    {
        $sql = 'SELECT `id`, `date_transaction`, `direction`, `amount`, `currency`,'
            . ' `counterparty_name`, `symbol1`, `operation`, `accounting_state`,'
            . ' `docState`, `docStateMain`'
            . ' FROM `' . $this->table . '`';

        $conditions = [];
        $params = [];

        $viewGroup = 'active';
        $onlyErrors = false;
        foreach ($filters as $filter) {
            if (($filter['id'] ?? null) === 'viewGroup') {
                $viewGroup = (string) $filter['value'];
            } elseif (($filter['id'] ?? null) === 'only_errors' && (string) ($filter['value'] ?? '') === '1') {
                $onlyErrors = true;
            }
        }

        if ($viewGroup !== 'all') {
            [$vgSql, $vgParams] = $this->buildViewGroupFilter($this->docStatesCfgItem, $viewGroup);
            if ($vgSql !== '') {
                $conditions[] = $vgSql;
                $params = array_merge($params, $vgParams);
            }
        }

        if ($onlyErrors) {
            $conditions[] = '`accounting_state` = 2';
        }

        if ($search !== null && $search !== '') {
            [$searchSql, $searchParams] = $this->buildSearchCondition(
                ['counterparty_name', 'counterparty_account', 'symbol1', 'message'],
                $search,
            );
            if ($searchSql !== '') {
                $conditions[] = $searchSql;
                $params = array_merge($params, $searchParams);
            }
        }

        if ($conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        $sql .= ' ORDER BY `docStateMain` ASC, `date_transaction` DESC, `id` DESC';

        [$offset, $limit] = $this->buildPaginationLimit($pageNumber);
        $sql .= ' LIMIT ' . $offset . ', ' . $limit;

        return $this->db->fetchAll($sql, ...$params);
    }

    public function renderRow(array $rowData): array
    {
        $counterparty = trim((string) ($rowData['counterparty_name'] ?? ''));
        $row = [
            'id' => (int) $rowData['id'],
            't1' => $counterparty !== '' ? $counterparty : '—',
        ];

        $sign = (int) ($rowData['direction'] ?? 0) === 2 ? '−' : '+';
        $row['i1'] = [
            ['text' => $sign . $this->formatMoney($rowData['amount'] ?? 0), 'class' => 'amount'],
            ['text' => strtoupper((string) ($rowData['currency'] ?? '')), 'class' => 'muted'],
        ];

        $t2 = [];
        $date = $this->formatDate($rowData['date_transaction'] ?? null);
        if ($date !== null) {
            $t2[] = ['text' => $date];
        }
        $vs = trim((string) ($rowData['symbol1'] ?? ''));
        if ($vs !== '') {
            $t2[] = ['text' => 'VS ' . $vs, 'class' => 'muted'];
        }
        $operation = $this->enumLabel('economy.bank.txOperations', $rowData['operation'] ?? null, 'string');
        if ($operation !== null) {
            $t2[] = ['text' => $operation, 'class' => 'muted'];
        }
        $row['t2'] = $t2 !== [] ? $t2 : null;

        $accState = (int) ($rowData['accounting_state'] ?? 0);
        if ($accState === 2) {
            $row['i2'] = [['text' => '⚠ ' . ($this->language === 'en' ? 'posting error' : 'chyba účtování'), 'class' => 'danger']];
        } elseif ($accState === 1) {
            $row['i2'] = [['text' => $this->language === 'en' ? 'posted' : 'zaúčtováno', 'class' => 'muted']];
        }

        $docState = (int) ($rowData['docState'] ?? 10);
        $cfg = DocStateConfig::fromCfgItem($this->config?->cfgItem($this->docStatesCfgItem));
        $stateData = $cfg->getState($docState);
        $row['stateStyle'] = $stateData['stateStyle'] ?? 'concept';

        return $row;
    }

    public function renderDetail(int $recordId): array
    {
        $r = $this->db->fetchRow(
            'SELECT t.*, ba.`code` AS account_code, ba.`name` AS account_name,'
            . ' p.`full_name` AS partner_name,'
            . ' s.`period_start` AS st_start, s.`period_end` AS st_end'
            . ' FROM `' . $this->table . '` t'
            . ' LEFT JOIN `economy_codebooks_bank_accounts` ba ON ba.`id` = t.`bank_account`'
            . ' LEFT JOIN `base_persons_persons` p ON p.`id` = t.`partner`'
            . ' LEFT JOIN `economy_bank_statements` s ON s.`id` = t.`statement`'
            . ' WHERE t.`id` = %i',
            $recordId,
        );

        if ($r === null) {
            return ['tabs' => []];
        }

        $cs = $this->language !== 'en';

        $txItems = [];
        $this->addItem($txItems, $cs ? 'Datum transakce' : 'Transaction date', $this->formatDate($r['date_transaction'] ?? null));
        $this->addItem($txItems, $cs ? 'Datum valuty' : 'Value date', $this->formatDate($r['date_value'] ?? null));
        $this->addItem($txItems, $cs ? 'Směr' : 'Direction', $this->enumLabel('economy.bank.txDirections', $r['direction'] ?? null, 'int'));
        $this->addItem($txItems, $cs ? 'Stav' : 'State', $this->enumLabel('economy.bank.txStates', $r['docState'] ?? null, 'int', 'stateName'));

        $amountItems = [];
        $curCode = strtoupper((string) ($r['currency'] ?? ''));
        $this->addItem($amountItems, $cs ? 'Částka' : 'Amount', $this->formatMoney($r['amount'] ?? 0) . ' ' . $curCode);
        $this->addItem($amountItems, $cs ? 'V domácí měně' : 'In home currency', $this->formatMoney($r['amount_dom'] ?? 0));
        $rate = (float) ($r['exchange_rate'] ?? 1);
        if (abs($rate - 1.0) > 0.0000001) {
            $this->addItem($amountItems, $cs ? 'Kurz' : 'Exchange rate', number_format($rate, 6, ',', ' '));
        }

        $cpItems = [];
        $this->addItem($cpItems, $cs ? 'Protiúčet' : 'Counterparty account', $r['counterparty_account'] ?? null);
        $this->addItem($cpItems, $cs ? 'Název protistrany' : 'Counterparty name', $r['counterparty_name'] ?? null);
        $this->addItem($cpItems, 'Partner', $r['partner_name'] ?? null);
        $this->addItem($cpItems, $cs ? 'Variabilní symbol' : 'Variable symbol', $r['symbol1'] ?? null);
        $this->addItem($cpItems, $cs ? 'Specifický symbol' : 'Specific symbol', $r['symbol2'] ?? null);
        $this->addItem($cpItems, $cs ? 'Konstantní symbol' : 'Constant symbol', $r['symbol3'] ?? null);
        $this->addItem($cpItems, $cs ? 'Zpráva' : 'Message', $r['message'] ?? null);

        $accItems = [];
        $this->addItem($accItems, $cs ? 'Pohyb' : 'Operation', $this->enumLabel('economy.bank.txOperations', $r['operation'] ?? null, 'string'));
        $this->addItem($accItems, $cs ? 'Stav účtování' : 'Accounting state', $this->enumLabel('economy.accounting.accountingStates', $r['accounting_state'] ?? null, 'int'));

        $accountLabel = trim(
            (string) ($r['account_code'] ?? '')
            . (($r['account_name'] ?? null) !== null ? ' — ' . $r['account_name'] : ''),
        );
        $ctxItems = [];
        $this->addItem($ctxItems, $cs ? 'Bankovní účet' : 'Bank account', $accountLabel !== '' ? $accountLabel : null);
        if (($r['st_start'] ?? null) !== null && ($r['st_end'] ?? null) !== null) {
            $this->addItem(
                $ctxItems,
                $cs ? 'Výpis' : 'Statement',
                $this->formatDate($r['st_start']) . ' – ' . $this->formatDate($r['st_end']),
            );
        }

        $groups = [];
        $this->addGroup($groups, $cs ? 'Transakce' : 'Transaction', $txItems);
        $this->addGroup($groups, $cs ? 'Částky' : 'Amounts', $amountItems);
        $this->addGroup($groups, $cs ? 'Protistrana' : 'Counterparty', $cpItems);
        $this->addGroup($groups, $cs ? 'Zaúčtování' : 'Accounting', $accItems);
        $this->addGroup($groups, $cs ? 'Účet a výpis' : 'Account & statement', $ctxItems);

        return ['tabs' => [[
            'id'      => 'overview',
            'label'   => $this->defaultOverviewLabel(),
            'content' => ['type' => 'properties', 'groups' => $groups],
        ]]];
    }

    public function getToolbarActions(?array $selectedRow): array
    {
        // Bez „nový" — transakce vzniká importem/migrací. Edit (operation /
        // partner / message a stavové přechody) přes Open na vybraném řádku.
        if ($selectedRow === null) {
            return [];
        }
        $defs = ($this->config?->cfgItem('core.system.viewerDefaults') ?? [])['toolbarActions'] ?? [];
        $editDef = $defs['edit'] ?? ['name' => 'Open', 'variant' => 'secondary'];
        return [[
            'id'      => 'edit',
            'label'   => $editDef['name'] ?? 'Open',
            'variant' => $editDef['variant'] ?? 'secondary',
        ]];
    }

    public function getFilters(): array
    {
        $cs = $this->language !== 'en';
        return [[
            'id'    => 'only_errors',
            'label' => $cs ? 'Jen chyby účtování' : 'Posting errors only',
            'type'  => 'checkbox',
        ]];
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function enumLabel(string $cfgItemId, mixed $value, string $keyType = 'int', string $field = 'name'): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $items = $this->config?->cfgItem($cfgItemId);
        if (!is_array($items)) {
            return null;
        }
        $key = $keyType === 'int' ? (string) (int) $value : (string) $value;
        $entry = $items[$key] ?? null;
        return is_array($entry) ? ($entry[$field] ?? null) : null;
    }

    /** @param array<int, array{label: string, value: string}> $items */
    private function addItem(array &$items, string $label, mixed $value): void
    {
        if ($value !== null && $value !== '') {
            $items[] = ['label' => $label, 'value' => (string) $value];
        }
    }

    private function addGroup(array &$groups, string $title, array $items): void
    {
        if ($items !== []) {
            $groups[] = ['title' => $title, 'items' => $items];
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
