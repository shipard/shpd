<script>
  // Dialog „Založit Registraci DPH" pro panel dsSetup (ds-setup Task 09,
  // §5.4 bod 2). Prefill přijde z GET /_setup/vat-registration-prefill;
  // tři pole, která registr nezná (valid_from + obě frekvence), doplní
  // uživatel vědomě — záměrně bez defaultů.
  //
  // Uložení jde přes generický POST /_ui/form/.../save, tedy přes
  // VatRegistrationDocument — afterSave hook hned dogeneruje období DPH.
  // Přímý INSERT by uživatele nechal s registrací bez období, což se
  // projeví až u prvního přiznání.
  import Modal from '../ui/Modal.svelte';
  import Button from '../ui/Button.svelte';
  import Select from '../ui/Select.svelte';
  import DateInput from '../ui/DateInput.svelte';
  import { t } from '../../i18n/index.js';
  import { translateError } from '../../i18n/errors.js';
  import { post } from '../../api/client.js';
  import { fetchVatRegistrationPrefill } from '../../api/setup.js';

  let {
    open = false,
    onClose = () => {},
    onSaved = () => {},
  } = $props();

  let loading = $state(true);
  let loadError = $state(null);
  let values = $state(null);
  let periodKindOptions = $state([]);

  // Tři pole na doplnění — bind cíle.
  let validFrom = $state('');
  let taxPeriodKind = $state(null);
  let reportPeriodKind = $state(null);

  let saving = $state(false);
  let saveError = $state(null);
  let fieldErrors = $state({});

  $effect(() => {
    if (open) {
      void resetAndLoad();
    }
  });

  async function resetAndLoad() {
    loading = true;
    loadError = null;
    values = null;
    periodKindOptions = [];
    validFrom = '';
    taxPeriodKind = null;
    reportPeriodKind = null;
    saving = false;
    saveError = null;
    fieldErrors = {};
    try {
      const result = await fetchVatRegistrationPrefill();
      if (result?.success) {
        values = result.data?.values ?? null;
        periodKindOptions = result.data?.periodKindOptions ?? [];
      } else {
        loadError = translateError(result?.error);
      }
    } catch (e) {
      loadError = e instanceof Error ? e.message : String(e);
    } finally {
      loading = false;
    }
  }

  const canSave = $derived(
    !loading && !saving && values !== null
      && validFrom !== '' && taxPeriodKind !== null && reportPeriodKind !== null,
  );

  async function handleSave() {
    if (!canSave) return;
    saving = true;
    saveError = null;
    fieldErrors = {};
    try {
      const result = await post('/_ui/form/economy_codebooks_vat_registrations/save', {
        ...values,
        valid_from: validFrom,
        tax_period_kind: taxPeriodKind,
        report_period_kind: reportPeriodKind,
      });
      if (result?.success) {
        onSaved();
        onClose();
        return;
      }
      const details = Array.isArray(result?.error?.details) ? result.error.details : [];
      const byField = {};
      for (const d of details) {
        if (d?.field) byField[d.field] = d.message;
      }
      fieldErrors = byField;
      saveError = details.length > 0 ? null : translateError(result?.error);
    } catch (e) {
      saveError = e instanceof Error ? e.message : String(e);
    } finally {
      saving = false;
    }
  }
</script>

<Modal title={t('setup.vatDialog.title')} {open} {onClose}>
  <div class="shpd-vat-prefill">
    {#if loading}
      <p class="shpd-vat-prefill__state">{t('common.loading')}</p>
    {:else if loadError}
      <p class="shpd-vat-prefill__state shpd-vat-prefill__state--error">{loadError}</p>
    {:else if values}
      <!-- Předvyplněný zbytek jen k nahlédnutí — zdrojem je vlastní Osoba
           a vrstva A, editace patří do plného formuláře („Zadat ručně"). -->
      <dl class="shpd-vat-prefill__summary">
        <div><dt>{t('setup.vatDialog.field.name')}</dt><dd>{values.name || '—'}</dd></div>
        <div><dt>{t('setup.vatDialog.field.vatId')}</dt><dd class="shpd-vat-prefill__mono">{values.vat_id ?? '—'}</dd></div>
        <div><dt>{t('setup.vatDialog.field.country')}</dt><dd>{(values.country ?? '').toUpperCase()}</dd></div>
      </dl>

      <div class="shpd-vat-prefill__field">
        <label for="vat-prefill-valid-from">{t('setup.vatDialog.validFrom')}</label>
        <DateInput id="vat-prefill-valid-from" bind:value={validFrom} required error={fieldErrors.valid_from ?? null} />
        {#if fieldErrors.valid_from}
          <p class="shpd-vat-prefill__error">{fieldErrors.valid_from}</p>
        {/if}
        <p class="shpd-vat-prefill__hint">{t('setup.vatDialog.validFromHint')}</p>
      </div>

      <div class="shpd-vat-prefill__field">
        <label for="vat-prefill-tax-period">{t('setup.vatDialog.taxPeriod')}</label>
        <Select
          id="vat-prefill-tax-period"
          bind:value={taxPeriodKind}
          options={periodKindOptions}
          placeholder={t('setup.vatDialog.periodPlaceholder')}
          error={fieldErrors.tax_period_kind ?? null}
        />
      </div>

      <div class="shpd-vat-prefill__field">
        <label for="vat-prefill-report-period">{t('setup.vatDialog.reportPeriod')}</label>
        <Select
          id="vat-prefill-report-period"
          bind:value={reportPeriodKind}
          options={periodKindOptions}
          placeholder={t('setup.vatDialog.periodPlaceholder')}
          error={fieldErrors.report_period_kind ?? null}
        />
      </div>

      {#if saveError}
        <p class="shpd-vat-prefill__state shpd-vat-prefill__state--error">{saveError}</p>
      {/if}
    {/if}
  </div>

  {#snippet footer()}
    <Button label={t('common.cancel')} variant="secondary" disabled={saving} onclick={onClose} />
    <Button
      label={saving ? t('common.saving') : t('setup.vatDialog.save')}
      variant="primary"
      disabled={!canSave}
      loading={saving}
      onclick={handleSave}
    />
  {/snippet}
</Modal>

<style>
  .shpd-vat-prefill {
    padding: var(--shpd-space-lg);
    display: flex;
    flex-direction: column;
    gap: var(--shpd-space-md);
  }

  .shpd-vat-prefill__state {
    color: var(--shpd-color-text-secondary);
  }

  .shpd-vat-prefill__state--error {
    color: var(--shpd-color-danger);
  }

  .shpd-vat-prefill__summary {
    margin: 0;
    padding: var(--shpd-space-md);
    border: 1px solid var(--shpd-color-border);
    border-radius: var(--shpd-radius-md);
    background: var(--shpd-color-bg-secondary);
    display: grid;
    grid-template-columns: max-content 1fr;
    gap: var(--shpd-space-xs) var(--shpd-space-lg);
  }

  .shpd-vat-prefill__summary div {
    display: contents;
  }

  .shpd-vat-prefill__summary dt {
    color: var(--shpd-color-text-secondary);
    font-size: var(--shpd-font-size-sm);
  }

  .shpd-vat-prefill__summary dd {
    margin: 0;
    color: var(--shpd-color-text);
  }

  .shpd-vat-prefill__mono {
    font-family: var(--shpd-font-mono, ui-monospace, SFMono-Regular, Menlo, monospace);
  }

  .shpd-vat-prefill__field {
    display: flex;
    flex-direction: column;
    gap: var(--shpd-space-xs);
    max-width: 320px;
  }

  .shpd-vat-prefill__field label {
    font-size: var(--shpd-font-size-sm);
    font-weight: 600;
    color: var(--shpd-color-text);
  }

  .shpd-vat-prefill__hint {
    font-size: var(--shpd-font-size-xs, 0.75rem);
    color: var(--shpd-color-text-secondary);
  }

  .shpd-vat-prefill__error {
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-danger);
  }
</style>
