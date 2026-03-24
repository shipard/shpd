<script>
  let { children } = $props();
</script>

<main class="shpd-content">
  {#if children}
    {@render children()}
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
