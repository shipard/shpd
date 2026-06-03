<script>
  import Button from '../ui/Button.svelte';
  import Popover from '../ui/Popover.svelte';
  import Icon from '../ui/Icon.svelte';
  import { iconMore } from '../../icons.js';
  import { layoutStore } from '../../stores/layout.svelte.js';
  import { t } from '../../i18n/index.js';

  let {
    docStates = null,
    saving = false,
    onSave,
    onTransition,
  } = $props();

  const showSave = $derived(!docStates || !docStates.read_only);
  const transitions = $derived(docStates?.transitions ?? []);

  const DESTRUCTIVE_STYLES = ['archive', 'trash', 'cancelled'];
  function isDestructive(stateStyle) {
    return DESTRUCTIVE_STYLES.includes(stateStyle);
  }

  // Přechody, které se na mobilu schovají do kebabu:
  //   - destruktivní (bezpečnost, méně omylů palcem) — v kebabu červeně,
  //   - 'concept' (návrat dokladu zpět na koncept — pomocná akce),
  //   - cokoli s příznakem `mobileKebab` z docStates (vedlejší akce, jejíž
  //     stateStyle nestačí — např. "Pozastavit" u úkolů má 'edit' stejně
  //     jako "Opravit" u faktur, které ale viditelné zůstává).
  // Nedestruktivní položky v kebabu zůstávají neutrální.
  const KEBAB_STYLES = [...DESTRUCTIVE_STYLES, 'concept'];
  function inKebab(tr) {
    return KEBAB_STYLES.includes(tr.stateStyle) || tr.mobileKebab === true;
  }

  function variantForStyle(stateStyle) {
    if (stateStyle === 'done') return 'primary';
    if (isDestructive(stateStyle)) return 'danger';
    return 'secondary';
  }

  // Mobil: postupové přechody (Potvrdit = confirmed, V pořádku = done,
  // Opravit = edit…) zůstanou viditelné vedle Uložit — je jich v daném
  // stavu max pár. Destruktivní + 'concept' jdou do kebabu. Na desktopu
  // se kebab nepoužije — renderují se všechny přechody vedle sebe.
  const visibleTransitions = $derived(
    layoutStore.isMobile
      ? transitions.filter(tr => !inKebab(tr))
      : transitions
  );
  const kebabTransitions = $derived(
    layoutStore.isMobile
      ? transitions.filter(tr => inKebab(tr))
      : []
  );

  let kebabOpen = $state(false);
  let kebabAnchor = $state(null);

  function openKebab(e) {
    kebabAnchor = e.currentTarget;
    kebabOpen = !kebabOpen;
  }
  function closeKebab() { kebabOpen = false; }

  function runTransition(tr) {
    closeKebab();
    onTransition?.(tr.state, tr.close_form ?? false);
  }
</script>

<div class="shpd-form-state-bar">
  {#if showSave}
    <Button
      label={t('common.save')}
      variant="primary"
      loading={saving}
      disabled={saving}
      onclick={onSave}
    />
  {/if}

  {#each visibleTransitions as tr (tr.state)}
    <Button
      label={tr.actionName}
      variant={variantForStyle(tr.stateStyle)}
      disabled={saving}
      onclick={() => onTransition?.(tr.state, tr.close_form ?? false)}
    />
  {/each}

  {#if kebabTransitions.length > 0}
    <button
      type="button"
      class="shpd-form-state-bar__kebab-btn"
      onclick={openKebab}
      disabled={saving}
      aria-label={t('form.moreActions')}
    >
      <Icon icon={iconMore} size="md" />
    </button>
  {/if}
</div>

{#if kebabOpen}
  <Popover open={true} anchor={kebabAnchor} placement="top" onClose={closeKebab}>
    <div class="shpd-form-state-bar__kebab-menu">
      {#each kebabTransitions as tr (tr.state)}
        <button
          type="button"
          class="shpd-form-state-bar__kebab-item"
          class:shpd-form-state-bar__kebab-item--danger={variantForStyle(tr.stateStyle) === 'danger'}
          onclick={() => runTransition(tr)}
        >
          {tr.actionName}
        </button>
      {/each}
    </div>
  </Popover>
{/if}

<style>
  .shpd-form-state-bar {
    display: flex;
    gap: var(--shpd-space-sm);
    padding: var(--shpd-space-sm) var(--shpd-space-lg);
    border-top: 1px solid var(--shpd-color-border);
    background: var(--shpd-color-bg);
    flex-shrink: 0;
    position: sticky;
    bottom: 0;
  }

  /* Mobilní kebab tlačítko (⋮) — schovává ne-done přechody. Stejný vzor
     jako kebab ve vieweru (fáze 2). Na desktopu se nerenderuje. */
  .shpd-form-state-bar__kebab-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 40px;
    height: 40px;
    flex-shrink: 0;
    /* Vždy na pravý konec footeru — vizuálně oddělený od akčních tlačítek. */
    margin-left: auto;
    color: var(--shpd-color-text);
    background: transparent;
    border: 1px solid var(--shpd-color-border);
    border-radius: var(--shpd-radius-md);
    cursor: pointer;
    transition: background-color 0.15s;
  }
  .shpd-form-state-bar__kebab-btn:hover {
    background-color: var(--shpd-color-bg-hover);
  }
  .shpd-form-state-bar__kebab-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
  }

  /* Kebab menu — položky v Popoveru (světlé pozadí). Stejný vzor jako
     kebab ve vieweru (fáze 2). */
  .shpd-form-state-bar__kebab-menu {
    display: flex;
    flex-direction: column;
    min-width: 180px;
    padding: 4px 0;
  }
  .shpd-form-state-bar__kebab-item {
    text-align: left;
    padding: 10px 14px;
    border: none;
    background: none;
    font-family: inherit;
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text);
    cursor: pointer;
  }
  .shpd-form-state-bar__kebab-item:hover {
    background-color: var(--shpd-color-bg-hover);
  }
  /* Destruktivní přechody (Archivovat, Smazat, Ukončit platnost) červeně —
     uživatel si uvědomí závažnost i bez velkého tlačítka. */
  .shpd-form-state-bar__kebab-item--danger {
    color: var(--shpd-color-danger);
  }
</style>
