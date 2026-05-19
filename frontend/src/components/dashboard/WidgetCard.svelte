<script>
  import { t } from '../../i18n/index.js';
  import { resolveIcon } from '../../icons.js';
  import Icon from '../ui/Icon.svelte';
  import WidgetRow from './WidgetRow.svelte';

  let { widget, onItemAction, onOpenAllAction } = $props();

  function handleRowClick(item) {
    onItemAction(item.action);
  }

  function handleOpenAll() {
    onOpenAllAction(widget.openAllAction);
  }

  // Empty-state text klíč podle typu widgetu — alerts/mail/tasks.
  const emptyKey = $derived(`dashboard.widget.${widget.type}.empty`);
</script>

<section class="shpd-widget-card">
  <header class="shpd-widget-card__header">
    <span class="shpd-widget-card__icon">
      <Icon icon={resolveIcon(widget.icon)} size="md" />
    </span>
    <h2 class="shpd-widget-card__title">{widget.title}</h2>
    <span class="shpd-widget-card__count">{widget.count}</span>
  </header>

  <div class="shpd-widget-card__body">
    {#if widget.items.length === 0}
      <div class="shpd-widget-card__empty">{t(emptyKey)}</div>
    {:else}
      <ul class="shpd-widget-card__list">
        {#each widget.items as item (item.id)}
          <WidgetRow {item} onclick={() => handleRowClick(item)} />
        {/each}
      </ul>
    {/if}
  </div>

  {#if widget.count > 0 && widget.openAllAction}
    <footer class="shpd-widget-card__footer">
      <button class="shpd-widget-card__open-all" onclick={handleOpenAll} type="button">
        {t('dashboard.openAll')} →
      </button>
    </footer>
  {/if}
</section>

<style>
  .shpd-widget-card {
    background: var(--shpd-color-bg);
    border: 1px solid var(--shpd-color-border);
    border-radius: var(--shpd-radius-md);
    display: flex;
    flex-direction: column;
    min-height: 200px;
    overflow: hidden;
  }

  .shpd-widget-card__header {
    display: flex;
    align-items: center;
    gap: var(--shpd-space-sm);
    padding: var(--shpd-space-sm) var(--shpd-space-md);
    border-bottom: 1px solid var(--shpd-color-border);
  }

  .shpd-widget-card__icon {
    color: var(--shpd-color-text-secondary);
    display: inline-flex;
  }

  .shpd-widget-card__title {
    flex: 1;
    margin: 0;
    font-size: 1rem;
    font-weight: 600;
    color: var(--shpd-color-text);
  }

  .shpd-widget-card__count {
    background: var(--shpd-color-primary-soft);
    color: var(--shpd-color-primary);
    padding: 2px 10px;
    border-radius: 999px;
    font-size: var(--shpd-font-size-sm);
    font-weight: 600;
    min-width: 1.75em;
    text-align: center;
  }

  .shpd-widget-card__body {
    flex: 1;
    overflow: hidden;
  }

  .shpd-widget-card__list {
    list-style: none;
    margin: 0;
    padding: 0;
  }

  .shpd-widget-card__empty {
    padding: var(--shpd-space-lg);
    text-align: center;
    color: var(--shpd-color-text-secondary);
    font-style: italic;
    font-size: var(--shpd-font-size-sm);
  }

  .shpd-widget-card__footer {
    padding: var(--shpd-space-sm) var(--shpd-space-md);
    border-top: 1px solid var(--shpd-color-border);
  }

  .shpd-widget-card__open-all {
    background: none;
    border: none;
    color: var(--shpd-color-primary);
    cursor: pointer;
    padding: 0;
    font: inherit;
    font-size: var(--shpd-font-size-sm);
  }

  .shpd-widget-card__open-all:hover {
    text-decoration: underline;
  }
</style>
