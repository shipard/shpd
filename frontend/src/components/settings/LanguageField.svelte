<script>
  // Řízený widget jazyka pro settings page (field type `language`). Vázaný
  // na language store — klik volá language.setMode(), který zapíše volbu na
  // server (per-user) a reloadne stránku (jazyk se aplikuje přes bootstrap).
  import { language, t } from '../../i18n/index.js';
  import Icon from '../ui/Icon.svelte';
  import { iconConfirm } from '../../icons.js';

  const options = [
    { value: 'cs',   labelKey: 'sidebar.language.cs' },
    { value: 'en',   labelKey: 'sidebar.language.en' },
    { value: 'auto', labelKey: 'sidebar.language.auto' },
  ];

  function select(value) {
    language.setMode(value);
  }
</script>

<div class="shpd-language-field" role="radiogroup">
  {#each options as opt}
    <button
      type="button"
      class="shpd-language-field__segment"
      class:shpd-language-field__segment--active={language.mode === opt.value}
      role="radio"
      aria-checked={language.mode === opt.value}
      onclick={() => select(opt.value)}
    >
      <span>{t(opt.labelKey)}</span>
      {#if language.mode === opt.value}
        <span class="shpd-language-field__check"><Icon icon={iconConfirm} size="xs" /></span>
      {/if}
    </button>
  {/each}
</div>

<style>
  .shpd-language-field {
    display: inline-flex;
    gap: var(--shpd-space-xs);
    flex-wrap: wrap;
  }

  .shpd-language-field__segment {
    display: inline-flex;
    align-items: center;
    gap: var(--shpd-space-xs);
    padding: var(--shpd-space-xs) var(--shpd-space-md);
    background-color: var(--shpd-color-bg);
    border: 1px solid var(--shpd-color-border);
    border-radius: var(--shpd-radius-md);
    color: var(--shpd-color-text);
    font-size: var(--shpd-font-size-sm);
    cursor: pointer;
    transition: background-color 0.12s, border-color 0.12s;
  }

  .shpd-language-field__segment:hover {
    background-color: var(--shpd-color-bg-secondary);
  }

  .shpd-language-field__segment--active {
    border-color: var(--shpd-color-accent);
    background-color: var(--shpd-color-bg-secondary);
    font-weight: 500;
  }

  .shpd-language-field__check {
    display: inline-flex;
    align-items: center;
    color: var(--shpd-color-accent);
  }
</style>
