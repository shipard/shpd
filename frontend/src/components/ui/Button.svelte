<script lang="ts">
  interface Props {
    label?: string;
    variant?: 'primary' | 'secondary' | 'danger';
    disabled?: boolean;
    loading?: boolean;
    type?: 'button' | 'submit' | 'reset';
    onclick?: () => void;
  }

  let {
    label,
    variant = 'primary',
    disabled = false,
    loading = false,
    type = 'button',
    onclick,
  }: Props = $props();

  const isDisabled = $derived(disabled || loading);
</script>

<button
  class="shpd-btn shpd-btn--{variant}"
  class:shpd-btn--loading={loading}
  {type}
  disabled={isDisabled}
  {onclick}
>
  {#if loading}
    <span class="shpd-btn__spinner" aria-hidden="true"></span>
  {/if}
  {#if label}
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
    background-color: #b91c1c;
    border-color: #b91c1c;
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
