<script>
  import { login } from '../../api/auth.js';
  import { authStore } from '../../stores/auth.svelte.js';
  import { appInfoStore } from '../../stores/appInfo.svelte.js';
  import { brandingUrl } from '../../api/app.js';
  import { language, t } from '../../i18n/index.js';
  import { translateError } from '../../i18n/errors.js';

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
      errorMessage = response?.error ? translateError(response.error) : t('login.failed');
    }
  }

  function handleKeydown(event) {
    if (event.key === 'Enter') {
      handleSubmit();
    }
  }

  // Discreet picker v patce karty — Phase 1B varianta B. Native <select>:
  // klávesnice + a11y dostupné zadarmo, žádný vlastní open/close state,
  // a v sidebar dropdown už dropdown stejně máme. setMode() reloadne stránku.
  const languageOptions = [
    { value: 'cs',   labelKey: 'sidebar.language.cs' },
    { value: 'en',   labelKey: 'sidebar.language.en' },
    { value: 'auto', labelKey: 'sidebar.language.auto' },
  ];

  function handleLanguageChange(event) {
    language.setMode(event.target.value);
  }
</script>

<div class="shpd-login">
  <div class="shpd-login__card">
    {#if appInfoStore.companyLogo}
      <img
        class="shpd-login__logo"
        src={brandingUrl('companyLogo', appInfoStore.companyLogo.hash)}
        alt=""
      />
    {/if}
    <h1 class="shpd-login__heading">{appInfoStore.name ?? t('login.heading')}</h1>

    <div class="shpd-login__field">
      <label class="shpd-login__label" for="login-name">{t('login.username')}</label>
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
      <label class="shpd-login__label" for="login-password">{t('login.password')}</label>
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
      {loading ? t('login.submitting') : t('login.submit')}
    </button>

    <div class="shpd-login__footer">
      <label class="shpd-login__lang-label" for="login-language">
        {t('login.languagePicker.label')}
      </label>
      <select
        id="login-language"
        class="shpd-login__lang-select"
        value={language.mode}
        onchange={handleLanguageChange}
      >
        {#each languageOptions as opt}
          <option value={opt.value}>{t(opt.labelKey)}</option>
        {/each}
      </select>
    </div>
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

  .shpd-login__logo {
    display: block;
    max-height: 48px;
    max-width: 200px;
    margin: 0 auto var(--shpd-space-md);
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

  .shpd-login__footer {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: var(--shpd-space-sm);
    margin-top: var(--shpd-space-lg);
    padding-top: var(--shpd-space-md);
    border-top: 1px solid var(--shpd-color-border);
  }

  .shpd-login__lang-label {
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text-secondary);
  }

  .shpd-login__lang-select {
    padding: 4px 8px;
    font-size: var(--shpd-font-size-sm);
    font-family: inherit;
    color: var(--shpd-color-text);
    background-color: var(--shpd-color-bg);
    border: 1px solid var(--shpd-color-border);
    border-radius: var(--shpd-radius-sm);
    cursor: pointer;
  }

  .shpd-login__lang-select:focus {
    outline: none;
    border-color: var(--shpd-color-border-focus);
    box-shadow: 0 0 0 2px var(--shpd-color-focus-ring);
  }
</style>
