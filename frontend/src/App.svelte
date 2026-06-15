<script>
  import { authStore } from './stores/auth.svelte.js';
  import { accountPrefs } from './stores/accountPrefs.svelte.js';
  import LoginScreen from './components/auth/LoginScreen.svelte';
  import AppShell from './components/layout/AppShell.svelte';

  // Po úspěšném loginu načti per-user preference (vzhled, jazyk) ze serveru.
  function handleLoginSuccess() {
    accountPrefs.load();
  }
</script>

{#if authStore.isAuthenticated}
  <AppShell onLogout={() => {}} />
{:else}
  <LoginScreen onSuccess={handleLoginSuccess} />
{/if}
