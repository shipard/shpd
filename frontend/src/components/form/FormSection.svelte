<script>
  import FormColumn from './FormColumn.svelte';

  let {
    section,
    formData = $bindable({}),
    fieldErrors = {},
    dataResolved = {},
    disabled = false,
    onTrigger,
    onResolveChange,
    parentId = null,
  } = $props();
</script>

{#if !section.hidden}
  <section class="shpd-form-section">
    {#if section.title}
      <h3 class="shpd-form-section__title">{section.title}</h3>
    {/if}
    <div
      class="shpd-form-section__columns"
      style:--shpd-form-section-cols={section.columns.length}
    >
      {#each section.columns as column, i (i)}
        <FormColumn
          {column}
          bind:formData
          {fieldErrors}
          {dataResolved}
          {disabled}
          {onTrigger}
          {onResolveChange}
          {parentId}
        />
      {/each}
    </div>
  </section>
{/if}

<style>
  .shpd-form-section {
    background: var(--shpd-color-bg-secondary);
    border: 1px solid var(--shpd-color-border-subtle);
    border-radius: var(--shpd-radius-md);
    padding: var(--shpd-form-section-padding-y) var(--shpd-space-lg);
  }

  .shpd-form-section__title {
    font-size: var(--shpd-font-size-sm);
    font-weight: 600;
    color: var(--shpd-color-text-secondary);
    margin: 0 0 var(--shpd-space-sm) 0;
    text-transform: uppercase;
    letter-spacing: 0.05em;
  }

  .shpd-form-section__columns {
    display: grid;
    grid-template-columns: repeat(var(--shpd-form-section-cols), 1fr);
    gap: var(--shpd-space-xl);
  }

  /* Breakpoint 768px ladí s MOBILE_BREAKPOINT v layout.svelte.js
     (konzistentní s ostatní mobilní prací). Na mobilu se sloupce
     skládají pod sebe; gap přepneme z xl (vodorovná mezera mezi
     sloupci vedle sebe) na sm, aby svislá mezera mezi naskládanými
     sloupci navazovala na row-gap polí uvnitř sloupce (FormColumn). */
  @media (max-width: 768px) {
    .shpd-form-section__columns {
      grid-template-columns: 1fr;
      gap: var(--shpd-space-sm);
    }
  }
</style>
