<script>
  import { login } from '../../api/auth.js';
  import { authStore } from '../../stores/auth.svelte.js';

  let { onSuccess } = $props();

  let loginName = $state('');
  let password = $state('');
  let loading = $state(false);
  let errorMessage = $state('');

  let loginInput;

  $effect(() => {
    loginInput?.focus();
  });

  async function handleSubmit() {
    if (loading) return;

    loading = true;
    errorMessage = '';

    const response = await login(loginName, password);

    loading = false;

    if (response?.success) {
      authStore.setAuth(response);
      onSuccess?.();
    } else {
      errorMessage = response?.error?.message ?? 'Přihlášení se nezdařilo.';
    }
  }

  function handleKeydown(event) {
    if (event.key === 'Enter') {
      handleSubmit();
    }
  }
</script>

<div class="shpd-login">
  <div class="shpd-login__card">
    <h1 class="shpd-login__heading">Shipard</h1>

    <div class="shpd-login__field">
      <label class="shpd-login__label" for="login-name">Přihlašovací jméno</label>
      <input
        bind:this={loginInput}
        id="login-name"
        class="shpd-login__input"
        type="text"
        autocomplete="username"
        disabled={loading}
        bind:value={loginName}
        onkeydown={handleKeydown}
      />
    </div>

    <div class="shpd-login__field">
      <label class="shpd-login__label" for="login-password">Heslo</label>
      <input
        id="login-password"
        class="shpd-login__input"
        type="password"
        autocomplete="current-password"
        disabled={loading}
        bind:value={password}
        onkeydown={handleKeydown}
      />
    </div>

    {#if errorMessage}
      <div class="shpd-login__error">{errorMessage}</div>
    {/if}

    <button
      class="shpd-login__button"
      type="button"
      disabled={loading}
      onclick={handleSubmit}
    >
      {loading ? 'Přihlašování…' : 'Přihlásit se'}
    </button>
  </div>
</div>

<style>
  .shpd-login {
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 100vh;
    background-color: var(--shpd-color-bg-secondary);
  }

  .shpd-login__card {
    width: 100%;
    max-width: 400px;
    padding: var(--shpd-space-xl);
    background-color: var(--shpd-color-bg);
    border-radius: var(--shpd-radius-lg);
    box-shadow: var(--shpd-shadow-md);
  }

  .shpd-login__heading {
    margin-bottom: var(--shpd-space-lg);
    font-size: var(--shpd-font-size-xl);
    font-weight: 700;
    color: var(--shpd-color-text);
    text-align: center;
  }

  .shpd-login__field {
    margin-bottom: var(--shpd-space-md);
  }

  .shpd-login__label {
    display: block;
    margin-bottom: var(--shpd-space-xs);
    font-size: var(--shpd-font-size-sm);
    font-weight: 500;
    color: var(--shpd-color-text-secondary);
  }

  .shpd-login__input {
    display: block;
    width: 100%;
    padding: var(--shpd-space-sm) var(--shpd-space-md);
    font-size: var(--shpd-font-size-base);
    color: var(--shpd-color-text);
    background-color: var(--shpd-color-bg);
    border: 1px solid var(--shpd-color-border);
    border-radius: var(--shpd-radius-md);
    outline: none;
    transition: border-color 0.15s;
  }

  .shpd-login__input:focus {
    border-color: var(--shpd-color-border-focus);
    box-shadow: 0 0 0 3px var(--shpd-color-focus-ring);
  }

  .shpd-login__input:disabled {
    opacity: 0.6;
    cursor: not-allowed;
  }

  .shpd-login__error {
    margin-bottom: var(--shpd-space-md);
    padding: var(--shpd-space-sm) var(--shpd-space-md);
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-danger);
    background-color: var(--shpd-color-danger-soft);
    border: 1px solid var(--shpd-color-danger);
    border-radius: var(--shpd-radius-md);
  }

  .shpd-login__button {
    display: block;
    width: 100%;
    margin-top: var(--shpd-space-lg);
    padding: var(--shpd-space-sm) var(--shpd-space-md);
    font-size: var(--shpd-font-size-base);
    font-weight: 500;
    color: #ffffff;
    background-color: var(--shpd-color-primary);
    border-radius: var(--shpd-radius-md);
    transition: background-color 0.15s;
  }

  .shpd-login__button:hover:not(:disabled) {
    background-color: var(--shpd-color-primary-hover);
  }

  .shpd-login__button:disabled {
    opacity: 0.6;
    cursor: not-allowed;
  }
</style>
