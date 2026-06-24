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

// Inicializace mobilní detekce (matchMedia listener). Jednou na začátku.
layoutStore.initLayout()

// Načtení názvu/ikony aplikace — endpoint je veřejný, jede už před loginem
// (titulek tabu, favicon, název na login obrazovce).
appInfoStore.load()

// Per-user preference (vzhled, jazyk) — jen když už jsme přihlášení
// (reload s platným tokenem). Fresh login je obsloužen v App.svelte onSuccess.
if (authStore.isAuthenticated) {
  accountPrefs.load()
  avatarStore.load()
}

const app = mount(App, {
  target: document.getElementById('app'),
})

export default app
