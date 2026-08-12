<script>
  // Nabídka účetních položek — karta v sekci „Volitelné" panelu dsSetup
  // (ds-setup Task 10, D18/D19). Jednorázová akce: vygeneruje položky pro
  // účtování bankovních poplatků, kurzových rozdílů a zaokrouhlení přímo
  // z řádku dokladu (acc.entry). Není to checklist ani alert — nesplněná
  // nabídka nikdy nic nerozsvítí.
  //
  // Komponenta si drží vlastní stav (fetch/generate) a s checklistem
  // v DsSetup nijak neinteraguje.
  import { onMount } from 'svelte';
  import Button from '../ui/Button.svelte';
  import Checkbox from '../ui/Checkbox.svelte';
  import { t } from '../../i18n/index.js';
  import { translateError } from '../../i18n/errors.js';
  import { fetchAccountingItemsOffer, generateAccountingItems } from '../../api/setup.js';

  let loading = $state(true);
  let loadError = $state(null);
  let offer = $state(null);
  let checked = $state({});   // code → bool

  let generating = $state(false);
  let generateError = $state(null);
  let summary = $state(null); // {createdCount, skipped: [...]}

  async function load() {
    loading = true;
    loadError = null;
    try {
      const result = await fetchAccountingItemsOffer();
      if (result?.success) {
        offer = result.data;
        // Předvybrat vše, co ještě neexistuje — nabídka je krátká a míněná
        // jako celek; odškrtnutí je opt-out.
        const preselect = {};
        for (const c of offer?.candidates ?? []) {
          preselect[c.code] = !c.exists;
        }
        checked = preselect;
      } else {
        loadError = translateError(result?.error);
      }
    } catch (e) {
      loadError = e instanceof Error ? e.message : String(e);
    } finally {
      loading = false;
    }
  }

  const selectedCodes = $derived(
    (offer?.candidates ?? []).filter((c) => !c.exists && checked[c.code]).map((c) => c.code),
  );

  const unavailableText = $derived.by(() => {
    const reason = offer?.unavailableReason;
    if (!reason) return null;
    const key = `setup.offer.items.unavailable.${reason}`;
    return t(key);
  });

  async function handleGenerate() {
    if (selectedCodes.length === 0 || generating) return;
    generating = true;
    generateError = null;
    summary = null;
    try {
      const result = await generateAccountingItems(selectedCodes);
      if (result?.success) {
        // Souhrn zůstává viditelný nad překreslenou nabídkou — přeskočené
        // položky má uživatel vidět, nic se nezavírá.
        summary = {
          createdCount: (result.data?.created ?? []).length,
          skipped: result.data?.skipped ?? [],
        };
        await load();
      } else {
        generateError = translateError(result?.error);
      }
    } catch (e) {
      generateError = e instanceof Error ? e.message : String(e);
    } finally {
      generating = false;
    }
  }

  function skippedLine(entry) {
    const reasonKey = `setup.offer.items.skipped.${entry.reason}`;
    const reason = entry.accountNumber
      ? t(reasonKey, { account: entry.accountNumber })
      : t(reasonKey);
    return `${entry.code} — ${reason}`;
  }

  onMount(load);
</script>

<div class="shpd-items-offer">
  <div class="shpd-items-offer__head">
    <span class="shpd-items-offer__title">{t('setup.offer.items.title')}</span>
  </div>
  <p class="shpd-items-offer__desc">{t('setup.offer.items.description')}</p>

  {#if loading}
    <p class="shpd-items-offer__state">{t('common.loading')}</p>
  {:else if loadError}
    <p class="shpd-items-offer__state shpd-items-offer__state--error">{loadError}</p>
  {:else if offer && !offer.available}
    <!-- Nedostupná nabídka se NEschovává — uživatel má vědět, že existuje
         a proč teď nejde (chart_undecided / chart_none / accounting_inactive). -->
    <p class="shpd-items-offer__state shpd-items-offer__state--muted">{unavailableText}</p>
  {:else if offer}
    {#if summary}
      <div class="shpd-items-offer__summary" role="status">
        <p>{t('setup.offer.items.summary.created', { count: summary.createdCount })}</p>
        {#if summary.skipped.length > 0}
          <p>{t('setup.offer.items.summary.skipped', { count: summary.skipped.length })}</p>
          <ul class="shpd-items-offer__skipped">
            {#each summary.skipped as entry (entry.code)}
              <li>{skippedLine(entry)}</li>
            {/each}
          </ul>
        {/if}
      </div>
    {/if}

    <ul class="shpd-items-offer__list">
      {#each offer.candidates as candidate (candidate.code)}
        <li class="shpd-items-offer__row" class:shpd-items-offer__row--exists={candidate.exists}>
          <Checkbox
            bind:checked={checked[candidate.code]}
            disabled={candidate.exists || generating}
          />
          <span class="shpd-items-offer__name">{candidate.name}</span>
          <span class="shpd-items-offer__account">{candidate.accountNumber}</span>
          {#if candidate.exists}
            <span class="shpd-items-offer__badge">{t('setup.offer.items.exists')}</span>
          {/if}
        </li>
      {/each}
    </ul>

    {#if generateError}
      <p class="shpd-items-offer__state shpd-items-offer__state--error">{generateError}</p>
    {/if}

    <div class="shpd-items-offer__actions">
      <Button
        label={generating ? t('common.saving') : t('setup.offer.items.generate', { count: selectedCodes.length })}
        size="sm"
        disabled={selectedCodes.length === 0 || generating}
        loading={generating}
        onclick={handleGenerate}
      />
    </div>
  {/if}
</div>

<style>
  .shpd-items-offer {
    padding: var(--shpd-space-md);
    border: 1px solid var(--shpd-color-border);
    border-radius: var(--shpd-radius-md);
    background-color: var(--shpd-color-bg-secondary);
  }

  .shpd-items-offer__head {
    display: flex;
    align-items: center;
    gap: var(--shpd-space-sm);
  }

  .shpd-items-offer__title {
    font-weight: 600;
    color: var(--shpd-color-text);
  }

  .shpd-items-offer__desc {
    margin-top: var(--shpd-space-xs);
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text-secondary);
  }

  .shpd-items-offer__state {
    margin-top: var(--shpd-space-sm);
    color: var(--shpd-color-text-secondary);
    font-size: var(--shpd-font-size-sm);
  }

  .shpd-items-offer__state--error {
    color: var(--shpd-color-danger);
  }

  .shpd-items-offer__state--muted {
    font-style: italic;
  }

  .shpd-items-offer__summary {
    margin-top: var(--shpd-space-sm);
    padding: var(--shpd-space-sm) var(--shpd-space-md);
    border: 1px solid var(--shpd-color-border);
    border-radius: var(--shpd-radius-md);
    background-color: var(--shpd-color-bg);
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text);
  }

  .shpd-items-offer__skipped {
    margin: var(--shpd-space-xs) 0 0;
    padding-left: var(--shpd-space-lg);
    color: var(--shpd-color-text-secondary);
  }

  .shpd-items-offer__list {
    list-style: none;
    margin: var(--shpd-space-sm) 0 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: var(--shpd-space-xs);
  }

  .shpd-items-offer__row {
    display: flex;
    align-items: center;
    gap: var(--shpd-space-sm);
  }

  .shpd-items-offer__row--exists {
    opacity: 0.6;
  }

  .shpd-items-offer__name {
    color: var(--shpd-color-text);
  }

  .shpd-items-offer__account {
    font-family: var(--shpd-font-mono, ui-monospace, SFMono-Regular, Menlo, monospace);
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text-secondary);
  }

  .shpd-items-offer__badge {
    padding: 1px var(--shpd-space-sm);
    border-radius: var(--shpd-radius-sm);
    font-size: var(--shpd-font-size-xs, 0.75rem);
    background: var(--shpd-color-bg-hover);
    color: var(--shpd-color-text-muted);
    border: 1px solid var(--shpd-color-border);
    white-space: nowrap;
  }

  .shpd-items-offer__actions {
    margin-top: var(--shpd-space-md);
  }
</style>
