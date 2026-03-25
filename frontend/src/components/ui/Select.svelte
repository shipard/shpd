<script lang="ts">
  interface Option {
    value: string | number;
    label: string;
  }

  interface Props {
    value?: string | number | null;
    label?: string;
    options?: Option[];
    required?: boolean;
    disabled?: boolean;
    error?: string | null;
    placeholder?: string;
  }

  let {
    value = $bindable(null),
    label,
    options = [],
    required = false,
    disabled = false,
    error = null,
    placeholder,
  }: Props = $props();

  const inputId = `shpd-select-${Math.random().toString(36).slice(2)}`;
</script>

<div class="shpd-select">
  {#if label}
    <label class="shpd-select__label" for={inputId}>
      {label}{#if required}<span class="shpd-select__required" aria-hidden="true">*</span>{/if}
    </label>
  {/if}
  <div class="shpd-select__wrapper">
    <select
      id={inputId}
      class="shpd-select__field"
      class:shpd-select__field--error={!!error}
      bind:value
      {required}
      {disabled}
    >
      {#if !required || placeholder}
        <option value={null}>{placeholder ?? ''}</option>
      {/if}
      {#each options as option}
        <option value={option.value}>{option.label}</option>
      {/each}
    </select>
    <span class="shpd-select__arrow" aria-hidden="true">▾</span>
  </div>
  {#if error}
    <span class="shpd-select__error">{error}</span>
  {/if}
</div>

<style>
  .shpd-select {
    display: flex;
    flex-direction: column;
    width: 100%;
    margin-bottom: var(--shpd-space-md);
  }

  .shpd-select__label {
    font-size: var(--shpd-font-size-sm);
    font-weight: 500;
    color: var(--shpd-color-text);
    margin-bottom: var(--shpd-space-sm);
  }

  .shpd-select__required {
    color: var(--shpd-color-danger);
    margin-left: 2px;
  }

  .shpd-select__wrapper {
    position: relative;
    width: 100%;
  }

  .shpd-select__field {
    width: 100%;
    padding: var(--shpd-space-sm);
    padding-right: calc(var(--shpd-space-lg) + var(--shpd-space-sm));
    border: 1px solid var(--shpd-color-border);
    border-radius: var(--shpd-radius-md);
    font-size: var(--shpd-font-size-base);
    font-family: var(--shpd-font-family);
    color: var(--shpd-color-text);
    background-color: var(--shpd-color-bg);
    box-sizing: border-box;
    appearance: none;
    -webkit-appearance: none;
    cursor: pointer;
    transition: border-color 0.15s ease;
  }

  .shpd-select__field:focus {
    outline: none;
    border-color: var(--shpd-color-border-focus);
    box-shadow: 0 0 0 2px rgba(37, 99, 235, 0.15);
  }

  .shpd-select__field--error {
    border-color: var(--shpd-color-danger);
  }

  .shpd-select__field--error:focus {
    box-shadow: 0 0 0 2px rgba(220, 38, 38, 0.15);
  }

  .shpd-select__field:disabled {
    opacity: 0.6;
    cursor: not-allowed;
    background-color: var(--shpd-color-bg-secondary);
  }

  .shpd-select__arrow {
    position: absolute;
    right: var(--shpd-space-sm);
    top: 50%;
    transform: translateY(-50%);
    pointer-events: none;
    color: var(--shpd-color-text-secondary);
    font-size: var(--shpd-font-size-sm);
  }

  .shpd-select__error {
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-danger);
    margin-top: var(--shpd-space-xs);
  }
</style>
