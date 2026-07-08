<script>
  /**
   * Jedna karta feedu: stavový proužek (kind→stateStyle přes globální
   * .docState_* třídy), ikona, titulek, podtitulek a řada akčních tlačítek.
   * Chování akcí drží rodič (Dashboard) — FeedCard jen emituje onAction(action).
   */
  import { resolveIcon } from '../../icons.js';
  import Icon from '../ui/Icon.svelte';
  import Button from '../ui/Button.svelte';
  import FeedCardAttachment from './FeedCardAttachment.svelte';
  import { t } from '../../i18n/index.js';

  let { card, onAction = () => {}, busy = false } = $props();

  const stateClass = $derived(card.stateStyle ? `docState_${card.stateStyle}` : '');

  // Alert akce nesou vlastní lokalizovaný label; mail akce se lokalizují
  // klientsky podle action.id (i18n klíče dashboard.card.action.*).
  function actionLabel(action) {
    return action.label ?? t(`dashboard.card.action.${action.id}`);
  }

  const hiddenAttachments = $derived(
    (card.attachmentsTotal ?? 0) - (card.attachments?.length ?? 0),
  );

  // „+N" = syntetická open_viewer akce — otevře zprávu v došlé poště
  // (stejný handler v Dashboard.svelte jako alert akce).
  function openMessage() {
    onAction({
      id: 'openMail',
      kind: 'open_viewer',
      target: { viewerId: 'core.mail.incoming', recordId: card.context?.messageNdx },
    });
  }
</script>

<div class="shpd-feed-card {stateClass}">
  <span class="shpd-feed-card__bar"></span>
  {#if card.icon}
    <span class="shpd-feed-card__icon">
      <Icon icon={resolveIcon(card.icon)} size="md" />
    </span>
  {/if}
  <div class="shpd-feed-card__body">
    <div class="shpd-feed-card__title">{card.title}</div>
    {#if card.subtitle}
      <div class="shpd-feed-card__subtitle">{card.subtitle}</div>
    {/if}
    {#if card.attachments?.length}
      <div class="shpd-feed-card__attachments">
        {#each card.attachments as att (att.id)}
          <FeedCardAttachment {att} />
        {/each}
        {#if hiddenAttachments > 0}
          <button
            type="button"
            class="shpd-feed-att__more"
            title={t('dashboard.card.attachments.more', { n: hiddenAttachments })}
            aria-label={t('dashboard.card.attachments.more', { n: hiddenAttachments })}
            disabled={busy}
            onclick={openMessage}
          >+{hiddenAttachments}</button>
        {/if}
      </div>
    {/if}
    {#if card.actions?.length}
      <div class="shpd-feed-card__actions">
        {#each card.actions as action (action.id)}
          <Button
            label={actionLabel(action)}
            variant={action.primary ? 'primary' : 'secondary'}
            size="sm"
            disabled={busy}
            onclick={() => onAction(action)}
          />
        {/each}
      </div>
    {/if}
  </div>
</div>

<style>
  .shpd-feed-card {
    --shpd-row-bar: transparent;

    position: relative;
    display: flex;
    align-items: flex-start;
    gap: var(--shpd-space-sm);
    padding: var(--shpd-space-md) var(--shpd-space-md) var(--shpd-space-md) calc(var(--shpd-space-md) + 4px);
    border: 1px solid var(--shpd-color-border);
    border-radius: var(--shpd-radius-md);
    background: var(--shpd-color-bg);
    color: var(--shpd-color-text);
  }

  /* Levý stavový proužek — 4px, barva z globální .docState_* třídy. */
  .shpd-feed-card__bar {
    position: absolute;
    left: 0;
    top: 0;
    bottom: 0;
    width: 4px;
    border-radius: var(--shpd-radius-md) 0 0 var(--shpd-radius-md);
    background: var(--shpd-row-bar);
  }

  .shpd-feed-card__icon {
    color: var(--shpd-color-text-secondary);
    flex-shrink: 0;
    margin-top: 2px;
  }

  .shpd-feed-card__body {
    flex: 1;
    min-width: 0;
  }

  .shpd-feed-card__title {
    font-weight: 600;
    color: var(--shpd-color-text);
  }

  .shpd-feed-card__subtitle {
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text-secondary);
    margin-top: 2px;
  }

  /* Řada chipů příloh — na mobilu se zalamuje, nic nepřetéká. */
  .shpd-feed-card__attachments {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--shpd-space-sm);
    margin-top: var(--shpd-space-sm);
  }

  /* „+N" — vzhledem ladí s chipem přílohy (FeedCardAttachment). */
  .shpd-feed-att__more {
    padding: var(--shpd-space-xs) var(--shpd-space-sm);
    border: 1px solid var(--shpd-color-border);
    border-radius: var(--shpd-radius-sm);
    background: var(--shpd-color-bg);
    color: var(--shpd-color-text-secondary);
    font-size: var(--shpd-font-size-sm);
    font-family: var(--shpd-font-family);
    line-height: 1;
    cursor: pointer;
    transition: border-color 0.15s ease, box-shadow 0.15s ease;
  }

  .shpd-feed-att__more:hover:not(:disabled) {
    border-color: var(--shpd-color-primary);
    box-shadow: var(--shpd-shadow-md);
  }

  .shpd-feed-att__more:disabled {
    opacity: 0.6;
    cursor: not-allowed;
  }

  .shpd-feed-card__actions {
    display: flex;
    flex-wrap: wrap;
    gap: var(--shpd-space-sm);
    margin-top: var(--shpd-space-sm);
  }
</style>
