<script>
  import { onMount } from 'svelte';
  import { t } from '../../i18n/index.js';
  import { fetchDashboard } from '../../api/dashboard.js';
  import { iconRefresh } from '../../icons.js';
  import Button from '../ui/Button.svelte';
  import AiSummaryCard from './AiSummaryCard.svelte';
  import WidgetCard from './WidgetCard.svelte';

  let data = $state(null);
  let loading = $state(true);
  let error = $state(null);

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
        <WidgetCard {widget} />
      {/each}
    </div>
  {/if}
</div>

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
