import './styles/reset.css'
import './styles/variables.css'
import './styles/base.css'
import { mount } from 'svelte'
import App from './App.svelte'
import { layoutStore } from './stores/layout.svelte.js'
import { appInfoStore } from './stores/appInfo.svelte.js'
import { authStore } from './stores/auth.svelte.js'
import { accountPrefs } from './stores/accountPrefs.svelte.js'
import { avatarStore } from './stores/avatar.svelte.js'
import { loginNotice } from './stores/loginNotice.svelte.js'
import { authAction } from './stores/authAction.svelte.js'
import { opAuth } from './stores/opAuth.svelte.js'
import { parseOidcRedirect, parseOpAuth } from './api/oidc.js'
import { parseAuthAction } from './api/authActions.js'
import { exchangeOidc } from './api/auth.js'

// Inicializace mobilní detekce (matchMedia listener). Jednou na začátku.
layoutStore.initLayout()

// Načtení názvu/ikony aplikace — endpoint je veřejný, jede už před loginem
// (titulek tabu, favicon, název na login obrazovce).
appInfoStore.load()

async function boot() {
  // Mailový link set-password (?auth_action=set-password&token=…): token
  // do in-memory store PŘED mountem, URL se hned čistí — token nesmí
  // zůstat v historii. App.svelte pak zobrazí SetPasswordScreen.
  const startupAction = parseAuthAction(window.location.search)
  if (startupAction) {
    history.replaceState(null, '', window.location.pathname)
    authAction.set(startupAction)
  }

  // OIDC návrat: ?login=oidc&code=… vyměnit za session PŘED mountem
  // (žádný flash login obrazovky), ?login_error=… předat login obrazovce.
  // URL se čistí hned — handoff kód je jednorázový a nesmí přežít reload.
  const oidcRedirect = parseOidcRedirect(window.location.search)
  if (!startupAction && oidcRedirect) {
    history.replaceState(null, '', window.location.pathname)
    if (oidcRedirect.kind === 'handoff') {
      try {
        const response = await exchangeOidc(oidcRedirect.code)
        if (response?.success) {
          authStore.setAuth(response)
        } else {
          loginNotice.set('oidc_invalid_state')
        }
      } catch {
        loginNotice.set('oidc_provider_error')
      }
    } else {
      loginNotice.set(oidcRedirect.error)
    }
  }

  // Příchod od OIDC OP hostingu (?op_auth={txn}, session bridge D10):
  // txn do in-memory store PŘED mountem, URL se hned čistí. Schválení
  // (approve → window.location) dělá OpAuthScreen — se session hned,
  // bez session až po loginu (in-memory store login přežije).
  const opAuthTxn = parseOpAuth(window.location.search)
  if (!startupAction && !oidcRedirect && opAuthTxn) {
    history.replaceState(null, '', window.location.pathname)
    opAuth.set(opAuthTxn)
  }

  // Per-user preference (vzhled, jazyk) — jen když už jsme přihlášení
  // (reload s platným tokenem nebo čerstvý OIDC handoff). Fresh lokální
  // login je obsloužen v App.svelte onSuccess.
  if (authStore.isAuthenticated) {
    accountPrefs.load()
    avatarStore.load()
  }

  mount(App, {
    target: document.getElementById('app'),
  })
}

boot()
