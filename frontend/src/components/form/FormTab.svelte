<script>
  import FormSection from './FormSection.svelte';
  import FormSubTable from './FormSubTable.svelte';
  import AttachmentPanel from './AttachmentPanel.svelte';

  let {
    tab,
    formData = $bindable({}),
    fieldErrors = {},
    dataResolved = {},
    disabled = false,
    onTrigger,
    onResolveChange,
    parentId = null,
  } = $props();
</script>

<div class="shpd-form-tab">
  {#if tab.type === 'subtable'}
    <FormSubTable element={tab.subtable} {parentId} {disabled} />
  {:else if tab.type === 'attachments'}
    <AttachmentPanel tableId={tab.table_id} recordId={parentId} {disabled} changeEndpoint={tab.change_endpoint} />
  {:else}
    {#each tab.sections as section, i (section.title ?? `section-${i}`)}
      <FormSection
        {section}
        bind:formData
        {fieldErrors}
        {dataResolved}
        {disabled}
        {onTrigger}
        {onResolveChange}
        {parentId}
      />
    {/each}
  {/if}
</div>

<style>
  .shpd-form-tab {
    display: flex;
    flex-direction: column;
    gap: var(--shpd-form-section-gap);
    padding: var(--shpd-space-sm) var(--shpd-space-md);
  }

  /* Součást řetězu pro fill sloupce — viz FormEditor / FormSection. */
  .shpd-form-tab:has(:global(.shpd-form-column--fill)) {
    flex: 1;
    min-height: 0;
  }
</style>
