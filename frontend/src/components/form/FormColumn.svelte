<script>
  import FormElement from './FormElement.svelte';

  let {
    column,
    formData = $bindable({}),
    fieldErrors = {},
    dataResolved = {},
    disabled = false,
    onTrigger,
    onResolveChange,
    parentId = null,
  } = $props();
</script>

<div class="shpd-form-column">
  {#each column.elements as element, i (element.column ?? `${element.type}-${i}`)}
    <FormElement
      {element}
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

<style>
  /*
   * Two-track grid: label column shrinks to the widest label, input column fills
   * the remainder. Every <FormFieldRow> emits two siblings (a <label> and an
   * <div class="shpd-form-field-row__input">) directly into this grid so labels
   * align across the column.
   */
  .shpd-form-column {
    display: grid;
    grid-template-columns: max-content 1fr;
    column-gap: var(--shpd-space-md);
    row-gap: var(--shpd-space-sm);
    align-items: baseline;
  }
</style>
