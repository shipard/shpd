import './styles/reset.css'
import './styles/variables.css'
import './styles/base.css'
import { mount } from 'svelte'
import App from './App.svelte'
import { layoutStore } from './stores/layout.svelte.js'
import { appInfoStore } from './stores/appInfo.svelte.js'

// Inicializace mobilní detekce (matchMedia listener). Jednou na začátku.
layoutStore.initLayout()

// Načtení názvu/ikony aplikace — endpoint je veřejný, jede už před loginem
// (titulek tabu, favicon, název na login obrazovce).
appInfoStore.load()

const app = mount(App, {
  target: document.getElementById('app'),
})

export default app
