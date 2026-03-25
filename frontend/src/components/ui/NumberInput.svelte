<script lang="ts">
  interface Props {
    value?: number | null;
    label?: string;
    required?: boolean;
    disabled?: boolean;
    error?: string | null;
    min?: number;
    max?: number;
    step?: number;
  }

  let {
    value = $bindable(null),
    label,
    required = false,
    disabled = false,
    error = null,
    min,
    max,
    step,
  }: Props = $props();

  const inputId = `shpd-input-${Math.random().toString(36).slice(2)}`;
</script>

<div class="shpd-input">
  {#if label}
    <label class="shpd-input__label" for={inputId}>
      {label}{#if required}<span class="shpd-input__required" aria-hidden="true">*</span>{/if}
    </label>
  {/if}
  <input
    id={inputId}
    class="shpd-input__field"
    class:shpd-input__field--error={!!error}
    type="number"
    bind:value
    {required}
    {disabled}
    {min}
    {max}
    {step}
  />
  {#if error}
    <span class="shpd-input__error">{error}</span>
  {/if}
</div>

<style>
  .shpd-input {
    display: flex;
    flex-direction: column;
    width: 100%;
    margin-bottom: var(--shpd-space-md);
  }

  .shpd-input__label {
    font-size: var(--shpd-font-size-sm);
    font-weight: 500;
    color: var(--shpd-color-text);
    margin-bottom: var(--shpd-space-sm);
  }

  .shpd-input__required {
    color: var(--shpd-color-danger);
    margin-left: 2px;
  }

  .shpd-input__field {
    width: 100%;
    padding: var(--shpd-space-sm);
    border: 1px solid var(--shpd-color-border);
    border-radius: var(--shpd-radius-md);
    font-size: var(--shpd-font-size-base);
    font-family: var(--shpd-font-family);
    color: var(--shpd-color-text);
    background-color: var(--shpd-color-bg);
    box-sizing: border-box;
    transition: border-color 0.15s ease;
  }

  .shpd-input__field:focus {
    outline: none;
    border-color: var(--shpd-color-border-focus);
    box-shadow: 0 0 0 2px rgba(37, 99, 235, 0.15);
  }

  .shpd-input__field--error {
    border-color: var(--shpd-color-danger);
  }

  .shpd-input__field--error:focus {
    box-shadow: 0 0 0 2px rgba(220, 38, 38, 0.15);
  }

  .shpd-input__field:disabled {
    opacity: 0.6;
    cursor: not-allowed;
    background-color: var(--shpd-color-bg-secondary);
  }

  .shpd-input__error {
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-danger);
    margin-top: var(--shpd-space-xs);
  }
</style>
