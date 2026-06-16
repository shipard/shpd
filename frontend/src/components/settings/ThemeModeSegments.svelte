<script>
  // Segmented control Shipard / Tmavý / Vlastní (mode light/dark/custom).
  // Bezstavový — dostane aktuální `mode` a callback `onSelect`. Sdílí ho
  // ThemeField (user, vázaný na themeStore) i DsThemeField (DS default,
  // controlled), aby vypadaly identicky.
  import { t } from '../../i18n/index.js';
  import Icon from '../ui/Icon.svelte';
  import { iconThemeLight, iconThemeDark, iconPalette, iconConfirm } from '../../icons.js';

  let { mode, onSelect } = $props();

  const options = [
    { value: 'light',  labelKey: 'sidebar.appearance.light',  icon: iconThemeLight },
    { value: 'dark',   labelKey: 'sidebar.appearance.dark',   icon: iconThemeDark },
    { value: 'custom', labelKey: 'sidebar.appearance.custom', icon: iconPalette },
  ];
</script>

<div class="shpd-theme-segments" role="radiogroup">
  {#each options as opt}
    <button
      type="button"
      class="shpd-theme-segments__segment"
      class:shpd-theme-segments__segment--active={mode === opt.value}
      role="radio"
      aria-checked={mode === opt.value}
      onclick={() => onSelect?.(opt.value)}
    >
      <Icon icon={opt.icon} size="sm" />
      <span>{t(opt.labelKey)}</span>
      {#if mode === opt.value}
        <span class="shpd-theme-segments__check"><Icon icon={iconConfirm} size="xs" /></span>
      {/if}
    </button>
  {/each}
</div>

<style>
  .shpd-theme-segments {
    display: inline-flex;
    gap: var(--shpd-space-xs);
    flex-wrap: wrap;
  }

  .shpd-theme-segments__segment {
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

  .shpd-theme-segments__segment:hover {
    background-color: var(--shpd-color-bg-secondary);
  }

  .shpd-theme-segments__segment--active {
    border-color: var(--shpd-color-accent);
    background-color: var(--shpd-color-bg-secondary);
    font-weight: 500;
  }

  .shpd-theme-segments__check {
    display: inline-flex;
    align-items: center;
    color: var(--shpd-color-accent);
  }
</style>
