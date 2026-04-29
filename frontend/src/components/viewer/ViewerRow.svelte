<script>
  let { row, selected = false, onclick } = $props();

  // Compute row CSS class including optional docState style
  let rowClass = $derived(
    'shpd-viewer-row' +
    (selected ? ' shpd-viewer-row--selected' : '') +
    (row.stateStyle ? ` docState_${row.stateStyle}` : '')
  );

  /**
   * Normalize a field value into an array of span objects.
   * Handles: null, string, object {text, class?, icon?}, or array of those.
   */
  function normalizeSpans(value) {
    if (value == null) return null;
    if (typeof value === 'string') return [{ text: value }];
    if (Array.isArray(value)) return value;
    if (typeof value === 'object' && value.text != null) return [value];
    return null;
  }
</script>

<button
  class={rowClass}
  {onclick}
  type="button"
>
  {#if row.avatar}
    <span class="shpd-viewer-row__avatar">{row.avatar}</span>
  {:else if row.icon}
    <span class="shpd-viewer-row__icon">{row.icon}</span>
  {/if}

  <div class="shpd-viewer-row__body">
    <!-- Line 1 -->
    <div class="shpd-viewer-row__line">
      <span class="shpd-viewer-row__t1">
        {#each normalizeSpans(row.t1) ?? [] as span}
          <span class={span.class ? `shpd-viewer-row__span--${span.class}` : ''}>{span.text}</span>
        {/each}
      </span>
      {#if row.i1 != null}
        <span class="shpd-viewer-row__i1">
          {#each normalizeSpans(row.i1) ?? [] as span}
            <span class={span.class ? `shpd-viewer-row__span--${span.class}` : ''}>{span.text}</span>
          {/each}
        </span>
      {/if}
    </div>

    <!-- Line 2 -->
    {#if row.t2 != null || row.i2 != null}
      <div class="shpd-viewer-row__line shpd-viewer-row__line--secondary">
        <span class="shpd-viewer-row__t2">
          {#each normalizeSpans(row.t2) ?? [] as span, i}
            {#if i > 0}<span class="shpd-viewer-row__sep"> · </span>{/if}
            <span class={span.class ? `shpd-viewer-row__span--${span.class}` : ''}>{span.text}</span>
          {/each}
        </span>
        {#if row.i2 != null}
          <span class="shpd-viewer-row__i2">
            {#each normalizeSpans(row.i2) ?? [] as span}
              <span class={span.class ? `shpd-viewer-row__span--${span.class}` : ''}>{span.text}</span>
            {/each}
          </span>
        {/if}
      </div>
    {/if}

    <!-- Line 3 -->
    {#if row.t3 != null}
      <div class="shpd-viewer-row__line shpd-viewer-row__line--tertiary">
        <span class="shpd-viewer-row__t3">
          {#each normalizeSpans(row.t3) ?? [] as span}
            <span class={span.class ? `shpd-viewer-row__span--${span.class}` : ''}>{span.text}</span>
          {/each}
        </span>
      </div>
    {/if}
  </div>
</button>

<style>
  .shpd-viewer-row {
    position: relative;
    display: flex;
    align-items: flex-start;
    gap: var(--shpd-space-sm);
    width: 100%;
    padding: var(--shpd-space-sm) var(--shpd-space-md);
    border: none;
    border-bottom: 1px solid var(--shpd-color-border);
    background: var(--shpd-color-bg);
    cursor: pointer;
    text-align: left;
    font-family: inherit;
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text);
    transition: background-color 0.1s;
  }

  /* Levý stavový / accent proužek (4px, plný v celé výšce řádku).
   *
   * Barva se řídí CSS proměnnou --shpd-row-bar, kterou nastavují
   * docState_* třídy níže. Pokud proměnná není nastavená
   * (= confirmed nebo neznámý stav), proužek je průhledný — nic
   * se nezobrazí. Tím dostáváme „confirmed = klid“ chování.
   *
   * Při výběru se proměnná přepíše na brand accent (oranžová),
   * takže proužek funguje současně jako indikátor výběru. */
  .shpd-viewer-row::before {
    content: '';
    position: absolute;
    left: 0;
    top: 0;
    bottom: 0;
    width: 6px;
    background-color: var(--shpd-row-bar, transparent);
  }

  .shpd-viewer-row:hover {
    background-color: var(--shpd-color-bg-secondary);
  }

  .shpd-viewer-row--selected {
    background-color: var(--shpd-color-bg-selected);
    --shpd-row-bar: var(--shpd-color-accent);
  }

  .shpd-viewer-row--selected:hover {
    background-color: var(--shpd-color-bg-selected-hover);
  }

  /* Doc-state stavové proužky (jen barva pruhu, pozadí zůstává bílé).
   *
   * Konvence:
   *   confirmed → žádný proužek ("OK = klid")
   *   concept   → žlutý       (rozpracováno)
   *   edit      → fialový      (právě se edituje)
   *   archive   → šedý         (tichý archiv)
   *   trash     → tmavší šedá + line-through
   *   cancelled → červený     (pozor!)
   *
   * Používáme child selectory místo :global(.docState_*) — :global
   * by se chytlo i na stejnojmenné třídy v jiných komponentách
   * (FormStateBadge atd.). docState_done schválně není — "V pořádku"
   * je default stav, neěrěží pozornost. */
  .shpd-viewer-row.docState_concept   { --shpd-row-bar: #facc15; }
  .shpd-viewer-row.docState_edit      { --shpd-row-bar: #a78bfa; }
  .shpd-viewer-row.docState_archive   { --shpd-row-bar: #cbd5e1; color: #64748b; }
  .shpd-viewer-row.docState_trash     { --shpd-row-bar: #94a3b8; color: #94a3b8; text-decoration: line-through; }
  .shpd-viewer-row.docState_cancelled { --shpd-row-bar: #ef4444; }

  .shpd-viewer-row__icon {
    width: 32px;
    flex-shrink: 0;
    text-align: center;
    font-size: var(--shpd-font-size-lg);
    line-height: 1.4;
  }

  /* Avatar (kruhový, s iniciálami).
   * Stejná sloupcová šířka jako __icon, aby řádky bez avataru
   * a s avatarem byly horizontálně zarovnané. Neutrální barva
   * — nemá v seznamu konkurovat stavovému pruhu ani výběru. */
  .shpd-viewer-row__avatar {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    flex-shrink: 0;
    border-radius: 50%;
    font-size: 0.75rem;
    font-weight: 600;
    line-height: 1;
    letter-spacing: 0.02em;
    color: var(--shpd-color-primary);
    background-color: var(--shpd-color-primary-soft);
    text-transform: uppercase;
  }

  .shpd-viewer-row__body {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 2px;
  }

  .shpd-viewer-row__line {
    display: flex;
    justify-content: space-between;
    align-items: baseline;
    gap: var(--shpd-space-sm);
  }

  .shpd-viewer-row__t1 {
    font-weight: 600;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .shpd-viewer-row__i1 {
    flex-shrink: 0;
    color: var(--shpd-color-text-secondary);
  }

  .shpd-viewer-row__line--secondary {
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text-secondary);
  }

  .shpd-viewer-row__line--tertiary {
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text-secondary);
    opacity: 0.8;
  }

  .shpd-viewer-row__t2,
  .shpd-viewer-row__t3 {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .shpd-viewer-row__i2 { flex-shrink: 0; }

  .shpd-viewer-row__sep { color: var(--shpd-color-border); }

  /* Styled span variants */
  .shpd-viewer-row__span--amount   { font-variant-numeric: tabular-nums; font-weight: 600; }
  .shpd-viewer-row__span--muted    { opacity: 0.6; }
  .shpd-viewer-row__span--bold     { font-weight: 600; }
  .shpd-viewer-row__span--primary  { color: var(--shpd-color-primary); }
  .shpd-viewer-row__span--success  { color: var(--shpd-color-success); }
  .shpd-viewer-row__span--warning  { color: var(--shpd-color-warning); }
  .shpd-viewer-row__span--danger   { color: var(--shpd-color-danger); }
</style>
