<script module>
  /**
   * Label zvoleného intervalu období DPH: název období („01/2026"),
   * rozsah („01/2026–03/2026"), u více registrací s prefixem názvu
   * registrace. Nezarovnaný interval (deep-link) degraduje na data.
   *
   * @param {{vatRegistration: number, dateFrom: string, dateTo: string}|null} value
   * @param {Array<{id: number, name: string, periods: Array<object>}>} registrations
   * @returns {string}
   */
  export function formatVatPeriodLabel(value, registrations = []) {
    if (!value) return '';
    const registration = registrations.find((r) => r.id === value.vatRegistration);
    const periods = (registration?.periods ?? []).filter(
      (p) => p.dateBegin >= value.dateFrom && p.dateEnd <= value.dateTo,
    );
    let label;
    if (periods.length === 1) label = periods[0].name;
    else if (periods.length > 1) label = `${periods[0].name}–${periods.at(-1).name}`;
    else label = `${value.dateFrom}–${value.dateTo}`;
    return registration && registrations.length > 1
      ? `${registration.name} — ${label}`
      : label;
  }
</script>

<script>
  // Picker období DPH — protějšek PeriodPicker pro reporty s periodSource
  // 'vatPeriod'. Controlled: dostane hodnotu a onChange, stav drží rodič.
  // Nabízí období zvolené registrace seskupená po letech; u měsíční
  // registrace navíc sloučené kvartály (jen když všechna 3 období existují
  // a navazují — server přesné pokrytí vynucuje).
  import Popover from '../ui/Popover.svelte';
  import Select from '../ui/Select.svelte';
  import { t } from '../../i18n/index.js';

  let {
    registrations = [],
    value = null,
    disabled = false,
    onChange,
  } = $props();

  let open = $state(false);
  let anchorEl = $state(null);

  // Registrace zvolená v panelu — sleduje value, dokud uživatel nepřepne.
  let panelRegistrationId = $state(null);
  $effect(() => {
    panelRegistrationId = value?.vatRegistration ?? registrations[0]?.id ?? null;
  });
  const registration = $derived(
    registrations.find((r) => r.id === panelRegistrationId) ?? null,
  );

  const registrationOptions = $derived(registrations.map((r) => ({
    value: r.id,
    label: r.vatId ? `${r.name} (${r.vatId})` : r.name,
  })));

  // Období per rok (dle roku date_begin), nejnovější rok nahoře.
  const yearRows = $derived.by(() => {
    const byYear = new Map();
    for (const period of registration?.periods ?? []) {
      const year = period.dateBegin.slice(0, 4);
      if (!byYear.has(year)) byYear.set(year, []);
      byYear.get(year).push(period);
    }
    return [...byYear.entries()]
      .map(([year, periods]) => ({ year, periods, quarters: quartersOf(periods) }))
      .sort((a, b) => b.year.localeCompare(a.year));
  });

  function nextDay(isoDate) {
    const date = new Date(isoDate);
    date.setUTCDate(date.getUTCDate() + 1);
    return date.toISOString().slice(0, 10);
  }

  // Sloučené kvartály měsíční registrace (KH/SH kvartálního plátce DP3 se
  // řeší obráceně — kvartální období jsou přímo v seznamu).
  function quartersOf(periods) {
    if (registration?.taxPeriodKind !== 1) return [];
    const out = [];
    for (let q = 1; q <= 4; q++) {
      const months = periods.filter((p) => {
        const month = Number(p.dateBegin.slice(5, 7));
        return month >= 3 * q - 2 && month <= 3 * q;
      });
      if (months.length !== 3) continue;
      if (months.some((p, i) => i > 0 && nextDay(months[i - 1].dateEnd) !== p.dateBegin)) continue;
      out.push({ q, dateFrom: months[0].dateBegin, dateTo: months.at(-1).dateEnd });
    }
    return out;
  }

  // Krátký label buňky: „01/2026" → „1", „Q1/2026" → „1Q", jinak celý název.
  function cellLabel(period) {
    const month = /^(\d{2})\/\d{4}$/.exec(period.name);
    if (month) return String(Number(month[1]));
    const quarter = /^Q(\d)\/\d{4}$/.exec(period.name);
    if (quarter) return `${quarter[1]}Q`;
    return period.name;
  }

  function select(dateFrom, dateTo) {
    open = false;
    onChange?.({ vatRegistration: panelRegistrationId, dateFrom, dateTo });
  }

  function isActive(dateFrom, dateTo) {
    return value !== null
      && value.vatRegistration === panelRegistrationId
      && value.dateFrom === dateFrom
      && value.dateTo === dateTo;
  }
</script>

<button
  type="button"
  class="shpd-period__trigger"
  bind:this={anchorEl}
  {disabled}
  aria-haspopup="dialog"
  aria-expanded={open}
  onclick={() => { if (!disabled) open = !open; }}
>
  <span class="shpd-period__trigger-label">{t('reports.period.label')}:</span>
  <span class="shpd-period__trigger-value">{formatVatPeriodLabel(value, registrations)}</span>
  <span class="shpd-period__trigger-arrow" aria-hidden="true">▾</span>
</button>

<Popover {open} anchor={anchorEl} placement="bottom" width="auto" onClose={() => { open = false; }}>
  <div class="shpd-period__panel">
    {#if registrations.length > 1}
      <div class="shpd-period__registration">
        <span class="shpd-period__registration-label">{t('reports.vatRegistration.label')}</span>
        <Select bind:value={panelRegistrationId} options={registrationOptions} required />
      </div>
    {/if}
    <div class="shpd-period__grid">
      {#each yearRows as row (row.year)}
        <div class="shpd-period__row">
          <span class="shpd-period__year">{row.year}</span>
          <span class="shpd-period__cells">
            {#each row.periods as period (period.id)}
              <button
                type="button"
                class="shpd-period__cell"
                class:shpd-period__cell--active={isActive(period.dateBegin, period.dateEnd)}
                class:shpd-period__cell--locked={period.locked}
                title={period.locked ? t('reports.period.locked') : undefined}
                onclick={() => select(period.dateBegin, period.dateEnd)}
              >{cellLabel(period)}</button>
            {/each}
          </span>
          {#if row.quarters.length > 0}
            <span class="shpd-period__cells shpd-period__cells--divided">
              {#each row.quarters as quarter (quarter.q)}
                <button
                  type="button"
                  class="shpd-period__cell"
                  class:shpd-period__cell--active={isActive(quarter.dateFrom, quarter.dateTo)}
                  onclick={() => select(quarter.dateFrom, quarter.dateTo)}
                >{quarter.q}Q</button>
              {/each}
            </span>
          {/if}
        </div>
      {/each}
    </div>
  </div>
</Popover>

<style>
  .shpd-period__trigger {
    display: inline-flex;
    align-items: center;
    gap: var(--shpd-space-xs);
    padding: var(--shpd-space-xs) var(--shpd-space-sm);
    background-color: var(--shpd-color-bg);
    border: 1px solid var(--shpd-color-border);
    border-radius: var(--shpd-radius-md);
    color: var(--shpd-color-text);
    font-size: var(--shpd-font-size-sm);
    font-family: var(--shpd-font-family);
    cursor: pointer;
  }

  .shpd-period__trigger:disabled {
    opacity: 0.6;
    cursor: not-allowed;
  }

  .shpd-period__trigger:hover:not(:disabled) {
    background-color: var(--shpd-color-bg-secondary);
  }

  .shpd-period__trigger-label {
    color: var(--shpd-color-text-secondary);
  }

  .shpd-period__trigger-value {
    font-weight: 600;
  }

  .shpd-period__trigger-arrow {
    color: var(--shpd-color-text-secondary);
    font-size: var(--shpd-font-size-sm);
  }

  .shpd-period__panel {
    display: flex;
    flex-direction: column;
    gap: var(--shpd-space-sm);
  }

  .shpd-period__registration {
    display: flex;
    align-items: center;
    gap: var(--shpd-space-sm);
  }

  .shpd-period__registration-label {
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text-secondary);
    white-space: nowrap;
  }

  .shpd-period__grid {
    display: flex;
    flex-direction: column;
    gap: 2px;
    max-height: 60vh;
    overflow-y: auto;
  }

  .shpd-period__row {
    display: flex;
    align-items: center;
    gap: var(--shpd-space-xs);
  }

  .shpd-period__year {
    width: 3.2em;
    flex: none;
    font-weight: 600;
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text);
  }

  .shpd-period__cells {
    display: inline-flex;
    gap: 2px;
  }

  .shpd-period__cells--divided {
    padding-left: var(--shpd-space-xs);
    border-left: 1px solid var(--shpd-color-border);
    margin-left: var(--shpd-space-xs);
  }

  .shpd-period__cell {
    min-width: 2em;
    padding: 2px 4px;
    background: none;
    border: 1px solid transparent;
    border-radius: var(--shpd-radius-sm, 4px);
    color: var(--shpd-color-text);
    font-size: var(--shpd-font-size-sm);
    font-variant-numeric: tabular-nums;
    cursor: pointer;
    text-align: center;
  }

  .shpd-period__cell:hover {
    background-color: var(--shpd-color-bg-hover);
  }

  .shpd-period__cell--active {
    background-color: var(--shpd-color-accent);
    color: var(--shpd-color-text-on-accent, #fff);
    font-weight: 600;
  }

  .shpd-period__cell--locked {
    text-decoration: underline dotted;
    text-underline-offset: 3px;
  }
</style>
