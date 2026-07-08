<script>
  /**
   * Read-only náhledy příloh přímo ve formuláři (element typu `component`,
   * component_name `attachmentsView`). Data zrcadlí tab Přílohy — tady jen
   * prohlížení, správa (upload / mazání / přejmenování) zůstává v tabu
   * Přílohy. Velké náhledy přes AttachmentGrid mode="full" (PDF v iframe,
   * obrázky inline), na výšku omezené se scrollem.
   *
   * `params.table_id` dodává backend (TabBuilder::component(..., params)).
   */
  import { listAttachments } from '../../api/attachments.js';
  import AttachmentGrid from '../viewer/AttachmentGrid.svelte';
  import { t } from '../../i18n/index.js';

  let { params = {}, parentId = null } = $props();

  const tableId = $derived(params?.table_id ?? null);

  // Co umí server vyrenderovat inline (mirror AttachmentGrid.isInlineRenderable).
  function isInlineRenderable(mime) {
    return mime === 'application/pdf' || (mime ?? '').startsWith('image/');
  }

  let attachments = $state([]);
  let loading = $state(false);

  // Náhledovatelné přílohy (PDF + obrázky) nahoru — u eml apod. není
  // co vidět. Uvnitř skupin zůstává původní (abecední) pořadí z API.
  const sortedAttachments = $derived(
    [...attachments].sort(
      (a, b) => isInlineRenderable(b.mime_type) - isInlineRenderable(a.mime_type),
    ),
  );

  async function fetchAttachments(tId, rId) {
    loading = true;
    const res = await listAttachments(tId, rId);
    if (res?.success) {
      attachments = res.data ?? [];
    }
    loading = false;
  }

  $effect(() => {
    const tId = tableId;
    const rId = parentId;
    if (tId != null && rId != null) fetchAttachments(tId, rId);
  });
</script>

<div class="shpd-form-attview">
  {#if parentId == null}
    <p class="shpd-form-attview__empty">{t('attachments.view.newRecord')}</p>
  {:else if loading && attachments.length === 0}
    <p class="shpd-form-attview__empty">{t('attachments.loading')}</p>
  {:else if attachments.length === 0}
    <p class="shpd-form-attview__empty">{t('attachments.view.empty')}</p>
  {:else}
    <div class="shpd-form-attview__scroll">
      <AttachmentGrid attachments={sortedAttachments} mode="full" />
    </div>
  {/if}
</div>

<style>
  .shpd-form-attview {
    /* Výšku udává sousední sloupec s poli (fill režim FormColumn) —
       scroll container je absolutní, takže do výšky řádku sekce
       nepřispívá a tělo formuláře kvůli přílohám nescrolluje.
       min-height je pojistka pro formuláře s málo poli. */
    position: relative;
    height: 100%;
    min-height: 320px;
  }

  .shpd-form-attview__scroll {
    position: absolute;
    inset: 0;
    overflow-y: auto;
    padding-right: var(--shpd-space-xs);
  }

  /* Na mobilu (sloupce pod sebou) se výška od souseda odvodit nedá —
     náhledy scrollují v pevném okně. Breakpoint ladí s FormSection. */
  @media (max-width: 768px) {
    .shpd-form-attview {
      height: auto;
      min-height: 0;
    }
    .shpd-form-attview__scroll {
      position: static;
      max-height: 60vh;
    }
  }

  .shpd-form-attview__empty {
    margin: 0;
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text-secondary);
  }
</style>
