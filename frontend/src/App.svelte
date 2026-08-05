<script>
  import { authStore } from './stores/auth.svelte.js';
  import { authAction } from './stores/authAction.svelte.js';
  import { accountPrefs } from './stores/accountPrefs.svelte.js';
  import { avatarStore } from './stores/avatar.svelte.js';
  import { appInfoStore } from './stores/appInfo.svelte.js';
  import LoginScreen from './components/auth/LoginScreen.svelte';
  import SetPasswordScreen from './components/auth/SetPasswordScreen.svelte';
  import PortalScreen from './components/portal/PortalScreen.svelte';
  import AppShell from './components/layout/AppShell.svelte';

  // Po úspěšném loginu načti per-user preference (vzhled, jazyk) ze serveru.
  function handleLoginSuccess() {
    accountPrefs.load();
    avatarStore.load();
  }
</script>

{#if authStore.isAuthenticated}
  {#if appInfoStore.hasPortal && !authStore.isAdmin}
    <!-- DS s aktivním hostingem: ne-admin dostane portál místo shellu (D10).
         Skutečná bariéra je na serveru (adminOnly tabulky + scopovaný
         endpoint) — tohle jen nezobrazuje mrtvou aplikaci. -->
    <PortalScreen />
  {:else}
    <AppShell onLogout={() => {}} />
  {/if}
{:else if authAction.kind === 'set-password'}
  <SetPasswordScreen />
{:else}
  <LoginScreen onSuccess={handleLoginSuccess} />
{/if}
