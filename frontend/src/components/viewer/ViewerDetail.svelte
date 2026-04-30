<script>
  import { post } from '../../api/client.js';
  import Button from '../ui/Button.svelte';

  let { detail = null, loading = false, onRefresh } = $props();

  let activeTabId = $state(null);

  // Auto-select first tab when detail changes
  $effect(() => {
    if (detail?.tabs?.length > 0) {
      activeTabId = detail.tabs[0].id;
    } else {
      activeTabId = null;
    }
  });

  let activeContent = $derived(
    detail?.tabs?.find(t => t.id === activeTabId)?.content ?? null
  );

  // --- Extracted documents — JSON detail modal state ---
  let detailModalDoc = $state(null);

  // --- Extracted documents — reject dialog state ---
  let rejectDialogDoc = $state(null);
  let rejectReason = $state('');
  let rejectSubmitting = $state(false);

  // --- Extracted documents — apply confirmation ---
  let actionInFlightNdx = $state(null);

  async function applyDocument(doc) {
    if (actionInFlightNdx !== null) return;
    actionInFlightNdx = doc.ndx;
    try {
      // Dedikovaný endpoint, který prochází přes Document hooks (validate,
      // beforeSave, afterPersist) — generic PATCH by je obešel a auto-transition
      // zprávy 30→40 by se nespustil.
      const result = await post(`/_mail/extracted-documents/${doc.ndx}/apply`, {});
      if (result?.success) {
        onRefresh?.();
      } else {
        alert('Nepodařilo se uložit: ' + (result?.error?.message ?? 'neznámá chyba'));
      }
    } finally {
      actionInFlightNdx = null;
    }
  }

  function openRejectDialog(doc) {
    rejectDialogDoc = doc;
    rejectReason = '';
  }

  function closeRejectDialog() {
    rejectDialogDoc = null;
    rejectReason = '';
  }

  async function submitReject() {
    if (!rejectDialogDoc || rejectReason.trim() === '' || rejectSubmitting) return;
    rejectSubmitting = true;
    try {
      const result = await post(`/_mail/extracted-documents/${rejectDialogDoc.ndx}/reject`, {
        reason: rejectReason.trim(),
      });
      if (result?.success) {
        closeRejectDialog();
        onRefresh?.();
      } else {
        alert('Nepodařilo se zamítnout: ' + (result?.error?.message ?? 'neznámá chyba'));
      }
    } finally {
      rejectSubmitting = false;
    }
  }

  function openDetailModal(doc) {
    detailModalDoc = doc;
  }

  function closeDetailModal() {
    detailModalDoc = null;
  }

  function formatJson(jsonStr) {
    if (!jsonStr) return '';
    try {
      return JSON.stringify(JSON.parse(jsonStr), null, 2);
    } catch {
      return jsonStr;
    }
  }
</script>

<div class="shpd-detail">
  {#if loading}
    <div class="shpd-detail__loading">
      <span class="shpd-detail__spinner"></span>
      <span>Načítám...</span>
    </div>
  {:else if detail?.tabs?.length > 0}
    {#if detail.title}
      <!-- Header (title + badges) — zobrazuje se jen pokud backend
           posílá detail.title. Bez něj se hlavička přeskočí a layout
           je stejný jako předtím. -->
      <div class="shpd-detail__header">
        <div class="shpd-detail__title-row">
          <h2 class="shpd-detail__title">{detail.title}</h2>
          {#each detail.badges ?? [] as badge}
            <span class="shpd-detail__badge shpd-detail__badge--{badge.style ?? 'neutral'}">
              {badge.label}
            </span>
          {/each}
        </div>
        {#if detail.subtitle}
          <div class="shpd-detail__subtitle">{detail.subtitle}</div>
        {/if}
      </div>
    {/if}

    <!-- Tab bar -->
    <div class="shpd-detail__tabs">
      {#each detail.tabs as tab (tab.id)}
        <button
          class="shpd-detail__tab"
          class:shpd-detail__tab--active={activeTabId === tab.id}
          onclick={() => activeTabId = tab.id}
          type="button"
        >
          {tab.label}
        </button>
      {/each}
    </div>

    <!-- Tab content -->
    <div class="shpd-detail__content">
      {#if activeContent?.type === 'properties'}
        {#each activeContent.groups ?? [] as group}
          <div class="shpd-detail__group">
            <h4 class="shpd-detail__group-title">{group.title}</h4>
            <dl class="shpd-detail__props">
              {#each group.items ?? [] as item}
                <div class="shpd-detail__prop">
                  <dt class="shpd-detail__prop-label">{item.label}</dt>
                  <dd class="shpd-detail__prop-value">{item.value}</dd>
                </div>
              {/each}
            </dl>
          </div>
        {/each}

      {:else if activeContent?.type === 'table'}
        <div class="shpd-detail__table-wrap">
          <table class="shpd-detail__table">
            <thead>
              <tr>
                {#each activeContent.columns ?? [] as col (col.id)}
                  <th class="shpd-detail__th">{col.label}</th>
                {/each}
              </tr>
            </thead>
            <tbody>
              {#if (activeContent.rows ?? []).length === 0}
                <tr>
                  <td class="shpd-detail__empty-cell" colspan={activeContent.columns?.length ?? 1}>
                    Žádné záznamy
                  </td>
                </tr>
              {:else}
                {#each activeContent.rows ?? [] as row}
                  <tr>
                    {#each activeContent.columns ?? [] as col (col.id)}
                      <td class="shpd-detail__td">{row[col.id] ?? '—'}</td>
                    {/each}
                  </tr>
                {/each}
              {/if}
            </tbody>
          </table>
        </div>

      {:else if activeContent?.type === 'html'}
        <div class="shpd-detail__html">
          {@html activeContent.html}
        </div>

      {:else if activeContent?.type === 'extracted-documents'}
        <div class="shpd-extracted">
          {#if (activeContent.documents ?? []).length === 0}
            <div class="shpd-extracted__empty">Žádné extrahované dokumenty.</div>
          {:else}
            {#each activeContent.documents as doc (doc.ndx)}
              <div class="shpd-extracted__card">
                <div class="shpd-extracted__header">
                  <span class="shpd-extracted__type">{doc.doc_type_label}</span>
                  <span class="shpd-extracted__badge shpd-extracted__badge--{doc.status_style}">
                    {doc.status_label}
                  </span>
                  {#if doc.confidence !== null}
                    <span class="shpd-extracted__confidence">{(doc.confidence * 100).toFixed(0)}%</span>
                  {/if}
                </div>

                {#if doc.summary}
                  <div class="shpd-extracted__summary">{doc.summary}</div>
                {/if}

                {#if doc.applied_at}
                  <div class="shpd-extracted__meta">Použito: {doc.applied_at}</div>
                {/if}
                {#if doc.rejected_reason}
                  <div class="shpd-extracted__meta">Důvod zamítnutí: {doc.rejected_reason}</div>
                {/if}

                <div class="shpd-extracted__actions">
                  <Button
                    label="Zobrazit detail"
                    variant="secondary"
                    size="sm"
                    onclick={() => openDetailModal(doc)}
                  />
                  {#if doc.can_apply}
                    <Button
                      label="Použít"
                      variant="success"
                      size="sm"
                      disabled={actionInFlightNdx === doc.ndx}
                      onclick={() => applyDocument(doc)}
                    />
                  {/if}
                  {#if doc.can_reject}
                    <Button
                      label="Zamítnout"
                      variant="danger"
                      size="sm"
                      disabled={actionInFlightNdx === doc.ndx}
                      onclick={() => openRejectDialog(doc)}
                    />
                  {/if}
                </div>
              </div>
            {/each}
          {/if}
        </div>
      {/if}
    </div>

    <!-- Detail modal with extracted JSON -->
    {#if detailModalDoc}
      <div class="shpd-modal-overlay" onclick={closeDetailModal} role="presentation">
        <div
          class="shpd-modal"
          onclick={(e) => e.stopPropagation()}
          onkeydown={(e) => e.key === 'Escape' && closeDetailModal()}
          role="dialog"
          aria-modal="true"
          tabindex="-1"
        >
          <div class="shpd-modal__header">
            <h3>{detailModalDoc.doc_type_label}</h3>
            <button type="button" class="shpd-modal__close" onclick={closeDetailModal} aria-label="Zavřít">×</button>
          </div>
          <div class="shpd-modal__body">
            <pre class="shpd-extracted__json">{formatJson(detailModalDoc.extracted_json)}</pre>
          </div>
        </div>
      </div>
    {/if}

    <!-- Reject reason dialog -->
    {#if rejectDialogDoc}
      <div class="shpd-modal-overlay" onclick={closeRejectDialog} role="presentation">
        <div
          class="shpd-modal shpd-modal--small"
          onclick={(e) => e.stopPropagation()}
          onkeydown={(e) => e.key === 'Escape' && closeRejectDialog()}
          role="dialog"
          aria-modal="true"
          tabindex="-1"
        >
          <div class="shpd-modal__header">
            <h3>Zamítnout dokument</h3>
            <button type="button" class="shpd-modal__close" onclick={closeRejectDialog} aria-label="Zavřít">×</button>
          </div>
          <div class="shpd-modal__body">
            <label for="reject-reason" class="shpd-extracted__field-label">Důvod zamítnutí (povinné):</label>
            <textarea
              id="reject-reason"
              class="shpd-extracted__textarea"
              bind:value={rejectReason}
              rows="3"
              placeholder="Např. False positive, špatně rozpoznaný typ..."
            ></textarea>
          </div>
          <div class="shpd-modal__footer">
            <Button label="Zrušit" variant="secondary" size="sm" onclick={closeRejectDialog} />
            <Button
              label={rejectSubmitting ? 'Ukládám…' : 'Zamítnout'}
              variant="danger"
              size="sm"
              disabled={rejectSubmitting || rejectReason.trim() === ''}
              onclick={submitReject}
            />
          </div>
        </div>
      </div>
    {/if}
  {:else}
    <div class="shpd-detail__empty">
      Žádné detaily
    </div>
  {/if}
</div>

<style>
  .shpd-detail {
    display: flex;
    flex-direction: column;
    height: 100%;
    overflow: hidden;
  }

  .shpd-detail__loading {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: var(--shpd-space-sm);
    padding: var(--shpd-space-xl);
    color: var(--shpd-color-text-secondary);
    font-size: var(--shpd-font-size-sm);
  }

  .shpd-detail__spinner {
    display: inline-block;
    width: 18px;
    height: 18px;
    border: 2px solid var(--shpd-color-border);
    border-top-color: var(--shpd-color-primary);
    border-radius: 50%;
    animation: shpd-detail-spin 0.7s linear infinite;
  }

  @keyframes shpd-detail-spin {
    to { transform: rotate(360deg); }
  }

  /* Header (title + badges) — zobrazuje se jen když backend
     pošle detail.title. Drobný panel nad taby. */
  .shpd-detail__header {
    padding: var(--shpd-space-sm) var(--shpd-space-md);
    border-bottom: 1px solid var(--shpd-color-border);
    background-color: var(--shpd-color-bg);
    flex-shrink: 0;
  }

  .shpd-detail__title-row {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: var(--shpd-space-sm);
  }

  .shpd-detail__title {
    margin: 0;
    font-size: var(--shpd-font-size-lg);
    font-weight: 600;
    color: var(--shpd-color-text);
    line-height: 1.3;
  }

  .shpd-detail__subtitle {
    margin-top: 2px;
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text-secondary);
  }

  .shpd-detail__badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 999px;
    font-size: 0.75rem;
    font-weight: 500;
    line-height: 1.4;
    white-space: nowrap;
  }

  /* Stylové varianty badge.
     Sjednoceno s doc-state škálou v ViewerRow + accent pro brand. */
  /* Typové varianty (mimo doc-state) — mají vlastní barevnou logiku.
   * neutral, primary, accent, success, warning, danger se používají pro
   * libovolné typové badge (typ záznamu, VIP, atd.). Sdílí paletu
   * s doc-state tokeny tam, kde to barevně sedí. */
  .shpd-detail__badge--neutral   { background: var(--shpd-color-state-confirmed-bg); color: var(--shpd-color-state-confirmed-text); }
  .shpd-detail__badge--primary   { background: var(--shpd-color-primary-soft); color: var(--shpd-color-primary); }
  .shpd-detail__badge--accent    { background: var(--shpd-color-accent-soft); color: var(--shpd-color-accent-hover); }
  .shpd-detail__badge--success   { background: var(--shpd-color-state-done-bg); color: var(--shpd-color-state-done-text); }
  .shpd-detail__badge--warning   { background: var(--shpd-color-state-concept-bg); color: var(--shpd-color-state-concept-text); }
  .shpd-detail__badge--danger    { background: var(--shpd-color-state-cancelled-bg); color: var(--shpd-color-state-cancelled-text); }

  /* Doc-state varianty — sdílejí tokeny s ostatními badge
   * (FormStateBadge, extracted__badge), aby stejný stav vypadal
   * všude stejně. */
  .shpd-detail__badge--concept   { background: var(--shpd-color-state-concept-bg);   color: var(--shpd-color-state-concept-text); }
  .shpd-detail__badge--edit      { background: var(--shpd-color-state-edit-bg);      color: var(--shpd-color-state-edit-text); }
  .shpd-detail__badge--confirmed { background: var(--shpd-color-state-confirmed-bg); color: var(--shpd-color-state-confirmed-text); }
  .shpd-detail__badge--archive   { background: var(--shpd-color-state-archive-bg);   color: var(--shpd-color-state-archive-text); }
  .shpd-detail__badge--trash     { background: var(--shpd-color-state-trash-bg);     color: var(--shpd-color-state-trash-text); text-decoration: line-through; }
  .shpd-detail__badge--cancelled { background: var(--shpd-color-state-cancelled-bg); color: var(--shpd-color-state-cancelled-text); }

  /* Tabs */
  .shpd-detail__tabs {
    display: flex;
    border-bottom: 1px solid var(--shpd-color-border);
    flex-shrink: 0;
  }

  .shpd-detail__tab {
    padding: var(--shpd-space-sm) var(--shpd-space-md);
    border: none;
    border-bottom: 2px solid transparent;
    background: none;
    font-family: inherit;
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text-secondary);
    cursor: pointer;
    transition: color 0.15s, border-color 0.15s;
  }

  .shpd-detail__tab:hover {
    color: var(--shpd-color-text);
  }

  .shpd-detail__tab--active {
    color: var(--shpd-color-primary);
    border-bottom-color: var(--shpd-color-primary);
    font-weight: 600;
  }

  /* Content area */
  .shpd-detail__content {
    flex: 1;
    overflow-y: auto;
    padding: var(--shpd-space-md);
  }

  .shpd-detail__empty {
    display: flex;
    align-items: center;
    justify-content: center;
    height: 100%;
    color: var(--shpd-color-text-secondary);
    font-size: var(--shpd-font-size-sm);
  }

  /* Properties content */
  .shpd-detail__group {
    margin-bottom: var(--shpd-space-lg);
  }

  .shpd-detail__group-title {
    font-size: var(--shpd-font-size-sm);
    font-weight: 600;
    color: var(--shpd-color-text);
    padding-bottom: var(--shpd-space-xs);
    border-bottom: 1px solid var(--shpd-color-border);
    margin-bottom: var(--shpd-space-sm);
  }

  .shpd-detail__props {
    display: grid;
    grid-template-columns: auto 1fr;
    gap: var(--shpd-space-xs) var(--shpd-space-md);
  }

  .shpd-detail__prop {
    display: contents;
  }

  .shpd-detail__prop-label {
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text-secondary);
    white-space: nowrap;
  }

  .shpd-detail__prop-value {
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text);
  }

  /* Table content */
  .shpd-detail__table-wrap {
    overflow-x: auto;
  }

  .shpd-detail__table {
    width: 100%;
    border-collapse: collapse;
    font-size: var(--shpd-font-size-sm);
  }

  .shpd-detail__th {
    padding: var(--shpd-space-xs) var(--shpd-space-sm);
    text-align: left;
    font-weight: 600;
    color: var(--shpd-color-text);
    border-bottom: 2px solid var(--shpd-color-border);
    white-space: nowrap;
  }

  .shpd-detail__td {
    padding: var(--shpd-space-xs) var(--shpd-space-sm);
    border-bottom: 1px solid var(--shpd-color-border);
    color: var(--shpd-color-text);
  }

  .shpd-detail__empty-cell {
    padding: var(--shpd-space-md);
    text-align: center;
    color: var(--shpd-color-text-secondary);
    border-bottom: 1px solid var(--shpd-color-border);
  }

  /* HTML content */
  .shpd-detail__html {
    font-size: var(--shpd-font-size-sm);
    line-height: 1.5;
  }

  /* Extracted documents tab */
  .shpd-extracted {
    display: flex;
    flex-direction: column;
    gap: var(--shpd-space-md);
  }

  .shpd-extracted__empty {
    color: var(--shpd-color-text-secondary);
    font-size: var(--shpd-font-size-sm);
    font-style: italic;
    text-align: center;
    padding: var(--shpd-space-md);
  }

  .shpd-extracted__card {
    border: 1px solid var(--shpd-color-border);
    border-radius: var(--shpd-radius-md);
    padding: var(--shpd-space-md);
    background-color: var(--shpd-color-bg);
  }

  .shpd-extracted__header {
    display: flex;
    align-items: center;
    gap: var(--shpd-space-sm);
    margin-bottom: var(--shpd-space-sm);
  }

  .shpd-extracted__type {
    font-weight: 600;
    color: var(--shpd-color-text);
  }

  .shpd-extracted__badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: var(--shpd-radius-sm);
    font-size: 12px;
    font-weight: 500;
  }

  /* Stejné tokeny jako shpd-detail__badge — stejný stav, stejná barva.
   * Pozn.: confirmed je tady tichá šedá (sjednoceno se zbytkem systému),
   * dříve byla zelená — oprava při přechodu na tokeny. */
  .shpd-extracted__badge--done      { background: var(--shpd-color-state-done-bg);      color: var(--shpd-color-state-done-text); }
  .shpd-extracted__badge--confirmed { background: var(--shpd-color-state-confirmed-bg); color: var(--shpd-color-state-confirmed-text); }
  .shpd-extracted__badge--edit      { background: var(--shpd-color-state-edit-bg);      color: var(--shpd-color-state-edit-text); }
  .shpd-extracted__badge--archive   { background: var(--shpd-color-state-archive-bg);   color: var(--shpd-color-state-archive-text); }
  .shpd-extracted__badge--error     { background: var(--shpd-color-state-error-bg);     color: var(--shpd-color-state-error-text); }
  .shpd-extracted__badge--concept   { background: var(--shpd-color-state-concept-bg);   color: var(--shpd-color-state-concept-text); }

  .shpd-extracted__confidence {
    margin-left: auto;
    color: var(--shpd-color-text-secondary);
    font-size: var(--shpd-font-size-sm);
  }

  .shpd-extracted__summary {
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text);
    margin-bottom: var(--shpd-space-sm);
  }

  .shpd-extracted__meta {
    font-size: 12px;
    color: var(--shpd-color-text-secondary);
    margin-top: 4px;
  }

  .shpd-extracted__actions {
    display: flex;
    gap: var(--shpd-space-sm);
    margin-top: var(--shpd-space-md);
    flex-wrap: wrap;
  }

  /* Tlačítkové styly tady byly dříve hardcoded přes !important.
     Teď používáme <Button variant="success|danger|secondary"> komponentu,
     která čerpá z brand palety v variables.css. */

  .shpd-extracted__json {
    background: var(--shpd-color-bg-secondary);
    padding: var(--shpd-space-sm);
    border-radius: var(--shpd-radius-sm);
    font-family: monospace;
    font-size: 12px;
    overflow-x: auto;
    max-height: 60vh;
  }

  .shpd-extracted__field-label {
    display: block;
    margin-bottom: 6px;
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text);
  }

  .shpd-extracted__textarea {
    width: 100%;
    box-sizing: border-box;
    padding: 6px;
    border: 1px solid var(--shpd-color-border);
    border-radius: var(--shpd-radius-sm);
    font-family: inherit;
    font-size: var(--shpd-font-size-sm);
  }

  /* Modal */
  .shpd-modal-overlay {
    position: fixed;
    inset: 0;
    background: var(--shpd-color-overlay);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 1000;
  }

  .shpd-modal {
    background: var(--shpd-color-bg);
    border-radius: var(--shpd-radius-md);
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
    max-width: 800px;
    width: calc(100% - 40px);
    max-height: 80vh;
    display: flex;
    flex-direction: column;
  }

  .shpd-modal--small { max-width: 480px; }

  .shpd-modal__header {
    padding: var(--shpd-space-md);
    border-bottom: 1px solid var(--shpd-color-border);
    display: flex;
    align-items: center;
    justify-content: space-between;
  }

  .shpd-modal__header h3 {
    margin: 0;
    font-size: var(--shpd-font-size-md);
    font-weight: 600;
  }

  .shpd-modal__close {
    border: none;
    background: none;
    font-size: 1.4rem;
    cursor: pointer;
    color: var(--shpd-color-text-secondary);
    padding: 0;
    width: 24px;
    height: 24px;
  }

  .shpd-modal__body {
    padding: var(--shpd-space-md);
    overflow-y: auto;
    flex: 1;
  }

  .shpd-modal__footer {
    padding: var(--shpd-space-md);
    border-top: 1px solid var(--shpd-color-border);
    display: flex;
    justify-content: flex-end;
    gap: var(--shpd-space-sm);
  }
</style>
