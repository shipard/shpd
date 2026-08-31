<script>
  // AppShell — globální starosti nad shelly (UI shells Fáze 4): resolver
  // shellu, load app navigace, Ctrl/Cmd+K, badge polling, ThemePanel,
  // CommandPalette, ChatPanel. Samotný layout chrome (sidebar/drawer,
  // resp. horní menu) kreslí komponenty v components/shells/.
  import ThemePanel from './ThemePanel.svelte';
  import ChatPanel from '../chat/ChatPanel.svelte';
  import CommandPalette from '../chrome/CommandPalette.svelte';
  import { shellComponents } from '../shells/index.js';
  import { navigationStore } from '../../stores/navigation.svelte.js';
  import { layoutStore } from '../../stores/layout.svelte.js';
  import { chatPanelStore } from '../../stores/chatPanel.svelte.js';
  import { paletteStore } from '../../stores/palette.svelte.js';
  import { sectionBadgesStore } from '../../stores/sectionBadges.svelte.js';
  import { shellStore } from '../../stores/shell.svelte.js';

  // Resolver shellu (R2): alternativní shell jen na desktopu v app módu —
  // mobil (D5) i settings/account módy (D6) jedou vždy v sidebar-style
  // chrome. Přepnutí je soft swap komponenty: navigationStore přežije,
  // uživatel zůstane na aktivní položce (D4). Neznámé jméno padá na
  // sidebar (druhá pojistka vedle server allowlistu).
  const shellName = $derived(
    (!layoutStore.isMobile && navigationStore.mode === 'app')
      ? shellStore.effective
      : 'sidebar'
  );
  const ShellComponent = $derived(shellComponents[shellName] ?? shellComponents.sidebar);

  // App nav strom vlastní navigationStore (potřebují ho oba shelly
  // i paleta) — load při každém vstupu do app módu (refetch jako dřív,
  // starý strom drží do příchodu nového).
  $effect(() => {
    if (navigationStore.mode === 'app') navigationStore.loadAppNavTree();
  });

  // Panel custom vzhledu žije tady, ne v shellu — mobilní drawer má
  // transform (containing block pro position:fixed) a Sidebar overflow:
  // hidden, takže panel/Modal renderovaný uvnitř by se ořízl. Otevírá ho
  // ThemeField (Nastavení účtu → Základní) přes onOpenThemePanel probublaný
  // skrz shell → ContentArea; levý offset (CSS délka) hlásí aktivní shell
  // přes bind — sidebar dle collapsed, classic konstantou šířky pásu.
  let themePanelOpen = $state(false);
  let themePanelLeftOffset = $state('calc(var(--shpd-sidebar-width) + var(--shpd-space-sm))');

  function openThemePanel() {
    themePanelOpen = true;
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

  // Polling badge stavů sekcí (60 s + focus) — žije v AppShellu, ne
  // v shellech: přežije soft swap shellu bez restartu intervalu.
  $effect(() => {
    sectionBadgesStore.startPolling();
    return () => sectionBadgesStore.stopPolling();
  });
</script>

<ShellComponent
  onOpenThemePanel={openThemePanel}
  bind:themePanelLeftOffset
/>

<ThemePanel
  open={themePanelOpen}
  onClose={() => { themePanelOpen = false; }}
  leftOffset={themePanelLeftOffset}
/>

<!-- Command palette — shell-nezávislá, shelly dodávají jen trigger. -->
<CommandPalette />

<!-- Boční AI chat panel — mimo shell, geometrii si řeší sám přes
     layoutStore.isMobile. Otevírá ho dashboardový ChatLauncher. -->
{#if chatPanelStore.isOpen}
  <ChatPanel />
{/if}
