<script lang="ts">
  import { onMount } from 'svelte';
  import { get, post, put } from '../../api/client.js';
  import FormField from './FormField.svelte';
  import Button from '../ui/Button.svelte';

  const AUTO_MANAGED = ['id', 'created', 'modified'];

  interface ColumnMeta {
    id: string;
    name: string;
    type: string;
    nullable?: boolean;
    length?: number;
    precision?: number;
    scale?: number;
    group?: string;
    cfgItem?: string;
    default?: unknown;
  }

  interface ColumnGroup {
    id: string;
    name: string;
  }

  interface TableMeta {
    table: string;
    name: string;
    columns: ColumnMeta[];
    columnGroups?: ColumnGroup[];
  }

  interface Props {
    table: string;
    recordId?: number | null;
    onSave?: (data: Record<string, unknown>) => void;
    onCancel?: () => void;
  }

  let {
    table,
    recordId = null,
    onSave,
    onCancel,
  }: Props = $props();

  let meta = $state<TableMeta | null>(null);
  let formData = $state<Record<string, unknown>>({});
  let fieldErrors = $state<Record<string, string>>({});
  let saving = $state(false);
  let loadError = $state<string | null>(null);

  const isEdit = $derived(recordId !== null);

  // Columns that should appear in the form
  const visibleColumns = $derived(computeVisibleColumns(meta?.columns ?? [], isEdit));

  // Build group sections: defined groups in order, then ungrouped fields as "Ostatní"
  const groupSections = $derived.by(() => {
    const cols = visibleColumns;
    const columnGroups = meta?.columnGroups ?? [];

    if (columnGroups.length === 0) {
      return [{ group: null as ColumnGroup | null, columns: cols }];
    }

    const sections: { group: ColumnGroup | null; columns: ColumnMeta[] }[] = [];

    for (const g of columnGroups) {
      const groupCols = cols.filter(c => c.group === g.id);
      if (groupCols.length > 0) {
        sections.push({ group: g, columns: groupCols });
      }
    }

    const ungrouped = cols.filter(c => !c.group);
    if (ungrouped.length > 0) {
      sections.push({ group: null, columns: ungrouped });
    }

    return sections;
  });

  function buildEmptyFormData(columns: ColumnMeta[]): Record<string, unknown> {
    const data: Record<string, unknown> = {};
    for (const col of columns) {
      if (col.type === 'boolean') {
        data[col.id] = col.default != null ? Boolean(col.default) : false;
      } else if (col.default != null) {
        data[col.id] = col.default;
      } else {
        data[col.id] = col.nullable ? null : '';
      }
    }
    return data;
  }

  function buildRecordFormData(columns: ColumnMeta[], record: Record<string, unknown>): Record<string, unknown> {
    const data: Record<string, unknown> = {};
    for (const col of columns) {
      if (!(col.id in record)) {
        // Field not in record response (e.g. password filtered by API) — use empty default
        data[col.id] = col.type === 'boolean' ? false : '';
        continue;
      }
      const val = record[col.id];
      // datetime-local input requires "YYYY-MM-DDTHH:MM" format
      if (col.type === 'datetime' && typeof val === 'string' && val) {
        data[col.id] = val.substring(0, 16).replace(' ', 'T');
      } else {
        data[col.id] = val;
      }
    }
    return data;
  }

  function computeVisibleColumns(columns: ColumnMeta[], editing: boolean): ColumnMeta[] {
    return columns.filter(col => {
      if (AUTO_MANAGED.includes(col.id)) return false;
      if (editing && col.id.includes('password')) return false;
      return true;
    });
  }

  async function loadData() {
    loadError = null;

    const metaRes = await get(`/_meta/tables/${table}`);
    if (!metaRes?.success) {
      loadError = metaRes?.error?.message ?? 'Nepodařilo se načíst metadata tabulky.';
      return;
    }

    const loadedMeta = metaRes.data as TableMeta;
    const editing = recordId !== null;
    const cols = computeVisibleColumns(loadedMeta.columns ?? [], editing);

    if (editing) {
      const recRes = await get(`/${table}/${recordId}`);
      if (!recRes?.success) {
        loadError = recRes?.error?.message ?? 'Nepodařilo se načíst záznam.';
        return;
      }
      formData = buildRecordFormData(cols, recRes.data as Record<string, unknown>);
    } else {
      formData = buildEmptyFormData(cols);
    }

    // Set meta last — the template guards on `meta !== null`, so fields only
    // render once formData is already populated (avoids undefined prop values).
    meta = loadedMeta;
  }

  async function handleSave() {
    saving = true;
    fieldErrors = {};

    const res = isEdit
      ? await put(`/${table}/${recordId}`, formData)
      : await post(`/${table}`, formData);

    if (res?.success) {
      onSave?.(res.data as Record<string, unknown>);
    } else if (res?.error?.code === 'VALIDATION_ERROR') {
      const errs: Record<string, string> = {};
      for (const detail of (res.error.details ?? []) as Array<{ field: string; message: string }>) {
        errs[detail.field] = detail.message;
      }
      fieldErrors = errs;
    } else {
      loadError = res?.error?.message ?? 'Při ukládání došlo k neznámé chybě.';
    }

    saving = false;
  }

  onMount(() => {
    loadData();
  });
</script>

<div class="shpd-form">
  {#if loadError}
    <p class="shpd-form__error-banner">{loadError}</p>
  {/if}

  {#if meta === null && !loadError}
    <p class="shpd-form__loading">Načítám…</p>
  {/if}

  {#if meta !== null}
    {#each groupSections as section}
      <div class="shpd-form__group">
        {#if section.group !== null}
          <h3 class="shpd-form__group-title">{section.group.name}</h3>
        {:else if groupSections.length > 1}
          <!-- "Ostatní" heading only when other named groups exist -->
          <h3 class="shpd-form__group-title">Ostatní</h3>
        {/if}

        <div class="shpd-form__fields">
          {#each section.columns as col (col.id)}
            <FormField
              column={col}
              bind:value={formData[col.id]}
              error={fieldErrors[col.id] ?? null}
              disabled={saving}
            />
          {/each}
        </div>
      </div>
    {/each}

    <div class="shpd-form__actions">
      <Button
        label="Zrušit"
        variant="secondary"
        disabled={saving}
        onclick={onCancel}
      />
      <Button
        label="Uložit"
        variant="primary"
        loading={saving}
        onclick={handleSave}
      />
    </div>
  {/if}
</div>

<style>
  .shpd-form {
    padding: var(--shpd-space-lg);
  }

  .shpd-form__loading {
    color: var(--shpd-color-text-secondary);
    font-size: var(--shpd-font-size-sm);
  }

  .shpd-form__error-banner {
    padding: var(--shpd-space-sm) var(--shpd-space-md);
    background-color: var(--shpd-color-danger-soft);
    border: 1px solid var(--shpd-color-danger);
    border-radius: var(--shpd-radius-md);
    color: var(--shpd-color-danger);
    font-size: var(--shpd-font-size-sm);
    margin-bottom: var(--shpd-space-md);
  }

  .shpd-form__group {
    margin-bottom: var(--shpd-space-lg);
  }

  .shpd-form__group-title {
    font-size: var(--shpd-font-size-lg);
    font-weight: 600;
    color: var(--shpd-color-text);
    margin: 0 0 var(--shpd-space-md) 0;
    padding-bottom: var(--shpd-space-sm);
    border-bottom: 1px solid var(--shpd-color-border);
  }

  .shpd-form__fields {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: var(--shpd-space-md);
  }

  @media (max-width: 600px) {
    .shpd-form__fields {
      grid-template-columns: 1fr;
    }
  }

  .shpd-form__actions {
    display: flex;
    justify-content: flex-end;
    gap: var(--shpd-space-sm);
    margin-top: var(--shpd-space-lg);
  }
</style>
