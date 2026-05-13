<script>
  let { element, id, children } = $props();
</script>

{#if !element.hidden}
  <label class="shpd-form-field-row__label" for={id}>
    {element.label ?? ''}{#if element.required}<span class="shpd-form-field-row__required">*</span>{/if}
  </label>
  <div class="shpd-form-field-row__input">
    {@render children()}
  </div>
{/if}

<style>
  /*
   * <label> and <.input> are two SEPARATE siblings inside the parent FormColumn
   * grid (no wrapping element around them). That's how all labels in a column
   * collapse to one shared "max-content" track.
   */
  .shpd-form-field-row__label {
    font-size: var(--shpd-font-size-sm);
    font-weight: 500;
    color: var(--shpd-color-text);
    text-align: right;
    white-space: nowrap;
  }
  .shpd-form-field-row__required {
    color: var(--shpd-color-danger);
    margin-left: 2px;
  }
  .shpd-form-field-row__input {
    min-width: 0;
  }
</style>
