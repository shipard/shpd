<script>
  import Button from '../ui/Button.svelte';

  let { actions = [], onAction } = $props();
</script>

<div class="shpd-viewer-toolbar">
  {#each actions as action (action.id)}
    <Button
      label={action.label}
      variant={action.variant ?? 'secondary'}
      onclick={() => onAction?.(action.id)}
    />
  {/each}
</div>

<style>
  .shpd-viewer-toolbar {
    display: flex;
    align-items: center;
    gap: var(--shpd-space-sm);
    padding: var(--shpd-space-sm) var(--shpd-space-md);
    border-bottom: 1px solid var(--shpd-color-border);
    background-color: var(--shpd-color-bg);
    flex-shrink: 0;
  }
</style>
