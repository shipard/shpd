<?php

declare(strict_types=1);

namespace Shipard\Core\Viewer;

use Shipard\Core\Database\DataSourceConnection;

abstract class TableViewer
{
    /** Default number of rows per page. Subclasses can override. */
    protected int $pageSize = 50;

    public function __construct(
        protected DataSourceConnection $db,
        protected string $table,
    ) {}

    /**
     * Get the page size for this viewer.
     */
    public function getPageSize(): int
    {
        return $this->pageSize;
    }

    /**
     * Select rows for the viewer list.
     *
     * IMPORTANT: The implementation should fetch pageSize + 1 rows. The caller
     * (ViewerController) uses the extra row to determine if more data exists:
     * - If count > pageSize: trim to pageSize, set hasMore = true
     * - If count <= pageSize: return as is, set hasMore = false
     *
     * The ORDER BY clause must always end with a unique column (typically `id`)
     * to guarantee deterministic ordering with LIMIT/OFFSET. For example:
     *   ORDER BY last_name ASC, first_name ASC, id ASC
     *
     * @param string|null $search     Fulltext search query
     * @param array       $filters    Active filters [{id: string, value: mixed}]
     * @param int         $pageNumber Zero-based page number (0 = first page)
     * @return array Array of raw DB rows (up to pageSize + 1)
     */
    abstract public function selectRows(
        ?string $search,
        array $filters,
        int $pageNumber,
    ): array;

    /**
     * Render a single row for the list display.
     * Returns a structured object for the frontend.
     *
     * Row format:
     * - id: int (record ID)
     * - icon: string|null (icon identifier for left side, ~32px area)
     * - t1: string|array|null (first line, left -- main text, displayed bold)
     * - i1: string|array|null (first line, right -- e.g. amount, code)
     * - t2: string|array|null (second line, left -- e.g. doc number, date)
     * - i2: string|array|null (second line, right -- e.g. total with VAT)
     * - t3: string|array|null (optional third line -- e.g. description)
     *
     * Each field (t1, i1, t2, i2, t3) can be:
     * - null: not displayed
     * - "string": plain text
     * - {"text": "...", "class": "...", "icon": "..."}: styled span
     * - [{"text": "...", "class": "..."}, ...]: array of styled spans
     *
     * Available classes: amount, muted, bold, primary, success, warning, danger
     *
     * @param array $rowData Raw DB row
     * @return array Structured row
     */
    abstract public function renderRow(array $rowData): array;

    /**
     * Render the detail panel for a selected row.
     * Returns tabs with content.
     *
     * Content types:
     * - "properties": {type: "properties", groups: [{title, items: [{label, value}]}]}
     * - "table": {type: "table", columns: [{id, label}], rows: [{col: val}]}
     * - "html": {type: "html", html: "<div>..."}
     *
     * @param int $recordId
     * @return array {tabs: [{id: string, label: string, content: array}]}
     */
    public function renderDetail(int $recordId): array
    {
        return ['tabs' => []];
    }

    /**
     * Toolbar actions. Changes based on whether a row is selected.
     *
     * @param array|null $selectedRow null if no row selected
     * @return array [{id: string, label: string, icon?: string, variant?: string}]
     */
    public function getToolbarActions(?array $selectedRow): array
    {
        $actions = [
            ['id' => 'create', 'label' => 'Add', 'variant' => 'primary'],
        ];

        if ($selectedRow !== null) {
            array_splice($actions, 1, 0, [
                ['id' => 'edit', 'label' => 'Open', 'variant' => 'secondary'],
            ]);
        }

        return $actions;
    }

    /**
     * Filter definitions for the right panel.
     *
     * @return array [{id: string, label: string, type: string, options?: array}]
     */
    public function getFilters(): array
    {
        return [];
    }

    /**
     * Build a LIKE search condition for multiple columns.
     * Helper for subclasses to use in selectRows().
     *
     * @param string[] $columns Column names to search
     * @param string $search Search term
     * @return array{0: string, 1: array} [sql_fragment, params]
     */
    protected function buildSearchCondition(array $columns, string $search): array
    {
        if (empty($columns) || $search === '') {
            return ['', []];
        }

        $parts = [];
        $params = [];
        $term = '%' . $search . '%';

        foreach ($columns as $col) {
            $parts[] = "`{$col}` LIKE %s";
            $params[] = $term;
        }

        return ['(' . implode(' OR ', $parts) . ')', $params];
    }

    /**
     * Compute LIMIT clause values for offset-based pagination.
     * Fetches pageSize + 1 to detect whether more rows exist.
     *
     * @param int $pageNumber Zero-based page number
     * @return array{0: int, 1: int} [offset, limit] -- use as "LIMIT offset, limit"
     */
    protected function buildPaginationLimit(int $pageNumber): array
    {
        $offset = $pageNumber * $this->pageSize;
        $limit = $this->pageSize + 1;
        return [$offset, $limit];
    }
}
