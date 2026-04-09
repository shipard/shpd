<script>
  import FormElement from './FormElement.svelte';

  let {
    tab,
    formData = $bindable({}),
    fieldErrors = {},
    disabled = false,
    onTrigger,
    parentId = null,
  } = $props();
</script>

<div class="shpd-form-tab">
  {#each tab.elements as element (element.column ?? element.label ?? Math.random())}
    <FormElement
      {element}
      bind:formData
      {fieldErrors}
      {disabled}
      {onTrigger}
      {parentId}
    />
  {/each}
</div>

<style>
  .shpd-form-tab {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: var(--shpd-space-md);
    padding: var(--shpd-space-lg);
    align-items: start;
  }

  @media (max-width: 899px) {
    .shpd-form-tab { grid-template-columns: repeat(2, 1fr); }
  }
  @media (max-width: 599px) {
    .shpd-form-tab { grid-template-columns: 1fr; }
  }
</style>
