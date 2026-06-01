<script>
  import Icon from '../ui/Icon.svelte';
  import { iconMenu } from '../../icons.js';
  import { layoutStore } from '../../stores/layout.svelte.js';
  import { navigationStore } from '../../stores/navigation.svelte.js';
  import { t } from '../../i18n/index.js';

  // Titul = label aktuální položky navigace. Fallback prázdný řetězec.
  // Pozn.: viewer navigovaný přes navigateToViewer má label == viewerId
  // (technický string) — akceptováno pro fázi 1, viz task out-of-scope.
  let title = $derived(navigationStore.activeItem?.label ?? '');
</script>

<header class="shpd-topbar">
  <button
    class="shpd-topbar__menu-btn"
    onclick={() => layoutStore.toggleDrawer()}
    aria-label={t('app.menu.open')}
  >
    <Icon icon={iconMenu} size="md" />
  </button>

  <span class="shpd-topbar__title">{title}</span>

  <!-- Slot pro budoucí akce (Přidat / Otevřít). Zatím prázdný placeholder,
       drží symetrii layoutu (titul je vystředěný mezi hamburgerem a slotem).
       Naplní se ve fázi vieweru. -->
  <div class="shpd-topbar__actions"></div>
</header>

<style>
  .shpd-topbar {
    display: flex;
    align-items: center;
    gap: var(--shpd-space-sm);
    height: var(--shpd-header-height);
    padding: 0 var(--shpd-space-sm);
    flex-shrink: 0;
    background-color: var(--shpd-color-bg-sidebar);
    color: var(--shpd-color-text-sidebar);
    border-bottom: 1px solid var(--shpd-color-bg-sidebar-border);
  }

  .shpd-topbar__menu-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 40px;
    height: 40px;
    flex-shrink: 0;
    color: var(--shpd-color-text-sidebar);
    background: transparent;
    border: none;
    border-radius: var(--shpd-radius-sm);
    cursor: pointer;
    transition: background-color 0.15s;
  }

  .shpd-topbar__menu-btn:hover {
    background-color: var(--shpd-color-bg-sidebar-hover);
  }

  .shpd-topbar__title {
    flex: 1;
    font-size: var(--shpd-font-size-base);
    font-weight: 600;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  /* Slot pro akce — drží šířku ~40px, aby titul zůstal opticky vystředěný
     vůči hamburgeru. Až se naplní, šířka se přizpůsobí obsahu. */
  .shpd-topbar__actions {
    display: flex;
    align-items: center;
    gap: var(--shpd-space-xs);
    flex-shrink: 0;
    min-width: 40px;
    justify-content: flex-end;
  }
</style>
