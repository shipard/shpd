<script>
  /**
   * Renders a `table` block token from markdown.js as a native <table>.
   * All cell content is text-bound spans — never {@html} — and the per-column
   * text-align comes from the parser's whitelisted values, never model text.
   */
  let { header = [], rows = [], align = [] } = $props();

  const cellStyle = (col) => (align[col] ? `text-align: ${align[col]}` : null);
</script>

{#snippet cell(spans)}
  {#each spans as span}
    {#if span.type === 'strong'}<strong>{span.text}</strong>
    {:else if span.type === 'em'}<em>{span.text}</em>
    {:else if span.type === 'code'}<code>{span.text}</code>
    {:else}{span.text}{/if}
  {/each}
{/snippet}

<div class="shpd-md-table">
  <table>
    <thead>
      <tr>
        {#each header as cellSpans, col}
          <th style={cellStyle(col)}>{@render cell(cellSpans)}</th>
        {/each}
      </tr>
    </thead>
    <tbody>
      {#each rows as row}
        <tr>
          {#each row as cellSpans, col}
            <td style={cellStyle(col)}>{@render cell(cellSpans)}</td>
          {/each}
        </tr>
      {/each}
    </tbody>
  </table>
</div>

<style>
  .shpd-md-table {
    position: relative;
    margin: 0 0 var(--shpd-space-sm);
    overflow-x: auto;
  }
  .shpd-md-table:last-child {
    margin-bottom: 0;
  }
  table {
    width: 100%;
    border-collapse: collapse;
    font-size: var(--shpd-font-size-sm);
  }
  th,
  td {
    padding: var(--shpd-space-xs) var(--shpd-space-sm);
    border-bottom: 1px solid var(--shpd-color-border);
    text-align: left;
    vertical-align: top;
  }
  th {
    font-weight: 600;
  }
  tbody tr:nth-child(even) {
    background-color: var(--shpd-color-bg-secondary);
  }
  tbody tr:last-child td {
    border-bottom: 0;
  }
</style>
