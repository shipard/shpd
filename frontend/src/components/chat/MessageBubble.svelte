<script>
  /**
   * Renders one persisted message by role/kind:
   *   user_text   → plain text (right-aligned bubble)
   *   assistant   → Markdown text + tool_use chips
   *   tool_results→ compact muted "tools returned" row (never raw JSON)
   */
  import Markdown from './Markdown.svelte';
  import ToolCallChip from './ToolCallChip.svelte';
  import { t } from '../../i18n/index.js';

  let { message } = $props();

  const blocks = $derived(Array.isArray(message.content) ? message.content : []);
  const text = $derived(blocks.filter((b) => b.type === 'text').map((b) => b.text).join('\n'));
  const toolUses = $derived(blocks.filter((b) => b.type === 'tool_use'));
  const toolResults = $derived(blocks.filter((b) => b.type === 'tool_result'));

  const isUser = $derived(message.kind === 'user_text');
  const isToolResults = $derived(message.kind === 'tool_results');
</script>

{#if isToolResults}
  <div class="shpd-msg shpd-msg--tools">
    <span class="shpd-msg__tools-label">{t('chat.toolResults')}</span>
    {#each toolResults as r}
      <ToolCallChip name="" state={r.is_error ? 'error' : 'done'} />
    {/each}
  </div>
{:else}
  <div class="shpd-msg" class:shpd-msg--user={isUser} class:shpd-msg--assistant={!isUser}>
    <div class="shpd-msg__bubble">
      {#if isUser}
        <span class="shpd-msg__text">{text}</span>
      {:else}
        {#if text}<Markdown {text} />{/if}
        {#if toolUses.length}
          <div class="shpd-msg__tools">
            {#each toolUses as tu}
              <ToolCallChip name={tu.name} state="done" />
            {/each}
          </div>
        {/if}
      {/if}
    </div>
  </div>
{/if}

<style>
  .shpd-msg {
    display: flex;
    margin-bottom: var(--shpd-space-md);
  }
  .shpd-msg--user {
    justify-content: flex-end;
  }
  .shpd-msg--assistant {
    justify-content: flex-start;
  }
  .shpd-msg__bubble {
    max-width: 80%;
    padding: var(--shpd-space-sm) var(--shpd-space-md);
    border-radius: var(--shpd-radius-lg);
    font-size: var(--shpd-font-size-base);
    color: var(--shpd-color-text);
  }
  .shpd-msg--user .shpd-msg__bubble {
    background-color: var(--shpd-color-primary-soft);
    border-bottom-right-radius: var(--shpd-radius-sm);
  }
  .shpd-msg--assistant .shpd-msg__bubble {
    background-color: var(--shpd-color-bg);
    border: 1px solid var(--shpd-color-border);
    border-bottom-left-radius: var(--shpd-radius-sm);
  }
  .shpd-msg__text {
    white-space: pre-wrap;
    word-break: break-word;
  }
  .shpd-msg__tools {
    display: flex;
    flex-wrap: wrap;
    gap: var(--shpd-space-xs);
    margin-top: var(--shpd-space-sm);
  }
  .shpd-msg--tools {
    align-items: center;
    gap: var(--shpd-space-sm);
    flex-wrap: wrap;
    justify-content: center;
  }
  .shpd-msg__tools-label {
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text-secondary);
  }
</style>
