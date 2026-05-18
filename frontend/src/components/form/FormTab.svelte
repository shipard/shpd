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
    <AttachmentPanel tableId={tab.table_id} recordId={parentId} {disabled} />
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
    gap: var(--shpd-space-lg);
    padding: var(--shpd-space-lg);
  }
</style>
