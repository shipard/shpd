<script lang="ts">
  import Icon from './Icon.svelte';

  interface Props {
    label?: string;
    icon?: object;
    iconOnly?: boolean;
    variant?: 'primary' | 'secondary' | 'danger' | 'success' | 'ghost';
    size?: 'sm' | 'md';
    disabled?: boolean;
    loading?: boolean;
    type?: 'button' | 'submit' | 'reset';
    onclick?: () => void;
  }

  let {
    label,
    icon = undefined,
    iconOnly = false,
    variant = 'primary',
    size = 'md',
    disabled = false,
    loading = false,
    type = 'button',
    onclick,
  }: Props = $props();

  const isDisabled = $derived(disabled || loading);
</script>

<button
  class="shpd-btn shpd-btn--{variant} shpd-btn--{size}"
  class:shpd-btn--loading={loading}
  class:shpd-btn--icon-only={iconOnly}
  {type}
  disabled={isDisabled}
  title={iconOnly ? label : undefined}
  {onclick}
>
  {#if loading}
    <span class="shpd-btn__spinner" aria-hidden="true"></span>
  {:else if icon}
    <Icon {icon} size={size === 'sm' ? 'sm' : 'md'} />
  {/if}
  {#if label && !iconOnly}
    <span class="shpd-btn__label">{label}</span>
  {/if}
</button>

<style>
  .shpd-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: var(--shpd-space-sm);
    padding: var(--shpd-space-sm) var(--shpd-space-lg);
    border-radius: var(--shpd-radius-md);
    font-size: var(--shpd-font-size-base);
    font-family: var(--shpd-font-family);
    font-weight: 500;
    cursor: pointer;
    border: 1px solid transparent;
    transition: background-color 0.15s ease, border-color 0.15s ease, opacity 0.15s ease;
    white-space: nowrap;
    line-height: 1;
  }

  .shpd-btn--sm {
    padding: var(--shpd-space-xs) var(--shpd-space-md);
    font-size: var(--shpd-font-size-sm);
  }

  .shpd-btn--icon-only {
    padding: var(--shpd-space-sm);
    aspect-ratio: 1;
  }

  .shpd-btn--icon-only.shpd-btn--sm {
    padding: var(--shpd-space-xs);
  }

  .shpd-btn:disabled {
    opacity: 0.6;
    cursor: not-allowed;
  }

  /* Primary */
  .shpd-btn--primary {
    background-color: var(--shpd-color-primary);
    color: #ffffff;
    border-color: var(--shpd-color-primary);
  }

  .shpd-btn--primary:hover:not(:disabled) {
    background-color: var(--shpd-color-primary-hover);
    border-color: var(--shpd-color-primary-hover);
  }

  /* Secondary */
  .shpd-btn--secondary {
    background-color: var(--shpd-color-bg);
    color: var(--shpd-color-text);
    border-color: var(--shpd-color-border);
  }

  .shpd-btn--secondary:hover:not(:disabled) {
    background-color: var(--shpd-color-bg-secondary);
  }

  /* Danger */
  .shpd-btn--danger {
    background-color: var(--shpd-color-danger);
    color: #ffffff;
    border-color: var(--shpd-color-danger);
  }

  .shpd-btn--danger:hover:not(:disabled) {
    background-color: var(--shpd-color-danger-hover);
    border-color: var(--shpd-color-danger-hover);
  }

  /* Success — použito pro "Přidat / Použít / Potvrdit" akce, kde se chce
   * od uživatele odlišit pozitivní očekávaný výsledek od neutrálního.
   * Záměrně málo častý — většina akcí jsou primary nebo secondary. */
  .shpd-btn--success {
    background-color: var(--shpd-color-success);
    color: #ffffff;
    border-color: var(--shpd-color-success);
  }

  .shpd-btn--success:hover:not(:disabled) {
    background-color: var(--shpd-color-success-hover);
    border-color: var(--shpd-color-success-hover);
  }

  /* Ghost — bez pozadí, jen ikona/text */
  .shpd-btn--ghost {
    background-color: transparent;
    color: var(--shpd-color-text-secondary);
    border-color: transparent;
    padding: var(--shpd-space-xs);
  }

  .shpd-btn--ghost:hover:not(:disabled) {
    background-color: var(--shpd-color-bg-secondary);
    color: var(--shpd-color-text);
  }

  /* Spinner */
  .shpd-btn__spinner {
    display: inline-block;
    width: 14px;
    height: 14px;
    border: 2px solid currentColor;
    border-top-color: transparent;
    border-radius: 50%;
    animation: shpd-spin 0.6s linear infinite;
    flex-shrink: 0;
  }

  @keyframes shpd-spin {
    to { transform: rotate(360deg); }
  }
</style>
