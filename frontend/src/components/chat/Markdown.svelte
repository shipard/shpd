<script>
  /**
   * Renders a safe Markdown subset (see markdown.js) into Svelte elements.
   * Inline spans and code are bound as text — never {@html} — so model output
   * carries no XSS surface.
   */
  import { parseMarkdown } from './markdown.js';

  let { text = '' } = $props();

  const blocks = $derived(parseMarkdown(text));
</script>

<div class="shpd-md">
  {#each blocks as block}
    {#if block.type === 'paragraph'}
      <p>
        {#each block.spans as span}
          {#if span.type === 'strong'}<strong>{span.text}</strong>
          {:else if span.type === 'em'}<em>{span.text}</em>
          {:else if span.type === 'code'}<code>{span.text}</code>
          {:else}{span.text}{/if}
        {/each}
      </p>
    {:else if block.type === 'code_block'}
      <pre><code>{block.text}</code></pre>
    {:else if block.type === 'list'}
      {#if block.ordered}
        <ol>
          {#each block.items as item}
            <li>
              {#each item as span}
                {#if span.type === 'strong'}<strong>{span.text}</strong>
                {:else if span.type === 'em'}<em>{span.text}</em>
                {:else if span.type === 'code'}<code>{span.text}</code>
                {:else}{span.text}{/if}
              {/each}
            </li>
          {/each}
        </ol>
      {:else}
        <ul>
          {#each block.items as item}
            <li>
              {#each item as span}
                {#if span.type === 'strong'}<strong>{span.text}</strong>
                {:else if span.type === 'em'}<em>{span.text}</em>
                {:else if span.type === 'code'}<code>{span.text}</code>
                {:else}{span.text}{/if}
              {/each}
            </li>
          {/each}
        </ul>
      {/if}
    {/if}
  {/each}
</div>

<style>
  .shpd-md {
    line-height: 1.5;
    word-break: break-word;
  }
  .shpd-md :global(p) {
    margin: 0 0 var(--shpd-space-sm);
  }
  .shpd-md :global(p:last-child) {
    margin-bottom: 0;
  }
  .shpd-md :global(ul),
  .shpd-md :global(ol) {
    margin: 0 0 var(--shpd-space-sm);
    padding-left: var(--shpd-space-lg);
  }
  .shpd-md :global(code) {
    font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
    font-size: 0.9em;
    background-color: var(--shpd-color-bg-secondary);
    padding: 1px 4px;
    border-radius: var(--shpd-radius-sm);
  }
  .shpd-md :global(pre) {
    margin: 0 0 var(--shpd-space-sm);
    padding: var(--shpd-space-sm) var(--shpd-space-md);
    background-color: var(--shpd-color-bg-secondary);
    border: 1px solid var(--shpd-color-border);
    border-radius: var(--shpd-radius-md);
    overflow-x: auto;
  }
  .shpd-md :global(pre code) {
    background: none;
    padding: 0;
  }
</style>
