<script>
  import { t } from '../../i18n/index.js';
  import { iconRobot } from '../../icons.js';
  import Icon from '../ui/Icon.svelte';

  let { summary } = $props();

  // Fáze 2b naplní `summary.aiText` generovaným shrnutím — pokud je, zobraz ho
  // přímo. Jinak (MVP) složíme statický text z počtů karet dle kind (plurály),
  // jen pro nenulová pásma. Vše nulové → univerzální empty text.
  const summaryText = $derived.by(() => {
    if (summary.aiText) {
      return summary.aiText;
    }
    const counts = summary.counts ?? {};
    const parts = [];
    if (counts.urgent > 0) {
      parts.push(t('dashboard.aiSummary.counts.urgent', { count: counts.urgent }));
    }
    if (counts.review > 0) {
      parts.push(t('dashboard.aiSummary.counts.review', { count: counts.review }));
    }
    if (counts.ready > 0) {
      parts.push(t('dashboard.aiSummary.counts.ready', { count: counts.ready }));
    }
    if (parts.length === 0) {
      return t('dashboard.aiSummary.empty');
    }
    return t('dashboard.aiSummary.intro') + ' ' + parts.join(', ') + '.';
  });
</script>

<div class="shpd-ai-summary">
  <span class="shpd-ai-summary__icon">
    <Icon icon={iconRobot} size="lg" />
  </span>
  <div class="shpd-ai-summary__body">
    <div class="shpd-ai-summary__title">{t('dashboard.aiSummary.title')}</div>
    <div class="shpd-ai-summary__text">{summaryText}</div>
    <div class="shpd-ai-summary__hint">{t('dashboard.aiSummary.placeholder')}</div>
  </div>
</div>

<style>
  .shpd-ai-summary {
    background: var(--shpd-color-primary-soft);
    border: 1px solid var(--shpd-color-primary-soft-2);
    border-radius: var(--shpd-radius-md);
    padding: var(--shpd-space-md);
    display: flex;
    gap: var(--shpd-space-md);
    align-items: flex-start;
  }

  .shpd-ai-summary__icon {
    color: var(--shpd-color-primary);
    flex-shrink: 0;
    margin-top: 2px;
  }

  .shpd-ai-summary__body { flex: 1; min-width: 0; }

  .shpd-ai-summary__title {
    font-weight: 600;
    color: var(--shpd-color-primary);
    margin-bottom: var(--shpd-space-xs);
  }

  .shpd-ai-summary__text {
    color: var(--shpd-color-text);
    line-height: 1.5;
  }

  .shpd-ai-summary__hint {
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text-secondary);
    margin-top: var(--shpd-space-xs);
    font-style: italic;
  }
</style>
