<script>
  import TableBrowser from '../browser/TableBrowser.svelte';
  import Viewer from '../viewer/Viewer.svelte';
  import { t } from '../../i18n/index.js';

  let { activeItem = null } = $props();
</script>

<main class="shpd-content">
  {#if activeItem?.type === 'viewer'}
    <Viewer tab={activeItem} />
  {:else if activeItem?.type === 'table'}
    <TableBrowser tab={activeItem} />
  {:else if activeItem}
    <!-- Placeholder for future content types (form, dashboard, …) -->
    <div class="shpd-content__empty">
      <p class="shpd-content__empty-text">{t('app.unsupportedPanel', { type: activeItem.type })}</p>
    </div>
  {:else}
    <div class="shpd-content__empty">
      <p class="shpd-content__empty-text">{t('app.selectMenuItem')}</p>
    </div>
  {/if}
</main>

<style>
  .shpd-content {
    flex: 1;
    overflow-y: auto;
    background-color: var(--shpd-color-bg-secondary);
  }

  .shpd-content__empty {
    display: flex;
    align-items: center;
    justify-content: center;
    height: 100%;
  }

  .shpd-content__empty-text {
    font-size: var(--shpd-font-size-lg);
    color: var(--shpd-color-text-secondary);
  }

</style>
