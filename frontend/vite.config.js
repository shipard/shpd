import { defineConfig } from 'vite'
import { svelte } from '@sveltejs/vite-plugin-svelte'

// Use relative base so built assets work regardless of URL prefix.
// In production the app is at /app/, in dev mode at /{ds-id}/app/.
// Relative paths (./assets/...) work correctly in both cases.
export default defineConfig({
  plugins: [svelte()],
  base: '/app/',
  build: {
    outDir: '../public/app',
    emptyOutDir: true,
    // Aplikační chunk má ~530 kB (154 kB gzip) — SPA za loginem, jeden
    // vstup, code-splitting per obrazovka zatím záměrně neděláme. Limit
    // zvednutý těsně nad současný stav, ať warning hlásí až další růst.
    chunkSizeWarningLimit: 600,
    rollupOptions: {
      output: {
        // Vendor kód do vlastního chunku — mezi deployi se nemění,
        // takže ho prohlížeč drží v cache i po vydání nové verze appky.
        manualChunks(id) {
          if (id.includes('node_modules')) return 'vendor'
        },
      },
    },
  },
  server: {
    proxy: {
      '/api': {
        target: 'http://localhost:80',
        changeOrigin: true,
      },
    },
  },
})
