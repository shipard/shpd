<script>
  import Button from '../ui/Button.svelte';

  let {
    docStates = null,
    saving = false,
    onSave,
    onTransition,
  } = $props();

  const showSave = $derived(!docStates || !docStates.read_only);
  const transitions = $derived(docStates?.transitions ?? []);

  function variantForStyle(stateStyle) {
    if (stateStyle === 'done') return 'primary';
    if (['archive', 'trash', 'cancelled'].includes(stateStyle)) return 'danger';
    return 'secondary';
  }
</script>

<div class="shpd-form-state-bar">
  {#if showSave}
    <Button
      label="Uložit"
      variant="primary"
      loading={saving}
      disabled={saving}
      onclick={onSave}
    />
  {/if}

  {#each transitions as tr (tr.state)}
    <Button
      label={tr.actionName}
      variant={variantForStyle(tr.stateStyle)}
      disabled={saving}
      onclick={() => onTransition?.(tr.state, tr.close_form ?? false)}
    />
  {/each}
</div>

<style>
  .shpd-form-state-bar {
    display: flex;
    gap: var(--shpd-space-sm);
    padding: var(--shpd-space-sm) var(--shpd-space-lg);
    border-top: 1px solid var(--shpd-color-border);
    background: var(--shpd-color-bg);
    flex-shrink: 0;
    position: sticky;
    bottom: 0;
  }
</style>
