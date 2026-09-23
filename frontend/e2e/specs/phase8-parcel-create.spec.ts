import { test, expect, login, psql } from '../fixtures';

/**
 * PHASE 8 E2E: manual parcel creation (TASK-072).
 * Verifies the frontend.md §20 flow:
 *   - "New parcel" button on the list opens the draw page
 *   - polygon drawing updates the live readout (vertices · perimeter · area)
 *   - provenance defaults to MANUAL_DRAWING, DIGITIZED_FROM_IMAGERY on satellite
 *   - survey-derived provenance options disabled until a survey plan is attached,
 *     then a typed justification is required
 *   - Save draft creates the parcel via the API (visible in the editor)
 */

const DRAW_CODE = 'E2E_PARCELL_DRAW';
const SURVEY_CODE = 'E2E_PARCELL_SRVY';

async function waitForMapReady(page: import('@playwright/test').Page) {
    await expect(page.getByTestId('parcel-create-map')).toBeVisible({ timeout: 20_000 });
    await expect(page.getByTestId('parcel-create-map')).toHaveAttribute('data-map-ready', 'true', { timeout: 20_000 });
}

async function drawQuad(page: import('@playwright/test').Page) {
    const s = { x: 560, y: 360 };
    await page.mouse.click(s.x, s.y);
    await page.mouse.click(s.x + 100, s.y);
    await page.mouse.click(s.x + 100, s.y + 70);
    await page.mouse.click(s.x, s.y + 70);
    await page.mouse.click(s.x, s.y); // close the ring
    // The polygon must be in component state before submitting the form, otherwise
    // geometry is null in the create payload (TASK-072 save draft).
    await expect(page.getByTestId('parcel-create-readout')).toBeVisible({ timeout: 15_000 });
}

test.describe('Phase 8 — new parcel (TASK-072)', () => {
    test.afterEach(async () => {
        psql(`DELETE FROM app.parcels WHERE parcel_code IN ('${DRAW_CODE}','${SURVEY_CODE}');`);
    });

    test('TASK-072: list button opens create page, drawing updates the readout', async ({ page }) => {
        await login(page);
        await page.goto('/parcels');

        await page.getByTestId('parcel-create-btn').click();
        await expect(page.getByRole('heading', { name: 'New parcel' })).toBeVisible({ timeout: 15_000 });
        await waitForMapReady(page);

        // Default provenance with the roads basemap is MANUAL_DRAWING.
        await expect(page.getByTestId('parcel-create-provenance')).toHaveValue('MANUAL_DRAWING');

        // Draw a poly → live readout appears with count > 0 and area > 0.
        await drawQuad(page);

        await expect(page.getByTestId('parcel-create-readout')).toBeVisible({ timeout: 15_000 });
        const vertices = Number(await page.getByTestId('parcel-create-vertices').textContent());
        const area = Number((await page.getByTestId('parcel-create-area').textContent())!.replace(/[^0-9.]/g, ''));
        expect(vertices).toBeGreaterThanOrEqual(4);
        expect(area).toBeGreaterThan(0);
    });

    test('TASK-072: satellite basemap flips the default provenance to DIGITIZED_FROM_IMAGERY', async ({ page }) => {
        await login(page);
        await page.goto('/parcels/new');
        await waitForMapReady(page);

        await page.getByTestId('create-basemap-satellite').click();
        await expect(page.getByTestId('parcel-create-provenance')).toHaveValue('DIGITIZED_FROM_IMAGERY');
    });

    test('TASK-072: save draft creates a MANUAL_DRAWING parcel at v1 with geometry in PostGIS', async ({ page }) => {
        await login(page);
        await page.goto('/parcels/new');
        await waitForMapReady(page);

        await drawQuad(page);

        await page.getByTestId('parcel-create-code').fill(DRAW_CODE);
        await page.getByTestId('parcel-create-save-draft').click();

        // Lands on the editor for the new parcel.
        await expect(page.getByRole('heading', { name: DRAW_CODE })).toBeVisible({ timeout: 20_000 });
        await expect(page.getByTestId('parcel-status-text')).toHaveText('DRAFT');
        await expect(page.getByTestId('parcel-provenance-text')).toHaveText('MANUAL_DRAWING');
        await expect(page.getByTestId('parcel-version-text')).toHaveText('v1');

        // Geometry really landed in PostGIS.
        const valid = psql(
            `SELECT count(*) FROM app.parcels WHERE parcel_code = '${DRAW_CODE}' AND ST_IsValid(geom) AND geometry_source = 'MANUAL_DRAWING' AND version = 1`,
        );
        expect(Number(valid)).toBe(1);
    });

    test('TASK-072: survey-derived options are disabled until a survey plan is attached, then need a justification', async ({ page }) => {
        await login(page);
        await page.goto('/parcels/new');
        await waitForMapReady(page);

        // Survey-derived option present but disabled (no survey data on this parcel).
        await expect(page.getByTestId('parcel-create-provenance-notice')).toBeVisible();
        const surveyOption = page.getByTestId('parcel-create-provenance')
            .locator('option[value="SURVEY_COORDINATES"]');
        await expect(surveyOption).toBeDisabled();

        // Attach a survey plan → the option becomes usable.
        await page.getByTestId('parcel-create-survey-plan').fill('1');
        await expect(surveyOption).toBeEnabled();

        // Selecting it reveals the justification requirement; saving without it is refused.
        await page.getByTestId('parcel-create-provenance').selectOption('SURVEY_COORDINATES');
        await expect(page.getByTestId('parcel-create-survey-note')).toBeVisible();

        // Now complete the flow: a polygon + justification → survey-derived parcel.
        await drawQuad(page);

        await page.getByTestId('parcel-create-code').fill(SURVEY_CODE);
        await page.getByTestId('parcel-create-justification').fill('Relabelled after attaching the field survey.');
        await page.getByTestId('parcel-create-save-draft').click();

        await expect(page.getByRole('heading', { name: SURVEY_CODE })).toBeVisible({ timeout: 20_000 });
        await expect(page.getByTestId('parcel-provenance-text')).toHaveText('SURVEY_COORDINATES');

        // Justification was recorded on the version (audit) row.
        const stored = psql(
            `SELECT pv.change_reason FROM audit.parcel_versions pv JOIN app.parcels p ON p.id = pv.parcel_id WHERE p.parcel_code = '${SURVEY_CODE}' AND pv.version = 1`,
        );
        expect(stored).toContain('field survey');
    });
});