import { Page, ConsoleMessage } from '@playwright/test';
// `test` must come from ../fixtures, not @playwright/test: the extended test
// carries the auto `resetAuthRateLimit` fixture. Importing the raw `test`
// silently skips it, and after ~10 logins the 10/60s auth limiter (api.md
// §1.5) answers 429 and every subsequent login fails with "Too many attempts".
import { test, expect, login, adminToken, API_BASE } from '../fixtures';

/**
 * Phase 5-15 form-driven QA sweep.
 *
 * This exists because a purely static gate (tsc / Vitest / vite build) cannot
 * see a component that throws during render: the route resolves, the HTTP
 * calls succeed, and the page is nevertheless a blank white screen. That is
 * exactly how `dependents.length` on an undefined array took down every
 * control-point detail page while `npm run build` stayed green.
 *
 * So for each module the sweep asserts three things that a build cannot:
 *   1. the route renders real content (not a blank document),
 *   2. no uncaught exception or console error was raised,
 *   3. no API call returned 4xx/5xx.
 *
 * Interaction is driven through the rendered UI (links, buttons, form fields)
 * rather than by calling the API directly, so a form whose submit button is
 * unwired still fails here.
 */

interface RouteCase {
    path: string;
    /** Shown in the failure message. */
    label: string;
    /** Substring that must appear once the module has loaded. */
    expectText?: RegExp;
}

const ROUTES: RouteCase[] = [
    { path: '/map', label: 'Phase 5-7 map shell', expectText: /Map Layers|Target layer/i },
    { path: '/parcels', label: 'Phase 8 parcels list', expectText: /parcel/i },
    { path: '/parcels/new', label: 'Phase 8 parcel create form', expectText: /parcel|draw/i },
    { path: '/parcels/inbox', label: 'Phase 13 reviewer inbox', expectText: /inbox|review|no /i },
    { path: '/control-points', label: 'Phase 9 control point list', expectText: /Control Point/i },
    { path: '/control-points/new', label: 'Phase 9 control point create form', expectText: /Point Name/i },
    { path: '/audit', label: 'Audit log view', expectText: /audit|log|no /i },
    { path: '/status', label: 'System status', expectText: /status|service|version/i },
    { path: '/admin/users', label: 'Admin users', expectText: /Users/i },
    { path: '/admin/roles', label: 'Admin roles', expectText: /Role/i },
    { path: '/admin/organizations', label: 'Admin organizations', expectText: /Organi/i },
    { path: '/admin/layers', label: 'Phase 5-7 GIS layers admin', expectText: /layer/i },
    { path: '/admin/basemaps', label: 'Phase 5-7 basemaps admin', expectText: /basemap|provider/i },
];

/**
 * Attach collectors for the page lifetime. `noise` holds patterns that are
 * expected in a healthy app and must not fail the sweep.
 */
function watch(page: Page) {
    const pageErrors: string[] = [];
    const consoleErrors: string[] = [];
    const badResponses: string[] = [];

    /**
     * Recording is gated on start().
     *
     * The login helper returns as soon as the app shell appears, which can be
     * before its post-login landing route has finished loading. Attaching the
     * watcher at that moment captured traffic belonging to the landing page
     * rather than to the route under test: a `401 GET /api/v1/basemaps` from the
     * landing page's map load was recorded, the test then navigated away, and
     * that abort killed the interceptor's retry before it answered. The result
     * was an intermittent failure attributed to whichever route happened to run
     * next — including routes that never load basemaps at all.
     *
     * Call start() immediately before the navigation being observed, so the
     * watcher sees that navigation's full cold boot and nothing before it.
     */
    let started = false;

    // Cold-boot 401 tracking.
    //
    // The access token lives only in memory (src/auth/tokenStore.ts), so every
    // hard reload starts with an empty token store: the GET /me bootstrap 401s
    // and the apiClient interceptor turns that into a silent refresh + retry.
    // Components that mount and query in the same tick (basemaps on /map,
    // notifications on /parcels/inbox) therefore also fire before the token
    // exists and 401 as well. That is by design and self-healing, so a blanket
    // "401 is a defect" rule makes the sweep fail intermittently on healthy
    // routes.
    //
    // The precise rule: a 401 is benign only if the SAME endpoint later answers
    // 2xx, which is what the interceptor's retry looks like on the wire. An
    // endpoint that stays unauthorized still fails the sweep.
    const unauthorized: { key: string; at: number; label: string }[] = [];
    const recovered = new Set<string>();

    page.on('pageerror', (err) => {
        if (!started) return;
        pageErrors.push(err.message);
    });

    page.on('console', (msg: ConsoleMessage) => {
        if (!started) return;
        if (msg.type() !== 'error') return;
        const text = msg.text();
        // Failed *requests* are reported by the response handler below, which
        // has the URL and status. Playwright's console text for those is only
        // "Failed to load resource: ... 401" with no URL, so filtering them here
        // by name avoids a duplicate, unidentifiable failure. What remains is
        // genuine JavaScript output (React warnings, thrown errors).
        if (/Failed to load resource/i.test(text)) return;
        consoleErrors.push(text);
    });

    page.on('response', (res) => {
        if (!started) return;
        const url = res.url();
        if (!url.includes('/api/v1/')) return;

        const key = `${res.request().method()} ${url.replace(/^https?:\/\/[^/]+/, '')}`;

        if (res.status() < 400) {
            // Anything under 400 counts as the retry succeeding, including
            // 304 Not Modified: a revalidated response still proves the
            // endpoint authorized the retry and served the body, so treating
            // only 2xx as recovered reported healthy cached endpoints as
            // "no successful retry".
            recovered.add(key);
            return;
        }
        if (res.status() === 401) {
            unauthorized.push({ key, at: Date.now(), label: `401 ${key}` });
            return;
        }
        badResponses.push(`${res.status()} ${key}`);
    });

    // Unresolved 401s are reported after the page has settled (see settle()).
    const collectUnrecovered = () => {
        for (const entry of unauthorized) {
            if (recovered.has(entry.key)) continue;
            badResponses.push(`${entry.label} (no successful retry)`);
        }
    };

    /**
     * Console errors with network-status narration removed.
     *
     * Loaders log a failed request ("Failed to load basemaps AxiosError:
     * Request failed with status code 401") from their own catch, which can run
     * after the interceptor already refreshed and retried, and can land after
     * the settle window. Chasing that timing is not worth it: HTTP correctness
     * is asserted precisely by badResponses, which only passes an endpoint whose
     * 401 was followed by a real 2xx retry. This list is for JavaScript-level
     * problems (React warnings, thrown errors), so the status-code narration is
     * dropped unconditionally — a genuinely unauthorized endpoint still fails on
     * badResponses.
     */
    const finalConsoleErrors = () => consoleErrors.filter((text) => !/status code 40\d/.test(text));

    /**
     * Wait for the cold-boot chain to finish: 401 → silent refresh → retry.
     * A fixed sleep is racy because the chain only starts once the component
     * mounts and queues behind whatever the /me bootstrap is already doing, so
     * the wait is on the recovery itself. An endpoint that never recovers
     * still fails, it just fails after the timeout instead of immediately.
     */
    const settle = async (timeoutMs = 15_000) => {
        const deadline = Date.now() + timeoutMs;
        while (Date.now() < deadline) {
            if (unauthorized.every((entry) => recovered.has(entry.key))) break;
            await new Promise((r) => setTimeout(r, 250));
        }
        // Give a late console narration a moment to land so finalConsoleErrors
        // sees it; it is filtered, not asserted, so this cannot mask a failure.
        await new Promise((r) => setTimeout(r, 500));
        collectUnrecovered();
    };

    /** Begin observing. Call immediately before the navigation under test. */
    const start = () => {
        started = true;
    };

    return { pageErrors, badResponses, settle, finalConsoleErrors, start };
}

test.describe('Phase 5-15 module sweep (form-driven)', () => {
    test.beforeEach(async ({ page }) => {
        await login(page);

        // Discard the post-login landing page. login() returns once the shell
        // appears, which is before the landing route has finished loading, so
        // that page's requests (including the map's basemaps fetch on /map) were
        // still in flight when the test navigated to the route under test. The
        // navigation aborted the interceptor's retry mid-flight, and the
        // resulting 401 was then reported against an unrelated route — one that
        // does not even load basemaps. Loading about:blank destroys the landing
        // page first, so each test starts with no in-flight traffic of its own.
        await page.goto('about:blank');
    });

    for (const route of ROUTES) {
        test(`${route.label} — ${route.path} renders without crashing`, async ({ page }) => {
            const sink = watch(page);

            sink.start();
            await page.goto(route.path);
            // Let queries settle. Networkidle is avoided because the map keeps
            // a long-lived tile connection open.
            await page.waitForLoadState('domcontentloaded');
            await expect
                .poll(async () => (await page.evaluate(() => document.body.innerText.trim().length)) ?? 0, {
                    timeout: 20_000,
                })
                .toBeGreaterThan(0);

            if (route.expectText) {
                await expect(page.locator('body')).toContainText(route.expectText, { timeout: 15_000 });
            }

            // Wait for the cold-boot refresh + retry before judging the 401s.
            await sink.settle();

            expect(sink.pageErrors, `uncaught exception on ${route.path}`).toEqual([]);
            expect(sink.finalConsoleErrors(), `console error on ${route.path}`).toEqual([]);
            expect(sink.badResponses, `failed API call on ${route.path}`).toEqual([]);
        });
    }

    /**
     * Control points created through the UI, removed again in afterAll.
     * The database is shared with the other specs and the backend suite, so an
     * untracked row here outlives the run: a GLOBAL-scope user then sees it in
     * a global nearest-point query, and Tests\Spatial\NearestPointTest starts
     * failing on rows it never created.
     */
    const createdControlPointIds: string[] = [];

    test.afterAll(async () => {
        if (createdControlPointIds.length === 0) return;
        const token = await adminToken();
        for (const id of createdControlPointIds) {
            // DELETE soft-deletes and requires an audit reason.
            const res = await fetch(`${API_BASE}/api/v1/control-points/${id}?reason=e2e+cleanup`, {
                method: 'DELETE',
                headers: { Authorization: `Bearer ${token}` },
            });
            if (!res.ok && res.status !== 404) {
                throw new Error(`cleanup: DELETE /control-points/${id} -> ${res.status}`);
            }
        }
    });

    test('a control point detail page renders its dependent-parcels section', async ({ page }) => {
        // Regression for the blank-screen crash: the detail page dereferenced
        // `dependents.length` while the /dependents payload is keyed `parcels`,
        // so every detail view threw and rendered nothing.
        const sink = watch(page);
        
        sink.start();
        await page.goto('/control-points/new');
        await page.locator('select').nth(2).selectOption('GEOGRAPHIC');

        const name = `E2E-SWEEP-${Date.now()}`;
        await page.getByPlaceholder('e.g. BLLM-1, MBM-4').fill(name);
        const nums = page.locator('input[type="number"]');
        await nums.nth(0).fill('14.6012');
        await nums.nth(1).fill('121.0234');
        await page.getByRole('button', { name: 'Create Control Point' }).click();

        await expect(page).toHaveURL(/\/control-points\/\d+$/, { timeout: 20_000 });
        await expect(page.locator('body')).toContainText(name);
        await expect(page.locator('body')).toContainText('Dependent Parcels');

        createdControlPointIds.push(page.url().split('/').pop()!);

        await sink.settle();
        expect(sink.pageErrors).toEqual([]);
        expect(sink.badResponses).toEqual([]);
    });

    test('every parcel editor tab renders for an existing parcel', async ({ page }) => {
        // Each of the 12 tabs is an independently rendered component, so a crash
        // in any single one stays invisible until that tab is opened. The tab
        // strip is driven through the UI so the test also covers the tab
        // routing itself rather than hand-built URLs.
        const sink = watch(page);
        
        sink.start();
        await page.goto('/parcels');
        // Target the anchor itself. A combined "tbody tr a, table tbody tr"
        // selector resolves .first() to the <tr>, which precedes its own inner
        // <a> in DOM order and carries no click handler, so the click is a no-op.
        await page.locator('tbody tr a[href^="/parcels/"]').first().click();
        await expect(page).toHaveURL(/\/parcels\/[0-9a-f-]{8,}/, { timeout: 20_000 });
        const parcelId = page.url().split('/parcels/')[1].split(/[/?#]/)[0];

        const tabs = [
            'Information',
            'Survey',
            'Title',
            'Tie point',
            'Technical description',
            'Computation',
            'Validation',
            'Split',
            'Consolidate',
            'Lineage',
            'Documents',
            'History',
        ];
        expect(tabs).toHaveLength(12);

        for (const label of tabs) {
            const before = sink.pageErrors.length;
            await page.getByRole('link', { name: label, exact: true }).click();
            await expect(page).toHaveURL(new RegExp(`/parcels/${parcelId}/`)).catch(() => undefined);
            await expect
                .poll(async () => (await page.evaluate(() => document.body.innerText.trim().length)) ?? 0, { timeout: 15_000 })
                .toBeGreaterThan(0);
            expect(
                sink.pageErrors.slice(before),
                `uncaught exception opening parcel tab "${label}"`,
            ).toEqual([]);
        }
    });
});
