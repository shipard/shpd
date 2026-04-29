<script lang="ts" module>
  // Modul-level stack otevřených modalů. Esc reaguje pouze na ten poslední (nejvýše
  // vykreslený) modal — jinak by se např. v subdialogu Kontaktu nad Osobou Esc trefil
  // do obou window listenerů a zavřel by oba.
  const modalStack: symbol[] = [];

  function isTopOfStack(id: symbol): boolean {
    return modalStack[modalStack.length - 1] === id;
  }

  function pushModal(id: symbol): void {
    modalStack.push(id);
  }

  function removeModal(id: symbol): void {
    const i = modalStack.indexOf(id);
    if (i !== -1) modalStack.splice(i, 1);
  }
</script>

<script lang="ts">
  import { type Snippet } from 'svelte';

  interface Props {
    title: string;
    open: boolean;
    onClose: () => void;
    children: Snippet;
    /** Optional content rendered in the header between title and close button.
     *  Useful for badges or other status indicators. */
    headerExtra?: Snippet;
    /** Modal width, e.g. '720px' or '1200px'. Default '640px'. */
    width?: string;
    /** Modal height. If set, the card uses this fixed height (capped at 90vh).
     *  If not set, the card sizes to its content (max 90vh). */
    height?: string;
  }

  let {
    title,
    open,
    onClose,
    children,
    headerExtra,
    width = '640px',
    height,
  }: Props = $props();

  // Unikátní ID této instance modalu — slouží k identifikaci ve stacku.
  const modalId = Symbol('modal');

  // Close on Escape key — jen pokud je tento modal na vrcholu stacku.
  function handleKeydown(e: KeyboardEvent) {
    if (e.key === 'Escape' && isTopOfStack(modalId)) {
      onClose();
    }
  }

  // Close when clicking the overlay but not the card
  function handleOverlayClick(e: MouseEvent) {
    if (e.target === e.currentTarget) onClose();
  }

  // Registrace ve stacku + body scroll lock při otevření; cleanup při zavření.
  $effect(() => {
    if (open) {
      pushModal(modalId);
      document.body.style.overflow = 'hidden';
      return () => {
        removeModal(modalId);
        // Body scroll obnovíme jen pokud už není otevřený žádný další modal.
        if (modalStack.length === 0) {
          document.body.style.overflow = '';
        }
      };
    }
  });

  const cardStyle = $derived(
    height
      ? `width: ${width}; height: min(${height}, 90vh);`
      : `width: ${width};`
  );
</script>

<svelte:window onkeydown={open ? handleKeydown : undefined} />

{#if open}
  <!-- svelte-ignore a11y_click_events_have_key_events a11y_no_static_element_interactions -->
  <div class="shpd-modal" onclick={handleOverlayClick} role="dialog" aria-modal="true" aria-label={title} tabindex="-1">
    <div class="shpd-modal__card" style={cardStyle}>
      <div class="shpd-modal__header">
        <span class="shpd-modal__title">{title}</span>
        {#if headerExtra}
          <span class="shpd-modal__header-extra">
            {@render headerExtra()}
          </span>
        {/if}
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
    background: var(--shpd-color-overlay);
    animation: shpd-modal-fade-in 0.15s ease;
  }

  @keyframes shpd-modal-fade-in {
    from { opacity: 0; }
    to   { opacity: 1; }
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
    /* Clip dětských elementů podle zaoblených rohů — jinak vlastní pozadí
       FormEditor / FormStateBar přetéká přes spodní zaoblení. */
    overflow: hidden;
    /* min-height needed for flex children to overflow correctly */
    min-height: 0;
  }

  .shpd-modal__header {
    display: flex;
    align-items: center;
    gap: var(--shpd-space-md);
    padding: var(--shpd-space-md) var(--shpd-space-lg);
    border-bottom: 1px solid var(--shpd-color-border);
    flex-shrink: 0;
  }

  .shpd-modal__title {
    flex: 1;
    font-size: var(--shpd-font-size-lg);
    font-weight: 600;
    color: var(--shpd-color-text);
    /* truncate long titles instead of pushing close button off-screen */
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .shpd-modal__header-extra {
    display: inline-flex;
    align-items: center;
    flex-shrink: 0;
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
    flex-shrink: 0;
  }

  .shpd-modal__close:hover {
    background-color: var(--shpd-color-bg-secondary);
    color: var(--shpd-color-text);
  }

  .shpd-modal__body {
    flex: 1;
    min-height: 0;
    overflow-y: auto;
    display: flex;
    flex-direction: column;
  }
</style>
