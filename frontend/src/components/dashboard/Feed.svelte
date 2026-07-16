<script>
  /**
   * Grid karet feedu (auto-fill sloupce, na běžné šířce 2). Řazení už přišlo
   * ze serveru (sortAndCap), Feed jen renderuje — pořadí = DOM pořadí
   * (row-major zleva doprava). Akce bublou nahoru přes onCardAction(card,
   * action); rodič (Dashboard) drží preview modal / reject prompt / toast.
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
  /* Karty v řádku mají stejnou výšku (stretch) — rozbalený detail vedle
     sbalené karty nechá u sousedky volné místo dole (záměr, žádný masonry,
     nerozbíjí prioritní pořadí). */
  .shpd-feed {
    display: grid;
    /* min(360px, 100%) — na displeji užším než 360px nesmí karta přetéct. */
    grid-template-columns: repeat(auto-fill, minmax(min(360px, 100%), 1fr));
    gap: var(--shpd-space-md);
    align-items: stretch;
  }

  .shpd-feed__empty {
    padding: var(--shpd-space-xl);
    text-align: center;
    color: var(--shpd-color-text-secondary);
  }
</style>
