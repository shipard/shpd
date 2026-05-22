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
</style>
