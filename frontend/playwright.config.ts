import { defineConfig, devices } from '@playwright/test';

/**
 * Playwright E2E for the WebGIS SPA.
 *
 * The stack runs in Docker: nginx on :80 serves the API at /api/v1.
 * The SPA dev server (vite :5173) proxies /api to :80. We run against
 * the vite dev server so the latest frontend source is exercised.
 */
const BASE = process.env.E2E_BASE_URL ?? 'http://localhost:5173';
const API_BASE = process.env.E2E_API_URL ?? 'http://localhost:80';

export default defineConfig({
    testDir: './e2e/specs',
    timeout: 90_000,
    expect: { timeout: 15_000 },
    fullyParallel: false, // shared DB fixtures — run serially
    retries: 0,
    workers: 1,
    reporter: [['list']],
    use: {
        baseURL: BASE,
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
        viewport: { width: 1440, height: 900 },
    },
    projects: [
        {
            name: 'chromium',
            use: { ...devices['Desktop Chrome'] },
        },
    ],
    // The dev server must already be running (npm run dev) — Playwright
    // reuses it across test files.
});

export { BASE, API_BASE };
