<script lang="ts">
  import { type Snippet } from 'svelte';

  interface Props {
    title: string;
    open: boolean;
    onClose: () => void;
    children: Snippet;
    width?: string;
  }

  let {
    title,
    open,
    onClose,
    children,
    width = '640px',
  }: Props = $props();

  // Close on Escape key
  function handleKeydown(e: KeyboardEvent) {
    if (e.key === 'Escape') onClose();
  }

  // Close when clicking the overlay but not the card
  function handleOverlayClick(e: MouseEvent) {
    if (e.target === e.currentTarget) onClose();
  }

  // Prevent body scroll while open
  $effect(() => {
    if (open) {
      document.body.style.overflow = 'hidden';
    } else {
      document.body.style.overflow = '';
    }
    return () => {
      document.body.style.overflow = '';
    };
  });
</script>

<svelte:window onkeydown={open ? handleKeydown : undefined} />

{#if open}
  <!-- svelte-ignore a11y_click_events_have_key_events a11y_no_static_element_interactions -->
  <div class="shpd-modal" onclick={handleOverlayClick} role="dialog" aria-modal="true" aria-label={title} tabindex="-1">
    <div class="shpd-modal__overlay"></div>
    <div class="shpd-modal__card" style="width: {width}">
      <div class="shpd-modal__header">
        <span class="shpd-modal__title">{title}</span>
        <button class="shpd-modal__close" onclick={onClose} aria-label="Zavřít">×</button>
      </div>
      <div class="shpd-modal__body">
        {@render children()}
      </div>
    </div>
  </div>
{/if}

<style>
  .shpd-modal {
    position: fixed;
    inset: 0;
    z-index: 1000;
    display: flex;
    align-items: center;
    justify-content: center;
    animation: shpd-modal-fade-in 0.15s ease;
  }

  @keyframes shpd-modal-fade-in {
    from { opacity: 0; }
    to   { opacity: 1; }
  }

  .shpd-modal__overlay {
    position: absolute;
    inset: 0;
    background: rgba(0, 0, 0, 0.5);
  }

  .shpd-modal__card {
    position: relative;
    max-width: calc(100vw - var(--shpd-space-lg) * 2);
    max-height: 90vh;
    display: flex;
    flex-direction: column;
    background: var(--shpd-color-bg);
    border-radius: var(--shpd-radius-lg);
    box-shadow: var(--shpd-shadow-lg);
  }

  .shpd-modal__header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: var(--shpd-space-md) var(--shpd-space-lg);
    border-bottom: 1px solid var(--shpd-color-border);
    flex-shrink: 0;
  }

  .shpd-modal__title {
    font-size: var(--shpd-font-size-lg);
    font-weight: 600;
    color: var(--shpd-color-text);
  }

  .shpd-modal__close {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    padding: 0;
    background: none;
    border: none;
    border-radius: var(--shpd-radius-sm);
    font-size: 1.25rem;
    line-height: 1;
    color: var(--shpd-color-text-secondary);
    cursor: pointer;
    transition: background-color 0.15s ease, color 0.15s ease;
  }

  .shpd-modal__close:hover {
    background-color: var(--shpd-color-bg-secondary);
    color: var(--shpd-color-text);
  }

  .shpd-modal__body {
    overflow-y: auto;
    max-height: calc(90vh - 57px); /* 57px = header height */
  }
</style>
