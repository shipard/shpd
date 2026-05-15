<script>
  // Decision panel rendered inside a Popover anchored to a clicked status
  // badge in DocumentExchangePreview. Lets the user pick one of:
  //
  //   - "Create new"           → onDecide('create')
  //   - "Pick existing"        → opens EntityPicker → onDecide('useExisting:<id>')
  //   - "Skip row"             → onDecide('skip')  (only when referenceKind='item')
  //   - one of N ambiguous candidates → onDecide('useExisting:<id>')
  //   - "Clear selection"      → onDecide(null)  (only when currentUserAction is set)
  //
  // The parent (DocumentExchangePreview) owns the userActions map and is
  // responsible for closing the popover after each decision.

  import EntityPicker from '../ui/EntityPicker.svelte';
  import { t } from '../../i18n/index.js';

  let {
    resolveBlock,
    referenceKind = 'party', // 'party' | 'item' | 'bankAccount'
    entityTable = 'base_persons_persons',
    entitySearchFields = ['full_name'],
    entityDisplayPattern = (row) => row.name ?? row.full_name ?? `#${row.id}`,
    currentUserAction = null,
    onDecide = () => {},
  } = $props();

  let pickerOpen = $state(false);

  let candidates = $derived(resolveBlock?.candidates ?? []);

  let createLabel = $derived(
    referenceKind === 'item'
      ? t('exchange.preview.decide.createItem')
      : t('exchange.preview.decide.createParty'),
  );

  // Friendly summary of the current decision, e.g. "useExisting:42" → "Use #42".
  let currentLabel = $derived(formatCurrentUserAction(currentUserAction));

  function formatCurrentUserAction(action) {
    if (action === null || action === undefined) return '';
    if (typeof action === 'string' && action.startsWith('useExisting:')) {
      return t('exchange.preview.decide.useCandidate', { id: action.slice('useExisting:'.length) });
    }
    if (action === 'create') return t('exchange.preview.decide.create');
    if (action === 'skip') return t('exchange.preview.decide.skip');
    return String(action);
  }

  function chooseCreate() {
    onDecide('create');
  }
  function chooseSkip() {
    onDecide('skip');
  }
  function chooseUnselect() {
    onDecide(null);
  }
  function chooseCandidate(id) {
    onDecide(`useExisting:${id}`);
  }
  function handlePickerSelect(row) {
    pickerOpen = false;
    onDecide(`useExisting:${row.id}`);
  }
</script>

<div class="shpd-decision">
  {#if currentUserAction !== null && currentUserAction !== undefined}
    <div class="shpd-decision__current">
      <span>{t('exchange.preview.decide.selected', { label: currentLabel })}</span>
      <button type="button" class="shpd-decision__unselect" onclick={chooseUnselect}>
        {t('exchange.preview.decide.unselect')}
      </button>
    </div>
    <hr class="shpd-decision__sep" />
  {/if}

  {#if resolveBlock?.status === 'ambiguous' && candidates.length > 0}
    <div class="shpd-decision__candidates">
      <div class="shpd-decision__heading">
        {t('exchange.preview.decide.candidates')}
      </div>
      <ul class="shpd-decision__candidate-list">
        {#each candidates as c (c.id)}
          <li>
            <button
              type="button"
              class="shpd-decision__candidate"
              onclick={() => chooseCandidate(c.id)}
            >
              {t('exchange.preview.decide.useCandidate', { id: c.id })}: {c.name ?? '—'}
            </button>
          </li>
        {/each}
      </ul>
    </div>
    <hr class="shpd-decision__sep" />
  {/if}

  <div class="shpd-decision__actions">
    {#if referenceKind !== 'bankAccount' || resolveBlock?.status === 'canCreate'}
      <button type="button" class="shpd-decision__action" onclick={chooseCreate}>
        {createLabel}
      </button>
    {/if}
    <button
      type="button"
      class="shpd-decision__action"
      onclick={() => (pickerOpen = true)}
    >
      {t('exchange.preview.decide.pickExisting')}
    </button>
    {#if referenceKind === 'item'}
      <button
        type="button"
        class="shpd-decision__action shpd-decision__action--danger"
        onclick={chooseSkip}
      >
        {t('exchange.preview.decide.skip')}
      </button>
    {/if}
  </div>
</div>

<EntityPicker
  open={pickerOpen}
  tableName={entityTable}
  searchFields={entitySearchFields}
  displayPattern={entityDisplayPattern}
  onSelect={handlePickerSelect}
  onClose={() => (pickerOpen = false)}
/>

<style>
  .shpd-decision {
    display: flex;
    flex-direction: column;
    gap: var(--shpd-space-xs);
    font-size: 0.875rem;
  }

  .shpd-decision__current {
    background: var(--shpd-color-primary-soft);
    color: var(--shpd-color-primary);
    padding: var(--shpd-space-xs) var(--shpd-space-sm);
    border-radius: 4px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: var(--shpd-space-sm);
    font-size: 0.8125rem;
  }

  .shpd-decision__unselect {
    background: transparent;
    border: 0;
    text-decoration: underline;
    color: inherit;
    cursor: pointer;
    font-size: 0.75rem;
    flex-shrink: 0;
  }

  .shpd-decision__heading {
    font-size: 0.6875rem;
    text-transform: uppercase;
    color: var(--shpd-color-text-muted);
    letter-spacing: 0.5px;
    margin-bottom: var(--shpd-space-xs);
  }

  .shpd-decision__candidate-list {
    list-style: none;
    padding: 0;
    margin: 0;
    display: flex;
    flex-direction: column;
    gap: 2px;
  }

  .shpd-decision__candidate {
    width: 100%;
    text-align: left;
    background: transparent;
    border: 1px solid var(--shpd-color-border);
    padding: var(--shpd-space-xs) var(--shpd-space-sm);
    border-radius: 4px;
    cursor: pointer;
    color: var(--shpd-color-text);
    font-size: 0.8125rem;
  }

  .shpd-decision__candidate:hover {
    background: var(--shpd-color-primary-soft);
  }

  .shpd-decision__sep {
    border: 0;
    border-top: 1px solid var(--shpd-color-border);
    margin: var(--shpd-space-xs) 0;
  }

  .shpd-decision__actions {
    display: flex;
    flex-direction: column;
    gap: var(--shpd-space-xs);
  }

  .shpd-decision__action {
    background: var(--shpd-color-primary-soft);
    border: 1px solid var(--shpd-color-border);
    padding: var(--shpd-space-xs) var(--shpd-space-sm);
    border-radius: 4px;
    cursor: pointer;
    color: var(--shpd-color-text);
    font-size: 0.875rem;
    text-align: left;
  }

  .shpd-decision__action:hover {
    background: var(--shpd-color-primary);
    color: white;
  }

  .shpd-decision__action--danger {
    background: var(--shpd-color-state-cancelled-bg);
    color: var(--shpd-color-state-cancelled-text);
  }

  .shpd-decision__action--danger:hover {
    background: var(--shpd-color-danger);
    color: white;
  }
</style>
