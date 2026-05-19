<script>
  import { onMount } from 'svelte';
  import { t } from '../../i18n/index.js';
  import { fetchDashboard } from '../../api/dashboard.js';
  import { iconRefresh } from '../../icons.js';
  import { navigationStore } from '../../stores/navigation.svelte.js';
  import Button from '../ui/Button.svelte';
  import FormDialog from '../form/FormDialog.svelte';
  import AiSummaryCard from './AiSummaryCard.svelte';
  import WidgetCard from './WidgetCard.svelte';

  let data = $state(null);
  let loading = $state(true);
  let error = $state(null);

  // Form modal state. wasSaved se nastaví true z onSaved callbacku FormDialogu;
  // handleFormClose podle něj rozhodne, zda refetchnout dashboard.
  let formModal = $state({
    open: false,
    table: '',
    recordId: null,
    wasSaved: false,
  });

  async function load() {
    loading = true;
    error = null;
    try {
      const result = await fetchDashboard();
      if (result) {
        data = result;
      } else {
        error = t('dashboard.error.failed');
      }
    } catch (err) {
      error = t('dashboard.error.failed');
      console.error('Dashboard load failed:', err);
    } finally {
      loading = false;
    }
  }

  function handleItemAction(action) {
    if (!action || !action.kind) return;

    if (action.kind === 'open_viewer') {
      navigationStore.navigateToViewer(action.viewerId, action.recordId ?? null);
      return;
    }

    if (action.kind === 'open_form') {
      formModal = {
        open: true,
        table: action.table,
        recordId: action.recordId ?? null,
        wasSaved: false,
      };
      return;
    }

    console.warn('Unknown widget action kind:', action.kind);
  }

  function handleOpenAllAction(action) {
    if (!action?.viewerId) return;
    navigationStore.navigateToViewer(action.viewerId);
  }

  function handleFormSaved() {
    // Mutace property, ne reassign celého $state proxy — reassign by propagoval
    // derived signals do všech bindingů FormDialogu (open/table/recordId), což by
    // re-runlo $effect ve FormDialog a způsobilo flash close+reopen modalu.
    formModal.wasSaved = true;
  }

  function handleFormClose() {
    const shouldRefetch = formModal.wasSaved;
    formModal = { open: false, table: '', recordId: null, wasSaved: false };
    if (shouldRefetch) {
      load();
    }
  }

  onMount(load);
</script>

<div class="shpd-dashboard">
  <header class="shpd-dashboard__header">
    <h1 class="shpd-dashboard__title">{t('dashboard.title')}</h1>
    <Button
      variant="ghost"
      size="sm"
      icon={iconRefresh}
      label={t('dashboard.refresh')}
      onclick={load}
      disabled={loading}
    />
  </header>

  {#if loading && !data}
    <div class="shpd-dashboard__loading">{t('common.loading')}</div>
  {:else if error && !data}
    <div class="shpd-dashboard__error">{error}</div>
  {:else if data}
    <AiSummaryCard summary={data.summary} />

    <div class="shpd-dashboard__widgets">
      {#each data.widgets as widget (widget.id)}
        <WidgetCard
          {widget}
          onItemAction={handleItemAction}
          onOpenAllAction={handleOpenAllAction}
        />
      {/each}
    </div>
  {/if}
</div>

{#if formModal.open}
  <FormDialog
    table={formModal.table}
    recordId={formModal.recordId}
    open={formModal.open}
    onSaved={handleFormSaved}
    onClose={handleFormClose}
  />
{/if}

<style>
  .shpd-dashboard {
    padding: var(--shpd-space-lg);
    display: flex;
    flex-direction: column;
    gap: var(--shpd-space-lg);
  }

  .shpd-dashboard__header {
    display: flex;
    align-items: center;
    justify-content: space-between;
  }

  .shpd-dashboard__title {
    margin: 0;
    font-size: var(--shpd-font-size-xl);
    color: var(--shpd-color-text);
  }

  .shpd-dashboard__widgets {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: var(--shpd-space-md);
  }

  .shpd-dashboard__loading,
  .shpd-dashboard__error {
    padding: var(--shpd-space-xl);
    text-align: center;
    color: var(--shpd-color-text-secondary);
  }
</style>
