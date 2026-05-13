<script>
  import Input from '../ui/Input.svelte';
  import TextArea from '../ui/TextArea.svelte';
  import Checkbox from '../ui/Checkbox.svelte';
  import Select from '../ui/Select.svelte';
  import NumberInput from '../ui/NumberInput.svelte';
  import DateInput from '../ui/DateInput.svelte';
  import FormFieldRow from './FormFieldRow.svelte';
  import FormInline from './FormInline.svelte';

  let {
    element,
    formData = $bindable({}),
    fieldErrors = {},
    disabled = false,
    onTrigger,
    parentId = null,
  } = $props();

  const error = $derived(element.column ? (fieldErrors[element.column] ?? null) : null);
  const elDisabled = $derived(disabled || element.read_only === true);
  const idSuffix = Math.random().toString(36).slice(2, 6);
  const inputId = $derived(`shpd-${element.column ?? element.type}-${idSuffix}`);

  function handleChange() {
    if (element.triggers === 'reload' && element.column) {
      onTrigger?.(element.column);
    }
  }
</script>

{#if element.type === 'separator'}
  <div class="shpd-form-separator" class:shpd-form-separator--hidden={element.hidden}>
    {#if element.label}<span class="shpd-form-separator__text">{element.label}</span>{/if}
  </div>

{:else if element.type === 'inline'}
  <FormInline {element} bind:formData {fieldErrors} disabled={elDisabled} {onTrigger} id={inputId} />

{:else if element.type === 'html'}
  <div class="shpd-form-html" class:shpd-form-html--hidden={element.hidden}>
    {@html element.content}
  </div>

{:else if element.type === 'component'}
  <div class="shpd-form-component" class:shpd-form-component--hidden={element.hidden}>
    [{element.component_name}]
  </div>

{:else if element.type === 'input' && element.input_type === 'checkbox'}
  <div class="shpd-form-checkbox-row" class:shpd-form-checkbox-row--hidden={element.hidden}>
    <Checkbox label={element.label} bind:checked={formData[element.column]} disabled={elDisabled} />
  </div>

{:else}
  <FormFieldRow {element} id={inputId}>
    {#if element.type === 'select'}
      <Select id={inputId} bind:value={formData[element.column]} options={element.options ?? []} required={element.required ?? false} disabled={elDisabled} {error} onchange={handleChange} />

    {:else if element.input_type === 'textarea'}
      <TextArea id={inputId} bind:value={formData[element.column]} required={element.required ?? false} disabled={elDisabled} {error} />

    {:else if element.input_type === 'date'}
      <DateInput id={inputId} bind:value={formData[element.column]} required={element.required ?? false} disabled={elDisabled} {error} />

    {:else if element.input_type === 'datetime'}
      <Input id={inputId} type="datetime-local" bind:value={formData[element.column]} required={element.required ?? false} disabled={elDisabled} {error} />

    {:else if element.input_type === 'time'}
      <Input id={inputId} type="time" bind:value={formData[element.column]} required={element.required ?? false} disabled={elDisabled} {error} />

    {:else if element.input_type === 'number'}
      <NumberInput id={inputId} bind:value={formData[element.column]} required={element.required ?? false} disabled={elDisabled} {error} />

    {:else}
      <Input id={inputId} type={element.input_type ?? 'text'} bind:value={formData[element.column]} placeholder={element.placeholder} required={element.required ?? false} disabled={elDisabled} {error} />
    {/if}
  </FormFieldRow>
{/if}

<style>
  /*
   * separator / inline / html / component / checkbox-row span both grid tracks
   * of the parent FormColumn (label + input). FormInline is the exception —
   * its outer label IS the first track, so it does NOT span.
   */
  .shpd-form-separator,
  .shpd-form-html,
  .shpd-form-component,
  .shpd-form-checkbox-row {
    grid-column: 1 / -1;
  }

  .shpd-form-separator {
    display: flex;
    align-items: center;
    gap: var(--shpd-space-sm);
    padding: var(--shpd-space-xs) 0 0 0;
    margin-top: var(--shpd-space-sm);
  }
  .shpd-form-separator::after {
    content: '';
    flex: 1;
    height: 1px;
    background: var(--shpd-color-border);
  }
  .shpd-form-separator__text {
    font-size: var(--shpd-font-size-sm);
    font-weight: 600;
    color: var(--shpd-color-text-secondary);
    white-space: nowrap;
  }

  .shpd-form-separator--hidden,
  .shpd-form-html--hidden,
  .shpd-form-component--hidden,
  .shpd-form-checkbox-row--hidden {
    display: none;
  }

  .shpd-form-html {
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text);
  }

  .shpd-form-component {
    font-style: italic;
    color: var(--shpd-color-text-secondary);
  }

  .shpd-form-checkbox-row {
    padding: var(--shpd-space-xs) 0;
  }
</style>
