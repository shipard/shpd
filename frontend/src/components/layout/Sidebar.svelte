<script>
  import { get } from '../../api/client.js';
  import { logout } from '../../api/auth.js';
  import { authStore } from '../../stores/auth.svelte.js';
  import { navigationStore } from '../../stores/navigation.svelte.js';
  import { layoutStore } from '../../stores/layout.svelte.js';
  import { appInfoStore } from '../../stores/appInfo.svelte.js';
  import { avatarStore } from '../../stores/avatar.svelte.js';
  import { brandingUrl } from '../../api/app.js';
  import { language, t } from '../../i18n/index.js';
  import Icon from '../ui/Icon.svelte';
  import {
    iconCollapse,
    iconExpand,
    iconClose,
    iconLogout,
    iconChevronDown,
    iconChevronUp,
    iconChevronRight,
    iconChevronLeft,
    iconSpinner,
    iconSettings,
    iconAppSettings,
    iconConfirm,
    iconWarning,
    resolveIcon,
  } from '../../icons.js';

  // Rekurzivně sebere všechny klikatelné leaves ze stromu navigace
  // v depth-first pořadí. Skupiny (bez `type`, jen s `children`) se
  // vynechají; jejich children se ploše zařadí do výsledku.
  function flattenLeaves(tree) {
    const leaves = [];
    for (const node of tree) {
      if (node.type) {
        leaves.push(node);
      } else if (node.children) {
        leaves.push(...flattenLeaves(node.children));
      }
    }
    return leaves;
  }

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

  // Plochý seznam klikatelných leaves pro sbalený stav sidebaru.
  let flatLeaves = $derived(flattenLeaves(navTree));

  // Track expanded state per group id
  let expanded = $state(new Set());

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
        // Expandnout jen položky, které mají `children`. Root-level leaf
        // (Dashboard) má `type` a žádné children — netřeba expandovat.
        expanded = new Set(navTree.filter(g => g.children).map(g => g.id));
        // Po loadu app navigace (ne settings) zajistíme, že je něco vybráno —
        // první root-level leaf stromu (na hosting DS portál, jinde
        // Dashboard, D6).
        if (navigationStore.mode === 'app') {
          navigationStore.ensureDefaultActiveItem(navTree);
        }
      } catch {
        error = t('sidebar.navigationLoadFailed');
      } finally {
        loading = false;
      }
    })();
  });

  function toggleGroup(id) {
    if (expanded.has(id)) {
      expanded.delete(id);
    } else {
      expanded.add(id);
    }
    // Trigger reactivity — reassign
    expanded = new Set(expanded);
  }

  function handleItemClick(item) {
    const navItem = { id: item.id, label: item.label, type: item.type, table: item.table, viewerId: item.viewerId, pageId: item.pageId, panelId: item.panelId, filter: item.filter, fixedViewGroup: item.fixedViewGroup };
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

  // --- User menu (dropdown nad uživatelským jménem v patce) ---
  let userMenuOpen = $state(false);
  let userMenuRoot = $state(null);

  function toggleUserMenu() {
    userMenuOpen = !userMenuOpen;
  }

  function closeUserMenu() {
    userMenuOpen = false;
  }

  function handleSettings() {
    navigationStore.enterAccount();
    // Menu se zavře přes $effect na změnu módu níže.
    // Na mobilu navíc zavři drawer (enterAccount neprochází handleNavigate).
    if (layoutStore.isMobile) layoutStore.closeDrawer();
  }

  function handleAppSettings() {
    navigationStore.enterSettings();
    // Menu se zavře přes $effect níže při změně módu.
    // Na mobilu navíc zavři drawer — enterSettings nejde přes
    // AppShell.handleNavigate, takže by drawer zůstal otevřený.
    if (layoutStore.isMobile) layoutStore.closeDrawer();
  }

  // Výstup ze sekundárního módu (Nastavení aplikace i Nastavení účtu) zpět
  // do aplikace — back button v hlavičce. Na mobilu zavři drawer, exitToApp
  // stejně jako enter* neprochází přes handleNavigate.
  function handleExitToApp() {
    navigationStore.exitToApp();
    if (layoutStore.isMobile) layoutStore.closeDrawer();
  }

  // Při změně módu zavři user menu
  $effect(() => {
    void navigationStore.mode;
    closeUserMenu();
  });

  // Položky jazyka — přepnutí volá location.reload() v storu, takže menu
  // se zavírat nemusí (stránka se beztak překreslí celá).
  const languageOptions = [
    { value: 'cs',   labelKey: 'sidebar.language.cs' },
    { value: 'en',   labelKey: 'sidebar.language.en' },
    { value: 'auto', labelKey: 'sidebar.language.auto' },
  ];

  function handleLanguageChange(value) {
    language.setMode(value);
  }

  function handleLogoutFromMenu() {
    // Záměrně nezavíráme menu předem — celý sidebar zmizí sám,
    // jakmile authStore.clearAuth() přepne aplikaci na LoginScreen.
    handleLogout();
  }

  // Zavři menu při kliknutí mimo něj nebo při Escape.
  $effect(() => {
    if (!userMenuOpen) return;

    function onDocClick(e) {
      if (userMenuRoot && !userMenuRoot.contains(e.target)) {
        closeUserMenu();
      }
    }
    function onKeyDown(e) {
      if (e.key === 'Escape') closeUserMenu();
    }

    document.addEventListener('click', onDocClick);
    document.addEventListener('keydown', onKeyDown);
    return () => {
      document.removeEventListener('click', onDocClick);
      document.removeEventListener('keydown', onKeyDown);
    };
  });

  // Při sbalení sidebaru zavři otevřené menu (jinak by zůstalo viset).
  $effect(() => {
    if (collapsed) closeUserMenu();
  });

  let activeId = $derived(navigationStore.activeId);
</script>

<nav
  class="shpd-sidebar"
  class:shpd-sidebar--collapsed={collapsed}
>
  <div class="shpd-sidebar__header">
    {#if !collapsed || layoutStore.isMobile}
      <div class="shpd-sidebar__brand">
        {#if appInfoStore.icon}
          <img
            class="shpd-sidebar__app-icon"
            src={brandingUrl('icon', appInfoStore.icon.hash)}
            alt=""
          />
        {/if}
        <span class="shpd-sidebar__logo">{appInfoStore.shortName ?? 'Shipard'}</span>
      </div>
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
    {#if collapsed}
      <div class="shpd-sidebar__back-bar shpd-sidebar__back-bar--collapsed">
        <button
          class="shpd-sidebar__back-button shpd-sidebar__back-button--icon-only"
          onclick={handleExitToApp}
          title={t('sidebar.backToApp')}
          aria-label={t('sidebar.backToApp')}
        >
          <Icon icon={iconChevronLeft} size="sm" />
        </button>
      </div>
    {:else}
      <div class="shpd-sidebar__back-bar">
        <button class="shpd-sidebar__back-button" onclick={handleExitToApp}>
          <Icon icon={iconChevronLeft} size="sm" />
          <span>{t('sidebar.backToApp')}</span>
        </button>
      </div>
    {/if}
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
      <!-- Sbalený stav: ploché ikony všech leaves, bez sekcí. -->
      <ul class="shpd-sidebar__list shpd-sidebar__list--collapsed">
        {#each flatLeaves as leaf}
          <li>
            <button
              class="shpd-sidebar__item shpd-sidebar__item--icon-only"
              class:shpd-sidebar__item--active={activeId === leaf.id}
              onclick={() => handleItemClick(leaf)}
              title={leaf.label}
              aria-label={leaf.label}
            >
              <Icon icon={resolveIcon(leaf.icon)} size="sm" />
            </button>
          </li>
        {/each}
      </ul>
    {:else}
      {#each navTree as group}
        {#if group.type}
          <!-- Root-level leaf (např. Dashboard) — žádná skupina, klikatelná
               položka přímo. Stejný layout jako leaf v podskupinách, aby
               vizuálně zapadl mezi ostatní sidebar items. -->
          <div class="shpd-sidebar__group shpd-sidebar__group--leaf">
            <button
              class="shpd-sidebar__item"
              class:shpd-sidebar__item--active={activeId === group.id}
              onclick={() => handleItemClick(group)}
            >
              <Icon icon={resolveIcon(group.icon)} size="sm" />
              <span>{group.label}</span>
            </button>
          </div>
        {:else}
          <div class="shpd-sidebar__group">
            <button
              class="shpd-sidebar__group-header"
              onclick={() => toggleGroup(group.id)}
            >
              <span class="shpd-sidebar__group-label">{group.label}</span>
              <span class="shpd-sidebar__chevron">
                <Icon icon={expanded.has(group.id) ? iconChevronDown : iconChevronRight} size="xs" />
              </span>
            </button>

            {#if expanded.has(group.id)}
              <ul class="shpd-sidebar__list">
                {#each group.children as child}
                  {#if child.children}
                    <!-- Nested sub-group -->
                    <li class="shpd-sidebar__subgroup">
                      <button
                        class="shpd-sidebar__subgroup-header"
                        onclick={() => toggleGroup(child.id)}
                      >
                        <span>{child.label}</span>
                        <span class="shpd-sidebar__chevron">
                          <Icon icon={expanded.has(child.id) ? iconChevronDown : iconChevronRight} size="xs" />
                        </span>
                      </button>

                      {#if expanded.has(child.id)}
                        <ul class="shpd-sidebar__list shpd-sidebar__list--nested">
                          {#each child.children as leaf}
                            <li>
                              <button
                                class="shpd-sidebar__item"
                                class:shpd-sidebar__item--active={activeId === leaf.id}
                                onclick={() => handleItemClick(leaf)}
                              >
                                <Icon icon={resolveIcon(leaf.icon)} size="sm" />
                                <span>{leaf.label}</span>
                              </button>
                            </li>
                          {/each}
                        </ul>
                      {/if}
                    </li>
                  {:else}
                    <li>
                      <button
                        class="shpd-sidebar__item"
                        class:shpd-sidebar__item--active={activeId === child.id}
                        onclick={() => handleItemClick(child)}
                      >
                        <Icon icon={resolveIcon(child.icon)} size="sm" />
                        <span>{child.label}</span>
                      </button>
                    </li>
                  {/if}
                {/each}
              </ul>
            {/if}
          </div>
        {/if}
      {/each}
    {/if}
  </div>

  <div class="shpd-sidebar__footer" bind:this={userMenuRoot}>
    <button
      class="shpd-sidebar__user-button"
      class:shpd-sidebar__user-button--collapsed={collapsed}
      class:shpd-sidebar__user-button--open={userMenuOpen}
      onclick={toggleUserMenu}
      title={authStore.user?.full_name ?? ''}
      aria-haspopup="menu"
      aria-expanded={userMenuOpen}
    >
      <span class="shpd-sidebar__avatar">
        {#if avatarStore.objectUrl}
          <img class="shpd-sidebar__avatar-img" src={avatarStore.objectUrl} alt="" />
        {:else}
          {(authStore.user?.full_name ?? '?').charAt(0)}
        {/if}
      </span>
      {#if !collapsed}
        <span class="shpd-sidebar__username">{authStore.user?.full_name ?? ''}</span>
        <span class="shpd-sidebar__user-chevron">
          <Icon icon={userMenuOpen ? iconChevronDown : iconChevronUp} size="xs" />
        </span>
      {/if}
    </button>

    {#if userMenuOpen}
      <div
        class="shpd-sidebar__user-menu"
        class:shpd-sidebar__user-menu--side={collapsed}
        role="menu"
      >
        {#if navigationStore.mode !== 'account'}
          <button class="shpd-sidebar__user-menu-item" onclick={handleSettings} role="menuitem">
            <Icon icon={iconSettings} size="sm" />
            <span>{t('sidebar.accountSettings')}</span>
          </button>
        {/if}

        <!-- Nastavení aplikace jen adminovi — server settings pages už
             chrání, tady se jen skrývá mrtvý odkaz (princip D9). -->
        {#if navigationStore.mode !== 'settings' && authStore.isAdmin}
          <button class="shpd-sidebar__user-menu-item" onclick={handleAppSettings} role="menuitem">
            <Icon icon={iconAppSettings} size="sm" />
            <span>{t('sidebar.appSettings')}</span>
          </button>
        {/if}

        <div class="shpd-sidebar__user-menu-divider"></div>
        <div class="shpd-sidebar__user-menu-label">{t('sidebar.language')}</div>
        {#each languageOptions as opt}
          <button
            class="shpd-sidebar__user-menu-item"
            class:shpd-sidebar__user-menu-item--active={language.mode === opt.value}
            onclick={() => handleLanguageChange(opt.value)}
            role="menuitemradio"
            aria-checked={language.mode === opt.value}
          >
            <span class="shpd-sidebar__user-menu-item-label">{t(opt.labelKey)}</span>
            {#if language.mode === opt.value}
              <span class="shpd-sidebar__user-menu-item-check">
                <Icon icon={iconConfirm} size="xs" />
              </span>
            {/if}
          </button>
        {/each}

        <div class="shpd-sidebar__user-menu-divider"></div>
        <button class="shpd-sidebar__user-menu-item" onclick={handleLogoutFromMenu} role="menuitem">
          <Icon icon={iconLogout} size="sm" />
          <span>{t('sidebar.logout')}</span>
        </button>
      </div>
    {/if}
  </div>
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
       (`.shpd-sidebar__user-menu--side`) mohl vyčnívat doprava ven ze
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

  .shpd-sidebar__brand {
    display: flex;
    align-items: center;
    gap: var(--shpd-space-sm);
    min-width: 0;
  }

  .shpd-sidebar__app-icon {
    width: 24px;
    height: 24px;
    object-fit: contain;
    border-radius: var(--shpd-radius-sm);
    flex-shrink: 0;
  }

  .shpd-sidebar__logo {
    font-size: var(--shpd-font-size-lg);
    font-weight: 700;
    color: var(--shpd-color-text-sidebar);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .shpd-sidebar__back-bar {
    padding: var(--shpd-space-sm);
    border-bottom: 1px solid var(--shpd-color-bg-sidebar-border);
    flex-shrink: 0;
  }

  .shpd-sidebar__back-button {
    display: flex;
    align-items: center;
    gap: var(--shpd-space-sm);
    width: 100%;
    padding: var(--shpd-space-xs) var(--shpd-space-sm);
    background: transparent;
    border: none;
    border-radius: var(--shpd-radius-sm);
    color: var(--shpd-color-text-sidebar);
    font-size: var(--shpd-font-size-sm);
    cursor: pointer;
    text-align: left;
    transition: background-color 0.15s;
  }

  .shpd-sidebar__back-button:hover {
    background-color: var(--shpd-color-bg-sidebar-hover);
  }

  .shpd-sidebar__nav {
    flex: 1;
    overflow-y: auto;
    min-height: 0;
  }

  /* Plochý seznam ikon ve sbaleném sidebaru.
   * Bez sekcí, bez chevronů — jen ikony pod sebou, vystředěné. */
  .shpd-sidebar__list--collapsed {
    padding: var(--shpd-space-sm) 0;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 2px;
    list-style: none;
  }

  .shpd-sidebar__list--collapsed > li {
    width: 100%;
    display: flex;
    justify-content: center;
  }

  /* Sbalená varianta klikatelné položky — čtvercové tlačítko, jen ikona.
   * Stejné barvy a aktivní stav (accent proužek vlevo + primary pozadí)
   * jako rozbalená varianta — uživatel pozná aktivní položku stejně. */
  .shpd-sidebar__item--icon-only {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 36px;
    height: 36px;
    padding: 0;
    gap: 0;
  }

  /* Kompaktní back button ve sbaleném settings sidebaru. */
  .shpd-sidebar__back-bar--collapsed {
    display: flex;
    justify-content: center;
    padding: var(--shpd-space-xs);
  }

  .shpd-sidebar__back-button--icon-only {
    width: 32px;
    height: 32px;
    padding: 0;
    gap: 0;
    justify-content: center;
  }

  .shpd-sidebar__footer {
    position: relative; /* anchor pro user menu dropdown */
    padding: var(--shpd-space-sm);
    flex-shrink: 0;
    border-top: 1px solid var(--shpd-color-bg-sidebar-border);
  }

  /* User button — celá řádka v patce sidebaru je klikatelná.
   * Otevírá dropdown s položkami Nastavení účtu / Odhlásit.
   * Layout: avatar (32px) | jméno (flex 1) | chevron. */
  .shpd-sidebar__user-button {
    display: flex;
    align-items: center;
    gap: var(--shpd-space-sm);
    width: 100%;
    padding: var(--shpd-space-xs) var(--shpd-space-sm);
    background: transparent;
    border: none;
    border-radius: var(--shpd-radius-sm);
    color: var(--shpd-color-text-sidebar);
    cursor: pointer;
    text-align: left;
    transition: background-color 0.15s;
  }

  .shpd-sidebar__user-button:hover,
  .shpd-sidebar__user-button--open {
    background-color: var(--shpd-color-bg-sidebar-hover);
  }

  .shpd-sidebar__user-button--collapsed {
    justify-content: center;
    padding: var(--shpd-space-xs);
  }

  .shpd-sidebar__user-chevron {
    display: inline-flex;
    align-items: center;
    color: var(--shpd-color-text-sidebar-muted);
    flex-shrink: 0;
  }

  /* Dropdown menu — otevírá se nahoru z patky.
   * V rozbaleném sidebaru: full width nad tlačítkem.
   * Ve sbaleném: vedle sidebaru vpravo (varianta --side).
   *
   * Barva pozadí navazuje na sidebar (modrá), aby dropdown
   * vizuálně patřil k němu — jen o stupeň světlejší, aby šlo
   * poznat kde sidebar končí a dropdown začíná. */
  .shpd-sidebar__user-menu {
    position: absolute;
    bottom: calc(100% - 1px);
    left: var(--shpd-space-sm);
    right: var(--shpd-space-sm);
    background-color: var(--shpd-color-bg-sidebar-elevated);
    color: var(--shpd-color-text-sidebar);
    border: 1px solid var(--shpd-color-bg-sidebar-border);
    border-radius: var(--shpd-radius-md);
    box-shadow: var(--shpd-shadow-md);
    padding: var(--shpd-space-xs);
    z-index: 200;
  }

  .shpd-sidebar__user-menu--side {
    bottom: var(--shpd-space-sm);
    left: calc(100% - 4px);
    right: auto;
    min-width: 200px;
  }

  .shpd-sidebar__user-menu-item {
    display: flex;
    align-items: center;
    gap: var(--shpd-space-sm);
    width: 100%;
    padding: var(--shpd-space-xs) var(--shpd-space-sm);
    background: transparent;
    border: none;
    border-radius: var(--shpd-radius-sm);
    color: var(--shpd-color-text-sidebar);
    font-size: var(--shpd-font-size-sm);
    text-align: left;
    cursor: pointer;
    transition: background-color 0.12s;
  }

  .shpd-sidebar__user-menu-item:hover {
    background-color: var(--shpd-color-bg-sidebar-hover);
  }

  /* Aktivní varianta položky (zatím používá jen sekce Vzhled).
   * Lehké zvýraznění pozadí + zatžítko vpravo říká "toto je vybraná volba". */
  .shpd-sidebar__user-menu-item--active {
    background-color: var(--shpd-color-bg-sidebar-hover);
  }

  .shpd-sidebar__user-menu-item-label {
    flex: 1;
  }

  .shpd-sidebar__user-menu-item-check {
    display: inline-flex;
    align-items: center;
    color: var(--shpd-color-accent);
    flex-shrink: 0;
  }

  /* Sekce label — nadpis skupiny položek v dropdownu ("Vzhled"). */
  .shpd-sidebar__user-menu-label {
    padding: var(--shpd-space-xs) var(--shpd-space-sm);
    font-size: 0.75rem;
    font-weight: 600;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    color: var(--shpd-color-text-sidebar-muted);
  }

  .shpd-sidebar__user-menu-divider {
    height: 1px;
    margin: var(--shpd-space-xs) 0;
    background-color: var(--shpd-color-bg-sidebar-border);
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

  .shpd-sidebar__avatar {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    font-size: var(--shpd-font-size-sm);
    font-weight: 600;
    color: var(--shpd-color-text-sidebar);
    background-color: var(--shpd-color-accent);
    border-radius: 50%;
    flex-shrink: 0;
    overflow: hidden;
  }

  /* Avatar fotka vyplní kolečko; bez fotky zůstává accent kolečko s iniciálou. */
  .shpd-sidebar__avatar-img {
    width: 100%;
    height: 100%;
    object-fit: cover;
  }

  .shpd-sidebar__username {
    flex: 1;
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text-sidebar);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .shpd-sidebar__group {
    padding-top: var(--shpd-space-sm);
  }

  .shpd-sidebar__group-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    width: 100%;
    padding: var(--shpd-space-xs) var(--shpd-space-md);
    font-size: 0.75rem;
    font-weight: 600;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    color: var(--shpd-color-text-sidebar-muted);
    transition: color 0.15s;
  }

  .shpd-sidebar__group-header:hover {
    color: var(--shpd-color-text-sidebar);
  }

  .shpd-sidebar__group-label {
    flex: 1;
    text-align: left;
  }

  .shpd-sidebar__chevron {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    line-height: 1;
  }

  .shpd-sidebar__list {
    padding: var(--shpd-space-xs) 0;
  }

  .shpd-sidebar__list--nested {
    padding-left: var(--shpd-space-md);
  }

  .shpd-sidebar__subgroup-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    width: 100%;
    padding: var(--shpd-space-xs) var(--shpd-space-md);
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text-sidebar-muted);
    transition: color 0.15s;
  }

  .shpd-sidebar__subgroup-header:hover {
    color: var(--shpd-color-text-sidebar);
  }

  .shpd-sidebar__item {
    position: relative;
    display: flex;
    align-items: center;
    gap: var(--shpd-space-sm);
    width: 100%;
    padding: var(--shpd-space-xs) var(--shpd-space-md);
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text-sidebar);
    text-align: left;
    border-radius: var(--shpd-radius-sm);
    transition: background-color 0.15s, opacity 0.15s;
    opacity: 0.85;
  }

  .shpd-sidebar__item:hover {
    background-color: var(--shpd-color-bg-sidebar-hover);
    opacity: 1;
  }

  .shpd-sidebar__item--active {
    background-color: var(--shpd-color-sidebar-active-bg);
    opacity: 1;
    font-weight: 500;
  }

  .shpd-sidebar__item--active:hover {
    background-color: var(--shpd-color-sidebar-active-bg-hover);
  }

  /* Levý oranžový proužek u aktivní položky — zvýrazňuje pozici
     uživatele a propojuje sidebar s brand accent barvou. */
  .shpd-sidebar__item--active::before {
    content: '';
    position: absolute;
    left: 0;
    top: 4px;
    bottom: 4px;
    width: 3px;
    border-radius: 0 2px 2px 0;
    background-color: var(--shpd-color-accent);
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
