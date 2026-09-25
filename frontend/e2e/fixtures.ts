import { test as base, expect, Page } from '@playwright/test';
import { execSync } from 'node:child_process';

export const API_BASE = process.env.E2E_API_URL ?? 'http://localhost:80';
export const ADMIN = { username: 'sample_app_admin', password: 'hash' };

/**
 * Empty the buckets that throttle credential and session traffic.
 *
 * `auth` and `auth_refresh` are separate classes with separate ceilings, and a
 * single LIKE cannot cover both: 'auth:%' stops at the underscore, so it matches
 * login/logout but never 'auth_refresh:%'. Clearing only one of them lets the
 * other fill up over a long run, and the resulting 429 looks like an unrelated
 * application failure rather than test-environment pressure.
 */
function resetAuthBuckets(): void {
    psql(
        "DELETE FROM app.rate_limit_entries " +
        "WHERE bucket_key LIKE 'auth:%' OR bucket_key LIKE 'auth\\_refresh:%'",
    );
}

/**
 * Log the SPA in through the real login form and wait for the SPA shell.
 *
 * The `auth` rate-limit bucket allows 10 requests per 60s (api.md §1.5) from
 * the single localhost IP, and one pass of this form costs two of them:
 * POST /auth/login plus the POST /auth/refresh that the /login mount fires for
 * the pre-auth /me probe. That is only five logins a minute, and the bucket is
 * shared with the backend suite and with any other spec in flight, so a login
 * can legitimately come back 429 mid-run.
 *
 * A 429 used to pass straight through: the SPA still redirected to the target
 * route, the helper's header assertion happened to pass, and the test then
 * failed much later on an unrelated-looking `401 GET /api/v1/basemaps`. So the
 * response status is checked explicitly and a throttled login clears the bucket
 * and retries once. The limiter's own behaviour stays covered by the backend
 * RateLimitTest; this only keeps e2e off its critical path.
 */
export async function login(page: Page): Promise<void> {
    // Guarantee clean auth buckets for this login. The auto fixture also clears
    // them, but clearing here as well makes the helper correct on its own and
    // immune to fixture ordering and to leftovers from a previous spec.
    //
    // Both buckets must go. `auth` covers login/logout; `auth_refresh` is a
    // separate class with its own ceiling, and a LIKE on 'auth:%' does not match
    // 'auth_refresh:%' because of the underscore. Clearing only `auth` left
    // refreshes accumulating across a whole suite run until a long run pushed
    // the window past its limit, so a refresh 429'd mid-run and the route under
    // test never recovered — which surfaced as an unrelated-looking
    // `401 GET /api/v1/basemaps` on a page that does not even load basemaps.
    resetAuthBuckets();

    await page.goto('/login');

    for (let attempt = 1; attempt <= 2; attempt++) {
        await page.getByLabel('Username').fill(ADMIN.username);
        await page.getByLabel('Password').fill(ADMIN.password);

        const [res] = await Promise.all([
            page.waitForResponse((r) => r.url().includes('/api/v1/auth/login'), { timeout: 20_000 }),
            page.getByRole('button', { name: /sign in/i }).click(),
        ]);

        if (res.status() === 429) {
            if (attempt === 2) {
                throw new Error(`login(): still rate limited after clearing the auth bucket (429)`);
            }
            resetAuthBuckets();
            continue;
        }

        if (res.status() !== 200) {
            throw new Error(`login(): POST /auth/login -> ${res.status()} ${await res.text()}`);
        }

        // Successful auth redirects off /login and renders the app header.
        await expect(page.locator('.app-header')).toBeVisible({ timeout: 20_000 });
        await expect(page).not.toHaveURL(/\/login/);
        return;
    }
}

/**
 * Mint a real admin JWT via the Docker API. Used by specs that need raw
 * API access (conflict test's stale write) and for teardown.
 *
 * Shares the `auth` rate-limit bucket with the form login above, so a 429 is
 * cleared and retried here too — teardown must not be able to fail a run.
 */
export async function adminToken(): Promise<string> {
    for (let attempt = 1; attempt <= 2; attempt++) {
        const res = await fetch(`${API_BASE}/api/v1/auth/login`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(ADMIN),
        });
        const body = (await res.json()) as { data?: { access_token?: string } };
        if (body.data?.access_token) {
            return body.data.access_token;
        }
        if (res.status === 429 && attempt === 1) {
            resetAuthBuckets();
            continue;
        }
        throw new Error(`adminToken(): login failed (${res.status}) ${JSON.stringify(body).slice(0, 200)}`);
    }
    throw new Error('adminToken(): exhausted retries');
}

/** Run SQL against the Docker Postgres (from the repo root). */
export function psql(sql: string): string {
    return execSync(
        `docker compose exec -T postgres psql -U app_rw -d webgis -t -A -c "${sql.replace(/"/g, '\\"')}"`,
        { cwd: process.env.PROJECT_ROOT ?? '..', encoding: 'utf8', stdio: ['ignore', 'pipe', 'inherit'] },
    ).trim();
}

/**
 * Reset the map-facing layer fixtures to a deterministic baseline so spec
 * assertions do not depend on ambient DB drift (e.g. admin-hid layers).
 * - sample layers must be visible in the hamburger (is_hidden = false)
 * - every role needs can_view so bbox GeoJSON loads (permission middleware)
 */
export function seedMapLayers(): void {
    // NOTE: keep this ALL on ONE line. execSync on win32 (cmd.exe) mangles
    // multi-line arguments to `psql -c "..."`, silently dropping statements.
    psql(`UPDATE app.gis_layers SET is_hidden = false WHERE code IN ('SAMPLE_PARCEL_POLYGON','E2E_LINE_LAYER','E2E_DRAW_TEST'); INSERT INTO app.layer_permissions (layer_id, role_id, can_view, can_create, can_update, can_delete, can_approve) SELECT l.id, r.id, true, true, true, true, true FROM app.gis_layers l CROSS JOIN app.roles r WHERE l.code IN ('SAMPLE_PARCEL_POLYGON','E2E_LINE_LAYER','E2E_DRAW_TEST') AND r.code IN ('SYS_ADMIN','app_rw','app_ro','DATA_ENCODER','GIS_SPECIALIST','SURVEYOR') ON CONFLICT (layer_id, role_id) DO UPDATE SET can_view = true; INSERT INTO app.layer_permissions (layer_id, role_id, can_view, can_create, can_update, can_delete, can_approve) SELECT 4, r.id, true, r.code = 'SYS_ADMIN', r.code = 'SYS_ADMIN', r.code = 'SYS_ADMIN', r.code = 'SYS_ADMIN' FROM app.roles r WHERE r.code IN ('SYS_ADMIN','app_rw','app_ro','DATA_ENCODER','GIS_SPECIALIST','SURVEYOR') ON CONFLICT (layer_id, role_id) DO UPDATE SET can_view = true;`);
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
            resetAuthBuckets();
            await use();
        },
        { auto: true },
    ],
});
export { expect };
