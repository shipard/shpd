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
  {#if row.icon}
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

  .shpd-viewer-row:hover {
    background-color: var(--shpd-color-bg-secondary);
  }

  .shpd-viewer-row--selected {
    background-color: #eff6ff;
  }

  .shpd-viewer-row--selected:hover {
    background-color: #dbeafe;
  }

  /* Doc state row backgrounds */
  :global(.docState_concept)        { background-color: #fefce8; }
  :global(.docState_concept:hover)  { background-color: #fef9c3; }
  :global(.docState_edit)           { background-color: #fff7ed; }
  :global(.docState_edit:hover)     { background-color: #ffedd5; }
  :global(.docState_confirmed)      { background-color: #eff6ff; }
  :global(.docState_archive)        { background-color: #f8fafc; color: #64748b; }
  :global(.docState_trash)          { background-color: #f8fafc; color: #94a3b8; text-decoration: line-through; }
  :global(.docState_cancelled)      { background-color: #fff1f2; color: #be123c; }

  /* Selected state always wins over docState background */
  :global(.shpd-viewer-row--selected.docState_concept)   { background-color: #eff6ff; }
  :global(.shpd-viewer-row--selected.docState_edit)      { background-color: #eff6ff; }
  :global(.shpd-viewer-row--selected.docState_archive)   { background-color: #eff6ff; color: inherit; }
  :global(.shpd-viewer-row--selected.docState_cancelled) { background-color: #eff6ff; color: inherit; }

  .shpd-viewer-row__icon {
    width: 32px;
    flex-shrink: 0;
    text-align: center;
    font-size: var(--shpd-font-size-lg);
    line-height: 1.4;
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
