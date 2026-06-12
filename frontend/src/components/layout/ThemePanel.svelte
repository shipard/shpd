<script>
  // Panel custom vzhledu — otevírá se z dropdownu v patce sidebaru
  // (volba Vlastní). Každá interakce volá themeStore.setCustom(),
  // tokeny se aplikují okamžitě (live preview); persistence je vedlejší
  // efekt — žádné tlačítko Uložit/Použít.
  //
  // Desktop: fixed panel přilehlý k sidebaru (pozice reaguje na
  // collapsed stav). Mobil: strukturní přepnutí — obsah se renderuje
  // uvnitř <Modal> (fullscreen automaticky). Stejný vzor jako
  // FormInline / FormStateBar.
  import { themeStore } from '../../stores/theme.svelte.js';
  import { layoutStore } from '../../stores/layout.svelte.js';
  import { t } from '../../i18n/index.js';
  import { THEME_PRESETS } from './themePresets.js';
  import Modal from '../ui/Modal.svelte';
  import Icon from '../ui/Icon.svelte';
  import { iconClose } from '../../icons.js';

  let { open, onClose, collapsed = false } = $props();

  let panelRoot = $state(null);

  function isSelected(preset) {
    return preset.color.toLowerCase() === themeStore.custom.sidebar.color.toLowerCase();
  }

  function selectBase(base) {
    themeStore.setCustom({ base });
  }

  function selectColor(color) {
    themeStore.setCustom({ sidebar: { type: 'solid', color } });
  }

  // Zavírání na desktopu: Esc + klik mimo panel. Listener se registruje
  // až po otevření ($effect po renderu) — otevírací klik z dropdownu už
  // dobublal, takže panel hned nezavře (viz past v docs/frontend.md
  // sekce Konvence → Dropdown / popover komponenty).
  $effect(() => {
    if (!open || layoutStore.isMobile) return;

    function onDocClick(e) {
      if (panelRoot && !panelRoot.contains(e.target)) {
        onClose();
      }
    }
    function onKeyDown(e) {
      if (e.key === 'Escape') onClose();
    }

    document.addEventListener('click', onDocClick);
    document.addEventListener('keydown', onKeyDown);
    return () => {
      document.removeEventListener('click', onDocClick);
      document.removeEventListener('keydown', onKeyDown);
    };
  });
</script>

{#snippet panelContent()}
  <div class="shpd-theme-panel__section">
    <div class="shpd-theme-panel__label">{t('theme.panel.base')}</div>
    <div class="shpd-theme-panel__base-toggle" role="radiogroup" aria-label={t('theme.panel.base')}>
      <button
        class="shpd-theme-panel__base-button"
        class:shpd-theme-panel__base-button--active={themeStore.custom.base === 'light'}
        onclick={() => selectBase('light')}
        role="radio"
        aria-checked={themeStore.custom.base === 'light'}
      >{t('theme.base.light')}</button>
      <button
        class="shpd-theme-panel__base-button"
        class:shpd-theme-panel__base-button--active={themeStore.custom.base === 'dark'}
        onclick={() => selectBase('dark')}
        role="radio"
        aria-checked={themeStore.custom.base === 'dark'}
      >{t('theme.base.dark')}</button>
    </div>
  </div>

  <div class="shpd-theme-panel__section">
    <div class="shpd-theme-panel__presets" role="listbox" aria-label={t('theme.panel.title')}>
      {#each THEME_PRESETS as preset (preset.id)}
        <button
          class="shpd-theme-panel__swatch"
          class:shpd-theme-panel__swatch--selected={isSelected(preset)}
          style="background: {preset.color}"
          onclick={() => selectColor(preset.color)}
          title={t(preset.nameKey)}
          aria-label={t(preset.nameKey)}
          role="option"
          aria-selected={isSelected(preset)}
        ></button>
      {/each}
    </div>
  </div>

  <div class="shpd-theme-panel__section">
    <label class="shpd-theme-panel__label" for="shpd-theme-custom-color">
      {t('theme.panel.customColor')}
    </label>
    <!-- oninput (ne jen onchange) — live preview už při tažení v pickeru -->
    <input
      id="shpd-theme-custom-color"
      class="shpd-theme-panel__color-input"
      type="color"
      value={themeStore.custom.sidebar.color}
      oninput={(e) => selectColor(e.target.value)}
    />
  </div>
{/snippet}

{#if layoutStore.isMobile}
  <Modal title={t('theme.panel.title')} {open} {onClose}>
    <div class="shpd-theme-panel__modal-body">
      {@render panelContent()}
    </div>
  </Modal>
{:else if open}
  <div
    class="shpd-theme-panel"
    class:shpd-theme-panel--collapsed={collapsed}
    bind:this={panelRoot}
    role="dialog"
    aria-label={t('theme.panel.title')}
  >
    <div class="shpd-theme-panel__header">
      <span class="shpd-theme-panel__title">{t('theme.panel.title')}</span>
      <button class="shpd-theme-panel__close" onclick={onClose} aria-label={t('common.close')}>
        <Icon icon={iconClose} size="sm" />
      </button>
    </div>
    {@render panelContent()}
  </div>
{/if}

<style>
  .shpd-theme-panel {
    position: fixed;
    top: 64px;
    left: calc(var(--shpd-sidebar-width) + var(--shpd-space-sm));
    width: 300px;
    background: var(--shpd-color-bg);
    border: 1px solid var(--shpd-color-border);
    border-radius: var(--shpd-radius-lg);
    box-shadow: var(--shpd-shadow-lg);
    /* Stejná vrstva jako user menu dropdown v sidebaru — nad obsahem,
       pod modály (1000). */
    z-index: 200;
    padding: var(--shpd-space-md);
  }

  .shpd-theme-panel--collapsed {
    left: calc(var(--shpd-sidebar-width-collapsed) + var(--shpd-space-sm));
  }

  .shpd-theme-panel__header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: var(--shpd-space-md);
  }

  .shpd-theme-panel__title {
    font-size: var(--shpd-font-size-base);
    font-weight: 600;
    color: var(--shpd-color-text);
  }

  .shpd-theme-panel__close {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    padding: 0;
    border-radius: var(--shpd-radius-sm);
    color: var(--shpd-color-text-secondary);
    transition: background-color 0.15s, color 0.15s;
  }

  .shpd-theme-panel__close:hover {
    background-color: var(--shpd-color-bg-hover);
    color: var(--shpd-color-text);
  }

  .shpd-theme-panel__section {
    margin-bottom: var(--shpd-space-md);
  }

  .shpd-theme-panel__section:last-child {
    margin-bottom: 0;
  }

  .shpd-theme-panel__label {
    display: block;
    margin-bottom: var(--shpd-space-xs);
    font-size: var(--shpd-font-size-sm);
    font-weight: 500;
    color: var(--shpd-color-text-secondary);
  }

  /* Segmented control — dvě toggle tlačítka vedle sebe. */
  .shpd-theme-panel__base-toggle {
    display: flex;
    gap: var(--shpd-space-xs);
  }

  .shpd-theme-panel__base-button {
    flex: 1;
    padding: var(--shpd-space-xs) var(--shpd-space-sm);
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text-secondary);
    background: var(--shpd-color-bg-secondary);
    border: 1px solid var(--shpd-color-border);
    border-radius: var(--shpd-radius-sm);
    cursor: pointer;
    transition: background-color 0.15s, color 0.15s, border-color 0.15s;
  }

  .shpd-theme-panel__base-button--active {
    color: var(--shpd-color-bg);
    background: var(--shpd-color-primary);
    border-color: var(--shpd-color-primary);
    font-weight: 500;
  }

  .shpd-theme-panel__presets {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: var(--shpd-space-sm);
    justify-items: center;
  }

  .shpd-theme-panel__swatch {
    width: 36px;
    height: 36px;
    padding: 0;
    border: 1px solid color-mix(in srgb, var(--shpd-color-text) 25%, transparent);
    border-radius: 50%;
    cursor: pointer;
    transition: border-color 0.15s;
  }

  .shpd-theme-panel__swatch:hover {
    border-color: var(--shpd-color-text-secondary);
  }

  .shpd-theme-panel__swatch--selected {
    outline: 2px solid var(--shpd-color-accent);
    outline-offset: 2px;
  }

  .shpd-theme-panel__color-input {
    width: 100%;
    height: 36px;
    padding: 2px;
    background: var(--shpd-color-bg);
    border: 1px solid var(--shpd-color-border);
    border-radius: var(--shpd-radius-sm);
    cursor: pointer;
  }

  .shpd-theme-panel__modal-body {
    padding: var(--shpd-space-md) var(--shpd-space-lg);
  }
</style>
