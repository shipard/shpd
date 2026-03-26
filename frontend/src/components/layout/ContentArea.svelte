<script>
  import TableBrowser from '../browser/TableBrowser.svelte';
  import Viewer from '../viewer/Viewer.svelte';

  let { activeTab = null } = $props();
</script>

<main class="shpd-content">
  {#if activeTab?.type === 'viewer'}
    <Viewer tab={activeTab} />
  {:else if activeTab?.type === 'table'}
    <TableBrowser tab={activeTab} />
  {:else if activeTab}
    <!-- Placeholder for future tab types (form, dashboard, …) -->
    <div class="shpd-content__empty">
      <p class="shpd-content__empty-text">Nepodporovaný typ panelu: {activeTab.type}</p>
    </div>
  {:else}
    <div class="shpd-content__empty">
      <p class="shpd-content__empty-text">Vyberte položku v menu</p>
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
