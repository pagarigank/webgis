import { test, expect, login, psql } from '../fixtures';

/**
 * PHASE 8 E2E: parcel editor shell (TASK-071).
 * Verifies the tabbed editor surface per frontend.md §7:
 *   - tabs each independently loadable via URL
 *   - persistent right-hand map preview
 *   - sticky status/action bar with status, provenance, version, save-state
 *   - explicit, visible save state ("Unsaved changes" / "Saved HH:MM")
 *   - navigation away with unsaved edits prompts via the guard modal
 */

const SEED_CODE = 'E2E_PARCEL_EDIT';
const EDIT_PARCEL_CODE = 'E2E_PARCEL_EDIT2';

test.describe('Phase 8 — parcel editor', () => {
    test.beforeEach(async ({ page }) => {
        psql(`INSERT INTO app.parcels (id, parcel_code, lot_number, status, psgc_barangay, geometry_source, version) VALUES (gen_random_uuid(), '${SEED_CODE}', 'EDIT-LOT-1', 'DRAFT', '990101000', 'MANUAL_DRAWING', 1), (gen_random_uuid(), '${EDIT_PARCEL_CODE}', 'EDIT-LOT-2', 'SUBMITTED', '990101000', 'MANUAL_DRAWING', 1);`);
        await login(page);
    });

    test.afterEach(async () => {
        psql(`DELETE FROM app.parcels WHERE parcel_code IN ('${SEED_CODE}','${EDIT_PARCEL_CODE}');`);
    });

    test('TASK-071: opens editor from list, renders tabs, map preview, and status bar', async ({ page }) => {
        await page.goto('/parcels');
        await page.getByTestId('parcel-open-' + SEED_CODE).click();

        // Editor loads with the information tab active (URL-driven).
        await expect(page.getByRole('heading', { name: SEED_CODE })).toBeVisible({ timeout: 15_000 });
        await expect(page.getByTestId('parcel-tab-information')).toHaveClass(/active/);
        await expect(page.getByTestId('parcel-editor-map')).toBeVisible({ timeout: 20_000 });

        // All nine tabs render as links.
        for (const tab of ['survey', 'title', 'tiepoint', 'techdesc', 'computation', 'validation', 'documents', 'history']) {
            await expect(page.getByTestId(`parcel-tab-${tab}`)).toBeVisible();
        }

        // Sticky bar shows status, provenance, version.
        const bar = page.getByTestId('parcel-status-bar');
        await expect(bar).toBeVisible({ timeout: 15_000 });
        await expect(bar.getByTestId('parcel-status-text')).toHaveText('DRAFT');
        await expect(bar.getByTestId('parcel-provenance-text')).toHaveText('MANUAL_DRAWING');
        await expect(bar.getByTestId('parcel-version-text')).toHaveText('v1');

        // Tab switch is independently loadable.
        await page.getByTestId('parcel-tab-history').click();
        await expect(page).toHaveURL(/\/parcels\/.+?\/history$/);
        await expect(page.getByText('Version history')).toBeVisible();
    });

    test('TASK-071: editing marks Unsaved, leaving prompts, save clears to Saved', async ({ page }) => {
        await page.goto('/parcels');
        await page.getByTestId('parcel-open-' + SEED_CODE).click();
        await expect(page.getByTestId('parcel-editor-lot')).toBeVisible({ timeout: 15_000 });

        // Initially clean.
        await expect(page.getByTestId('parcel-save-state')).toContainText('No local changes');

        // Edit a field -> explicit "Unsaved changes".
        await page.getByTestId('parcel-editor-lot').fill('EDIT-LOT-CHANGED');
        await expect(page.getByTestId('parcel-save-state')).toContainText('Unsaved changes', { timeout: 5_000 });

        // Navigating away with unsaved changes prompts.
        await page.getByRole('link', { name: 'Back to list' }).click();
        await expect(page.getByRole('heading', { name: 'Unsaved changes' })).toBeVisible({ timeout: 5_000 });
        await page.getByTestId('parcel-guard-leave').click();
        await expect(page).toHaveURL(/\/parcels$/);
        await expect(page.getByTestId('parcel-search')).toBeVisible();
    });

    test('TASK-071: stay on guard keeps edits, save persists then shows Saved', async ({ page }) => {
        await page.goto('/parcels');
        await page.getByTestId('parcel-open-' + SEED_CODE).click();
        await expect(page.getByTestId('parcel-editor-lot')).toBeVisible({ timeout: 15_000 });

        // Edit + attempt to leave; guard asks; "Stay" keeps us + the edits.
        await page.getByTestId('parcel-editor-lot').fill('EDIT-LOT-CHANGED');
        await expect(page.getByTestId('parcel-save-state')).toContainText('Unsaved changes', { timeout: 5_000 });
        await page.getByRole('link', { name: 'Back to list' }).click();
        await expect(page.getByRole('heading', { name: 'Unsaved changes' })).toBeVisible({ timeout: 5_000 });
        await page.getByRole('button', { name: 'Stay' }).click();
        await expect(page.getByRole('heading', { name: 'Unsaved changes' })).toHaveCount(0);
        await expect(page.getByTestId('parcel-editor-lot')).toHaveValue('EDIT-LOT-CHANGED');

        // Save via the sticky bar -> an explicit "Saved HH:MM".
        await page.getByTestId('parcel-editor-save').click();
        await expect(page.getByTestId('parcel-save-state')).toContainText(/Saved \d{1,2}:\d{2}/, { timeout: 10_000 });

        // Persisted: reopening the editor shows the saved lot.
        await page.goto('/parcels');
        await page.getByTestId('parcel-open-' + SEED_CODE).click();
        await expect(page.getByTestId('parcel-editor-lot')).toBeVisible({ timeout: 15_000 });
        await expect(page.getByTestId('parcel-editor-lot')).toHaveValue('EDIT-LOT-CHANGED');
    });
});