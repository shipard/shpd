<script>
  // Portálový přehled „Moje zdroje dat" — panel hlavní navigace
  // (tasks/hosting-07-portal-in-shell.md, D1/D2). Seznam „moje DS" se čte
  // výhradně z /_hosting/portal/my-datasources (server scopuje na session
  // uživatele); hosting tabulky jsou pro ne-adminy zavřené (D9).
  import { onMount } from 'svelte';
  import { fetchMyDatasources } from '../../api/portal.js';
  import { t } from '../../i18n/index.js';
  import Icon from '../ui/Icon.svelte';
  import { iconDatabase, iconOpenExternal, iconSuccess } from '../../icons.js';

  let items = $state(null);   // null = načítá se
  let error = $state(false);

  // Snapshot starší než hodina se nevydává za aktuální — badge se nekreslí
  // vůbec (D7, tasks/hosting-06-stats.md).
  const STATS_FRESH_MS = 60 * 60 * 1000;

  function freshStats(ds) {
    if (!ds.stats?.collected_at) return null;
    // NaN neprojde; záporné age (drobný clock skew) je čerstvé.
    const age = Date.now() - Date.parse(ds.stats.collected_at);
    if (!(age < STATS_FRESH_MS)) return null;
    return ds.stats;
  }

  function statsTotal(stats) {
    return (stats.alerts ?? 0) + (stats.mail ?? 0);
  }

  function statsTitle(stats) {
    return t('portal.stats.tooltip', { alerts: stats.alerts ?? 0, mail: stats.mail ?? 0 });
  }

  async function load() {
    error = false;
    items = null;
    const result = await fetchMyDatasources();
    if (result === null) {
      error = true;
      items = [];
    } else {
      items = result;
    }
  }

  onMount(load);
</script>

<div class="portal">
  <h1 class="portal__title">{t('portal.subtitle')}</h1>

  {#if items === null}
    <div class="portal__status">{t('portal.loading')}</div>
  {:else if error}
    <div class="portal__error">
      {t('portal.error')}
      <button class="portal__retry" type="button" onclick={load}>
        {t('portal.retry')}
      </button>
    </div>
  {:else if items.length === 0}
    <div class="portal__status">{t('portal.empty')}</div>
  {:else}
    <div class="portal__grid">
      {#each items as ds (ds.id)}
        {@const stats = freshStats(ds)}
        <div class="portal__card">
          <div class="portal__card-icon">
            <Icon icon={iconDatabase} size="lg" />
          </div>
          <div class="portal__card-body">
            <div class="portal__card-name">
              {ds.name}
              {#if ds.role === 'admin'}
                <span class="portal__badge">{t('portal.role.admin')}</span>
              {/if}
            </div>
            {#if stats}
              <div class="portal__card-stats" title={statsTitle(stats)}>
                {#if statsTotal(stats) > 0}
                  <span class="portal__pending">{t('portal.stats.pending', { count: statsTotal(stats) })}</span>
                {:else}
                  <span class="portal__all-done">
                    <Icon icon={iconSuccess} size="sm" />
                    {t('portal.stats.allDone')}
                  </span>
                {/if}
              </div>
            {/if}
          </div>
          <a
            class="portal__enter"
            href={ds.url_app}
            target="_blank"
            rel="noopener"
          >
            {t('portal.enter')}
            <Icon icon={iconOpenExternal} size="sm" />
          </a>
        </div>
      {/each}
    </div>
  {/if}
</div>

<style>
  .portal {
    width: 100%;
    max-width: 720px;
    margin: 0 auto;
    padding: var(--shpd-space-xl);
  }

  .portal__title {
    margin-bottom: var(--shpd-space-lg);
    font-size: var(--shpd-font-size-lg);
    font-weight: 700;
    color: var(--shpd-color-text);
  }

  .portal__status {
    padding: var(--shpd-space-xl);
    text-align: center;
    font-size: var(--shpd-font-size-base);
    color: var(--shpd-color-text-secondary);
    background-color: var(--shpd-color-bg);
    border: 1px solid var(--shpd-color-border);
    border-radius: var(--shpd-radius-lg);
  }

  .portal__error {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: var(--shpd-space-md);
    padding: var(--shpd-space-xl);
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-danger);
    background-color: var(--shpd-color-danger-soft);
    border: 1px solid var(--shpd-color-danger);
    border-radius: var(--shpd-radius-lg);
  }

  .portal__retry {
    padding: var(--shpd-space-xs) var(--shpd-space-md);
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text);
    background-color: var(--shpd-color-bg);
    border: 1px solid var(--shpd-color-border);
    border-radius: var(--shpd-radius-md);
    cursor: pointer;
  }

  .portal__grid {
    display: flex;
    flex-direction: column;
    gap: var(--shpd-space-md);
  }

  .portal__card {
    display: flex;
    align-items: center;
    gap: var(--shpd-space-md);
    padding: var(--shpd-space-md) var(--shpd-space-lg);
    background-color: var(--shpd-color-bg);
    border: 1px solid var(--shpd-color-border);
    border-radius: var(--shpd-radius-lg);
    box-shadow: var(--shpd-shadow-sm);
  }

  .portal__card-icon {
    flex-shrink: 0;
    color: var(--shpd-color-text-secondary);
  }

  .portal__card-body {
    flex: 1;
    min-width: 0;
  }

  .portal__card-name {
    display: flex;
    align-items: center;
    gap: var(--shpd-space-sm);
    font-size: var(--shpd-font-size-base);
    font-weight: 600;
    color: var(--shpd-color-text);
  }

  .portal__badge {
    padding: 1px 8px;
    font-size: var(--shpd-font-size-sm);
    font-weight: 500;
    color: var(--shpd-color-accent);
    background-color: var(--shpd-color-accent-soft);
    border-radius: 999px;
  }

  .portal__card-stats {
    margin-top: var(--shpd-space-xs);
    font-size: var(--shpd-font-size-sm);
  }

  .portal__pending {
    display: inline-block;
    padding: 1px 8px;
    font-weight: 500;
    color: var(--shpd-color-accent);
    background-color: var(--shpd-color-accent-soft);
    border-radius: 999px;
  }

  .portal__all-done {
    display: inline-flex;
    align-items: center;
    gap: var(--shpd-space-xs);
    color: var(--shpd-color-success);
  }

  .portal__enter {
    display: flex;
    align-items: center;
    gap: var(--shpd-space-xs);
    flex-shrink: 0;
    padding: var(--shpd-space-sm) var(--shpd-space-md);
    font-size: var(--shpd-font-size-sm);
    font-weight: 500;
    color: #ffffff;
    background-color: var(--shpd-color-primary);
    border-radius: var(--shpd-radius-md);
    text-decoration: none;
    transition: background-color 0.15s;
  }

  .portal__enter:hover {
    background-color: var(--shpd-color-primary-hover);
  }
</style>
