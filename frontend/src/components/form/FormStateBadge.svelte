<script>
  let { docStates = null } = $props();
</script>

{#if docStates}
  <span class="shpd-form-state-badge docState_{docStates.stateStyle}">
    {docStates.stateName}
  </span>
{/if}

<style>
  .shpd-form-state-badge {
    display: inline-block;
    padding: 2px var(--shpd-space-sm);
    border-radius: var(--shpd-radius-sm);
    font-size: var(--shpd-font-size-sm);
    font-weight: 600;
    white-space: nowrap;
  }

  :global(.docState_concept)   { background: #fef3c7; color: #92400e; }
  :global(.docState_done)      { background: #dcfce7; color: #166534; }
  :global(.docState_edit)      { background: #ffedd5; color: #9a3412; }
  :global(.docState_confirmed) { background: #dbeafe; color: #1e40af; }
  :global(.docState_archive)   { background: #f1f5f9; color: #475569; }
  :global(.docState_trash)     { background: #f1f5f9; color: #475569; text-decoration: line-through; }
  :global(.docState_cancelled) { background: #fee2e2; color: #991b1b; }
</style>
