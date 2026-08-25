<script>
  // Sidebar shell — kompozice chrome primitiv (components/chrome/):
  // BrandingHeader + collapse toggle, ModeBackBar (settings/account),
  // NavTree / NavIconStrip dle `collapsed`, UserMenu v patce.
  // Vlastní zůstává: fetch navigace per mode, collapsed stav, loading/error.
  import { get } from '../../api/client.js';
  import { logout } from '../../api/auth.js';
  import { authStore } from '../../stores/auth.svelte.js';
  import { navigationStore } from '../../stores/navigation.svelte.js';
  import { layoutStore } from '../../stores/layout.svelte.js';
  import { t } from '../../i18n/index.js';
  import Icon from '../ui/Icon.svelte';
  import BrandingHeader from '../chrome/BrandingHeader.svelte';
  import ModeBackBar from '../chrome/ModeBackBar.svelte';
  import NavTree from '../chrome/NavTree.svelte';
  import NavIconStrip from '../chrome/NavIconStrip.svelte';
  import UserMenu from '../chrome/UserMenu.svelte';
  import {
    iconCollapse,
    iconExpand,
    iconClose,
    iconSpinner,
    iconWarning,
  } from '../../icons.js';

  // `collapsed` je bindable — AppShell ho zrcadlí kvůli pozici ThemePanel
  // (panel se renderuje v AppShellu, ne tady; drawer má transform, který
  // by position:fixed panel uvěznil). Panel custom vzhledu už neotevírá
  // sidebar (dropdown vzhledu zanikl) — otevírá ho ThemeField na stránce
  // Nastavení účtu → Základní.
  let { onNavigate, onLogout, collapsed = $bindable(false) } = $props();

  // Navigation tree loaded from server API — reloads when mode changes
  let navTree = $state([]);
  let loading = $state(true);
  let error   = $state(null);

  $effect(() => {
    const url = navigationStore.mode === 'settings'
      ? '/_ui/settings/navigation'
      : navigationStore.mode === 'account'
        ? '/_ui/account/navigation'
        : '/_ui/navigation';

    loading = true;
    error   = null;
    navTree = [];

    (async () => {
      try {
        const response = await get(url);
        if (response === null) { error = t('sidebar.notAuthenticated'); return; }
        if (!response.success) { error = response.error?.message ?? t('sidebar.navigationLoadFailed'); return; }
        navTree = response.data;
        // Po loadu app navigace (ne settings) zajistíme, že je něco vybráno —
        // deep-link reportu má přednost (no-op bez stashe), jinak první
        // root-level leaf stromu (na hosting DS portál, jinde Dashboard, D6).
        if (navigationStore.mode === 'app') {
          navigationStore.activateReportDeepLink(navTree);
          navigationStore.ensureDefaultActiveItem(navTree);
        }
      } catch {
        error = t('sidebar.navigationLoadFailed');
      } finally {
        loading = false;
      }
    })();
  });

  function handleItemClick(item) {
    const navItem = { id: item.id, label: item.label, type: item.type, table: item.table, viewerId: item.viewerId, pageId: item.pageId, panelId: item.panelId, panelParams: item.panelParams, filter: item.filter, fixedViewGroup: item.fixedViewGroup };
    navigationStore.navigate(navItem);
    onNavigate?.(navItem);
  }

  async function handleLogout() {
    try {
      await logout();
    } catch (err) {
      console.warn('Logout API call failed (continuing):', err);
    }
    authStore.clearAuth();
    onLogout?.();
  }

  function toggleCollapse() {
    collapsed = !collapsed;
  }
</script>

<nav
  class="shpd-sidebar"
  class:shpd-sidebar--collapsed={collapsed}
>
  <div class="shpd-sidebar__header">
    {#if !collapsed || layoutStore.isMobile}
      <BrandingHeader />
    {/if}
    {#if layoutStore.isMobile}
      <!-- Na mobilu nahrazuje collapse toggle ✕, které zavře drawer.
           Collapse nedává v draweru smysl (je buď otevřený, nebo zavřený). -->
      <button
        class="shpd-sidebar__toggle"
        onclick={() => layoutStore.closeDrawer()}
        aria-label={t('app.menu.close')}
      >
        <Icon icon={iconClose} size="sm" />
      </button>
    {:else}
      <button class="shpd-sidebar__toggle" onclick={toggleCollapse} title={collapsed ? t('sidebar.expand') : t('sidebar.collapse')}>
        <Icon icon={collapsed ? iconExpand : iconCollapse} size="sm" />
      </button>
    {/if}
  </div>

  {#if navigationStore.mode !== 'app'}
    <ModeBackBar compact={collapsed} />
  {/if}

  <div class="shpd-sidebar__nav">
    {#if loading}
      <div class="shpd-sidebar__status">
        <Icon icon={iconSpinner} spin size="sm" />
        {#if !collapsed}<span>{t('common.loading')}</span>{/if}
      </div>
    {:else if error}
      <div class="shpd-sidebar__status shpd-sidebar__status--error">
        {#if collapsed}
          <Icon icon={iconWarning} size="sm" />
        {:else}
          {error}
        {/if}
      </div>
    {:else if collapsed}
      <NavIconStrip tree={navTree} activeId={navigationStore.activeId} onNavigate={handleItemClick} />
    {:else}
      <NavTree tree={navTree} activeId={navigationStore.activeId} onNavigate={handleItemClick} />
    {/if}
  </div>

  <UserMenu compact={collapsed} onLogout={handleLogout} />
</nav>

<style>
  .shpd-sidebar {
    display: flex;
    flex-direction: column;
    width: var(--shpd-sidebar-width);
    height: 100%;
    /* Shorthand kvůli custom gradientu (--shpd-sidebar-bg-image je
       image, ne barva) — token existuje jen jako inline property
       z theme storu, jinak platí fallback na solid barvu. */
    background: var(--shpd-sidebar-bg-image, var(--shpd-color-bg-sidebar));
    color: var(--shpd-color-text-sidebar);
    flex-shrink: 0;
    transition: width 0.2s ease;
    overflow: hidden;
  }

  .shpd-sidebar--collapsed {
    width: var(--shpd-sidebar-width-collapsed);
    /* Ve sbaleném stavu povolíme overflow, aby user-menu dropdown
       (side varianta v UserMenu) mohl vyčnívat doprava ven ze
       48px proužku. V rozbaleném stavu zůstává `overflow: hidden`
       z `.shpd-sidebar` kvůli skrývání labelů během toggle transition. */
    overflow: visible;
  }

  .shpd-sidebar__header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    height: var(--shpd-header-height);
    padding: 0 var(--shpd-space-md);
    flex-shrink: 0;
    border-bottom: 1px solid var(--shpd-color-bg-sidebar-border);
  }

  .shpd-sidebar--collapsed .shpd-sidebar__header {
    justify-content: center;
    padding: 0;
  }

  .shpd-sidebar__nav {
    flex: 1;
    overflow-y: auto;
    min-height: 0;
  }

  .shpd-sidebar__toggle {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    color: var(--shpd-color-text-sidebar-muted);
    border-radius: var(--shpd-radius-sm);
    transition: color 0.15s, background-color 0.15s;
    flex-shrink: 0;
  }

  .shpd-sidebar__toggle:hover {
    color: var(--shpd-color-text-sidebar);
    background-color: var(--shpd-color-bg-sidebar-hover);
  }

  .shpd-sidebar__status {
    display: flex;
    align-items: center;
    gap: var(--shpd-space-sm);
    padding: var(--shpd-space-md);
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text-sidebar-muted);
  }

  .shpd-sidebar__status--error {
    color: var(--shpd-color-danger, #ef4444);
  }
</style>
