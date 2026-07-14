<script>
  import { onMount } from 'svelte';
  import { t } from '../../i18n/index.js';
  import { translateError } from '../../i18n/errors.js';
  import { fetchDashboard, setMessageDocState } from '../../api/dashboard.js';
  import {
    applyExtractedDocument,
    unapplyExtractedDocument,
    rejectExtractedDocument,
    reanalyzeMessage,
  } from '../../api/exchange.js';
  import { iconRefresh } from '../../icons.js';
  import { navigationStore } from '../../stores/navigation.svelte.js';
  import Button from '../ui/Button.svelte';
  import FormDialog from '../form/FormDialog.svelte';
  import DocumentExchangePreviewModal from '../exchange/DocumentExchangePreviewModal.svelte';
  import AiSummaryCard from './AiSummaryCard.svelte';
  import WidgetCard from './WidgetCard.svelte';
  import Feed from './Feed.svelte';
  import RejectReasonPrompt from './RejectReasonPrompt.svelte';

  const HEADS_TABLE = 'docs_core_heads';
  const REGISTRY_TABLE = 'base_registry_documents';

  let data = $state(null);
  let loading = $state(true);
  let error = $state(null);

  // Karta s právě běžící inline akcí (apply/reanalyze) → disabluje její tlačítka.
  let busyCardId = $state(null);

  // Review modal (fall-through i „Zkontrolovat") a reject prompt drží extractedNdx.
  let previewNdx = $state(null);
  let rejectNdx = $state(null);
  let rejectSubmitting = $state(false);

  // Form modal (alert open_form + toast „Otevřít"). wasSaved viz handleFormClose.
  let formModal = $state({ open: false, table: '', recordId: null, wasSaved: false });

  // Minimální lokální toast (app nemá toast infra). kind: 'applied' → Otevřít+Vrátit.
  // docTable řídí, kterou tabulku „Otevřít" otevře (docs vs. Spisovna).
  let toast = $state({ visible: false, kind: null, message: '', docId: null, extractedNdx: null, docTable: null });
  let toastTimer = null;

  async function load() {
    loading = true;
    error = null;
    try {
      const result = await fetchDashboard();
      if (result) {
        data = result;
      } else {
        error = t('dashboard.error.failed');
      }
    } catch (err) {
      error = t('dashboard.error.failed');
      console.error('Dashboard load failed:', err);
    } finally {
      loading = false;
    }
  }

  // ── Toast ─────────────────────────────────────────────────────────────────

  function showToast(next) {
    clearTimeout(toastTimer);
    toast = { visible: true, docId: null, extractedNdx: null, docTable: null, ...next };
    toastTimer = setTimeout(dismissToast, 8000);
  }

  function dismissToast() {
    clearTimeout(toastTimer);
    toast = { visible: false, kind: null, message: '', docId: null, extractedNdx: null, docTable: null };
  }

  function openCreatedDoc() {
    const docId = toast.docId;
    const docTable = toast.docTable ?? HEADS_TABLE;
    dismissToast();
    if (docId) {
      formModal = { open: true, table: docTable, recordId: docId, wasSaved: false };
    }
  }

  async function undoApply() {
    const ndx = toast.extractedNdx;
    dismissToast();
    if (!ndx) return;
    const result = await unapplyExtractedDocument(ndx);
    if (result?.success) {
      showToast({ kind: 'reverted', message: t('dashboard.toast.reverted') });
      load();
    } else {
      alert(t('dashboard.card.actionFailed', { msg: translateError(result?.error) }));
    }
  }

  // ── Optimistické odebrání karty ─────────────────────────────────────────────

  function dropCardById(cardId) {
    if (data?.cards) data.cards = data.cards.filter((c) => c.id !== cardId);
  }

  function dropCardByExtracted(extractedNdx) {
    if (data?.cards) data.cards = data.cards.filter((c) => c.context?.extractedNdx !== extractedNdx);
  }

  // ── Akce karet ──────────────────────────────────────────────────────────────

  function handleCardAction(card, action) {
    const target = action.target ?? {};
    switch (action.kind) {
      case 'apply_extracted':
        return applyFlow(target.extractedNdx, card.id, card.context?.target);
      case 'review_extracted':
        previewNdx = target.extractedNdx;
        return;
      case 'reject_extracted':
        rejectNdx = target.extractedNdx;
        return;
      case 'reanalyze':
        return reanalyzeFlow(target.messageNdx, card.id);
      case 'trash_message':
        return messageStateFlow(target.messageNdx, 90, card.id);
      case 'archive_message':
        return messageStateFlow(target.messageNdx, 80, card.id);
      case 'open_viewer':
        return navigationStore.navigateToViewer(target.viewerId, target.recordId ?? null);
      case 'open_form':
        formModal = {
          open: true,
          table: target.table,
          recordId: target.recordId ?? target.id ?? null,
          wasSaved: false,
        };
        return;
      default:
        console.warn('Unknown card action kind:', action.kind);
    }
  }

  // Toast payload dle targetu extrakce: registry karty otevírají dokument
  // Spisovny a nesou vlastní hlášku „Zařazeno…"; docs beze změny.
  function appliedToast(cardTarget, docId, extractedNdx) {
    const isRegistry = cardTarget === 'registry';
    return {
      kind: 'applied',
      message: isRegistry
        ? t('dashboard.toast.appliedRegistry', { id: docId })
        : t('dashboard.toast.applied', { id: docId }),
      docId,
      extractedNdx,
      docTable: isRegistry ? REGISTRY_TABLE : HEADS_TABLE,
    };
  }

  // Jednoklik apply (safe mód). Fall-through: nevyřešené reference → review modal.
  async function applyFlow(extractedNdx, cardId, cardTarget = null) {
    if (busyCardId !== null || !extractedNdx) return;
    busyCardId = cardId;
    try {
      const result = await applyExtractedDocument(extractedNdx);
      if (result?.success) {
        const docId = result.data?.savedDocId ?? 0;
        dropCardById(cardId);
        showToast(appliedToast(cardTarget, docId, extractedNdx));
        load();
      } else if (result?.error?.code === 'unresolved_required') {
        previewNdx = extractedNdx; // fall-through — dořeší v modalu
      } else {
        alert(t('dashboard.card.actionFailed', { msg: translateError(result?.error) }));
      }
    } finally {
      busyCardId = null;
    }
  }

  async function reanalyzeFlow(messageNdx, cardId) {
    if (busyCardId !== null || !messageNdx) return;
    busyCardId = cardId;
    try {
      const result = await reanalyzeMessage(messageNdx);
      if (result?.success) {
        dropCardById(cardId);
        load();
      } else {
        alert(t('dashboard.card.actionFailed', { msg: translateError(result?.error) }));
      }
    } finally {
      busyCardId = null;
    }
  }

  // Jednoklik Koš (90) / Archiv (80) z karty „Není faktura".
  async function messageStateFlow(messageNdx, docState, cardId) {
    if (busyCardId !== null || !messageNdx) return;
    busyCardId = cardId;
    try {
      const result = await setMessageDocState(messageNdx, docState);
      if (result?.success) {
        dropCardById(cardId);
        load();
      } else {
        alert(t('dashboard.card.actionFailed', { msg: translateError(result?.error) }));
      }
    } finally {
      busyCardId = null;
    }
  }

  // ── Review modal ─────────────────────────────────────────────────────────────

  async function handleApplyFromModal(extractedNdx, userActions = null) {
    const cardTarget = data?.cards?.find(
      (c) => c.context?.extractedNdx === extractedNdx,
    )?.context?.target ?? null;
    const result = await applyExtractedDocument(extractedNdx, userActions);
    if (result?.success) {
      const docId = result.data?.savedDocId ?? 0;
      previewNdx = null;
      dropCardByExtracted(extractedNdx);
      showToast(appliedToast(cardTarget, docId, extractedNdx));
      load();
    } else {
      alert(t('dashboard.card.actionFailed', { msg: translateError(result?.error) }));
    }
  }

  function handleRejectFromModal(extractedNdx) {
    previewNdx = null;
    rejectNdx = extractedNdx;
  }

  // ── Reject prompt ────────────────────────────────────────────────────────────

  async function submitRejectFlow(reason) {
    const ndx = rejectNdx;
    if (!ndx || rejectSubmitting) return;
    rejectSubmitting = true;
    try {
      const result = await rejectExtractedDocument(ndx, reason);
      if (result?.success) {
        rejectNdx = null;
        dropCardByExtracted(ndx);
        load();
      } else {
        alert(t('dashboard.card.actionFailed', { msg: translateError(result?.error) }));
      }
    } finally {
      rejectSubmitting = false;
    }
  }

  // ── Tasks widget (flat action shape z fáze 1) ────────────────────────────────

  function handleItemAction(action) {
    if (!action || !action.kind) return;
    if (action.kind === 'open_viewer') {
      navigationStore.navigateToViewer(action.viewerId, action.recordId ?? null);
    } else if (action.kind === 'open_form') {
      formModal = { open: true, table: action.table, recordId: action.recordId ?? null, wasSaved: false };
    }
  }

  function handleOpenAllAction(action) {
    if (action?.viewerId) navigationStore.navigateToViewer(action.viewerId);
  }

  function handleFormSaved() {
    formModal.wasSaved = true;
  }

  function handleFormClose() {
    const shouldRefetch = formModal.wasSaved;
    formModal = { open: false, table: '', recordId: null, wasSaved: false };
    if (shouldRefetch) load();
  }

  onMount(load);
</script>

<div class="shpd-dashboard">
  <header class="shpd-dashboard__header">
    <h1 class="shpd-dashboard__title">{t('dashboard.title')}</h1>
    <Button
      variant="ghost"
      size="sm"
      icon={iconRefresh}
      label={t('dashboard.refresh')}
      onclick={load}
      disabled={loading}
    />
  </header>

  {#if loading && !data}
    <div class="shpd-dashboard__loading">{t('common.loading')}</div>
  {:else if error && !data}
    <div class="shpd-dashboard__error">{error}</div>
  {:else if data}
    <AiSummaryCard summary={data.summary} />

    <Feed cards={data.cards} {busyCardId} onCardAction={handleCardAction} />

    {#if data.tasks}
      <WidgetCard
        widget={data.tasks}
        onItemAction={handleItemAction}
        onOpenAllAction={handleOpenAllAction}
      />
    {/if}
  {/if}
</div>

<DocumentExchangePreviewModal
  open={previewNdx !== null}
  extractedNdx={previewNdx}
  onClose={() => (previewNdx = null)}
  onApply={handleApplyFromModal}
  onReject={handleRejectFromModal}
/>

<RejectReasonPrompt
  open={rejectNdx !== null}
  submitting={rejectSubmitting}
  title={t('dashboard.reject.title')}
  reasonLabel={t('dashboard.reject.label')}
  placeholder={t('dashboard.reject.placeholder')}
  confirmLabel={t('dashboard.reject.confirm')}
  onConfirm={submitRejectFlow}
  onClose={() => (rejectNdx = null)}
/>

{#if formModal.open}
  <FormDialog
    table={formModal.table}
    recordId={formModal.recordId}
    open={formModal.open}
    onSaved={handleFormSaved}
    onClose={handleFormClose}
  />
{/if}

{#if toast.visible}
  <div class="shpd-toast" role="status">
    <span class="shpd-toast__msg">{toast.message}</span>
    {#if toast.kind === 'applied'}
      <button type="button" class="shpd-toast__action" onclick={openCreatedDoc}>{t('dashboard.toast.open')}</button>
      <button type="button" class="shpd-toast__action" onclick={undoApply}>{t('dashboard.toast.undo')}</button>
    {/if}
    <button type="button" class="shpd-toast__close" onclick={dismissToast} aria-label={t('common.cancel')}>×</button>
  </div>
{/if}

<style>
  .shpd-dashboard {
    padding: var(--shpd-space-lg);
    display: flex;
    flex-direction: column;
    gap: var(--shpd-space-lg);
  }

  .shpd-dashboard__header {
    display: flex;
    align-items: center;
    justify-content: space-between;
  }

  .shpd-dashboard__title {
    margin: 0;
    font-size: var(--shpd-font-size-xl);
    color: var(--shpd-color-text);
  }

  .shpd-dashboard__loading,
  .shpd-dashboard__error {
    padding: var(--shpd-space-xl);
    text-align: center;
    color: var(--shpd-color-text-secondary);
  }

  /* Minimální toast — fixed dole na střed, auto-dismiss ~8 s. */
  .shpd-toast {
    position: fixed;
    left: 50%;
    bottom: var(--shpd-space-lg);
    transform: translateX(-50%);
    z-index: 1000;
    display: flex;
    align-items: center;
    gap: var(--shpd-space-md);
    max-width: min(90vw, 560px);
    padding: var(--shpd-space-sm) var(--shpd-space-md);
    background: var(--shpd-color-text);
    color: var(--shpd-color-bg);
    border-radius: var(--shpd-radius-md);
    box-shadow: var(--shpd-shadow-lg, 0 4px 16px rgba(0, 0, 0, 0.25));
    font-size: var(--shpd-font-size-sm);
  }

  .shpd-toast__msg {
    flex: 1;
    min-width: 0;
  }

  .shpd-toast__action {
    flex-shrink: 0;
    border: none;
    background: none;
    color: var(--shpd-color-bg);
    font: inherit;
    font-weight: 600;
    text-decoration: underline;
    cursor: pointer;
    padding: 0 var(--shpd-space-xs);
  }

  .shpd-toast__close {
    flex-shrink: 0;
    border: none;
    background: none;
    color: var(--shpd-color-bg);
    font-size: 1.1rem;
    line-height: 1;
    cursor: pointer;
    padding: 0 var(--shpd-space-xs);
    opacity: 0.7;
  }

  .shpd-toast__close:hover {
    opacity: 1;
  }
</style>
