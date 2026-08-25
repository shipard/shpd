<script>
  import Sidebar from './Sidebar.svelte';
  import ContentArea from './ContentArea.svelte';
  import MobileTopBar from './MobileTopBar.svelte';
  import ThemePanel from './ThemePanel.svelte';
  import ChatPanel from '../chat/ChatPanel.svelte';
  import CommandPalette from '../chrome/CommandPalette.svelte';
  import { navigationStore } from '../../stores/navigation.svelte.js';
  import { layoutStore } from '../../stores/layout.svelte.js';
  import { chatPanelStore } from '../../stores/chatPanel.svelte.js';
  import { paletteStore } from '../../stores/palette.svelte.js';
  import { sectionBadgesStore } from '../../stores/sectionBadges.svelte.js';

  let { onLogout } = $props();

  // Panel custom vzhledu žije tady, ne v Sidebaru — mobilní drawer má
  // transform (containing block pro position:fixed) a Sidebar overflow:
  // hidden, takže panel/Modal renderovaný uvnitř by se ořízl. Otevírá ho
  // ThemeField (Nastavení účtu → Základní) přes onOpenThemePanel probublaný
  // skrz ContentArea; collapsed se zrcadlí přes bind kvůli left pozici
  // panelu na desktopu.
  let themePanelOpen = $state(false);
  let sidebarCollapsed = $state(false);

  function openThemePanel() {
    themePanelOpen = true;
  }

  function handleNavigate(item) {
    navigationStore.navigate(item);
    // Na mobilu klik na položku zavře drawer (jinak by zůstal přes obsah).
    if (layoutStore.isMobile) layoutStore.closeDrawer();
  }

  // Globální zkratka Ctrl/Cmd+K otevírá command paletu (R7). Nereaguje,
  // když uživatel píše v inputu/textarea/contenteditable mimo paletu
  // (kolize s editací ve FormDialogu — čistá detekce „dialog otevřen"
  // neexistuje, focus test je v1 dostatečný).
  $effect(() => {
    function onKeyDown(e) {
      if (e.key !== 'k' || (!e.ctrlKey && !e.metaKey)) return;
      const el = document.activeElement;
      if (el && !paletteStore.open
          && (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA' || el.isContentEditable)) {
        return;
      }
      e.preventDefault();
      paletteStore.openPalette();
    }
    document.addEventListener('keydown', onKeyDown);
    return () => document.removeEventListener('keydown', onKeyDown);
  });

  // Esc zavírá drawer (jen mobil + otevřený drawer).
  $effect(() => {
    if (!layoutStore.isMobile || !layoutStore.drawerOpen) return;
    function onKeyDown(e) {
      if (e.key === 'Escape') layoutStore.closeDrawer();
    }
    document.addEventListener('keydown', onKeyDown);
    return () => document.removeEventListener('keydown', onKeyDown);
  });

  // Polling badge stavů sekcí (60 s + focus) — žije se shellem.
  $effect(() => {
    sectionBadgesStore.startPolling();
    return () => sectionBadgesStore.stopPolling();
  });
</script>

<div class="shpd-shell" class:shpd-shell--mobile={layoutStore.isMobile}>
  {#if layoutStore.isMobile}
    <!-- Mobilní režim: top bar nahoře, sidebar jako overlay drawer. -->
    <MobileTopBar />

    {#if layoutStore.drawerOpen}
      <div
        class="shpd-shell__overlay"
        onclick={() => layoutStore.closeDrawer()}
        aria-hidden="true"
      ></div>
    {/if}

    <div
      class="shpd-shell__drawer"
      class:shpd-shell__drawer--open={layoutStore.drawerOpen}
    >
      <Sidebar onNavigate={handleNavigate} {onLogout} />
    </div>

    <div class="shpd-shell__main">
      <ContentArea activeItem={navigationStore.activeItem} onOpenThemePanel={openThemePanel} />
    </div>
  {:else}
    <!-- Desktop režim: beze změny — pevný sidebar + obsah vedle. -->
    <Sidebar
      onNavigate={handleNavigate}
      {onLogout}
      bind:collapsed={sidebarCollapsed}
    />
    <div class="shpd-shell__main">
      <ContentArea activeItem={navigationStore.activeItem} onOpenThemePanel={openThemePanel} />
    </div>
  {/if}

  <ThemePanel
    open={themePanelOpen}
    onClose={() => { themePanelOpen = false; }}
    collapsed={sidebarCollapsed}
  />

  <!-- Command palette — shell-nezávislá, shelly dodávají jen trigger. -->
  <CommandPalette />

  <!-- Boční AI chat panel — mimo mobilní/desktop větve, geometrii si řeší
       sám přes layoutStore.isMobile. Otevírá ho dashboardový ChatLauncher. -->
  {#if chatPanelStore.isOpen}
    <ChatPanel />
  {/if}
</div>

<style>
  .shpd-shell {
    display: flex;
    /* 100dvh (dynamic viewport height) řeší ořez spodku shellu pod
       adresní lištou mobilních prohlížečů (sticky launcher, composer) —
       stejný pattern jako fullscreen Modal. Staré prohlížeče dvh
       ignorují a zůstanou na 100vh. */
    height: 100vh;
    height: 100dvh;
    overflow: hidden;
    position: relative;
  }

  /* Mobilní režim: shell je vertikální (top bar nad obsahem).
     Sidebar drawer a overlay jsou position:fixed mimo tok. */
  .shpd-shell--mobile {
    flex-direction: column;
  }

  .shpd-shell__main {
    display: flex;
    flex-direction: column;
    flex: 1;
    overflow: hidden;
    min-height: 0;
  }

  /* --- Mobilní drawer + overlay --- */

  .shpd-shell__overlay {
    position: fixed;
    inset: 0;
    top: var(--shpd-header-height); /* pod top barem */
    background-color: var(--shpd-color-overlay);
    z-index: 90;
  }

  .shpd-shell__drawer {
    position: fixed;
    top: var(--shpd-header-height);
    bottom: 0;
    left: 0;
    width: 72%;
    max-width: 320px;
    z-index: 100;
    transform: translateX(-100%);
    transition: transform 0.22s ease;
    /* Drawer obsahuje Sidebar, který má vlastní pozadí (modré).
       Sidebar uvnitř roztáhneme na plnou šířku i výšku drawera. */
    display: flex;
  }

  /* Sidebar uvnitř draweru vyplní celou jeho šířku (desktop má pevných
     260px; v draweru chceme bez průhledného pruhu vpravo). */
  .shpd-shell__drawer :global(.shpd-sidebar) {
    width: 100%;
  }

  .shpd-shell__drawer--open {
    transform: translateX(0);
  }
</style>
