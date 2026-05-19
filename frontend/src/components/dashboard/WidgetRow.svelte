<script>
  import { resolveIcon } from '../../icons.js';
  import Icon from '../ui/Icon.svelte';

  let { item, onclick } = $props();

  // Globální .docState_* třída nastaví CSS proměnnou --shpd-row-bar
  // (viz styles/base.css). WidgetRow ji konzumuje níže.
  const stateClass = $derived(item.stateStyle ? `docState_${item.stateStyle}` : '');
</script>

<li class="shpd-widget-row__item">
  <button
    class="shpd-widget-row {stateClass}"
    onclick={onclick}
    type="button"
  >
    <span class="shpd-widget-row__bar"></span>
    {#if item.icon}
      <span class="shpd-widget-row__icon">
        <Icon icon={resolveIcon(item.icon)} size="sm" />
      </span>
    {/if}
    <div class="shpd-widget-row__body">
      <div class="shpd-widget-row__title">{item.title}</div>
      {#if item.subtitle}
        <div class="shpd-widget-row__subtitle">{item.subtitle}</div>
      {/if}
    </div>
  </button>
</li>

<style>
  .shpd-widget-row__item {
    list-style: none;
  }

  .shpd-widget-row {
    /* Default proužek průhledný — globální .docState_* třídy ho přepíší. */
    --shpd-row-bar: transparent;

    position: relative;
    display: flex;
    align-items: center;
    gap: var(--shpd-space-sm);
    width: 100%;
    padding: var(--shpd-space-sm) var(--shpd-space-md) var(--shpd-space-sm) calc(var(--shpd-space-md) + 4px);
    cursor: pointer;
    border: none;
    border-bottom: 1px solid var(--shpd-color-border);
    background: var(--shpd-color-bg);
    color: var(--shpd-color-text);
    font: inherit;
    font-size: var(--shpd-font-size-sm);
    text-align: left;
  }

  .shpd-widget-row__item:last-child .shpd-widget-row {
    border-bottom: none;
  }

  .shpd-widget-row:hover { background: var(--shpd-color-bg-hover); }

  .shpd-widget-row:focus-visible {
    outline: 2px solid var(--shpd-color-focus-ring);
    outline-offset: -2px;
  }

  /* Levý stavový proužek — 4px (kompaktnější než 6px ve vieweru). */
  .shpd-widget-row__bar {
    position: absolute;
    left: 0;
    top: 0;
    bottom: 0;
    width: 4px;
    background: var(--shpd-row-bar);
  }

  .shpd-widget-row__icon {
    color: var(--shpd-color-text-secondary);
    flex-shrink: 0;
  }

  .shpd-widget-row__body {
    flex: 1;
    min-width: 0;
  }

  .shpd-widget-row__title {
    color: var(--shpd-color-text);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .shpd-widget-row__subtitle {
    font-size: 0.8125rem;
    color: var(--shpd-color-text-secondary);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    margin-top: 2px;
  }
</style>
