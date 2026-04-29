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

  /* Stylové varianty.
   *
   * Používáme child selectory (.shpd-form-state-badge.docState_*)
   * místo :global(.docState_*) — :global by se chytlo i na .docState_*
   * třídy v jiných komponentách (např. ViewerRow), kde mají
   * úplně jiný význam (tam doc-state ovládá jen levý proužek,
   * pozadí je bílé). Sdílené třídní názvy + :global by tedy
   * v jednom turně kolidovaly.
   *
   * Paleta sjednocená s ostatními badge v aplikaci:
   *   concept   → žlutá
   *   done      → zelená ("hotovo")
   *   edit      → fialová (NE oranžová — ta je pro brand accent)
   *   confirmed → tichá šedá ("OK = klid")
   *   archive   → šedá
   *   trash     → šedá + line-through
   *   cancelled → červená
   *   error     → červená
   */
  .shpd-form-state-badge.docState_concept   { background: #fef3c7; color: #854d0e; }
  .shpd-form-state-badge.docState_done      { background: #dcfce7; color: #166534; }
  .shpd-form-state-badge.docState_edit      { background: #ede9fe; color: #5b21b6; }
  .shpd-form-state-badge.docState_confirmed { background: #f1f5f9; color: #475569; }
  .shpd-form-state-badge.docState_archive   { background: #e5e7eb; color: #374151; }
  .shpd-form-state-badge.docState_trash     { background: #e5e7eb; color: #6b7280; text-decoration: line-through; }
  .shpd-form-state-badge.docState_cancelled { background: #fecaca; color: #991b1b; }
  .shpd-form-state-badge.docState_error     { background: #fecaca; color: #991b1b; }
</style>
