<script>
  import Input from '../ui/Input.svelte';
  import TextArea from '../ui/TextArea.svelte';
  import Checkbox from '../ui/Checkbox.svelte';
  import Select from '../ui/Select.svelte';
  import NumberInput from '../ui/NumberInput.svelte';
  import DateInput from '../ui/DateInput.svelte';
  import FormSubTable from './FormSubTable.svelte';
  import Self from './FormElement.svelte';

  let {
    element,
    formData = $bindable({}),
    fieldErrors = {},
    disabled = false,
    onTrigger,
    parentId = null,
  } = $props();

  const error = $derived(element.column ? (fieldErrors[element.column] ?? null) : null);
  const isHidden = $derived(element.hidden === true);
  const isFullSpan = $derived(
    (element.type === 'separator' || element.type === 'group') && element.cols >= 4
  );

  const colsClass = $derived(`shpd-form-el--cols-${element.cols}`);

  const elDisabled = $derived(disabled || element.read_only === true);

  function handleChange() {
    if (element.triggers === 'reload' && element.column) {
      onTrigger?.(element.column);
    }
  }
</script>

<div
  class="shpd-form-el {colsClass}"
  class:shpd-form-el--full-span={isFullSpan}
  style:display={isHidden ? 'none' : undefined}
>
  {#if element.type === 'separator'}
    <div class="shpd-form-separator">
      {#if element.label}
        <span class="shpd-form-separator__text">{element.label}</span>
      {/if}
    </div>

  {:else if element.type === 'html'}
    <div class="shpd-form-html">{@html element.content}</div>

  {:else if element.type === 'group'}
    {#if element.label}
      <div class="shpd-form-group__label">{element.label}</div>
    {/if}
    <div class="shpd-form-group__grid">
      {#each element.elements ?? [] as child, i (child.column ?? `${child.type}-${i}`)}
        <Self
          element={child}
          bind:formData
          {fieldErrors}
          disabled={elDisabled}
          {onTrigger}
          {parentId}
        />
      {/each}
    </div>

  {:else if element.type === 'subtable'}
    <FormSubTable
      {element}
      {parentId}
      disabled={elDisabled}
    />

  {:else if element.type === 'select'}
    <Select
      label={element.label}
      bind:value={formData[element.column]}
      options={element.options ?? []}
      required={element.required ?? false}
      disabled={elDisabled}
      {error}
      onchange={handleChange}
    />

  {:else if element.type === 'input'}
    {#if element.input_type === 'checkbox'}
      <Checkbox
        label={element.label}
        bind:checked={formData[element.column]}
        disabled={elDisabled}
      />

    {:else if element.input_type === 'date'}
      <DateInput
        label={element.label}
        bind:value={formData[element.column]}
        required={element.required ?? false}
        disabled={elDisabled}
        {error}
      />

    {:else if element.input_type === 'datetime'}
      <Input
        label={element.label}
        type="datetime-local"
        bind:value={formData[element.column]}
        required={element.required ?? false}
        disabled={elDisabled}
        {error}
      />

    {:else if element.input_type === 'time'}
      <Input
        label={element.label}
        type="time"
        bind:value={formData[element.column]}
        required={element.required ?? false}
        disabled={elDisabled}
        {error}
      />

    {:else if element.input_type === 'textarea'}
      <TextArea
        label={element.label}
        bind:value={formData[element.column]}
        required={element.required ?? false}
        disabled={elDisabled}
        {error}
      />

    {:else if element.input_type === 'number'}
      <NumberInput
        label={element.label}
        bind:value={formData[element.column]}
        required={element.required ?? false}
        disabled={elDisabled}
        {error}
      />

    {:else if element.input_type === 'email'}
      <Input
        label={element.label}
        type="email"
        bind:value={formData[element.column]}
        placeholder={element.placeholder}
        required={element.required ?? false}
        disabled={elDisabled}
        {error}
      />

    {:else if element.input_type === 'tel'}
      <Input
        label={element.label}
        type="tel"
        bind:value={formData[element.column]}
        placeholder={element.placeholder}
        required={element.required ?? false}
        disabled={elDisabled}
        {error}
      />

    {:else if element.input_type === 'url'}
      <Input
        label={element.label}
        type="url"
        bind:value={formData[element.column]}
        placeholder={element.placeholder}
        required={element.required ?? false}
        disabled={elDisabled}
        {error}
      />

    {:else if element.input_type === 'password'}
      <Input
        label={element.label}
        type="password"
        bind:value={formData[element.column]}
        placeholder={element.placeholder}
        required={element.required ?? false}
        disabled={elDisabled}
        {error}
      />

    {:else}
      <Input
        label={element.label}
        type="text"
        bind:value={formData[element.column]}
        placeholder={element.placeholder}
        required={element.required ?? false}
        disabled={elDisabled}
        {error}
      />
    {/if}
  {/if}
</div>

<style>
  .shpd-form-el {
    min-width: 0;
  }

  .shpd-form-el--cols-1 { grid-column: span 1; }
  .shpd-form-el--cols-2 { grid-column: span 2; }
  .shpd-form-el--cols-3 { grid-column: span 3; }
  .shpd-form-el--cols-4 { grid-column: span 4; }
  .shpd-form-el--full-span { grid-column: 1 / -1; }

  @media (max-width: 899px) {
    .shpd-form-el--cols-3,
    .shpd-form-el--cols-4 { grid-column: 1 / -1; }
  }

  @media (max-width: 599px) {
    .shpd-form-el--cols-1,
    .shpd-form-el--cols-2,
    .shpd-form-el--cols-3,
    .shpd-form-el--cols-4 { grid-column: 1 / -1; }
  }

  .shpd-form-separator {
    grid-column: 1 / -1;
    display: flex;
    align-items: center;
    gap: var(--shpd-space-sm);
    padding: var(--shpd-space-sm) 0;
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

  .shpd-form-group__label {
    font-size: var(--shpd-font-size-sm);
    font-weight: 600;
    color: var(--shpd-color-text-secondary);
    margin-bottom: var(--shpd-space-sm);
  }

  .shpd-form-group__grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: var(--shpd-space-md);
  }

  @media (max-width: 899px) {
    .shpd-form-group__grid { grid-template-columns: repeat(2, 1fr); }
  }
  @media (max-width: 599px) {
    .shpd-form-group__grid { grid-template-columns: 1fr; }
  }

  .shpd-form-html {
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text-secondary);
  }
</style>
