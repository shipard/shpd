<script>
  import { get, del } from '../../api/client.js';
  import Button from '../ui/Button.svelte';
  import FormDialog from './FormDialog.svelte';
  import { t } from '../../i18n/index.js';

  let {
    element,
    parentId = null,
    disabled = false,
  } = $props();

  let rows = $state([]);
  let loading = $state(false);
  let columns = $state([]);

  // Sub-form dialog state
  let dialogOpen = $state(false);
  let editRecordId = $state(null);

  const EXCLUDE_COLS = new Set(['id', 'order_pos', 'created', 'modified']);
  const MAX_COLS = 5;

  async function fetchRows() {
    if (parentId == null) return;
    loading = true;
    const fk = element.foreign_key;
    const sort = element.sort ?? 'order_pos:asc';
    const res = await get(`/${element.table}?filter[${fk}]=eq:${parentId}&sort=${encodeURIComponent(sort)}`);
    if (res?.success) {
      rows = res.data ?? [];
      deriveColumns();
    }
    loading = false;
  }

  function deriveColumns() {
    if (rows.length === 0) { columns = []; return; }
    const fk = element.foreign_key;
    const allKeys = Object.keys(rows[0]).filter(k =>
      !EXCLUDE_COLS.has(k) && k !== fk
    );
    columns = allKeys.slice(0, MAX_COLS);
  }

  function handleAdd() {
    editRecordId = null;
    dialogOpen = true;
  }

  function handleEdit(id) {
    editRecordId = id;
    dialogOpen = true;
  }

  async function handleDelete(id) {
    if (!confirm(t('subtable.confirmDelete'))) return;
    await del(`/${element.table}/${id}`);
    fetchRows();
  }

  function handleDialogClose() {
    dialogOpen = false;
    editRecordId = null;
  }

  function handleDialogSaved() {
    dialogOpen = false;
    editRecordId = null;
    fetchRows();
  }

  $effect(() => {
    const pid = parentId;
    if (pid != null) fetchRows();
  });
</script>

<div class="shpd-form-subtable">
  {#if parentId == null}
    <div class="shpd-form-subtable__info">
      {t('subtable.saveFirst')}
    </div>
  {:else}
    <div class="shpd-form-subtable__toolbar">
      <Button
        label={t('common.add')}
        variant="secondary"
        size="sm"
        onclick={handleAdd}
        {disabled}
      />
    </div>

    {#if loading}
      <div class="shpd-form-subtable__loading">{t('common.loading')}</div>
    {:else if rows.length === 0}
      <div class="shpd-form-subtable__empty">{t('common.empty')}</div>
    {:else}
      <div class="shpd-form-subtable__table-wrap">
        <table class="shpd-form-subtable__table">
          <thead>
            <tr>
              {#each columns as col}
                <th>{col}</th>
              {/each}
              <th class="shpd-form-subtable__actions-th"></th>
            </tr>
          </thead>
          <tbody>
            {#each rows as row (row.id)}
              <tr>
                {#each columns as col}
                  <td>{row[col] ?? ''}</td>
                {/each}
                <td class="shpd-form-subtable__actions">
                  {#if !disabled}
                    <button class="shpd-form-subtable__btn" onclick={() => handleEdit(row.id)} title={t('common.edit')}>✎</button>
                    <button class="shpd-form-subtable__btn shpd-form-subtable__btn--danger" onclick={() => handleDelete(row.id)} title={t('common.delete')}>✕</button>
                  {/if}
                </td>
              </tr>
            {/each}
          </tbody>
        </table>
      </div>
    {/if}
  {/if}
</div>

<FormDialog
  table={element.table}
  recordId={editRecordId}
  open={dialogOpen}
  onClose={handleDialogClose}
  onSaved={handleDialogSaved}
  defaultData={{ [element.foreign_key]: parentId }}
/>

<style>
  .shpd-form-subtable {
    width: 100%;
  }

  .shpd-form-subtable__info {
    padding: var(--shpd-space-lg);
    text-align: center;
    color: var(--shpd-color-text-secondary);
    font-size: var(--shpd-font-size-sm);
  }

  .shpd-form-subtable__toolbar {
    display: flex;
    justify-content: flex-end;
    padding: var(--shpd-space-sm) 0;
  }

  .shpd-form-subtable__loading,
  .shpd-form-subtable__empty {
    padding: var(--shpd-space-md);
    text-align: center;
    color: var(--shpd-color-text-secondary);
    font-size: var(--shpd-font-size-sm);
  }

  .shpd-form-subtable__table-wrap {
    overflow-x: auto;
  }

  .shpd-form-subtable__table {
    width: 100%;
    border-collapse: collapse;
    font-size: var(--shpd-font-size-sm);
  }

  .shpd-form-subtable__table th {
    text-align: left;
    padding: var(--shpd-space-xs) var(--shpd-space-sm);
    border-bottom: 2px solid var(--shpd-color-border);
    font-weight: 600;
    color: var(--shpd-color-text-secondary);
    white-space: nowrap;
  }

  .shpd-form-subtable__table td {
    padding: var(--shpd-space-xs) var(--shpd-space-sm);
    border-bottom: 1px solid var(--shpd-color-border);
    color: var(--shpd-color-text);
  }

  .shpd-form-subtable__table tbody tr:hover {
    background: var(--shpd-color-bg-hover);
  }

  .shpd-form-subtable__actions-th {
    width: 60px;
  }

  .shpd-form-subtable__actions {
    white-space: nowrap;
    text-align: right;
  }

  .shpd-form-subtable__btn {
    background: none;
    border: none;
    cursor: pointer;
    padding: 2px var(--shpd-space-xs);
    color: var(--shpd-color-text-secondary);
    font-size: var(--shpd-font-size-base);
    border-radius: var(--shpd-radius-sm);
  }

  .shpd-form-subtable__btn:hover {
    background: var(--shpd-color-bg-hover);
    color: var(--shpd-color-text);
  }

  .shpd-form-subtable__btn--danger:hover {
    color: var(--shpd-color-danger);
  }
</style>
