<script>
  /**
   * Seznam karet feedu. Řazení už přišlo ze serveru (sortAndCap), Feed jen
   * renderuje. Akce bublou nahoru přes onCardAction(card, action); rodič
   * (Dashboard) drží preview modal / reject prompt / toast.
   * emptyText: per-záložkový empty stav filtru; null → globální „Vše zpracováno".
   */
  import { t } from '../../i18n/index.js';
  import FeedCard from './FeedCard.svelte';

  let { cards = [], onCardAction = () => {}, busyCardId = null, emptyText = null } = $props();
</script>

{#if cards.length === 0}
  <div class="shpd-feed__empty">{emptyText ?? t('dashboard.feed.empty')}</div>
{:else}
  <div class="shpd-feed">
    {#each cards as card (card.id)}
      <FeedCard
        {card}
        busy={busyCardId === card.id}
        onAction={(action) => onCardAction(card, action)}
      />
    {/each}
  </div>
{/if}

<style>
  .shpd-feed {
    display: flex;
    flex-direction: column;
    gap: var(--shpd-space-sm);
  }

  .shpd-feed__empty {
    padding: var(--shpd-space-xl);
    text-align: center;
    color: var(--shpd-color-text-secondary);
  }
</style>
