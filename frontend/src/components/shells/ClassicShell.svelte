<script>
  // Classic shell — horní menu agend + levý pás uzlů úrovně 2 s flyouty
  // (styl starého Shipardu). Desktop-only a jen app mód — garantuje
  // resolver v AppShellu (R2): mobil i settings/account módy dostávají
  // SidebarShell. Screen surface na desktopu region nemá (D7) — obsah si
  // kreslí vlastní toolbary, beze změny.
  import ContentArea from '../layout/ContentArea.svelte';
  import TopMenuBar from './classic/TopMenuBar.svelte';
  import NavFlyoutStrip from '../chrome/NavFlyoutStrip.svelte';
  import { navigationStore } from '../../stores/navigation.svelte.js';

  let {
    onLogout,
    onOpenThemePanel,
    // ThemePanel kreslí AppShell — classic hlásí konstantní offset
    // šířky pásu (CSS délka nad tokeny, vzor SidebarShell).
    themePanelLeftOffset = $bindable('calc(var(--shpd-classic-strip-width) + var(--shpd-space-sm))'),
  } = $props();

  $effect(() => {
    themePanelLeftOffset = 'calc(var(--shpd-classic-strip-width) + var(--shpd-space-sm))';
  });

  const tree = $derived(navigationStore.appNavTree ?? []);

  // Uzly pásu: úroveň 2 aktivní sekce; při domečku (activeSection null)
  // root-level leafy (`_top`, dashboard, chat) — D2/D8.
  const stripNodes = $derived.by(() => {
    const sectionId = navigationStore.activeSection;
    if (sectionId === null) {
      return tree.filter((n) => n?.type);
    }
    return tree.find((n) => n.id === sectionId)?.children ?? [];
  });

  // Stejná normalizace položky jako Sidebar.handleItemClick — navigate()
  // si z ní bere jen whitelist polí (+ icon pro recents palety).
  function handleNavigate(item) {
    navigationStore.navigate({
      id: item.id,
      label: item.label,
      type: item.type,
      table: item.table,
      viewerId: item.viewerId,
      pageId: item.pageId,
      panelId: item.panelId,
      panelParams: item.panelParams,
      filter: item.filter,
      fixedViewGroup: item.fixedViewGroup,
      icon: item.icon,
    });
  }
</script>

<div class="shpd-classic">
  <TopMenuBar onNavigate={handleNavigate} {onLogout} />

  <div class="shpd-classic__body">
    <aside class="shpd-classic__strip">
      <NavFlyoutStrip
        nodes={stripNodes}
        activeId={navigationStore.activeId}
        onNavigate={handleNavigate}
      />
    </aside>

    <div class="shpd-classic__main">
      <ContentArea activeItem={navigationStore.activeItem} {onOpenThemePanel} />
    </div>
  </div>
</div>

<style>
  .shpd-classic {
    display: flex;
    flex-direction: column;
    /* Stejný dvh pattern jako SidebarShell — ořez pod adresní lištou. */
    height: 100vh;
    height: 100dvh;
    overflow: hidden;
    position: relative;
  }

  .shpd-classic__body {
    display: flex;
    flex: 1;
    overflow: hidden;
    min-height: 0;
  }

  /* Pás sdílí barevný systém sidebaru (vč. custom theme gradientu). */
  .shpd-classic__strip {
    display: flex;
    flex-direction: column;
    width: var(--shpd-classic-strip-width);
    flex-shrink: 0;
    background: var(--shpd-sidebar-bg-image, var(--shpd-color-bg-sidebar));
    overflow: hidden;
  }

  .shpd-classic__main {
    display: flex;
    flex-direction: column;
    flex: 1;
    overflow: hidden;
    min-height: 0;
  }
</style>
