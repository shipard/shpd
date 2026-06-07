<script>
  /**
   * Active conversation thread: persisted messages, the live streaming reply,
   * tool-call chips, an error banner, and the composer. Auto-scrolls to the
   * bottom as content grows.
   */
  import MessageBubble from './MessageBubble.svelte';
  import ChatInput from './ChatInput.svelte';
  import Markdown from './Markdown.svelte';
  import ToolCallChip from './ToolCallChip.svelte';
  import { chatStore } from '../../stores/chat.svelte.js';
  import { translateError } from '../../i18n/errors.js';
  import { t } from '../../i18n/index.js';

  let scroller = $state(null);

  // Auto-scroll to bottom whenever the thread grows or text streams in.
  $effect(() => {
    // Touch the reactive deps so the effect re-runs on any of them.
    void chatStore.messages.length;
    void chatStore.streamingText;
    void chatStore.streamingTools.length;
    if (scroller) scroller.scrollTop = scroller.scrollHeight;
  });

  const isEmpty = $derived(
    chatStore.messages.length === 0 && !chatStore.streaming && !chatStore.loadingThread,
  );
</script>

<div class="shpd-thread">
  <div class="shpd-thread__messages" bind:this={scroller}>
    {#if chatStore.loadingThread}
      <p class="shpd-thread__hint">{t('chat.loading')}</p>
    {:else if isEmpty}
      <p class="shpd-thread__hint">{t('chat.empty')}</p>
    {/if}

    {#each chatStore.messages as message (message.id)}
      <MessageBubble {message} />
    {/each}

    {#if chatStore.streaming}
      <div class="shpd-msg shpd-msg--assistant">
        <div class="shpd-msg__bubble">
          {#if chatStore.streamingText}
            <Markdown text={chatStore.streamingText} />
          {:else}
            <span class="shpd-thread__typing">{t('chat.thinking')}</span>
          {/if}
          {#if chatStore.streamingTools.length}
            <div class="shpd-thread__tools">
              {#each chatStore.streamingTools as call}
                <ToolCallChip name={call.name} state="running" />
              {/each}
            </div>
          {/if}
        </div>
      </div>
    {/if}

    {#if chatStore.error}
      <div class="shpd-thread__error">{translateError(chatStore.error)}</div>
    {/if}
  </div>

  <ChatInput disabled={chatStore.streaming} onsend={(text) => chatStore.send(text)} />
</div>

<style>
  .shpd-thread {
    display: flex;
    flex-direction: column;
    height: 100%;
    overflow: hidden;
    background-color: var(--shpd-color-bg-secondary);
  }
  .shpd-thread__messages {
    flex: 1;
    overflow-y: auto;
    padding: var(--shpd-space-lg);
  }
  .shpd-thread__hint {
    text-align: center;
    color: var(--shpd-color-text-secondary);
    margin-top: var(--shpd-space-xl);
  }
  .shpd-thread__typing {
    color: var(--shpd-color-text-secondary);
    font-style: italic;
  }
  .shpd-thread__tools {
    display: flex;
    flex-wrap: wrap;
    gap: var(--shpd-space-xs);
    margin-top: var(--shpd-space-sm);
  }
  .shpd-thread__error {
    margin: var(--shpd-space-sm) 0;
    padding: var(--shpd-space-sm) var(--shpd-space-md);
    border-radius: var(--shpd-radius-md);
    background-color: var(--shpd-color-danger);
    color: #fff;
    font-size: var(--shpd-font-size-sm);
  }
</style>
