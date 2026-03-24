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
