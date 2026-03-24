<script>
  import { authStore } from './stores/auth.svelte.js';
  import LoginScreen from './components/auth/LoginScreen.svelte';
  import AppShell from './components/layout/AppShell.svelte';
</script>

{#if authStore.isAuthenticated}
  <AppShell onLogout={() => {}} />
{:else}
  <LoginScreen onSuccess={() => {}} />
{/if}
