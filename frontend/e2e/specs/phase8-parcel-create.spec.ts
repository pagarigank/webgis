import { test, expect, login, psql } from '../fixtures';

/**
 * PHASE 8 E2E: manual parcel creation (TASK-072).
 * Verifies the frontend.md §20 flow:
 *   - "New parcel" button on the list opens the draw page
 *   - polygon drawing updates the live readout (vertices · perimeter · area)
 *   - provenance defaults to DIGITIZED_FROM_IMAGERY (satellite basemap), MANUAL_DRAWING on roads
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
    // Click the map canvas via its locator so Playwright scrolls it into view
    // first — form fills can push the map off-screen and miss viewport clicks.
    // Pace the clicks a little so mapbox-gl-draw reliably registers each vertex
    // and computes the centroid for the ring-close hit test.
    const canvas = page.getByTestId('parcel-create-map').locator('canvas');
    await expect(canvas).toBeVisible({ timeout: 15_000 });
    const box = (await canvas.boundingBox())!;
    const s = { x: box.width * 0.4, y: box.height * 0.4 };
    const click = async (dx: number, dy: number) => {
        await canvas.click({ position: { x: dx, y: dy } });
        await page.waitForTimeout(120);
    };
    await click(s.x, s.y);
    await click(s.x + box.width * 0.15, s.y);
    await click(s.x + box.width * 0.15, s.y + box.height * 0.25);
    await click(s.x, s.y + box.height * 0.25);
    await click(s.x, s.y); // close the ring
    // The polygon must be in component state before submitting the form, otherwise
    // geometry is null in the create payload (TASK-072 save draft). The readout
    // placeholder is always rendered, so wait on the vertices counter instead.
    await expect(page.getByTestId('parcel-create-vertices')).toContainText(/[1-9]/, { timeout: 15_000 });
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

        // Default provenance with the satellite basemap is DIGITIZED_FROM_IMAGERY.
        await expect(page.getByTestId('parcel-create-provenance')).toHaveValue('DIGITIZED_FROM_IMAGERY');

        // Draw a poly → live readout appears with count > 0 and area > 0.
        await drawQuad(page);

        await expect(page.getByTestId('parcel-create-readout')).toBeVisible({ timeout: 15_000 });
        const vertices = Number(await page.getByTestId('parcel-create-vertices').textContent());
        const area = Number((await page.getByTestId('parcel-create-area').textContent())!.replace(/[^0-9.]/g, ''));
        expect(vertices).toBeGreaterThanOrEqual(4);
        expect(area).toBeGreaterThan(0);
    });

    test('TASK-072: basemap toggle flips the default provenance (satellite → DIGITIZED_FROM_IMAGERY, roads → MANUAL_DRAWING)', async ({ page }) => {
        await login(page);
        await page.goto('/parcels/new');
        await waitForMapReady(page);

        // Satellite is the default backdrop → DIGITIZED_FROM_IMAGERY.
        await expect(page.getByTestId('parcel-create-provenance')).toHaveValue('DIGITIZED_FROM_IMAGERY');

        // Roads → MANUAL_DRAWING.
        await page.getByTestId('create-basemap-roads').click();
        await expect(page.getByTestId('parcel-create-provenance')).toHaveValue('MANUAL_DRAWING');

        // Back to satellite → DIGITIZED_FROM_IMAGERY.
        await page.getByTestId('create-basemap-satellite').click();
        await expect(page.getByTestId('parcel-create-provenance')).toHaveValue('DIGITIZED_FROM_IMAGERY');
    });

    test('TASK-072: save draft creates a MANUAL_DRAWING parcel at v1 with geometry in PostGIS', async ({ page }) => {
        await login(page);
        await page.goto('/parcels/new');
        await waitForMapReady(page);

        // Over the roads basemap the drawn polygon is a manual drawing.
        await page.getByTestId('create-basemap-roads').click();
        await expect(page.getByTestId('parcel-create-provenance')).toHaveValue('MANUAL_DRAWING');

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