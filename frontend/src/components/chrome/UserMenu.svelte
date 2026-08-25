<script>
  // Chrome primitiv: avatar + jméno v patce s dropdown menu — Nastavení
  // účtu / Nastavení aplikace, jazyk, odhlásit. `compact` = jen kruhový
  // avatar, dropdown vyjíždí do strany (side-overlay).
  //
  // Mode akce volá přímo navigationStore (enterAccount/enterSettings);
  // logout dodává rodič přes `onLogout` — menu se při něm záměrně
  // NEZAVÍRÁ, celý chrome zmizí sám, jakmile clearAuth přepne aplikaci
  // na LoginScreen (viz docs/frontend.md §9 Dropdown / popover komponenty).
  import { authStore } from '../../stores/auth.svelte.js';
  import { navigationStore } from '../../stores/navigation.svelte.js';
  import { layoutStore } from '../../stores/layout.svelte.js';
  import { avatarStore } from '../../stores/avatar.svelte.js';
  import { language, t } from '../../i18n/index.js';
  import Icon from '../ui/Icon.svelte';
  import {
    iconLogout,
    iconChevronDown,
    iconChevronUp,
    iconSettings,
    iconAppSettings,
    iconConfirm,
  } from '../../icons.js';

  let { compact = false, onLogout } = $props();

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
    // Záměrně nezavíráme menu předem — celý chrome zmizí sám,
    // jakmile clearAuth přepne aplikaci na LoginScreen.
    onLogout?.();
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

  // Při přepnutí do kompaktu zavři otevřené menu (jinak by zůstalo viset).
  $effect(() => {
    if (compact) closeUserMenu();
  });
</script>

<div class="shpd-usermenu" bind:this={userMenuRoot}>
  <button
    class="shpd-usermenu__button"
    class:shpd-usermenu__button--compact={compact}
    class:shpd-usermenu__button--open={userMenuOpen}
    onclick={toggleUserMenu}
    title={authStore.user?.full_name ?? ''}
    aria-haspopup="menu"
    aria-expanded={userMenuOpen}
  >
    <span class="shpd-usermenu__avatar">
      {#if avatarStore.objectUrl}
        <img class="shpd-usermenu__avatar-img" src={avatarStore.objectUrl} alt="" />
      {:else}
        {(authStore.user?.full_name ?? '?').charAt(0)}
      {/if}
    </span>
    {#if !compact}
      <span class="shpd-usermenu__username">{authStore.user?.full_name ?? ''}</span>
      <span class="shpd-usermenu__chevron">
        <Icon icon={userMenuOpen ? iconChevronDown : iconChevronUp} size="xs" />
      </span>
    {/if}
  </button>

  {#if userMenuOpen}
    <div
      class="shpd-usermenu__menu"
      class:shpd-usermenu__menu--side={compact}
      role="menu"
    >
      {#if navigationStore.mode !== 'account'}
        <button class="shpd-usermenu__menu-item" onclick={handleSettings} role="menuitem">
          <Icon icon={iconSettings} size="sm" />
          <span>{t('sidebar.accountSettings')}</span>
        </button>
      {/if}

      <!-- Nastavení aplikace jen adminovi — server settings pages už
           chrání, tady se jen skrývá mrtvý odkaz (princip D9). -->
      {#if navigationStore.mode !== 'settings' && authStore.isAdmin}
        <button class="shpd-usermenu__menu-item" onclick={handleAppSettings} role="menuitem">
          <Icon icon={iconAppSettings} size="sm" />
          <span>{t('sidebar.appSettings')}</span>
        </button>
      {/if}

      <div class="shpd-usermenu__menu-divider"></div>
      <div class="shpd-usermenu__menu-label">{t('sidebar.language')}</div>
      {#each languageOptions as opt}
        <button
          class="shpd-usermenu__menu-item"
          class:shpd-usermenu__menu-item--active={language.mode === opt.value}
          onclick={() => handleLanguageChange(opt.value)}
          role="menuitemradio"
          aria-checked={language.mode === opt.value}
        >
          <span class="shpd-usermenu__menu-item-label">{t(opt.labelKey)}</span>
          {#if language.mode === opt.value}
            <span class="shpd-usermenu__menu-item-check">
              <Icon icon={iconConfirm} size="xs" />
            </span>
          {/if}
        </button>
      {/each}

      <div class="shpd-usermenu__menu-divider"></div>
      <button class="shpd-usermenu__menu-item" onclick={handleLogoutFromMenu} role="menuitem">
        <Icon icon={iconLogout} size="sm" />
        <span>{t('sidebar.logout')}</span>
      </button>
    </div>
  {/if}
</div>

<style>
  .shpd-usermenu {
    position: relative; /* anchor pro dropdown */
    padding: var(--shpd-space-sm);
    flex-shrink: 0;
    border-top: 1px solid var(--shpd-color-bg-sidebar-border);
  }

  /* User button — celá řádka v patce je klikatelná.
   * Layout: avatar (32px) | jméno (flex 1) | chevron. */
  .shpd-usermenu__button {
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

  .shpd-usermenu__button:hover,
  .shpd-usermenu__button--open {
    background-color: var(--shpd-color-bg-sidebar-hover);
  }

  .shpd-usermenu__button--compact {
    justify-content: center;
    padding: var(--shpd-space-xs);
  }

  .shpd-usermenu__chevron {
    display: inline-flex;
    align-items: center;
    color: var(--shpd-color-text-sidebar-muted);
    flex-shrink: 0;
  }

  .shpd-usermenu__avatar {
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
  .shpd-usermenu__avatar-img {
    width: 100%;
    height: 100%;
    object-fit: cover;
  }

  .shpd-usermenu__username {
    flex: 1;
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text-sidebar);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  /* Dropdown menu — otevírá se nahoru z patky.
   * V plném režimu: full width nad tlačítkem.
   * V kompaktu: vedle chrome vpravo (varianta --side).
   *
   * Barva pozadí navazuje na sidebar (modrá), aby dropdown
   * vizuálně patřil k němu — jen o stupeň světlejší, aby šlo
   * poznat kde sidebar končí a dropdown začíná. */
  .shpd-usermenu__menu {
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

  .shpd-usermenu__menu--side {
    bottom: var(--shpd-space-sm);
    left: calc(100% - 4px);
    right: auto;
    min-width: 200px;
  }

  .shpd-usermenu__menu-item {
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

  .shpd-usermenu__menu-item:hover {
    background-color: var(--shpd-color-bg-sidebar-hover);
  }

  /* Aktivní varianta položky (zatím používá jen volba jazyka).
   * Lehké zvýraznění pozadí + zatržítko vpravo říká "toto je vybraná volba". */
  .shpd-usermenu__menu-item--active {
    background-color: var(--shpd-color-bg-sidebar-hover);
  }

  .shpd-usermenu__menu-item-label {
    flex: 1;
  }

  .shpd-usermenu__menu-item-check {
    display: inline-flex;
    align-items: center;
    color: var(--shpd-color-accent);
    flex-shrink: 0;
  }

  /* Sekce label — nadpis skupiny položek v dropdownu ("Jazyk"). */
  .shpd-usermenu__menu-label {
    padding: var(--shpd-space-xs) var(--shpd-space-sm);
    font-size: 0.75rem;
    font-weight: 600;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    color: var(--shpd-color-text-sidebar-muted);
  }

  .shpd-usermenu__menu-divider {
    height: 1px;
    margin: var(--shpd-space-xs) 0;
    background-color: var(--shpd-color-bg-sidebar-border);
  }
</style>
