<script>
  import FormColumn from './FormColumn.svelte';

  let {
    section,
    formData = $bindable({}),
    fieldErrors = {},
    disabled = false,
    onTrigger,
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
          {disabled}
          {onTrigger}
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
    padding: var(--shpd-space-md) var(--shpd-space-lg);
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

  @media (max-width: 700px) {
    .shpd-form-section__columns {
      grid-template-columns: 1fr;
    }
  }
</style>
