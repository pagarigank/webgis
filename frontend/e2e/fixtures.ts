import { test as base, expect, Page } from '@playwright/test';
import { execSync } from 'node:child_process';

export const API_BASE = process.env.E2E_API_URL ?? 'http://localhost:80';
export const ADMIN = { username: 'sample_app_admin', password: 'hash' };

/** Log the SPA in through the real login form and wait for the SPA shell. */
export async function login(page: Page): Promise<void> {
    await page.goto('/login');
    await page.getByLabel('Username').fill(ADMIN.username);
    await page.getByLabel('Password').fill(ADMIN.password);
    await page.getByRole('button', { name: /sign in/i }).click();
    // Successful auth redirects off /login and renders the app header.
    await expect(page.locator('.app-header')).toBeVisible({ timeout: 20_000 });
    await expect(page).not.toHaveURL(/\/login/);
}

/**
 * Mint a real admin JWT via the Docker API. Used by specs that need raw
 * API access (conflict test's stale write).
 */
export async function adminToken(): Promise<string> {
    const res = await fetch(`${API_BASE}/api/v1/auth/login`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(ADMIN),
    });
    const body = (await res.json()) as { data?: { access_token?: string } };
    if (!body.data?.access_token) {
        throw new Error(`adminToken(): login failed (${res.status})`);
    }
    return body.data.access_token;
}

/** Run SQL against the Docker Postgres (from the repo root). */
export function psql(sql: string): string {
    return execSync(
        `docker compose exec -T postgres psql -U app_rw -d webgis -t -A -c "${sql.replace(/"/g, '\\"')}"`,
        { cwd: process.env.PROJECT_ROOT ?? '..', encoding: 'utf8', stdio: ['ignore', 'pipe', 'inherit'] },
    ).trim();
}

export const test = base.extend<{ resetAuthRateLimit: void }>({
    /**
     * Every test logs in through the real form (and the /login mount triggers a
     * /me → /auth/refresh), so the suite can exceed the spec's 10/60s `auth`
     * rate limit (api.md §1.5) from the single localhost IP. The limiter itself
     * is covered by backend RateLimitTest; reset its bucket so e2e is isolated
     * from it rather than racing a 429.
     */
    resetAuthRateLimit: [
        async ({}, use) => {
            psql("DELETE FROM app.rate_limit_entries WHERE bucket_key LIKE 'auth:%'");
            await use();
        },
        { auto: true },
    ],
});
export { expect };
