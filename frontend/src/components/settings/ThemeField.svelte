<script>
  // Řízený widget vzhledu pro settings page (field type `theme`). Vázaný
  // na themeStore — segmented control Shipard/Tmavý/Vlastní mění mód okamžitě
  // (live preview + server sync přes store). U „Vlastní" tlačítko otevře
  // ThemePanel (vlastněný AppShellem) přes onOpenThemePanel callback.
  //
  // Čte store reaktivně, takže zůstává v synchronu s dropdownem v patce
  // sidebaru i s panelem — jedna pravda ve storu.
  import { themeStore } from '../../stores/theme.svelte.js';
  import { t } from '../../i18n/index.js';
  import Icon from '../ui/Icon.svelte';
  import { iconThemeLight, iconThemeDark, iconPalette, iconConfirm } from '../../icons.js';

  let { onOpenThemePanel } = $props();

  const options = [
    { value: 'light',  labelKey: 'sidebar.appearance.light',  icon: iconThemeLight },
    { value: 'dark',   labelKey: 'sidebar.appearance.dark',   icon: iconThemeDark },
    { value: 'custom', labelKey: 'sidebar.appearance.custom', icon: iconPalette },
  ];

  // Otevření panelu deferujeme za aktuální klik (setTimeout 0). ThemePanel
  // si na desktopu po otevření přidá document click listener; bez deferu by
  // tentýž klik probublal na document a panel hned zavřel. Mobil (Modal)
  // listener nemá, ale defer nevadí. Stejný vzor jako Sidebar dropdown.
  function openPanel() {
    setTimeout(() => { onOpenThemePanel?.(); }, 0);
  }

  function select(value) {
    themeStore.setMode(value);
    if (value === 'custom') {
      openPanel();
    }
  }
</script>

<div class="shpd-theme-field">
  <div class="shpd-theme-field__segments" role="radiogroup">
    {#each options as opt}
      <button
        type="button"
        class="shpd-theme-field__segment"
        class:shpd-theme-field__segment--active={themeStore.mode === opt.value}
        role="radio"
        aria-checked={themeStore.mode === opt.value}
        onclick={() => select(opt.value)}
      >
        <Icon icon={opt.icon} size="sm" />
        <span>{t(opt.labelKey)}</span>
        {#if themeStore.mode === opt.value}
          <span class="shpd-theme-field__check"><Icon icon={iconConfirm} size="xs" /></span>
        {/if}
      </button>
    {/each}
  </div>

  {#if themeStore.mode === 'custom'}
    <button type="button" class="shpd-theme-field__edit" onclick={openPanel}>
      <Icon icon={iconPalette} size="sm" />
      <span>{t('account.theme.editColor')}</span>
    </button>
  {/if}
</div>

<style>
  .shpd-theme-field {
    display: flex;
    flex-direction: column;
    gap: var(--shpd-space-sm);
  }

  .shpd-theme-field__segments {
    display: inline-flex;
    gap: var(--shpd-space-xs);
    flex-wrap: wrap;
  }

  .shpd-theme-field__segment {
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

  .shpd-theme-field__segment:hover {
    background-color: var(--shpd-color-bg-secondary);
  }

  .shpd-theme-field__segment--active {
    border-color: var(--shpd-color-accent);
    background-color: var(--shpd-color-bg-secondary);
    font-weight: 500;
  }

  .shpd-theme-field__check {
    display: inline-flex;
    align-items: center;
    color: var(--shpd-color-accent);
  }

  .shpd-theme-field__edit {
    display: inline-flex;
    align-items: center;
    gap: var(--shpd-space-xs);
    align-self: flex-start;
    padding: var(--shpd-space-xs) var(--shpd-space-md);
    background: transparent;
    border: 1px solid var(--shpd-color-border);
    border-radius: var(--shpd-radius-md);
    color: var(--shpd-color-text);
    font-size: var(--shpd-font-size-sm);
    cursor: pointer;
    transition: background-color 0.12s;
  }

  .shpd-theme-field__edit:hover {
    background-color: var(--shpd-color-bg-secondary);
  }
</style>
