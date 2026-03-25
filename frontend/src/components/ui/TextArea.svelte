<script lang="ts">
  interface Props {
    value?: string;
    label?: string;
    placeholder?: string;
    required?: boolean;
    disabled?: boolean;
    error?: string | null;
    rows?: number;
  }

  let {
    value = $bindable(''),
    label,
    placeholder,
    required = false,
    disabled = false,
    error = null,
    rows = 4,
  }: Props = $props();

  const inputId = `shpd-textarea-${Math.random().toString(36).slice(2)}`;
</script>

<div class="shpd-textarea">
  {#if label}
    <label class="shpd-textarea__label" for={inputId}>
      {label}{#if required}<span class="shpd-textarea__required" aria-hidden="true">*</span>{/if}
    </label>
  {/if}
  <textarea
    id={inputId}
    class="shpd-textarea__field"
    class:shpd-textarea__field--error={!!error}
    bind:value
    {placeholder}
    {required}
    {disabled}
    {rows}
  ></textarea>
  {#if error}
    <span class="shpd-textarea__error">{error}</span>
  {/if}
</div>

<style>
  .shpd-textarea {
    display: flex;
    flex-direction: column;
    width: 100%;
    margin-bottom: var(--shpd-space-md);
  }

  .shpd-textarea__label {
    font-size: var(--shpd-font-size-sm);
    font-weight: 500;
    color: var(--shpd-color-text);
    margin-bottom: var(--shpd-space-sm);
  }

  .shpd-textarea__required {
    color: var(--shpd-color-danger);
    margin-left: 2px;
  }

  .shpd-textarea__field {
    width: 100%;
    padding: var(--shpd-space-sm);
    border: 1px solid var(--shpd-color-border);
    border-radius: var(--shpd-radius-md);
    font-size: var(--shpd-font-size-base);
    font-family: var(--shpd-font-family);
    color: var(--shpd-color-text);
    background-color: var(--shpd-color-bg);
    box-sizing: border-box;
    resize: vertical;
    transition: border-color 0.15s ease;
  }

  .shpd-textarea__field:focus {
    outline: none;
    border-color: var(--shpd-color-border-focus);
    box-shadow: 0 0 0 2px rgba(37, 99, 235, 0.15);
  }

  .shpd-textarea__field--error {
    border-color: var(--shpd-color-danger);
  }

  .shpd-textarea__field--error:focus {
    box-shadow: 0 0 0 2px rgba(220, 38, 38, 0.15);
  }

  .shpd-textarea__field:disabled {
    opacity: 0.6;
    cursor: not-allowed;
    background-color: var(--shpd-color-bg-secondary);
  }

  .shpd-textarea__error {
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-danger);
    margin-top: var(--shpd-space-xs);
  }
</style>
