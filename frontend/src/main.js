import './styles/reset.css'
import './styles/variables.css'
import './styles/base.css'
import { mount } from 'svelte'
import App from './App.svelte'
import { layoutStore } from './stores/layout.svelte.js'

// Inicializace mobilní detekce (matchMedia listener). Jednou na začátku.
layoutStore.initLayout()

const app = mount(App, {
  target: document.getElementById('app'),
})

export default app
