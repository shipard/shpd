<script>
  // Segmented control volby shellu — bezstavový, dostane aktuální
  // hodnotu, seznam options [{value, labelKey}] a callback. Sdílí ho
  // ShellField (user, vázaný na shellStore, se segmentem „Podle
  // aplikace") i DsShellField (DS default, controlled, jen jména
  // shellů) — vzor ThemeModeSegments.
  import { t } from '../../i18n/index.js';
  import Icon from '../ui/Icon.svelte';
  import { iconConfirm } from '../../icons.js';

  let { value, options = [], onSelect } = $props();
</script>

<div class="shpd-shell-segments" role="radiogroup">
  {#each options as opt (opt.value)}
    <button
      type="button"
      class="shpd-shell-segments__segment"
      class:shpd-shell-segments__segment--active={value === opt.value}
      role="radio"
      aria-checked={value === opt.value}
      onclick={() => onSelect?.(opt.value)}
    >
      <span>{t(opt.labelKey)}</span>
      {#if value === opt.value}
        <span class="shpd-shell-segments__check"><Icon icon={iconConfirm} size="xs" /></span>
      {/if}
    </button>
  {/each}
</div>

<style>
  .shpd-shell-segments {
    display: inline-flex;
    gap: var(--shpd-space-xs);
    flex-wrap: wrap;
  }

  .shpd-shell-segments__segment {
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

  .shpd-shell-segments__segment:hover {
    background-color: var(--shpd-color-bg-secondary);
  }

  .shpd-shell-segments__segment--active {
    border-color: var(--shpd-color-accent);
    background-color: var(--shpd-color-bg-secondary);
    font-weight: 500;
  }

  .shpd-shell-segments__check {
    display: inline-flex;
    align-items: center;
    color: var(--shpd-color-accent);
  }
</style>
