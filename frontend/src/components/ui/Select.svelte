<script lang="ts">
  interface Option {
    value: string | number;
    label: string;
  }

  interface Props {
    id?: string;
    value?: string | number | null;
    options?: Option[];
    required?: boolean;
    disabled?: boolean;
    error?: string | null;
    placeholder?: string;
    onchange?: () => void;
  }

  let {
    id,
    value = $bindable(null),
    options = [],
    required = false,
    disabled = false,
    error = null,
    placeholder,
    onchange,
  }: Props = $props();
</script>

<div class="shpd-select__wrapper">
  <select
    {id}
    class="shpd-select__field"
    class:shpd-select__field--error={!!error}
    bind:value
    {required}
    {disabled}
    {onchange}
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

<style>
  .shpd-select__wrapper {
    position: relative;
    width: 100%;
  }

  .shpd-select__field {
    width: 100%;
    padding: var(--shpd-input-padding-y) var(--shpd-space-sm);
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
    box-shadow: 0 0 0 2px var(--shpd-color-focus-ring);
  }

  .shpd-select__field--error {
    border-color: var(--shpd-color-danger);
  }

  .shpd-select__field--error:focus {
    box-shadow: 0 0 0 2px var(--shpd-color-error-ring);
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
