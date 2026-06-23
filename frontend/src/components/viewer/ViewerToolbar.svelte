<script>
  import Button from '../ui/Button.svelte';
  import { iconAdd, iconDelete, iconRefresh, iconFilter, resolveIcon } from '../../icons.js';

  let { actions = [], onAction } = $props();

  /**
   * Mapování běžných action IDs na ikony.
   * Pokud akce má vlastní `icon`, použije se ten (string → resolveIcon, objekt = už hotová ikona).
   */
  const defaultActionIcons = {
    'add': iconAdd,
    'create': iconAdd,
    'new': iconAdd,
    'delete': iconDelete,
    'remove': iconDelete,
    'refresh': iconRefresh,
    'filter': iconFilter,
  };

  function getActionIcon(action) {
    if (typeof action.icon === 'string' && action.icon !== '') {
      // Backend posílá jméno ikony jako string (např. "cloud-download").
      // resolveIcon vrátí undefined fallback iconTable, ale my chceme
      // raději nic než iconTable na toolbarovém tlačítku.
      return resolveIcon(action.icon, undefined);
    }
    return action.icon ?? defaultActionIcons[action.id] ?? undefined;
  }
</script>

<div class="shpd-viewer-toolbar">
  {#each actions as action (action.id)}
    <Button
      label={action.label}
      icon={getActionIcon(action)}
      variant={action.variant ?? 'secondary'}
      size={action.size ?? 'md'}
      onclick={() => onAction?.(action.id)}
    />
  {/each}
  {#if actions.length === 0}
    <!-- Spacer: drží přesnou výšku řady tlačítek, když viewer nemá žádné
         akce (např. bankovní transakce bez „Přidat"). Bez něj toolbar
         kolabuje a při výběru řádku layout poskočí. Skutečné tlačítko
         zaručuje pixel-přesnou výšku (font, padding, border, line-height)
         lépe než ručně počítaná min-height.
         Button má pevný Props interface (nepropaguje aria/tabindex), proto
         skrýváme přes wrapper a label = nezalomitelná mezera, aby se
         vyrenderoval i __label span (jinak by chyběla výška textu). -->
    <div class="shpd-viewer-toolbar__spacer" aria-hidden="true">
      <Button label={'\u00A0'} variant="secondary" size="md" disabled />
    </div>
  {/if}
</div>

<style>
  .shpd-viewer-toolbar {
    display: flex;
    align-items: center;
    gap: var(--shpd-space-sm);
    padding: var(--shpd-space-sm) var(--shpd-space-md);
    border-bottom: 1px solid var(--shpd-color-border);
    background-color: var(--shpd-color-bg);
    flex-shrink: 0;
  }

  /* Spacer (render jen když nejsou akce) zabírá místo, ale není vidět ani
     nereaguje. Drží výšku toolbaru shodnou s variantou, kde tlačítko je. */
  .shpd-viewer-toolbar__spacer {
    display: inline-flex;
    visibility: hidden;
    pointer-events: none;
  }
</style>
