import react from '@vitejs/plugin-react'
import { defineConfig } from 'vitest/config'

// https://vite.dev/config/
export default defineConfig({
  plugins: [react()],
  test: {
    // Playwright specs live under e2e/ and use @playwright/test, not Vitest.
    exclude: ['node_modules/**', 'dist/**', 'e2e/**'],
  },
  optimizeDeps: {
    // maplibre-gl ships its worker as a separate entry the dep optimizer
    // mangles ("maplibre-gl-worker.mjs does not exist"); prebundling it
    // breaks page load entirely under Vite 8.
    exclude: ['maplibre-gl'],
  },
  server: {
    host: '127.0.0.1',
    strictPort: true,
    proxy: {
      // SPA dev server reaches the PHP API through nginx on :8080 (ADR-13).
      '/api': {
        target: 'http://localhost',
        changeOrigin: true,
      },
    },
  },
})