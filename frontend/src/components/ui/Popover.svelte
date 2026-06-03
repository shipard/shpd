<script>
  // Universal floating panel anchored to a DOM element. Used by the
  // Exchange resolve-decision flow (Phase 3b) to render decision actions
  // (Create / Pick existing / Skip) next to a clicked status badge.
  //
  // Props:
  //   open       boolean
  //   anchor     HTMLElement | null — element to position relative to
  //   placement  'bottom' | 'top' | 'right' | 'left' (default 'bottom')
  //   onClose    () => void  — fires on Escape or outside click
  //   children   Svelte 5 children snippet
  //
  // Click-outside is portal-aware: if the outside target is inside a
  // `.shpd-modal` (e.g. a nested FormDialog mounted into body), the click
  // is treated as "inside the popover's logical hierarchy" and ignored.
  // Without this guard, clicking inside the nested dialog would
  // simultaneously close the popover that opened it.

  let {
    open = false,
    anchor = null,
    placement = 'bottom',
    width = null,
    onClose = () => {},
    children,
  } = $props();

  let panelEl = $state(null);
  let position = $state({ top: 0, left: 0 });

  // Reposition when open / anchor / placement changes.
  $effect(() => {
    if (!open || !anchor || !panelEl) return;
    const r = anchor.getBoundingClientRect();
    const pr = panelEl.getBoundingClientRect();
    let top;
    let left;
    switch (placement) {
      case 'top':
        top = r.top - pr.height - 8;
        left = r.left;
        break;
      case 'right':
        top = r.top;
        left = r.right + 8;
        break;
      case 'left':
        top = r.top;
        left = r.left - pr.width - 8;
        break;
      case 'bottom':
      default:
        top = r.bottom + 8;
        left = r.left;
        break;
    }
    // Basic viewport flip: bottom-overflow → flip to top.
    if (placement === 'bottom' && top + pr.height > window.innerHeight) {
      top = r.top - pr.height - 8;
    }
    // Clamp horizontally — keep at least 8px margin from each viewport edge.
    left = Math.max(8, Math.min(left, window.innerWidth - pr.width - 8));
    // Clamp top too — if even the flipped position would overflow, pin to 8px.
    top = Math.max(8, top);
    position = { top, left };
  });

  // Document-level click + Escape, only while open.
  function handleDocClick(event) {
    if (!open || !panelEl) return;
    // Inside the panel → ignore.
    if (panelEl.contains(event.target)) return;
    // On the anchor itself (e.g. the kebab button) → ignore here and let the
    // anchor's own click handler decide. Otherwise the capture-phase close
    // would race the button's toggle and the popover couldn't be closed by
    // re-tapping the trigger.
    if (anchor?.contains?.(event.target)) return;
    // Inside a *nested* modal (a FormDialog the popover itself opened, which
    // sits as a separate `.shpd-modal` under body) → ignore, so opening that
    // dialog doesn't close this popover. But a popover that *lives inside* a
    // modal (e.g. the mobile FormStateBar footer in a fullscreen modal) must
    // still close on clicks elsewhere in that same modal — so only ignore a
    // modal that is NOT the popover's own modal.
    const targetModal = event.target?.closest?.('.shpd-modal');
    if (targetModal && targetModal !== anchor?.closest?.('.shpd-modal')) return;
    onClose();
  }

  function handleKey(event) {
    if (event.key === 'Escape' && open) {
      onClose();
    }
  }

  $effect(() => {
    if (!open) return;
    document.addEventListener('click', handleDocClick, true);
    document.addEventListener('keydown', handleKey);
    return () => {
      document.removeEventListener('click', handleDocClick, true);
      document.removeEventListener('keydown', handleKey);
    };
  });
</script>

{#if open}
  <div
    class="shpd-popover"
    bind:this={panelEl}
    style:top="{position.top}px"
    style:left="{position.left}px"
    style:width={width}
    style:max-width={width}
    role="dialog"
  >
    {@render children?.()}
  </div>
{/if}

<style>
  .shpd-popover {
    position: fixed;
    z-index: 1000;
    min-width: 240px;
    max-width: 360px;
    /* --shpd-color-surface v projektu zatím neexistuje — fallback na bg,
       jinak popover prosvítá podklad. */
    background: var(--shpd-color-surface, var(--shpd-color-bg));
    border: 1px solid var(--shpd-color-border);
    border-radius: 6px;
    box-shadow: var(--shpd-shadow-lg, 0 4px 12px rgba(0, 0, 0, 0.15));
    padding: var(--shpd-space-sm);
  }
</style>
