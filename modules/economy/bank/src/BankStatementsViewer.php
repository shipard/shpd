<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Bank;

use Shipard\Core\Document\DocStateConfig;
use Shipard\Core\Viewer\TableViewer;

/**
 * Viewer bankovních výpisů (economy_bank_statements).
 *
 * Archivní stavové taby (core.system.docStatesArchive). Bez akce „nový" —
 * výpis vzniká importem/migrací; editace a přílohy (PDF) přes Open (form má
 * tab Přílohy). Rekonciliace je Fáze 2.
 */
class BankStatementsViewer extends TableViewer
{
    protected ?string $docStatesCfgItem = 'core.system.docStatesArchive';

    /** tableId výpisů — vazba příloh v core_attachments_files (shodně s form tab „Přílohy"). */
    private const TABLE_ID_STATEMENTS = 415;

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
        $sql = 'SELECT `id`, `statement_number`, `period_start`, `period_end`,'
            . ' `closing_balance`, `currency`, `reconciliation_state`,'
            . ' `docState`, `docStateMain`'
            . ' FROM `' . $this->table . '`';

        $conditions = [];
        $params = [];

        $viewGroup = 'active';
        foreach ($filters as $filter) {
            if (($filter['id'] ?? null) === 'viewGroup') {
                $viewGroup = (string) $filter['value'];
            }
        }

        if ($viewGroup !== 'all') {
            [$vgSql, $vgParams] = $this->buildViewGroupFilter($this->docStatesCfgItem, $viewGroup);
            if ($vgSql !== '') {
                $conditions[] = $vgSql;
                $params = array_merge($params, $vgParams);
            }
        }

        if ($search !== null && $search !== '') {
            [$searchSql, $searchParams] = $this->buildSearchCondition(['statement_number'], $search);
            if ($searchSql !== '') {
                $conditions[] = $searchSql;
                $params = array_merge($params, $searchParams);
            }
        }

        if ($conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        $sql .= ' ORDER BY `docStateMain` ASC, `period_end` DESC, `id` DESC';

        [$offset, $limit] = $this->buildPaginationLimit($pageNumber);
        $sql .= ' LIMIT ' . $offset . ', ' . $limit;

        return $this->db->fetchAll($sql, ...$params);
    }

    public function renderRow(array $rowData): array
    {
        $period = $this->formatDate($rowData['period_start'] ?? null) . ' – ' . $this->formatDate($rowData['period_end'] ?? null);
        $number = trim((string) ($rowData['statement_number'] ?? ''));

        $row = [
            'id' => (int) $rowData['id'],
            't1' => $number !== '' ? $number : $period,
        ];

        $closing = $rowData['closing_balance'] ?? null;
        if ($closing !== null && $closing !== '') {
            $row['i1'] = [
                ['text' => $this->formatMoney($closing), 'class' => 'amount'],
                ['text' => strtoupper((string) ($rowData['currency'] ?? '')), 'class' => 'muted'],
            ];
        }

        $t2 = [['text' => $period, 'class' => 'muted']];
        $recon = (int) ($rowData['reconciliation_state'] ?? 0);
        if ($recon !== 0) {
            $label = $this->enumLabel('economy.bank.reconciliationStates', $recon, 'int');
            if ($label !== null) {
                $t2[] = ['text' => $label, 'class' => $recon === 2 ? 'danger' : 'success'];
            }
        }
        $row['t2'] = $t2;

        $docState = (int) ($rowData['docState'] ?? 10);
        $cfg = DocStateConfig::fromCfgItem($this->config?->cfgItem($this->docStatesCfgItem));
        $stateData = $cfg->getState($docState);
        $row['stateStyle'] = $stateData['stateStyle'] ?? 'concept';

        return $row;
    }

    public function renderDetail(int $recordId): array
    {
        $r = $this->db->fetchRow(
            'SELECT s.*, ba.`code` AS account_code, ba.`name` AS account_name'
            . ' FROM `' . $this->table . '` s'
            . ' LEFT JOIN `economy_codebooks_bank_accounts` ba ON ba.`id` = s.`bank_account`'
            . ' WHERE s.`id` = %i',
            $recordId,
        );

        if ($r === null) {
            return ['tabs' => []];
        }

        $cs = $this->language !== 'en';

        $accountLabel = trim(
            (string) ($r['account_code'] ?? '')
            . (($r['account_name'] ?? null) !== null ? ' — ' . $r['account_name'] : ''),
        );

        $stmtItems = [];
        $this->addItem($stmtItems, $cs ? 'Bankovní účet' : 'Bank account', $accountLabel !== '' ? $accountLabel : null);
        $this->addItem($stmtItems, $cs ? 'Číslo výpisu' : 'Statement number', $r['statement_number'] ?? null);
        $this->addItem(
            $stmtItems,
            $cs ? 'Období' : 'Period',
            $this->formatDate($r['period_start'] ?? null) . ' – ' . $this->formatDate($r['period_end'] ?? null),
        );
        $this->addItem(
            $stmtItems,
            $cs ? 'Rekonciliace' : 'Reconciliation',
            $this->enumLabel('economy.bank.reconciliationStates', $r['reconciliation_state'] ?? null, 'int'),
        );

        $curCode = strtoupper((string) ($r['currency'] ?? ''));
        $balanceItems = [];
        $this->addItem($balanceItems, $cs ? 'Počáteční zůstatek' : 'Opening balance', $this->balanceLabel($r['opening_balance'] ?? null, $curCode));
        $this->addItem($balanceItems, $cs ? 'Koncový zůstatek' : 'Closing balance', $this->balanceLabel($r['closing_balance'] ?? null, $curCode));

        $groups = [];
        $this->addGroup($groups, $cs ? 'Výpis' : 'Statement', $stmtItems);
        $this->addGroup($groups, $cs ? 'Zůstatky' : 'Balances', $balanceItems);

        // Tab Přehled = composite: vlastnosti -> transakce výpisu -> přílohy.
        // Transakce a přílohy se přidávají jen když existují. Přílohy s
        // přepínačem Velké náhledy/Miniatury — stejně jako u Přijatých faktur,
        // ale ve stejném tabu, aby byly hned vidět.
        $blocks = [['type' => 'properties', 'groups' => $groups]];
        foreach ($this->detailTransactionBlocks($recordId) as $txBlock) {
            $blocks[] = $txBlock;
        }
        foreach ($this->detailAttachmentBlocks($recordId) as $attBlock) {
            $blocks[] = $attBlock;
        }

        $tabs = [[
            'id'      => 'overview',
            'label'   => $this->defaultOverviewLabel(),
            'content' => ['type' => 'composite', 'blocks' => $blocks],
        ]];

        $header = $this->buildDetailHeader($r);

        return [
            'title'    => $header['title'],
            'subtitle' => $header['subtitle'],
            'badges'   => $header['badges'],
            'icon'     => $header['icon'],
            'tabs'     => $tabs,
        ];
    }

    public function getToolbarActions(?array $selectedRow): array
    {
        // Bez „nový" — výpis vzniká importem. Akce Importovat výpis (vždy)
        // + Open na vybraném řádku (edit + přílohy).
        $cs = $this->language !== 'en';
        $actions = [[
            'id'      => 'import_statement',
            'label'   => $cs ? 'Importovat výpis' : 'Import statement',
            'variant' => 'primary',
        ]];
        if ($selectedRow !== null) {
            $defs = ($this->config?->cfgItem('core.system.viewerDefaults') ?? [])['toolbarActions'] ?? [];
            $editDef = $defs['edit'] ?? ['name' => 'Open', 'variant' => 'secondary'];
            $actions[] = [
                'id'      => 'edit',
                'label'   => $editDef['name'] ?? 'Open',
                'variant' => $editDef['variant'] ?? 'secondary',
            ];
        }
        return $actions;
    }

    /**
     * Hlavička detailu nad taby (generický header ViewerDetail, sjednocené
     * s doklady, Osobami, Položkami a Bankovními transakcemi). Title = číslo
     * výpisu (nejvýraznější údaj — obdoba čísla dokladu u faktur), při chybějícím
     * čísle fallback na období; subtitle = bankovní účet · období; badges = stav
     * dokladu (docStatesArchive) a stav rekonciliace, když není
     * „Nezkontrolováno" (0 je default — samostatný badge by byl šum, obdoba
     * „Zaúčtováno" u transakcí). Ikona shodná s viewers[].icon v module.jsonc (bank).
     *
     * @param array<string, mixed> $record
     * @return array{title: string, subtitle: ?string, badges: array<int, array{label: string, style: string}>, icon: string}
     */
    private function buildDetailHeader(array $record): array
    {
        $period = $this->formatDate($record['period_start'] ?? null)
            . ' – ' . $this->formatDate($record['period_end'] ?? null);
        $number = trim((string) ($record['statement_number'] ?? ''));
        $title = $number !== '' ? $number : $period;

        $accountLabel = trim(
            (string) ($record['account_code'] ?? '')
            . (($record['account_name'] ?? null) !== null ? ' — ' . $record['account_name'] : ''),
        );
        // Subtitle: „účet · období"; když účet chybí, jen období (a naopak,
        // když title už nese období kvůli chybějícímu číslu, subtitle ho neopakuje).
        $subtitleParts = [];
        if ($accountLabel !== '') {
            $subtitleParts[] = $accountLabel;
        }
        if ($number !== '') {
            $subtitleParts[] = $period;
        }
        $subtitle = implode(' · ', $subtitleParts);

        $badges = [];
        $cfg = DocStateConfig::fromCfgItem($this->config?->cfgItem($this->docStatesCfgItem));
        $stateData = $cfg->getState((int) ($record['docState'] ?? 10));
        $stateName = (string) ($stateData['stateName'] ?? '');
        if ($stateName !== '') {
            $badges[] = [
                'label' => $stateName,
                'style' => (string) ($stateData['stateStyle'] ?? 'concept'),
            ];
        }

        // Rekonciliace — badge jen když není „Nezkontrolováno" (0). Souhlasí (1)
        // success, Nesouhlasí (2) error.
        $recon = (int) ($record['reconciliation_state'] ?? 0);
        if ($recon !== 0) {
            $reconLabel = $this->enumLabel('economy.bank.reconciliationStates', $recon, 'int');
            if ($reconLabel !== null) {
                $badges[] = [
                    'label' => $reconLabel,
                    'style' => $recon === 2 ? 'error' : 'success',
                ];
            }
        }

        return [
            'title'    => $title,
            'subtitle' => $subtitle !== '' ? $subtitle : null,
            'badges'   => $badges,
            'icon'     => 'bank',
        ];
    }

    /**
     * Bloky příloh výpisu pro tab Přehled (composite). Vlastní přílohy
     * z core_attachments_files (table_id = výpisy, shodně s form tabem
     * „Přílohy"); když žádné nejsou, vrací prázdné pole a do composite se
     * nic nepřidá. Nadpis „Přílohy" + plochý grid (attachment-grid) s
     * přepínačem Velké náhledy/Miniatury — frontend ViewerDetail to renderuje
     * sdílenou AttachmentGrid, beze změny.
     *
     * @return list<array<string, mixed>>
     */
    private function detailAttachmentBlocks(int $recordId): array
    {
        $files = $this->db->fetchAll(
            'SELECT `id`, `name`, `file_name`, `file_size`, `mime_type`'
            . ' FROM `core_attachments_files`'
            . ' WHERE `table_id` = %i AND `record_id` = %i AND `is_deleted` = 0'
            . ' ORDER BY `att_order` ASC, `name` ASC',
            self::TABLE_ID_STATEMENTS,
            $recordId,
        );
        if ($files === []) {
            return [];
        }

        $attachments = [];
        foreach ($files as $f) {
            $attachments[] = [
                'id'        => (int) $f['id'],
                'name'      => (string) ($f['name'] ?? $f['file_name']),
                'mime_type' => (string) ($f['mime_type'] ?? ''),
                'file_size' => (int) ($f['file_size'] ?? 0),
            ];
        }

        return [
            ['type' => 'heading', 'text' => $this->language === 'en' ? 'Attachments' : 'Přílohy'],
            ['type' => 'attachment-grid', 'attachments' => $attachments],
        ];
    }

    /**
     * Bloky transakcí výpisu pro tab Přehled (composite). Výčet transakcí
     * navázaných přes economy_bank_transactions.statement, vzestupně dle data
     * (přirozené pořadí pohybů na výpisu). Bez součtového řádku; když výpis
     * žádné transakce nemá, vrací prázdné pole a do composite se nic nepřidá.
     * Řádky nejsou klikací — table blok proklik neumí, je to přehledový výčet.
     *
     * @return list<array<string, mixed>>
     */
    private function detailTransactionBlocks(int $recordId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT t.`id`, t.`date_transaction`, t.`direction`, t.`amount`, t.`currency`,'
            . ' t.`counterparty_name`, t.`payment_reference`, t.`docState`,'
            . ' p.`full_name` AS partner_name'
            . ' FROM `economy_bank_transactions` t'
            . ' LEFT JOIN `base_persons_persons` p ON p.`id` = t.`partner`'
            . ' WHERE t.`statement` = %i'
            . ' ORDER BY t.`date_transaction` ASC, t.`id` ASC',
            $recordId,
        );
        if ($rows === []) {
            return [];
        }

        $cs = $this->language !== 'en';
        $txCfg = DocStateConfig::fromCfgItem($this->config?->cfgItem('economy.bank.txStates'));

        $columns = [
            ['id' => 'date',    'label' => $cs ? 'Datum' : 'Date'],
            ['id' => 'amount',  'label' => $cs ? 'Částka' : 'Amount', 'align' => 'right'],
            ['id' => 'party',   'label' => $cs ? 'Protistrana' : 'Counterparty'],
            ['id' => 'vs',      'label' => $cs ? 'VS' : 'VS'],
            ['id' => 'state',   'label' => $cs ? 'Stav' : 'State'],
        ];

        $tableRows = [];
        foreach ($rows as $tx) {
            $sign = (int) ($tx['direction'] ?? 0) === 2 ? '−' : '+';
            $curCode = strtoupper((string) ($tx['currency'] ?? ''));
            $amount = $sign . $this->formatMoney($tx['amount'] ?? 0) . ($curCode !== '' ? ' ' . $curCode : '');

            $partner = trim((string) ($tx['partner_name'] ?? ''));
            $party = $partner !== '' ? $partner : trim((string) ($tx['counterparty_name'] ?? ''));

            $stateData = $txCfg->getState((int) ($tx['docState'] ?? 10));

            $tableRows[] = [
                'date'   => $this->formatDate($tx['date_transaction'] ?? null) ?? '',
                'amount' => $amount,
                'party'  => $party !== '' ? $party : '—',
                'vs'     => trim((string) ($tx['payment_reference'] ?? '')),
                'state'  => (string) ($stateData['stateName'] ?? ''),
            ];
        }

        return [
            ['type' => 'heading', 'text' => $cs ? 'Transakce' : 'Transactions'],
            ['type' => 'table', 'columns' => $columns, 'rows' => $tableRows],
        ];
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function balanceLabel(mixed $value, string $curCode): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        return $this->formatMoney($value) . ($curCode !== '' ? ' ' . $curCode : '');
    }

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
