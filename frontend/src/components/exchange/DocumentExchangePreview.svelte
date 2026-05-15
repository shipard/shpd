<script>
  // Read-only canonical document visualization.
  //
  // Renders a `shpd.docs.document.v1` payload as a human-readable invoice
  // preview with status badges per resolve outcome (matched/canCreate/
  // ambiguous/notFound). Badges are non-interactive in Phase 3a; Phase 3b
  // promotes them to <button>s that open EntityPicker.
  //
  // For `aiFailed=true` (status=70 from /result with ai_failed wrapper) the
  // component switches to an error view that surfaces _validationError +
  // _validationIssues + raw output (collapsible).
  //
  // Props:
  //   canonical  shpd.docs.document.v1 payload (with _resolve, optional)
  //   aiFailed   boolean — if true, render error view from wrapper
  //   wrapper    AI failed wrapper {_validationError, _validationIssues, _rawOutput}

  import { t } from '../../i18n/index.js';

  let { canonical = null, aiFailed = false, wrapper = null } = $props();

  // Derived helpers
  let docType = $derived(canonical?.docType ?? null);
  let docTypeLabel = $derived(
    docType ? t(`exchange.preview.docType.${docType}`) : '',
  );
  let resolve = $derived(canonical?._resolve ?? null);
  let summary = $derived(resolve?.summary ?? null);
  let issues = $derived(resolve?.issues ?? []);

  function statusKey(status) {
    if (status === 'matched') return 'matched';
    if (status === 'canCreate') return 'canCreate';
    if (status === 'ambiguous') return 'ambiguous';
    if (status === 'notFound') return 'notFound';
    return null;
  }

  function statusLabel(resolveBlock) {
    if (!resolveBlock) return null;
    const s = resolveBlock.status;
    if (s === 'matched') {
      const id = resolveBlock.matchedId;
      const by = resolveBlock.matchedBy;
      if (by === 'created') {
        return t('exchange.preview.status.matchedCreated', { id });
      }
      return t('exchange.preview.status.matched', { id });
    }
    if (s === 'canCreate') return t('exchange.preview.status.canCreate');
    if (s === 'ambiguous') {
      return t('exchange.preview.status.ambiguous', {
        count: (resolveBlock.candidates ?? []).length,
      });
    }
    if (s === 'notFound') return t('exchange.preview.status.notFound');
    return null;
  }

  function statusGlyph(s) {
    if (s === 'matched') return '✓';
    if (s === 'canCreate') return '+';
    if (s === 'ambiguous') return '?';
    if (s === 'notFound') return '✗';
    return null;
  }

  function formatMoney(value, currency) {
    if (value === null || value === undefined) return '—';
    try {
      return new Intl.NumberFormat('cs-CZ', {
        style: 'currency',
        currency: currency ?? 'CZK',
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
      }).format(value);
    } catch {
      return String(value);
    }
  }

  function formatDate(iso) {
    if (!iso) return '—';
    try {
      return new Intl.DateTimeFormat('cs-CZ').format(new Date(iso));
    } catch {
      return String(iso);
    }
  }

  function formatAddress(addr) {
    if (!addr) return '—';
    if (addr.displayLine) return addr.displayLine;
    const street = [addr.street, addr.houseNumber].filter(Boolean).join(' ');
    const city = [addr.zip, addr.city].filter(Boolean).join(' ');
    return [street, city].filter(Boolean).join(', ') || '—';
  }

  function issueGlyph(severity) {
    if (severity === 'error') return '✗';
    if (severity === 'warning') return '⚠';
    return 'ℹ';
  }
</script>

{#snippet statusBadge(resolveBlock)}
  {#if resolveBlock && statusKey(resolveBlock.status)}
    {@const sk = statusKey(resolveBlock.status)}
    <span
      class="shpd-exchange__status shpd-exchange__status--{sk}"
      title={statusLabel(resolveBlock)}
    >
      <span class="shpd-exchange__status-glyph">{statusGlyph(sk)}</span>
    </span>
  {/if}
{/snippet}

{#snippet partyCard(label, party, partyResolve)}
  <div class="shpd-exchange__party">
    <div class="shpd-exchange__party-header">
      <h3 class="shpd-exchange__party-label">{label}</h3>
      {@render statusBadge(partyResolve)}
    </div>
    {#if party}
      <div class="shpd-exchange__party-body">
        {#if party.name}
          <div class="shpd-exchange__party-name">{party.name}</div>
        {/if}
        {#if party.companyId || party.taxId || party.vatId}
          <div class="shpd-exchange__party-ids">
            {#if party.companyId}
              <span>{t('exchange.preview.field.companyId')}: <strong>{party.companyId}</strong></span>
            {/if}
            {#if party.taxId}
              <span>{t('exchange.preview.field.taxId')}: <strong>{party.taxId}</strong></span>
            {/if}
            {#if party.vatId && party.vatId !== party.taxId}
              <span>{t('exchange.preview.field.vatId')}: <strong>{party.vatId}</strong></span>
            {/if}
          </div>
        {/if}
        {#if party.address}
          <div class="shpd-exchange__party-address">{formatAddress(party.address)}</div>
        {/if}
        {#if party.contact?.email || party.contact?.phone}
          <div class="shpd-exchange__party-contact">
            {#if party.contact?.email}<span>{party.contact.email}</span>{/if}
            {#if party.contact?.phone}<span>{party.contact.phone}</span>{/if}
          </div>
        {/if}
        {#if party.bankAccount?.iban || party.bankAccount?.accountNumber}
          <div class="shpd-exchange__party-bank">
            {t('exchange.preview.field.bankAccount')}:
            <strong>{party.bankAccount.iban ?? party.bankAccount.accountNumber}</strong>
          </div>
        {/if}
      </div>
    {:else}
      <div class="shpd-exchange__party-empty">—</div>
    {/if}
  </div>
{/snippet}

{#snippet field(label, value)}
  <div class="shpd-exchange__field">
    <span class="shpd-exchange__field-label">{label}</span>
    <span class="shpd-exchange__field-value">{value ?? '—'}</span>
  </div>
{/snippet}

{#if aiFailed && wrapper}
  <div class="shpd-exchange shpd-exchange--ai-failed">
    <div class="shpd-exchange__ai-failed-header">
      <span class="shpd-exchange__ai-failed-icon" aria-hidden="true">⚠</span>
      <h2>{t('exchange.preview.aiFailed.title')}</h2>
    </div>
    <p class="shpd-exchange__ai-failed-message">
      {t('exchange.preview.aiFailed.message')}
    </p>
    {#if wrapper._validationIssues && wrapper._validationIssues.length > 0}
      <h3>{t('exchange.preview.aiFailed.issues')}</h3>
      <ul class="shpd-exchange__ai-failed-issues">
        {#each wrapper._validationIssues as issue}
          <li>
            <code>{issue.path}</code> — {issue.message}
          </li>
        {/each}
      </ul>
    {/if}
    <details class="shpd-exchange__ai-failed-raw">
      <summary>{t('exchange.preview.aiFailed.rawOutput')}</summary>
      <pre>{JSON.stringify(wrapper._rawOutput, null, 2)}</pre>
    </details>
  </div>
{:else if canonical}
  <div class="shpd-exchange">
    <header class="shpd-exchange__header">
      <div class="shpd-exchange__header-main">
        <span class="shpd-exchange__type-badge">{docTypeLabel}</span>
        <h2 class="shpd-exchange__title">{canonical.docNumber ?? '—'}</h2>
        {#if canonical.docText}
          <p class="shpd-exchange__subtitle">{canonical.docText}</p>
        {/if}
      </div>
      {#if summary}
        <span
          class="shpd-exchange__summary-badge shpd-exchange__summary-badge--{summary.status}"
        >
          {summary.status === 'ok'
            ? t('exchange.preview.status.summary.ok')
            : t('exchange.preview.status.summary.needsAttention')}
        </span>
      {/if}
    </header>

    <section class="shpd-exchange__parties">
      {@render partyCard(
        t('exchange.preview.section.supplier'),
        canonical.supplier,
        resolve?.supplier,
      )}
      {@render partyCard(
        t('exchange.preview.section.customer'),
        canonical.customer,
        resolve?.customer,
      )}
    </section>

    <section class="shpd-exchange__meta-grid">
      {@render field(t('exchange.preview.field.issueDate'), formatDate(canonical.dates?.issueDate))}
      {@render field(t('exchange.preview.field.dueDate'), formatDate(canonical.dates?.dueDate))}
      {@render field(t('exchange.preview.field.accountingDate'), formatDate(canonical.dates?.accountingDate))}
      {@render field(t('exchange.preview.field.taxPointDate'), formatDate(canonical.dates?.taxPointDate))}
      {@render field(t('exchange.preview.field.vatObligationDate'), formatDate(canonical.dates?.vatObligationDate))}
      {@render field(t('exchange.preview.field.currency'), canonical.currency)}
      {@render field(t('exchange.preview.field.paymentMethod'), canonical.payment?.method)}
      {@render field(t('exchange.preview.field.variableSymbol'), canonical.payment?.variableSymbol)}
    </section>

    {#if (canonical.rows ?? []).length > 0}
      <section class="shpd-exchange__section">
        <h3 class="shpd-exchange__section-heading">
          {t('exchange.preview.section.rows')}
        </h3>
        <table class="shpd-exchange__rows">
          <thead>
            <tr>
              <th class="shpd-exchange__rows-pos">{t('exchange.preview.row.position')}</th>
              <th>{t('exchange.preview.row.item')}</th>
              <th class="num">{t('exchange.preview.row.quantity')}</th>
              <th>{t('exchange.preview.row.unit')}</th>
              <th class="num">{t('exchange.preview.row.unitPrice')}</th>
              <th>{t('exchange.preview.row.vat')}</th>
              <th class="num">{t('exchange.preview.row.total')}</th>
            </tr>
          </thead>
          <tbody>
            {#each canonical.rows as row, i}
              <tr>
                <td>{row.orderPos ?? i + 1}</td>
                <td>
                  <span class="shpd-exchange__row-name">{row.item?.name ?? '—'}</span>
                  {#if row.item?.supplierCode}
                    <span class="shpd-exchange__row-code">{row.item.supplierCode}</span>
                  {/if}
                  {@render statusBadge(resolve?.rows?.[i]?.item)}
                </td>
                <td class="num">{row.quantity ?? '—'}</td>
                <td>
                  {row.unit ?? '—'}
                  {@render statusBadge(resolve?.rows?.[i]?.unit)}
                </td>
                <td class="num">{formatMoney(row.unitPrice, canonical.currency)}</td>
                <td>
                  {row.vat?.pct ?? '—'}%
                  {@render statusBadge(resolve?.rows?.[i]?.vatCode)}
                </td>
                <td class="num">{formatMoney(row.totalPrice, canonical.currency)}</td>
              </tr>
            {/each}
          </tbody>
        </table>
      </section>
    {/if}

    {#if (canonical.vatRecap ?? []).length > 0 || canonical.totals}
      <section class="shpd-exchange__section shpd-exchange__totals-section">
        {#if (canonical.vatRecap ?? []).length > 0}
          <table class="shpd-exchange__vat-recap">
            <thead>
              <tr>
                <th>{t('exchange.preview.row.vat')}</th>
                <th class="num">{t('exchange.preview.totals.base')}</th>
                <th class="num">{t('exchange.preview.totals.vat')}</th>
                <th class="num">{t('exchange.preview.totals.total')}</th>
              </tr>
            </thead>
            <tbody>
              {#each canonical.vatRecap as r}
                <tr>
                  <td>{r.vatPct}%</td>
                  <td class="num">{formatMoney(r.base, canonical.currency)}</td>
                  <td class="num">{formatMoney(r.tax, canonical.currency)}</td>
                  <td class="num">{formatMoney(r.total, canonical.currency)}</td>
                </tr>
              {/each}
            </tbody>
          </table>
        {/if}
        {#if canonical.totals}
          <div class="shpd-exchange__totals-summary">
            <div>
              {t('exchange.preview.totals.base')}:
              <strong>{formatMoney(canonical.totals.totalBase, canonical.currency)}</strong>
            </div>
            <div>
              {t('exchange.preview.totals.vat')}:
              <strong>{formatMoney(canonical.totals.totalVat, canonical.currency)}</strong>
            </div>
            <div class="shpd-exchange__total">
              {t('exchange.preview.totals.total')}:
              <strong>{formatMoney(canonical.totals.totalAmount, canonical.currency)}</strong>
            </div>
          </div>
        {/if}
      </section>
    {/if}

    {#if issues.length > 0}
      <section class="shpd-exchange__section shpd-exchange__issues">
        <h3 class="shpd-exchange__section-heading">
          {t('exchange.preview.section.issues')}
        </h3>
        <ul>
          {#each issues as issue}
            <li class="shpd-exchange__issue shpd-exchange__issue--{issue.severity}">
              <span class="shpd-exchange__issue-glyph" aria-hidden="true">
                {issueGlyph(issue.severity)}
              </span>
              <code>{issue.path}</code>
              <span>—</span>
              <span>{issue.message}</span>
            </li>
          {/each}
        </ul>
      </section>
    {/if}
  </div>
{/if}

<style>
  .shpd-exchange {
    display: flex;
    flex-direction: column;
    gap: var(--shpd-space-md);
    padding: var(--shpd-space-md);
    font-size: 0.875rem;
  }

  .shpd-exchange__header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: var(--shpd-space-md);
    border-bottom: 1px solid var(--shpd-color-border);
    padding-bottom: var(--shpd-space-md);
  }

  .shpd-exchange__type-badge {
    display: inline-block;
    padding: 2px 8px;
    background: var(--shpd-color-primary-soft);
    color: var(--shpd-color-primary);
    border-radius: 4px;
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: var(--shpd-space-xs);
  }

  .shpd-exchange__title {
    margin: 0;
    font-size: 1.25rem;
    font-weight: 600;
  }

  .shpd-exchange__subtitle {
    margin: var(--shpd-space-xs) 0 0;
    color: var(--shpd-color-text-muted);
  }

  .shpd-exchange__summary-badge {
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 0.75rem;
    font-weight: 500;
    white-space: nowrap;
  }

  .shpd-exchange__summary-badge--ok {
    background: var(--shpd-color-state-done-bg);
    color: var(--shpd-color-state-done-text);
  }

  .shpd-exchange__summary-badge--needsAttention {
    background: var(--shpd-color-state-concept-bg);
    color: var(--shpd-color-state-concept-text);
  }

  /* ── Parties ─────────────────────────────────────────────────────────── */

  .shpd-exchange__parties {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: var(--shpd-space-md);
  }

  @media (max-width: 600px) {
    .shpd-exchange__parties {
      grid-template-columns: 1fr;
    }
  }

  .shpd-exchange__party {
    border: 1px solid var(--shpd-color-border);
    border-radius: 6px;
    padding: var(--shpd-space-sm);
    background: var(--shpd-color-surface);
  }

  .shpd-exchange__party-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: var(--shpd-space-xs);
  }

  .shpd-exchange__party-label {
    margin: 0;
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--shpd-color-text-muted);
  }

  .shpd-exchange__party-name {
    font-weight: 600;
    font-size: 1rem;
    margin-bottom: var(--shpd-space-xs);
  }

  .shpd-exchange__party-ids,
  .shpd-exchange__party-contact {
    display: flex;
    flex-wrap: wrap;
    gap: var(--shpd-space-md);
    font-size: 0.8125rem;
    color: var(--shpd-color-text-muted);
    margin-bottom: var(--shpd-space-xs);
  }

  .shpd-exchange__party-address,
  .shpd-exchange__party-bank {
    font-size: 0.8125rem;
    margin-bottom: var(--shpd-space-xs);
  }

  .shpd-exchange__party-empty {
    color: var(--shpd-color-text-muted);
    font-style: italic;
  }

  /* ── Meta grid ───────────────────────────────────────────────────────── */

  .shpd-exchange__meta-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: var(--shpd-space-xs) var(--shpd-space-md);
  }

  .shpd-exchange__field {
    display: flex;
    flex-direction: column;
  }

  .shpd-exchange__field-label {
    font-size: 0.6875rem;
    text-transform: uppercase;
    color: var(--shpd-color-text-muted);
    letter-spacing: 0.5px;
  }

  .shpd-exchange__field-value {
    font-size: 0.875rem;
  }

  /* ── Status badges ───────────────────────────────────────────────────── */

  .shpd-exchange__status {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 18px;
    height: 18px;
    border-radius: 50%;
    margin-left: var(--shpd-space-xs);
    font-size: 0.625rem;
    font-weight: 700;
    vertical-align: middle;
  }

  .shpd-exchange__status-glyph {
    line-height: 1;
  }

  .shpd-exchange__status--matched {
    background: var(--shpd-color-state-done-bg);
    color: var(--shpd-color-state-done-text);
  }

  .shpd-exchange__status--canCreate {
    background: var(--shpd-color-state-concept-bg);
    color: var(--shpd-color-state-concept-text);
  }

  .shpd-exchange__status--ambiguous {
    background: var(--shpd-color-accent-soft);
    color: var(--shpd-color-accent);
  }

  .shpd-exchange__status--notFound {
    background: var(--shpd-color-state-cancelled-bg);
    color: var(--shpd-color-state-cancelled-text);
  }

  /* ── Rows table ──────────────────────────────────────────────────────── */

  .shpd-exchange__section-heading {
    margin: 0 0 var(--shpd-space-xs);
    font-size: 0.875rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--shpd-color-text-muted);
  }

  .shpd-exchange__rows,
  .shpd-exchange__vat-recap {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.8125rem;
  }

  .shpd-exchange__rows th,
  .shpd-exchange__rows td,
  .shpd-exchange__vat-recap th,
  .shpd-exchange__vat-recap td {
    padding: var(--shpd-space-xs);
    border-bottom: 1px solid var(--shpd-color-border);
    text-align: left;
  }

  .shpd-exchange__rows th,
  .shpd-exchange__vat-recap th {
    font-size: 0.6875rem;
    text-transform: uppercase;
    color: var(--shpd-color-text-muted);
    font-weight: 500;
    letter-spacing: 0.5px;
  }

  .shpd-exchange__rows .num,
  .shpd-exchange__vat-recap .num {
    text-align: right;
    font-variant-numeric: tabular-nums;
  }

  .shpd-exchange__row-name {
    font-weight: 500;
  }

  .shpd-exchange__row-code {
    display: inline-block;
    margin-left: var(--shpd-space-xs);
    padding: 0 4px;
    background: var(--shpd-color-surface-alt, #f5f5f5);
    border-radius: 3px;
    font-family: var(--shpd-font-mono, monospace);
    font-size: 0.6875rem;
    color: var(--shpd-color-text-muted);
  }

  /* ── Totals ──────────────────────────────────────────────────────────── */

  .shpd-exchange__totals-section {
    display: flex;
    justify-content: space-between;
    gap: var(--shpd-space-md);
    align-items: flex-end;
    flex-wrap: wrap;
  }

  .shpd-exchange__totals-summary {
    display: flex;
    flex-direction: column;
    gap: var(--shpd-space-xs);
    text-align: right;
    min-width: 220px;
    font-variant-numeric: tabular-nums;
  }

  .shpd-exchange__total {
    border-top: 1px solid var(--shpd-color-border);
    padding-top: var(--shpd-space-xs);
    font-size: 1rem;
  }

  /* ── Issues ──────────────────────────────────────────────────────────── */

  .shpd-exchange__issues ul {
    list-style: none;
    padding: 0;
    margin: 0;
    display: flex;
    flex-direction: column;
    gap: var(--shpd-space-xs);
  }

  .shpd-exchange__issue {
    display: flex;
    gap: var(--shpd-space-xs);
    padding: var(--shpd-space-xs);
    border-radius: 4px;
    font-size: 0.8125rem;
  }

  .shpd-exchange__issue--error {
    background: var(--shpd-color-state-cancelled-bg);
    color: var(--shpd-color-state-cancelled-text);
  }

  .shpd-exchange__issue--warning {
    background: var(--shpd-color-state-concept-bg);
    color: var(--shpd-color-state-concept-text);
  }

  .shpd-exchange__issue--info {
    background: var(--shpd-color-primary-soft);
    color: var(--shpd-color-primary);
  }

  .shpd-exchange__issue-glyph {
    font-weight: 700;
  }

  /* ── ai_failed ───────────────────────────────────────────────────────── */

  .shpd-exchange--ai-failed {
    color: var(--shpd-color-state-cancelled-text);
  }

  .shpd-exchange__ai-failed-header {
    display: flex;
    align-items: center;
    gap: var(--shpd-space-sm);
  }

  .shpd-exchange__ai-failed-icon {
    font-size: 1.5rem;
  }

  .shpd-exchange__ai-failed-issues {
    margin: var(--shpd-space-xs) 0;
    padding-left: var(--shpd-space-md);
  }

  .shpd-exchange__ai-failed-raw pre {
    background: var(--shpd-color-surface-alt, #f5f5f5);
    padding: var(--shpd-space-sm);
    border-radius: 4px;
    overflow: auto;
    font-size: 0.75rem;
    max-height: 320px;
  }
</style>
