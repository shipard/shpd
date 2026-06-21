<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Accounting;

use Shipard\Core\Viewer\TableViewer;

/**
 * Viewer účetního deníku (economy_accounting_journal).
 *
 * Deník je derivát dokladů — vždy read-only: žádné new/edit/delete akce,
 * žádné docState taby ($docStatesCfgItem = null, vzor AlertsViewer).
 * Detail řádku odkazuje na zdrojový doklad přes akci `open_viewer`.
 *
 * Filtry (fiskální rok/měsíc, prefix účtu, partner, jen chyby) renderuje
 * generický ViewerFilters.svelte z definic v getFilters().
 */
class JournalViewer extends TableViewer
{
    protected ?string $docStatesCfgItem = null;

    public function selectRows(?string $search, array $filters, int $pageNumber): array
    {
        $sql = 'SELECT j.`id`, j.`accounting_date`, j.`doc_head`, j.`doc_number`,'
            . ' j.`account_number`, j.`text`, j.`money_dr`, j.`money_cr`,'
            . ' j.`currency`, j.`money_dr_cur`, j.`money_cr_cur`, j.`is_error`,'
            . ' j.`payment_reference`,'
            . ' p.`full_name` AS partner_name'
            . ' FROM `' . $this->table . '` j'
            . ' LEFT JOIN `base_persons_persons` p ON p.`id` = j.`partner`';

        $conditions = [];
        $params = [];

        foreach ($filters as $filter) {
            $id = $filter['id'] ?? null;
            $value = $filter['value'] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            if ($id === 'fiscal_year') {
                $conditions[] = 'j.`fiscal_year` = %i';
                $params[] = (int) $value;
            } elseif ($id === 'fiscal_month') {
                $conditions[] = 'j.`fiscal_month` = %i';
                $params[] = (int) $value;
            } elseif ($id === 'account') {
                // Prefix match — „504" najde celou účtovou skupinu.
                $conditions[] = 'j.`account_number` LIKE %s';
                $params[] = (string) $value . '%';
            } elseif ($id === 'partner') {
                $conditions[] = 'p.`full_name` LIKE %s';
                $params[] = '%' . (string) $value . '%';
            } elseif ($id === 'payment_reference') {
                // VS se hledá přesně/od začátku (prefix) — ne substring.
                $conditions[] = 'j.`payment_reference` LIKE %s';
                $params[] = (string) $value . '%';
            } elseif ($id === 'only_errors' && (string) $value === '1') {
                $conditions[] = 'j.`is_error` = 1';
            }
        }

        if ($search !== null && $search !== '') {
            $term = '%' . $search . '%';
            $conditions[] = '(j.`text` LIKE %s OR j.`doc_number` LIKE %s OR j.`account_number` LIKE %s OR j.`payment_reference` LIKE %s)';
            $params[] = $term;
            $params[] = $term;
            $params[] = $term;
            $params[] = $term;
        }

        if ($conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        $sql .= ' ORDER BY j.`accounting_date` DESC, j.`id` DESC';

        [$offset, $limit] = $this->buildPaginationLimit($pageNumber);
        $sql .= ' LIMIT ' . $offset . ', ' . $limit;

        return $this->db->fetchAll($sql, ...$params);
    }

    public function renderRow(array $rowData): array
    {
        $isError = (int) ($rowData['is_error'] ?? 0) === 1;
        $account = (string) ($rowData['account_number'] ?? '');
        $text = trim((string) ($rowData['text'] ?? ''));

        $row = [
            'id'         => (int) $rowData['id'],
            't1'         => $text !== '' ? $text : $account,
            'i1'         => $account,
            'stateStyle' => $isError ? 'error' : 'done',
        ];

        $t2 = [];
        $date = $this->formatDate($rowData['accounting_date'] ?? null);
        if ($date !== null) {
            $t2[] = ['text' => $date];
        }
        $docNumber = trim((string) ($rowData['doc_number'] ?? ''));
        if ($docNumber !== '') {
            $t2[] = ['text' => $docNumber, 'class' => 'muted'];
        }
        $partnerName = trim((string) ($rowData['partner_name'] ?? ''));
        if ($partnerName !== '') {
            $t2[] = ['text' => $partnerName, 'class' => 'muted'];
        }
        if ($isError) {
            $t2[] = ['text' => '⚠', 'class' => 'danger'];
        }
        $row['t2'] = $t2 !== [] ? $t2 : null;

        // Částka (dom) se stranou zápisu; u cizoměnového řádku navíc částka
        // v měně dokladu s kódem.
        $dr = (float) ($rowData['money_dr'] ?? 0);
        $cr = (float) ($rowData['money_cr'] ?? 0);
        $i2 = [
            ['text' => $dr !== 0.0 ? 'MD' : 'DAL', 'class' => 'muted'],
            ['text' => $this->formatMoney($dr !== 0.0 ? $dr : $cr), 'class' => 'amount'],
        ];
        $drCur = (float) ($rowData['money_dr_cur'] ?? 0);
        $crCur = (float) ($rowData['money_cr_cur'] ?? 0);
        if ($drCur !== 0.0 || $crCur !== 0.0) {
            $curCode = strtoupper((string) ($rowData['currency'] ?? ''));
            $i2[] = [
                'text'  => $this->formatMoney($drCur !== 0.0 ? $drCur : $crCur) . ' ' . $curCode,
                'class' => 'muted',
            ];
        }
        $row['i2'] = $i2;

        return $row;
    }

    public function renderDetail(int $recordId): array
    {
        $r = $this->db->fetchRow(
            'SELECT j.*, p.`full_name` AS partner_name, a.`name` AS account_name,'
            . ' fy.`name` AS fiscal_year_name, fm.`calendar_year`, fm.`calendar_month`'
            . ' FROM `' . $this->table . '` j'
            . ' LEFT JOIN `base_persons_persons` p ON p.`id` = j.`partner`'
            . ' LEFT JOIN `economy_accounting_accounts` a ON a.`id` = j.`account`'
            . ' LEFT JOIN `economy_codebooks_fiscal_years` fy ON fy.`id` = j.`fiscal_year`'
            . ' LEFT JOIN `economy_codebooks_fiscal_months` fm ON fm.`id` = j.`fiscal_month`'
            . ' WHERE j.`id` = %i',
            $recordId,
        );

        if ($r === null) {
            return ['tabs' => []];
        }

        $cs = $this->language === 'cs';

        $entryItems = [];
        $this->addItem($entryItems, $cs ? 'Datum' : 'Date', $this->formatDate($r['accounting_date'] ?? null));
        $accountLabel = trim(
            (string) ($r['account_number'] ?? '')
            . (($r['account_name'] ?? null) !== null ? ' — ' . $r['account_name'] : ''),
        );
        $this->addItem($entryItems, $cs ? 'Účet' : 'Account', $accountLabel);
        $this->addItem($entryItems, 'Text', $r['text'] ?? null);
        $this->addItem(
            $entryItems,
            $cs ? 'Operace' : 'Operation',
            $this->cfgItemName('docs.core.rowOperations', $r['operation'] ?? null),
        );
        $this->addItem($entryItems, 'Partner', $r['partner_name'] ?? null);
        $this->addItem($entryItems, $cs ? 'Fiskální rok' : 'Fiscal year', $r['fiscal_year_name'] ?? null);
        if (($r['calendar_month'] ?? null) !== null && ($r['calendar_year'] ?? null) !== null) {
            $this->addItem(
                $entryItems,
                $cs ? 'Fiskální měsíc' : 'Fiscal month',
                $r['calendar_month'] . '/' . $r['calendar_year'],
            );
        }
        if ((int) ($r['is_error'] ?? 0) === 1) {
            $this->addItem($entryItems, $cs ? 'Chybový řádek' : 'Error line', $cs ? '⚠ Ano' : '⚠ Yes');
        }

        $amountItems = [];
        $this->addItem($amountItems, 'MD', $this->formatMoney($r['money_dr'] ?? 0));
        $this->addItem($amountItems, 'DAL', $this->formatMoney($r['money_cr'] ?? 0));
        $drCur = (float) ($r['money_dr_cur'] ?? 0);
        $crCur = (float) ($r['money_cr_cur'] ?? 0);
        if ($drCur !== 0.0 || $crCur !== 0.0) {
            $curCode = strtoupper((string) ($r['currency'] ?? ''));
            $this->addItem($amountItems, 'MD ' . $curCode, $this->formatMoney($drCur));
            $this->addItem($amountItems, 'DAL ' . $curCode, $this->formatMoney($crCur));
        }

        $docItems = [];
        $this->addItem(
            $docItems,
            $cs ? 'Typ dokladu' : 'Document type',
            $this->cfgItemName('docs.core.docTypes', $r['doc_type'] ?? null),
        );
        $this->addItem($docItems, $cs ? 'Číslo dokladu' : 'Document number', $r['doc_number'] ?? null);

        $paymentItems = [];
        $this->addItem($paymentItems, $cs ? 'Variabilní symbol' : 'Payment reference', $r['payment_reference'] ?? null);
        $this->addItem($paymentItems, $cs ? 'Specifický symbol' : 'Specific symbol', $r['specific_symbol'] ?? null);
        $this->addItem($paymentItems, $cs ? 'Konstantní symbol' : 'Constant symbol', $r['constant_symbol'] ?? null);
        $this->addItem($paymentItems, $cs ? 'Splatnost' : 'Due date', $this->formatDate($r['due_date'] ?? null));

        $groups = [
            ['title' => $cs ? 'Zápis' : 'Entry', 'items' => $entryItems],
            ['title' => $cs ? 'Částky' : 'Amounts', 'items' => $amountItems],
        ];
        if ($docItems !== []) {
            $groups[] = ['title' => $cs ? 'Doklad' : 'Document', 'items' => $docItems];
        }
        if ($paymentItems !== []) {
            $groups[] = ['title' => $cs ? 'Platba' : 'Payment', 'items' => $paymentItems];
        }

        $detail = ['tabs' => [[
            'id'      => 'overview',
            'label'   => $this->defaultOverviewLabel(),
            'content' => ['type' => 'properties', 'groups' => $groups],
        ]]];

        $docHead = (int) ($r['doc_head'] ?? 0);
        if ($docHead > 0) {
            $detail['actions'] = [[
                'id'       => 'open_doc',
                'label'    => $cs ? 'Otevřít doklad' : 'Open document',
                'kind'     => 'open_viewer',
                'viewerId' => 'docs.core.heads',
                'recordId' => $docHead,
                'variant'  => 'secondary',
            ]];
        }

        return $detail;
    }

    /**
     * Filtry seznamu. Options fiskálních období se čtou z economy_codebooks
     * číselníků; měsíce jsou závislý select (parentFilter + option.parent),
     * frontend je nabídne až po volbě roku.
     */
    public function getFilters(): array
    {
        $cs = $this->language === 'cs';

        $yearOptions = [];
        $years = $this->db->fetchAll(
            'SELECT `id`, `name` FROM `economy_codebooks_fiscal_years`'
            . ' WHERE `docState` != 90 ORDER BY `name` DESC',
        );
        foreach ($years as $y) {
            $yearOptions[] = ['value' => (int) $y['id'], 'label' => (string) $y['name']];
        }

        $monthOptions = [];
        $months = $this->db->fetchAll(
            'SELECT `id`, `fiscal_year`, `calendar_year`, `calendar_month`'
            . ' FROM `economy_codebooks_fiscal_months` ORDER BY `date_begin` DESC',
        );
        foreach ($months as $m) {
            $monthOptions[] = [
                'value'  => (int) $m['id'],
                'label'  => $m['calendar_month'] . '/' . $m['calendar_year'],
                'parent' => (int) $m['fiscal_year'],
            ];
        }

        return [
            [
                'id'      => 'fiscal_year',
                'label'   => $cs ? 'Fiskální rok' : 'Fiscal year',
                'type'    => 'select',
                'options' => $yearOptions,
            ],
            [
                'id'           => 'fiscal_month',
                'label'        => $cs ? 'Měsíc' : 'Month',
                'type'         => 'select',
                'parentFilter' => 'fiscal_year',
                'options'      => $monthOptions,
            ],
            [
                'id'    => 'account',
                'label' => $cs ? 'Účet' : 'Account',
                'type'  => 'text',
            ],
            [
                'id'    => 'partner',
                'label' => 'Partner',
                'type'  => 'text',
            ],
            [
                'id'    => 'payment_reference',
                'label' => $cs ? 'Variabilní symbol' : 'Payment reference',
                'type'  => 'text',
            ],
            [
                'id'    => 'only_errors',
                'label' => $cs ? 'Jen chyby' : 'Errors only',
                'type'  => 'checkbox',
            ],
        ];
    }

    /** Deník je read-only — žádné Add/Open toolbar akce. */
    public function getToolbarActions(?array $selectedRow): array
    {
        return [];
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /** Lokalizovaný název položky cfgItem číselníku ({key: {name}}); NULL → null. */
    private function cfgItemName(string $cfgItemId, mixed $key): ?string
    {
        if ($key === null || $key === '' || $this->config === null) {
            return null;
        }
        $items = $this->config->cfgItem($cfgItemId);
        if (!is_array($items)) {
            return null;
        }
        $entry = $items[(string) $key] ?? null;
        return is_array($entry) ? ($entry['name'] ?? null) : null;
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
