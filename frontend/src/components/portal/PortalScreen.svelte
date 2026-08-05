<script>
  // Portálová obrazovka hostingu (D10) — ne-admin na DS s aktivním
  // hosting.core ji dostane místo standardního app shellu. Seznam „moje DS"
  // se čte výhradně z /_hosting/portal/my-datasources (server scopuje na
  // session uživatele); hosting tabulky jsou pro ne-adminy zavřené (D9).
  import { onMount } from 'svelte';
  import { fetchMyDatasources } from '../../api/portal.js';
  import { logout } from '../../api/auth.js';
  import { authStore } from '../../stores/auth.svelte.js';
  import { appInfoStore } from '../../stores/appInfo.svelte.js';
  import { brandingUrl } from '../../api/app.js';
  import { t } from '../../i18n/index.js';
  import Icon from '../ui/Icon.svelte';
  import { iconDatabase, iconLogout, iconOpenExternal } from '../../icons.js';

  let items = $state(null);   // null = načítá se
  let error = $state(false);

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

  async function handleLogout() {
    try {
      await logout();
    } catch (err) {
      console.warn('Logout API call failed (continuing):', err);
    }
    authStore.clearAuth();
  }

  onMount(load);
</script>

<div class="portal">
  <header class="portal__header">
    <div class="portal__brand">
      {#if appInfoStore.companyLogo}
        <img
          class="portal__logo"
          src={brandingUrl('companyLogo', appInfoStore.companyLogo.hash)}
          alt=""
        />
      {/if}
      <h1 class="portal__heading">{appInfoStore.name ?? t('portal.heading')}</h1>
    </div>
    <button class="portal__logout" type="button" onclick={handleLogout}>
      <Icon icon={iconLogout} size="sm" />
      {t('sidebar.logout')}
    </button>
  </header>

  <main class="portal__content">
    <h2 class="portal__subtitle">{t('portal.subtitle')}</h2>

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
  </main>
</div>

<style>
  .portal {
    display: flex;
    flex-direction: column;
    min-height: 100vh;
    background-color: var(--shpd-color-bg-secondary);
  }

  .portal__header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--shpd-space-md);
    padding: var(--shpd-space-md) var(--shpd-space-xl);
    background-color: var(--shpd-color-bg);
    border-bottom: 1px solid var(--shpd-color-border);
  }

  .portal__brand {
    display: flex;
    align-items: center;
    gap: var(--shpd-space-md);
    min-width: 0;
  }

  .portal__logo {
    max-height: 36px;
    max-width: 160px;
  }

  .portal__heading {
    font-size: var(--shpd-font-size-lg);
    font-weight: 700;
    color: var(--shpd-color-text);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  .portal__logout {
    display: flex;
    align-items: center;
    gap: var(--shpd-space-xs);
    padding: var(--shpd-space-xs) var(--shpd-space-md);
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text-secondary);
    background: none;
    border: 1px solid var(--shpd-color-border);
    border-radius: var(--shpd-radius-md);
    cursor: pointer;
    transition: border-color 0.15s, color 0.15s;
  }

  .portal__logout:hover {
    color: var(--shpd-color-text);
    border-color: var(--shpd-color-border-focus);
  }

  .portal__content {
    width: 100%;
    max-width: 720px;
    margin: 0 auto;
    padding: var(--shpd-space-xl);
  }

  .portal__subtitle {
    margin-bottom: var(--shpd-space-lg);
    font-size: var(--shpd-font-size-base);
    font-weight: 600;
    color: var(--shpd-color-text-secondary);
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
