<script>
  /**
   * Plovoucí chat launcher na dashboardu — jednořádkový input ve stylu
   * „command bar" (pill, stín), sticky u spodní hrany. Odeslání založí
   * novou konverzaci, otevře boční ChatPanel (AppShell) a pošle zprávu;
   * streaming doteče přes sdílený chatStore. Když je panel otevřený,
   * launcher se nerenderuje (composer je v panelu).
   */
  import Button from '../ui/Button.svelte';
  import { iconChat } from '../../icons.js';
  import { chatStore } from '../../stores/chat.svelte.js';
  import { chatPanelStore } from '../../stores/chatPanel.svelte.js';
  import { t } from '../../i18n/index.js';

  let text = $state('');

  function submit() {
    const value = text.trim();
    if (value === '') return;
    text = '';
    chatStore.newConversation();
    chatPanelStore.open();
    // Bez await — panel se otevře okamžitě, optimistická zpráva + streaming
    // dotečou přes store.
    chatStore.send(value);
  }

  function handleKeydown(e) {
    if (e.key === 'Enter') {
      e.preventDefault();
      submit();
    }
  }
</script>

{#if !chatPanelStore.isOpen}
  <div class="shpd-chat-launcher">
    <input
      type="text"
      class="shpd-chat-launcher__field"
      bind:value={text}
      placeholder={t('dashboard.chatLauncher.placeholder')}
      onkeydown={handleKeydown}
    />
    <Button
      icon={iconChat}
      iconOnly
      label={t('dashboard.chatLauncher.send')}
      variant="primary"
      size="sm"
      disabled={text.trim() === ''}
      onclick={submit}
    />
  </div>
{/if}

<style>
  .shpd-chat-launcher {
    position: sticky;
    bottom: var(--shpd-space-lg);
    margin-top: auto; /* krátký obsah → launcher u spodní hrany */
    align-self: center;
    width: min(560px, 100%);
    z-index: 10; /* nad kartami feedu */
    display: flex;
    align-items: center;
    gap: var(--shpd-space-sm);
    padding: var(--shpd-space-xs) var(--shpd-space-xs) var(--shpd-space-xs) var(--shpd-space-md);
    border: 1px solid var(--shpd-color-border);
    border-radius: 999px;
    background: var(--shpd-color-bg);
    box-shadow: var(--shpd-shadow-lg, 0 4px 16px rgba(0, 0, 0, 0.2));
    box-sizing: border-box;
  }

  .shpd-chat-launcher__field {
    flex: 1;
    min-width: 0;
    border: none;
    background: none;
    padding: var(--shpd-space-xs) 0;
    font-size: var(--shpd-font-size-base);
    font-family: var(--shpd-font-family);
    color: var(--shpd-color-text);
  }

  .shpd-chat-launcher__field:focus {
    outline: none;
  }

  .shpd-chat-launcher:focus-within {
    border-color: var(--shpd-color-border-focus);
    box-shadow:
      0 0 0 2px var(--shpd-color-focus-ring),
      var(--shpd-shadow-lg, 0 4px 16px rgba(0, 0, 0, 0.2));
  }
</style>
