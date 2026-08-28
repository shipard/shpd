<script>
  /**
   * Feed rozdělený do sekcí podle pásem (Issue #32/2, D1/D2) — čistě
   * prezentační vrstva: serverové řazení (sortAndCap) se nemění, sekce jsou
   * $derived seskupení už seřazených karet (uvnitř sekce pořadí = pořadí
   * serveru). Asymetrická váha: urgent full-width karty, review dnešní grid
   * (auto-fill 2 sloupce), ready sbalený pruh / kompaktní řádky
   * (FeedReadySection), info tlumené kompaktní řádky. Prázdná sekce se
   * nerenderuje (ani hlavička). Akce bublou nahoru přes onCardAction(card,
   * action); rodič (Dashboard) drží preview modal / reject prompt / toast.
   * emptyText: per-záložkový empty stav filtru; null → globální „Vše
   * zpracováno". onWalkthrough = sériový průchod omezený na ready pásmo (D9).
   */
  import { t } from '../../i18n/index.js';
  import FeedCard from './FeedCard.svelte';
  import FeedReadySection from './FeedReadySection.svelte';
  import FeedRowCompact from './FeedRowCompact.svelte';

  let {
    cards = [],
    readySummary = null,
    onCardAction = () => {},
    onWalkthrough = () => {},
    busyCardId = null,
    emptyText = null,
  } = $props();

  // Pořadí sekcí = prioritní žebříček KIND_ORDER serveru.
  const KINDS = ['urgent', 'review', 'ready', 'info'];

  const sections = $derived.by(() => {
    const by = { urgent: [], review: [], ready: [], info: [] };
    for (const card of cards) {
      // Neznámé pásmo (defenziva vůči budoucím kind) → sekce Ostatní.
      (by[card.kind] ?? by.info).push(card);
    }
    return by;
  });

  // Ready pásmo se dělí per kategorie (D11): pruh přijatých faktur
  // a samostatný pruh Spisovny — zrcadlí skupiny readySummary
  // (bez kategorie → invoices, stejný defenzivní default jako server).
  const readyGroups = $derived.by(() => {
    const g = { invoices: [], registry: [] };
    for (const card of sections.ready) {
      (card.category === 'registry' ? g.registry : g.invoices).push(card);
    }
    return g;
  });
</script>

{#if cards.length === 0}
  <div class="shpd-feed__empty">{emptyText ?? t('dashboard.feed.empty')}</div>
{:else}
  <div class="shpd-feed" data-testid="feed">
    {#each KINDS as kind (kind)}
      {#if sections[kind].length > 0}
        <section class="shpd-feed__section">
          <h2 class="shpd-feed__section-title">
            <span class="shpd-feed__section-dot shpd-feed__section-dot--{kind}" aria-hidden="true"></span>
            {t(`dashboard.feed.section.${kind}`)}
            <span class="shpd-feed__section-count">({sections[kind].length})</span>
          </h2>
          {#if kind === 'urgent'}
            <div class="shpd-feed__stack">
              {#each sections.urgent as card (card.id)}
                <FeedCard
                  {card}
                  busy={busyCardId === card.id}
                  onAction={(action) => onCardAction(card, action)}
                />
              {/each}
            </div>
          {:else if kind === 'review'}
            <div class="shpd-feed__grid">
              {#each sections.review as card (card.id)}
                <FeedCard
                  {card}
                  busy={busyCardId === card.id}
                  onAction={(action) => onCardAction(card, action)}
                />
              {/each}
            </div>
          {:else if kind === 'ready'}
            {#if readyGroups.invoices.length > 0}
              <FeedReadySection
                cards={readyGroups.invoices}
                summary={readySummary?.invoices ?? null}
                variant="invoices"
                {busyCardId}
                {onCardAction}
                {onWalkthrough}
              />
            {/if}
            {#if readyGroups.registry.length > 0}
              <FeedReadySection
                cards={readyGroups.registry}
                summary={readySummary?.registry ?? null}
                variant="registry"
                {busyCardId}
                {onCardAction}
              />
            {/if}
          {:else}
            <div class="shpd-feed__rows">
              {#each sections.info as card (card.id)}
                <FeedRowCompact
                  {card}
                  mode="info"
                  busy={busyCardId === card.id}
                  onAction={(action) => onCardAction(card, action)}
                />
              {/each}
            </div>
          {/if}
        </section>
      {/if}
    {/each}
  </div>
{/if}

<style>
  .shpd-feed {
    display: flex;
    flex-direction: column;
    gap: var(--shpd-space-lg);
  }

  .shpd-feed__section {
    display: flex;
    flex-direction: column;
    gap: var(--shpd-space-sm);
  }

  .shpd-feed__section-title {
    display: flex;
    align-items: center;
    gap: var(--shpd-space-xs);
    margin: 0;
    font-size: var(--shpd-font-size-sm);
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.03em;
    color: var(--shpd-color-text-secondary);
  }

  .shpd-feed__section-count {
    font-weight: 500;
  }

  /* Barevná tečka pásma — zrcadlí barvy stavových proužků karet. */
  .shpd-feed__section-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    flex-shrink: 0;
  }

  .shpd-feed__section-dot--urgent { background: var(--shpd-color-danger); }
  .shpd-feed__section-dot--review { background: var(--shpd-color-warning); }
  .shpd-feed__section-dot--ready  { background: var(--shpd-color-success); }
  .shpd-feed__section-dot--info   { background: var(--shpd-color-text-secondary); }

  /* Urgent: full-width karty pod sebou (D2). */
  .shpd-feed__stack {
    display: flex;
    flex-direction: column;
    gap: var(--shpd-space-md);
  }

  /* Review: dnešní grid — karty v řádku mají stejnou výšku (stretch),
     rozbalený detail vedle sbalené karty nechá u sousedky volné místo dole
     (záměr, žádný masonry, nerozbíjí prioritní pořadí). */
  .shpd-feed__grid {
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
