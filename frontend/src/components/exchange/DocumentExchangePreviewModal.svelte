<script>
  // Full-screen modal hosting the PDF + canonical preview split-view.
  //
  // Message-centric UX flow (tasks/mail-message-centric.md):
  //   - User clicks "Detail" / "Zkontrolovat" on the message's proposal
  //   - This modal opens, calls previewMessage(messageNdx)
  //   - Left: PDF / image attachments via PdfViewerPanel — all content
  //     attachments of the message (D10)
  //   - Right: canonical visualization via DocumentExchangePreview
  //   - Footer: Zavřít / Zamítnout / Použít (Použít disabled for ai_failed)
  //
  // Apply / reject delegate to parent callbacks — parent still owns the
  // actual API call (Dashboard / ViewerDetail).
  //
  // Resolve decisions:
  //   - `userActions` state accumulates the resolve-decision choices from
  //     clickable badges in DocumentExchangePreview.
  //   - `canApply` is true only when all non-matched references have a
  //     decision (or are explicitly skipped). Unit / vatCode badges don't
  //     gate apply — the applier has fallback defaults.
  //   - "Použít" passes `userActions` and the doc target ('docs' /
  //     'registry') to onApply — parents branch post-apply UX on it.
  //
  // Mobile (<768px): single column with PDF/Preview tab switcher.

  import Modal from '../ui/Modal.svelte';
  import Button from '../ui/Button.svelte';
  import DocumentExchangePreview from './DocumentExchangePreview.svelte';
  import RegistryExtractedPreview from './RegistryExtractedPreview.svelte';
  import PdfViewerPanel from './PdfViewerPanel.svelte';
  import { previewMessage } from '../../api/exchange.js';
  import { t } from '../../i18n/index.js';

  let {
    open = false,
    messageNdx = null,
    onClose = () => {},
    onApply = () => {},
    onReject = () => {},
  } = $props();

  let loading = $state(false);
  let error = $state(null);
  let data = $state(null);
  let mobileTab = $state('pdf'); // 'pdf' | 'preview'

  // Accumulated decisions from clickable status badges. Flat
  // {path: action} map — see api/exchange.js applyMessage.
  let userActions = $state({});

  $effect(() => {
    if (open && messageNdx !== null && messageNdx !== undefined) {
      void loadPreview(messageNdx);
    } else {
      data = null;
      error = null;
      userActions = {};
    }
  });

  async function loadPreview(ndx) {
    loading = true;
    error = null;
    data = null;
    userActions = {};
    try {
      const result = await previewMessage(ndx);
      if (result?.success) {
        data = result.data;
      } else {
        error = result?.error?.message ?? 'Unknown error';
      }
    } catch (e) {
      error = e instanceof Error ? e.message : String(e);
    } finally {
      loading = false;
    }
  }

  function handleUserActionsChange(next) {
    userActions = next;
  }

  // Walk `_resolve` and verify every non-matched reference has a decision.
  // unit/vatCode badges are excluded — applier falls back to defaults.
  function allDecided(resolve, ua) {
    if (!resolve) return true;
    for (const key of ['supplier', 'customer', 'supplierBank', 'customerBank']) {
      const block = resolve[key];
      if (!block) continue;
      if (block.status === 'matched') continue;
      if (ua[key] !== undefined && ua[key] !== null) continue;
      return false;
    }
    const rows = resolve.rows ?? [];
    for (let i = 0; i < rows.length; i++) {
      const itemBlock = rows[i]?.item;
      if (!itemBlock) continue;
      if (itemBlock.status === 'matched') continue;
      const p = `rows[${i}].item`;
      if (ua[p] !== undefined && ua[p] !== null) continue;
      return false;
    }
    return true;
  }

  // Registry target (Spisovna) — server posílá `target: 'registry'`;
  // preview je kompaktní bez resolve panelu, apply se negatuje jen ai_failed
  // (registry canonical žádné `_resolve` nenese).
  let isRegistry = $derived(data?.target === 'registry');

  let canApply = $derived(
    data !== null
      && !data.aiFailed
      && allDecided(data.canonical?._resolve ?? null, userActions),
  );

  function handleApplyClick() {
    onApply(messageNdx, userActions, data?.target ?? 'docs');
  }
</script>

<Modal title={t('exchange.preview.title')} {open} {onClose} width="full">
  {#if loading}
    <div class="shpd-exchange-modal__loading">
      {t('exchange.preview.loading')}
    </div>
  {:else if error}
    <div class="shpd-exchange-modal__error">{error}</div>
  {:else if data}
    <div class="shpd-exchange-modal__mobile-tabs" role="tablist">
      <button
        class="shpd-exchange-modal__mobile-tab"
        class:shpd-exchange-modal__mobile-tab--active={mobileTab === 'pdf'}
        onclick={() => (mobileTab = 'pdf')}
        role="tab"
        aria-selected={mobileTab === 'pdf'}
      >
        {t('exchange.preview.tabs.pdf')}
      </button>
      <button
        class="shpd-exchange-modal__mobile-tab"
        class:shpd-exchange-modal__mobile-tab--active={mobileTab === 'preview'}
        onclick={() => (mobileTab = 'preview')}
        role="tab"
        aria-selected={mobileTab === 'preview'}
      >
        {t('exchange.preview.tabs.preview')}
      </button>
    </div>

    <div
      class="shpd-exchange-modal__split"
      data-mobile-tab={mobileTab}
    >
      <div class="shpd-exchange-modal__pdf">
        <PdfViewerPanel attachments={data.attachments ?? []} />
      </div>
      <div class="shpd-exchange-modal__preview">
        {#if isRegistry && !data.aiFailed}
          <RegistryExtractedPreview canonical={data.canonical} />
        {:else}
          <DocumentExchangePreview
            canonical={data.canonical}
            aiFailed={data.aiFailed}
            wrapper={data.wrapper}
            {userActions}
            onUserActionsChange={handleUserActionsChange}
          />
        {/if}
      </div>
    </div>
  {/if}

  {#snippet footer()}
    <Button
      label={t('exchange.preview.actions.close')}
      variant="secondary"
      onclick={onClose}
    />
    <Button
      label={t('exchange.preview.actions.reject')}
      variant="danger"
      disabled={data === null}
      onclick={() => onReject(messageNdx)}
    />
    <Button
      label={isRegistry
        ? t('exchange.preview.actions.applyRegistry')
        : t('exchange.preview.actions.apply')}
      variant="success"
      disabled={!canApply}
      onclick={handleApplyClick}
    />
  {/snippet}
</Modal>

<style>
  .shpd-exchange-modal__loading,
  .shpd-exchange-modal__error {
    display: flex;
    align-items: center;
    justify-content: center;
    padding: var(--shpd-space-xl);
    color: var(--shpd-color-text-muted);
    font-size: 0.875rem;
    min-height: 200px;
  }

  .shpd-exchange-modal__error {
    color: var(--shpd-color-danger);
  }

  /* Mobile tab bar — hidden on desktop. */
  .shpd-exchange-modal__mobile-tabs {
    display: none;
    border-bottom: 1px solid var(--shpd-color-border);
    padding: 0 var(--shpd-space-sm);
    gap: var(--shpd-space-xs);
    background: var(--shpd-color-surface);
    flex-shrink: 0;
  }

  .shpd-exchange-modal__mobile-tab {
    border: 0;
    background: transparent;
    padding: var(--shpd-space-sm) var(--shpd-space-md);
    cursor: pointer;
    color: var(--shpd-color-text-muted);
    border-bottom: 2px solid transparent;
    margin-bottom: -1px;
    font-size: 0.875rem;
    font-weight: 500;
  }

  .shpd-exchange-modal__mobile-tab--active {
    color: var(--shpd-color-primary);
    border-bottom-color: var(--shpd-color-primary);
  }

  /* Desktop: 50/50 split. */
  .shpd-exchange-modal__split {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: var(--shpd-space-md);
    height: 100%;
    min-height: 0;
    overflow: hidden;
  }

  .shpd-exchange-modal__pdf,
  .shpd-exchange-modal__preview {
    overflow-y: auto;
    min-height: 0;
    border: 1px solid var(--shpd-color-border);
    border-radius: 6px;
    background: var(--shpd-color-surface);
  }

  /* Mobile: single column controlled by tab. */
  @media (max-width: 768px) {
    .shpd-exchange-modal__mobile-tabs {
      display: flex;
    }

    .shpd-exchange-modal__split {
      grid-template-columns: 1fr;
    }

    .shpd-exchange-modal__split[data-mobile-tab='pdf'] .shpd-exchange-modal__preview {
      display: none;
    }

    .shpd-exchange-modal__split[data-mobile-tab='preview'] .shpd-exchange-modal__pdf {
      display: none;
    }
  }
</style>
