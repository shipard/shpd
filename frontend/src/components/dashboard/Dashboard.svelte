<script>
  import { onMount } from 'svelte';
  import { t } from '../../i18n/index.js';
  import { translateError } from '../../i18n/errors.js';
  import { fetchDashboard, setMessageDocState } from '../../api/dashboard.js';
  import {
    applyMessage,
    rejectMessage,
    reanalyzeMessage,
  } from '../../api/exchange.js';
  import { confirmSenderRule, rejectSenderRule, undoAutoArchive } from '../../api/mail.js';
  import { iconRefresh } from '../../icons.js';
  import { navigationStore } from '../../stores/navigation.svelte.js';
  import Button from '../ui/Button.svelte';
  import FormDialog from '../form/FormDialog.svelte';
  import DocumentExchangePreviewModal from '../exchange/DocumentExchangePreviewModal.svelte';
  import AiSummaryCard from './AiSummaryCard.svelte';
  import ChatLauncher from './ChatLauncher.svelte';
  import Feed from './Feed.svelte';
  import FeedFilter from './FeedFilter.svelte';
  import RejectReasonPrompt from './RejectReasonPrompt.svelte';

  const HEADS_TABLE = 'docs_core_heads';
  const REGISTRY_TABLE = 'base_registry_documents';

  let data = $state(null);
  let loading = $state(true);
  let error = $state(null);

  // Filtr feedu — čistě klientský, drží se jen ve stavu komponenty (neresetuje
  // se v load(), takže přežije manuální Obnovit; reload aplikace → 'all').
  // Karty bez `category` (např. „…a další") se zobrazují jen v záložce Vše.
  let feedFilter = $state('all');

  const CATEGORIES = ['invoices', 'registry', 'other'];

  let feedCounts = $derived.by(() => {
    const c = { all: data?.cards?.length ?? 0, invoices: 0, registry: 0, other: 0 };
    for (const card of data?.cards ?? []) {
      if (CATEGORIES.includes(card.category)) c[card.category]++;
    }
    return c;
  });

  let feedUrgent = $derived.by(() => {
    const u = { invoices: false, registry: false, other: false };
    for (const card of data?.cards ?? []) {
      if (card.kind === 'urgent' && CATEGORIES.includes(card.category)) u[card.category] = true;
    }
    return u;
  });

  let filteredCards = $derived(
    feedFilter === 'all'
      ? (data?.cards ?? [])
      : (data?.cards ?? []).filter((c) => c.category === feedFilter),
  );

  // Karta s právě běžící inline akcí (reanalyze, koš/archiv, pravidla)
  // → disabluje její tlačítka.
  let busyCardId = $state(null);

  // Review modal („Zkontrolovat“) a reject prompt drží messageNdx.
  let previewNdx = $state(null);
  let rejectNdx = $state(null);
  let rejectSubmitting = $state(false);

  // Form modal — alert open_form, toast „Otevřít“ (registry) a vystavená
  // faktura po apply z review modalu. wasSaved viz handleFormClose.
  let formModal = $state({ open: false, table: '', recordId: null, wasSaved: false });

  // Minimální lokální toast (app nemá toast infra). kind: 'applied' → Otevřít.
  // docTable řídí, kterou tabulku „Otevřít“ otevře — dnes jen Spisovna;
  // vystavená faktura (docs) se místo toastu otevírá rovnou ve FormDialogu.
  let toast = $state({ visible: false, kind: null, message: '', docId: null, docTable: null });
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
    toast = { visible: true, docId: null, docTable: null, ...next };
    toastTimer = setTimeout(dismissToast, 8000);
  }

  function dismissToast() {
    clearTimeout(toastTimer);
    toast = { visible: false, kind: null, message: '', docId: null, docTable: null };
  }

  function openCreatedDoc() {
    const docId = toast.docId;
    const docTable = toast.docTable ?? HEADS_TABLE;
    dismissToast();
    if (docId) {
      formModal = { open: true, table: docTable, recordId: docId, wasSaved: false };
    }
  }

  // ── Optimistické odebrání karty ─────────────────────────────────────────────

  function dropCardById(cardId) {
    if (data?.cards) data.cards = data.cards.filter((c) => c.id !== cardId);
  }

  function dropCardByMessage(messageNdx) {
    if (data?.cards) data.cards = data.cards.filter((c) => c.context?.messageNdx !== messageNdx);
  }

  // ── Akce karet ──────────────────────────────────────────────────────────────

  function handleCardAction(card, action) {
    const target = action.target ?? {};
    switch (action.kind) {
      case 'apply_message':
        return applyFlow(card, target.messageNdx);
      case 'review_message':
        previewNdx = target.messageNdx;
        return;
      case 'reject_message':
        rejectNdx = target.messageNdx;
        return;
      case 'reanalyze':
        return reanalyzeFlow(target.messageNdx, card.id);
      case 'trash_message':
        return messageStateFlow(target.messageNdx, 90, card.id);
      case 'archive_message':
        return messageStateFlow(target.messageNdx, 80, card.id);
      case 'confirm_sender_rule':
        return senderRuleFlow(confirmSenderRule, target.ruleId, card.id);
      case 'reject_sender_rule':
        return senderRuleFlow(rejectSenderRule, target.ruleId, card.id);
      case 'undo_auto_archive':
        return undoAutoArchiveFlow(target.date ?? null, card.id);
      case 'open_viewer':
        return navigationStore.navigateToViewer(target.viewerId, target.recordId ?? null, target.viewGroup ?? null);
      case 'open_panel':
        return navigationStore.navigateToPanel(target.panelId, action.label ?? null);
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

  // Jednoklik apply z karty (pásmo ready) — safe mode bez userActions.
  // 422 unresolved_required → fall-through do review modalu, kde uživatel
  // reference dořeší; ostatní chyby → alert.
  async function applyFlow(card, messageNdx) {
    if (busyCardId !== null || !messageNdx) return;
    busyCardId = card.id;
    try {
      const result = await applyMessage(messageNdx, null);
      if (result?.success) {
        finishApply(messageNdx, result.data?.savedDocId ?? 0, card.context?.target ?? 'docs');
      } else if (result?.error?.code === 'unresolved_required') {
        previewNdx = messageNdx;
      } else {
        alert(t('dashboard.card.actionFailed', { msg: translateError(result?.error) }));
      }
    } finally {
      busyCardId = null;
    }
  }

  // Společné dokončení apply (jednoklik i modal): docs → vystavený Koncept
  // se rovnou otevře ve FormDialogu (kontrola + případné uzavření);
  // registry → toast „Zařazeno… [Otevřít]“ (u Spisovny není co uzavírat).
  function finishApply(messageNdx, docId, target) {
    previewNdx = null;
    dropCardByMessage(messageNdx);
    if (target === 'registry') {
      showToast({
        kind: 'applied',
        message: t('dashboard.toast.appliedRegistry', { id: docId }),
        docId,
        docTable: REGISTRY_TABLE,
      });
    } else if (docId) {
      formModal = { open: true, table: HEADS_TABLE, recordId: docId, wasSaved: false };
    }
    load();
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

  // Potvrzení / zamítnutí návrhu pravidla odesílatele z review karty.
  async function senderRuleFlow(apiCall, ruleId, cardId) {
    if (busyCardId !== null || !ruleId) return;
    busyCardId = cardId;
    try {
      const result = await apiCall(ruleId);
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

  // „Vrátit vše" z digest karty — obnoví dnešní auto-archiv vč. re-queue analýzy.
  async function undoAutoArchiveFlow(date, cardId) {
    if (busyCardId !== null) return;
    busyCardId = cardId;
    try {
      const result = await undoAutoArchive(date);
      if (result?.success) {
        dropCardById(cardId);
        showToast({
          kind: 'reverted',
          message: t('dashboard.toast.autoArchiveReverted', { count: result.data?.restored ?? 0 }),
        });
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

  // Apply z review modalu (target přichází z modalu, který ho má z preview
  // endpointu).
  async function handleApplyFromModal(messageNdx, userActions = null, target = 'docs') {
    const result = await applyMessage(messageNdx, userActions);
    if (result?.success) {
      finishApply(messageNdx, result.data?.savedDocId ?? 0, target);
    } else {
      alert(t('dashboard.card.actionFailed', { msg: translateError(result?.error) }));
    }
  }

  function handleRejectFromModal(messageNdx) {
    previewNdx = null;
    rejectNdx = messageNdx;
  }

  // ── Reject prompt ────────────────────────────────────────────────────────────

  async function submitRejectFlow(reason) {
    const ndx = rejectNdx;
    if (!ndx || rejectSubmitting) return;
    rejectSubmitting = true;
    try {
      const result = await rejectMessage(ndx, reason);
      if (result?.success) {
        rejectNdx = null;
        dropCardByMessage(ndx);
        load();
      } else {
        alert(t('dashboard.card.actionFailed', { msg: translateError(result?.error) }));
      }
    } finally {
      rejectSubmitting = false;
    }
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

    {#if (data.cards?.length ?? 0) > 0}
      <FeedFilter
        value={feedFilter}
        counts={feedCounts}
        urgent={feedUrgent}
        onChange={(v) => (feedFilter = v)}
      />
    {/if}

    <Feed
      cards={filteredCards}
      {busyCardId}
      onCardAction={handleCardAction}
      emptyText={feedFilter !== 'all' && (data.cards?.length ?? 0) > 0
        ? t('dashboard.feed.emptyCategory')
        : null}
    />
  {/if}

  <ChatLauncher />
</div>

<DocumentExchangePreviewModal
  open={previewNdx !== null}
  messageNdx={previewNdx}
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
    /* min-height + margin-top:auto na launcheru drží ChatLauncher u spodní
       hrany viewportu i při krátkém obsahu; při dlouhém obsahu ho
       position:sticky nechá „plavat" nad kartami během scrollu. */
    min-height: 100%;
    box-sizing: border-box;
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

  /* Minimální toast — fixed dole na střed, auto-dismiss ~8 s.
     Bottom offset ~72px (výška launcheru + mezera) — vyskakuje nad
     ChatLauncherem, nepřekrývají se. */
  .shpd-toast {
    position: fixed;
    left: 50%;
    bottom: calc(var(--shpd-space-lg) + 72px);
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
