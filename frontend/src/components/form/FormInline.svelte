<script>
  import Input from '../ui/Input.svelte';
  import Select from '../ui/Select.svelte';
  import DateInput from '../ui/DateInput.svelte';
  import NumberInput from '../ui/NumberInput.svelte';

  let {
    element,
    formData = $bindable({}),
    fieldErrors = {},
    disabled = false,
    onTrigger,
    id,
  } = $props();

  function innerId(_idx) {
    return `${id}-${_idx}`;
  }

  function handleChange(inner) {
    if (inner.triggers === 'reload' && inner.column) {
      onTrigger?.(inner.column);
    }
  }
</script>

{#if !element.hidden}
  <label class="shpd-form-field-row__label" for={id}>
    {element.elements[0].label ?? ''}
  </label>
  <div class="shpd-form-inline">
    {#each element.elements as inner, i (inner.column ?? i)}
      <span class="shpd-form-inline__item">
        {#if i > 0}<span class="shpd-form-inline__mini-label">{inner.label ?? ''}:</span>{/if}

        {#if inner.type === 'select'}
          <Select
            id={i === 0 ? id : innerId(i)}
            bind:value={formData[inner.column]}
            options={inner.options ?? []}
            required={inner.required ?? false}
            disabled={disabled || inner.read_only === true}
            error={fieldErrors[inner.column] ?? null}
            onchange={() => handleChange(inner)}
          />
        {:else if inner.input_type === 'date'}
          <DateInput
            id={i === 0 ? id : innerId(i)}
            bind:value={formData[inner.column]}
            required={inner.required ?? false}
            disabled={disabled || inner.read_only === true}
            error={fieldErrors[inner.column] ?? null}
          />
        {:else if inner.input_type === 'number'}
          <NumberInput
            id={i === 0 ? id : innerId(i)}
            bind:value={formData[inner.column]}
            required={inner.required ?? false}
            disabled={disabled || inner.read_only === true}
            error={fieldErrors[inner.column] ?? null}
          />
        {:else}
          <Input
            id={i === 0 ? id : innerId(i)}
            type={inner.input_type ?? 'text'}
            bind:value={formData[inner.column]}
            placeholder={inner.placeholder}
            required={inner.required ?? false}
            disabled={disabled || inner.read_only === true}
            error={fieldErrors[inner.column] ?? null}
          />
        {/if}
      </span>
    {/each}
  </div>
{/if}

<style>
  .shpd-form-inline {
    display: flex;
    gap: var(--shpd-space-md);
    align-items: baseline;
    flex-wrap: wrap;
  }
  .shpd-form-inline__item {
    display: flex;
    gap: var(--shpd-space-xs);
    align-items: baseline;
  }
  .shpd-form-inline__mini-label {
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text-secondary);
    white-space: nowrap;
  }
</style>
