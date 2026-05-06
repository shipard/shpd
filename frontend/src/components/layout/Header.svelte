<script>
  import { logout } from '../../api/auth.js';
  import { authStore } from '../../stores/auth.svelte.js';
  import Icon from '../ui/Icon.svelte';
  import { iconLogout } from '../../icons.js';
  import { t } from '../../i18n/index.js';

  let { onLogout } = $props();

  async function handleLogout() {
    await logout();
    authStore.clearAuth();
    onLogout?.();
  }
</script>

<header class="shpd-header">
  <span class="shpd-header__logo">Shipard</span>

  <div class="shpd-header__user">
    <span class="shpd-header__username">{authStore.user?.full_name ?? ''}</span>
    <button class="shpd-header__logout" onclick={handleLogout}>
      <Icon icon={iconLogout} size="sm" />
      <span>{t('sidebar.logout')}</span>
    </button>
  </div>
</header>

<style>
  .shpd-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    height: var(--shpd-header-height);
    padding: 0 var(--shpd-space-lg);
    background-color: var(--shpd-color-bg);
    border-bottom: 1px solid var(--shpd-color-border);
    flex-shrink: 0;
  }

  .shpd-header__logo {
    font-size: var(--shpd-font-size-lg);
    font-weight: 700;
    color: var(--shpd-color-primary);
  }

  .shpd-header__user {
    display: flex;
    align-items: center;
    gap: var(--shpd-space-md);
  }

  .shpd-header__username {
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text-secondary);
  }

  .shpd-header__logout {
    display: inline-flex;
    align-items: center;
    gap: var(--shpd-space-xs);
    padding: var(--shpd-space-xs) var(--shpd-space-md);
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text-secondary);
    border: 1px solid var(--shpd-color-border);
    border-radius: var(--shpd-radius-sm);
    transition: color 0.15s, border-color 0.15s;
  }

  .shpd-header__logout:hover {
    color: var(--shpd-color-danger);
    border-color: var(--shpd-color-danger);
  }
</style>
