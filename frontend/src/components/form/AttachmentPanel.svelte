<script>
  import {
    listAttachments,
    uploadAttachment,
    deleteAttachment,
    renameAttachment,
    thumbnailUrl,
    downloadUrl,
    formatFileSize,
  } from '../../api/attachments.js';
  import Button from '../ui/Button.svelte';
  import Icon from '../ui/Icon.svelte';
  import { iconUpload, iconDownload, iconDelete, iconEdit, iconFile, iconFilePdf, iconFileImage, iconSpinner } from '../../icons.js';

  let {
    tableId,
    recordId = null,
    disabled = false,
  } = $props();

  let attachments = $state([]);
  let loading = $state(false);
  let uploading = $state(false);
  let dragOver = $state(false);
  let renamingId = $state(null);
  let renameValue = $state('');

  // ── Load attachments ────────────────────────────────────────────────────────

  async function fetchAttachments(tId, rId) {
    if (tId == null || rId == null) return;
    loading = true;
    const res = await listAttachments(tId, rId);
    if (res?.success) {
      attachments = res.data ?? [];
    }
    loading = false;
  }

  $effect(() => {
    const tId = tableId;
    const rId = recordId;
    if (rId != null) fetchAttachments(tId, rId);
  });

  // ── Upload ──────────────────────────────────────────────────────────────────

  async function handleFiles(files) {
    if (!files || files.length === 0 || disabled) return;
    uploading = true;
    for (const file of files) {
      await uploadAttachment(tableId, recordId, file);
    }
    uploading = false;
    await fetchAttachments(tableId, recordId);
  }

  function handleFileInput(e) {
    handleFiles(e.target.files);
    e.target.value = '';
  }

  // ── Drag and drop ───────────────────────────────────────────────────────────

  function handleDragOver(e) {
    e.preventDefault();
    if (!disabled) dragOver = true;
  }

  function handleDragLeave() {
    dragOver = false;
  }

  function handleDrop(e) {
    e.preventDefault();
    dragOver = false;
    if (!disabled) handleFiles(e.dataTransfer?.files);
  }

  // ── Actions ─────────────────────────────────────────────────────────────────

  function handleDownload(att) {
    window.open(downloadUrl(att.id), '_blank');
  }

  async function handleDelete(att) {
    if (!confirm(`Opravdu smazat přílohu „${att.name}"?`)) return;
    await deleteAttachment(att.id);
    await fetchAttachments(tableId, recordId);
  }

  function startRename(att) {
    renamingId = att.id;
    renameValue = att.name;
  }

  async function confirmRename() {
    if (renamingId == null || renameValue.trim() === '') return;
    await renameAttachment(renamingId, renameValue.trim());
    renamingId = null;
    renameValue = '';
    await fetchAttachments(tableId, recordId);
  }

  function cancelRename() {
    renamingId = null;
    renameValue = '';
  }

  function handleRenameKeydown(e) {
    if (e.key === 'Enter') confirmRename();
    if (e.key === 'Escape') cancelRename();
  }

  // ── Helpers ─────────────────────────────────────────────────────────────────

  function hasThumbnail(att) {
    const mime = att.mime_type ?? '';
    return mime.startsWith('image/') || mime === 'application/pdf';
  }

  function fileIcon(att) {
    const mime = att.mime_type ?? '';
    if (mime === 'application/pdf') return iconFilePdf;
    if (mime.startsWith('image/')) return iconFileImage;
    return iconFile;
  }

  let fileInput = $state(null);
</script>

<!-- svelte-ignore a11y_no_static_element_interactions -->
<div
  class="shpd-attachments"
  class:shpd-attachments--dragover={dragOver}
  ondragover={handleDragOver}
  ondragleave={handleDragLeave}
  ondrop={handleDrop}
  role="region"
  aria-label="Přílohy"
>
  {#if recordId == null}
    <div class="shpd-attachments__info">
      Nejprve uložte záznam, poté budete moci přidávat přílohy.
    </div>
  {:else}
    <!-- Toolbar -->
    <div class="shpd-attachments__toolbar">
      <Button
        label="Nahrát přílohu"
        icon={iconUpload}
        variant="secondary"
        size="sm"
        disabled={disabled || uploading}
        loading={uploading}
        onclick={() => fileInput.click()}
      />
      <input
        bind:this={fileInput}
        type="file"
        multiple
        class="shpd-attachments__file-input"
        onchange={handleFileInput}
      />
      <span class="shpd-attachments__count">
        {attachments.length} {attachments.length === 1 ? 'příloha' : attachments.length >= 2 && attachments.length <= 4 ? 'přílohy' : 'příloh'}
      </span>
    </div>

    <!-- Content -->
    {#if loading}
      <div class="shpd-attachments__loading">
        <Icon icon={iconSpinner} spin />
        <span>Načítám přílohy…</span>
      </div>
    {:else if attachments.length === 0}
      <div class="shpd-attachments__empty">
        <div class="shpd-attachments__drop-hint">
          Přetáhněte soubory sem nebo klikněte na „Nahrát přílohu"
        </div>
      </div>
    {:else}
      <div class="shpd-attachments__grid">
        {#each attachments as att (att.id)}
          <div class="shpd-attachments__card">
            <!-- Thumbnail / Icon -->
            <button type="button" class="shpd-attachments__thumb" onclick={() => handleDownload(att)}>
              {#if hasThumbnail(att)}
                <img
                  src={thumbnailUrl(att.id, 200)}
                  alt={att.name}
                  class="shpd-attachments__thumb-img"
                  loading="lazy"
                />
              {:else}
                <div class="shpd-attachments__thumb-icon">
                  <Icon icon={fileIcon(att)} size="xl" />
                </div>
              {/if}
            </button>

            <!-- Info -->
            <div class="shpd-attachments__info-row">
              {#if renamingId === att.id}
                <input
                  class="shpd-attachments__rename-input"
                  bind:value={renameValue}
                  onkeydown={handleRenameKeydown}
                  onblur={confirmRename}
                />
              {:else}
                <span class="shpd-attachments__name" title={att.name}>
                  {att.name}
                </span>
              {/if}
              <span class="shpd-attachments__size">
                {formatFileSize(att.file_size)}
              </span>
            </div>

            <!-- Actions -->
            {#if !disabled}
              <div class="shpd-attachments__actions">
                <button
                  class="shpd-attachments__action-btn"
                  title="Stáhnout"
                  onclick={() => handleDownload(att)}
                >
                  <Icon icon={iconDownload} size="sm" />
                </button>
                <button
                  class="shpd-attachments__action-btn"
                  title="Přejmenovat"
                  onclick={() => startRename(att)}
                >
                  <Icon icon={iconEdit} size="sm" />
                </button>
                <button
                  class="shpd-attachments__action-btn shpd-attachments__action-btn--danger"
                  title="Smazat"
                  onclick={() => handleDelete(att)}
                >
                  <Icon icon={iconDelete} size="sm" />
                </button>
              </div>
            {/if}
          </div>
        {/each}
      </div>
    {/if}
  {/if}
</div>

<style>
  .shpd-attachments {
    padding: var(--shpd-space-lg);
    min-height: 200px;
    transition: background-color 0.15s;
  }

  .shpd-attachments--dragover {
    background: color-mix(in srgb, var(--shpd-color-primary) 6%, transparent);
    outline: 2px dashed var(--shpd-color-primary);
    outline-offset: -4px;
    border-radius: var(--shpd-radius-md);
  }

  .shpd-attachments__file-input {
    display: none;
  }

  .shpd-attachments__toolbar {
    display: flex;
    align-items: center;
    gap: var(--shpd-space-md);
    margin-bottom: var(--shpd-space-md);
  }

  .shpd-attachments__count {
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text-secondary);
  }

  .shpd-attachments__info {
    padding: var(--shpd-space-xl);
    text-align: center;
    color: var(--shpd-color-text-secondary);
    font-size: var(--shpd-font-size-sm);
  }

  .shpd-attachments__loading {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: var(--shpd-space-sm);
    padding: var(--shpd-space-xl);
    color: var(--shpd-color-text-secondary);
    font-size: var(--shpd-font-size-sm);
  }

  .shpd-attachments__empty {
    display: flex;
    align-items: center;
    justify-content: center;
    padding: var(--shpd-space-xl) var(--shpd-space-lg);
  }

  .shpd-attachments__drop-hint {
    color: var(--shpd-color-text-secondary);
    font-size: var(--shpd-font-size-sm);
    text-align: center;
  }

  /* Grid of cards */
  .shpd-attachments__grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
    gap: var(--shpd-space-md);
  }

  .shpd-attachments__card {
    border: 1px solid var(--shpd-color-border);
    border-radius: var(--shpd-radius-md);
    overflow: hidden;
    background: var(--shpd-color-bg);
    transition: box-shadow 0.15s;
  }

  .shpd-attachments__card:hover {
    box-shadow: var(--shpd-shadow-md);
  }

  /* Thumbnail area */
  .shpd-attachments__thumb {
    aspect-ratio: 4 / 3;
    background: var(--shpd-color-bg-secondary);
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    overflow: hidden;
    border: none;
    width: 100%;
    padding: 0;
    font: inherit;
  }

  .shpd-attachments__thumb-img {
    width: 100%;
    height: 100%;
    object-fit: cover;
  }

  .shpd-attachments__thumb-icon {
    color: var(--shpd-color-text-secondary);
    font-size: 2rem;
  }

  /* Info row */
  .shpd-attachments__info-row {
    padding: var(--shpd-space-sm);
    display: flex;
    flex-direction: column;
    gap: 2px;
    min-width: 0;
  }

  .shpd-attachments__name {
    font-size: var(--shpd-font-size-sm);
    font-weight: 500;
    color: var(--shpd-color-text);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  .shpd-attachments__size {
    font-size: 0.75rem;
    color: var(--shpd-color-text-secondary);
  }

  .shpd-attachments__rename-input {
    font-size: var(--shpd-font-size-sm);
    font-family: var(--shpd-font-family);
    padding: 2px var(--shpd-space-xs);
    border: 1px solid var(--shpd-color-border-focus);
    border-radius: var(--shpd-radius-sm);
    outline: none;
    width: 100%;
    box-sizing: border-box;
  }

  /* Actions */
  .shpd-attachments__actions {
    display: flex;
    border-top: 1px solid var(--shpd-color-border);
  }

  .shpd-attachments__action-btn {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: var(--shpd-space-xs) 0;
    background: none;
    border: none;
    cursor: pointer;
    color: var(--shpd-color-text-secondary);
    transition: background-color 0.12s, color 0.12s;
  }

  .shpd-attachments__action-btn:hover {
    background: var(--shpd-color-bg-secondary);
    color: var(--shpd-color-text);
  }

  .shpd-attachments__action-btn--danger:hover {
    color: var(--shpd-color-danger);
  }

  .shpd-attachments__action-btn + .shpd-attachments__action-btn {
    border-left: 1px solid var(--shpd-color-border);
  }
</style>
