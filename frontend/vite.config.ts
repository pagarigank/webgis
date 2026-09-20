import react from '@vitejs/plugin-react'
import { defineConfig } from 'vite'

// https://vite.dev/config/
export default defineConfig({
  plugins: [react()],
  server: {
    proxy: {
      // SPA dev server reaches the PHP API through nginx on :8080 (ADR-13).
      '/api': {
        target: 'http://localhost',
        changeOrigin: true,
      },
    },
  },
})