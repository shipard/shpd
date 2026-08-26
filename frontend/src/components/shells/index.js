// Registry shellů — mapa jméno → komponenta (vzor panelComponents
// v ContentArea.svelte). Klíče musí odpovídat KNOWN_SHELLS
// v utils/shell.js (tam žije seznam jmen, protože store komponenty
// importovat nesmí). Resolver v AppShellu padá na sidebar, když jméno
// v mapě chybí.
import SidebarShell from './SidebarShell.svelte';
import ClassicShell from './ClassicShell.svelte';

export const shellComponents = {
  sidebar: SidebarShell,
  classic: ClassicShell,
};
