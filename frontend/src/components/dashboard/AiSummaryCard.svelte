<script>
  import { t } from '../../i18n/index.js';
  import { iconRobot } from '../../icons.js';
  import Icon from '../ui/Icon.svelte';

  let { summary } = $props();

  // Skládáme části jen pro nenulové počty — pak join čárkou. Pokud je
  // vše nulové, vrátíme univerzální empty text (viz `dashboard.aiSummary.empty`).
  const summaryText = $derived.by(() => {
    const parts = [];
    if (summary.alertsCount > 0) {
      parts.push(t('dashboard.aiSummary.alerts', { count: summary.alertsCount }));
    }
    if (summary.incomingMailCount > 0) {
      parts.push(t('dashboard.aiSummary.mail', { count: summary.incomingMailCount }));
    }
    if (summary.tasksCount > 0) {
      parts.push(t('dashboard.aiSummary.tasks', { count: summary.tasksCount }));
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
