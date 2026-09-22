import { test, expect, login, psql } from '../fixtures';

/**
 * PHASE 8 E2E: parcel list page with filters, search, and map preview.
 * Serial (workers=1) like the other specs — share the dev server + DB.
 *
 * TASK-070 AC under test: `include_historical=true` is the ONLY way to see
 * SUPERSEDED parcels — the default list must hide them even when filtered.
 */

test.describe('Phase 8 — parcel list', () => {
    test.beforeEach(async ({ page }) => {
        // Seed two deterministic parcels; single-line `psql` (win32 mangles multi-line args).
        psql(`INSERT INTO app.parcels (id, parcel_code, lot_number, status, psgc_barangay, geometry_source, version) VALUES (gen_random_uuid(), 'E2E_PARCEL_LIVE', 'E2E-LOT-1', 'DRAFT', '990101000', 'MANUAL_DRAWING', 1), (gen_random_uuid(), 'E2E_PARCEL_HIST', 'E2E-LOT-2', 'SUPERSEDED', '990101000', 'MANUAL_DRAWING', 1);`);
        await login(page);
    });

    test.afterEach(async () => {
        psql(`DELETE FROM app.parcels WHERE parcel_code IN ('E2E_PARCEL_LIVE','E2E_PARCEL_HIST');`);
    });

    test('TASK-070: page renders filter bar, results table, and map preview', async ({ page }) => {
        await page.goto('/parcels');
        await expect(page.getByTestId('parcel-map')).toBeVisible({ timeout: 20_000 });
        await expect(page.getByTestId('parcel-search')).toBeVisible();
        await expect(page.getByTestId('parcel-status-filter')).toBeVisible();
        await expect(page.getByTestId('parcel-include-historical')).toBeVisible();

        // Default (non-historical) list shows the live fixture, hides the historical one.
        await page.getByTestId('parcel-search').fill('E2E_PARCEL');
        await page.getByTestId('parcel-search-btn').click();
        await expect(page.getByTestId('parcel-row').first()).toBeVisible({ timeout: 15_000 });
        await expect(page.locator('[data-testid="parcel-row"] code', { hasText: 'E2E_PARCEL_LIVE' })).toBeVisible();
        await expect(page.locator('[data-testid="parcel-row"]', { hasText: 'E2E_PARCEL_HIST' })).toHaveCount(0);

        // include_historical surfaces the SUPERSEDED parcel.
        await page.getByTestId('parcel-include-historical').check();
        await page.getByTestId('parcel-search-btn').click();
        await expect(page.locator('[data-testid="parcel-row"]', { hasText: 'E2E_PARCEL_HIST' })).toBeVisible({ timeout: 15_000 });
        await expect(page.locator('[data-testid="parcel-row"]', { hasText: 'SUPERSEDED' })).toBeVisible();

        // Status filter narrows the result set.
        await page.getByTestId('parcel-status-filter').selectOption('DRAFT');
        await page.getByTestId('parcel-search-btn').click();
        await expect(page.locator('[data-testid="parcel-row"]', { hasText: 'E2E_PARCEL_HIST' })).toHaveCount(0);
        await expect(page.locator('[data-testid="parcel-row"]', { hasText: 'E2E_PARCEL_LIVE' })).toBeVisible();
    });
});