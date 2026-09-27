import { test, expect, login, psql } from '../fixtures';

/**
 * TASK-128 — import wizard.
 *
 * Both acceptance criteria are asserted here against the running SPA:
 *
 *  1. "the CRS step cannot be skipped" — the continue control is disabled with
 *     no declared CRS, the tab stays locked, and clicking it does nothing.
 *     Asserting only the disabled attribute would pass against a button that is
 *     merely styled that way, so the click is attempted and the step is checked
 *     for not having advanced.
 *
 *  2. "commit is unreachable until validation succeeds" — before validation the
 *     commit tab is locked and its panel is not in the DOM at all, so there is
 *     nothing to click or focus. After validation it becomes reachable.
 *
 * The rejection path is the interesting half: a file with a row that cannot
 * validate must show per-row errors, offer the error CSV, and refuse a
 * full commit while allowing a partial one. A wizard that lets a bad file
 * through is worse than no wizard.
 */

const LAYER_CODE = 'E2E_IMPORT_LAYER';

/** A small square polygon, offset so each feature occupies its own ground. */
const square = (x: number, y: number) => ({
    type: 'Polygon',
    coordinates: [
        [
            [x, y],
            [x + 0.01, y],
            [x + 0.01, y + 0.01],
            [x, y + 0.01],
            [x, y],
        ],
    ],
});

const lot = (label: string, x: number, y: number) => ({
    type: 'Feature',
    properties: { lot_label: label, owner_tin: '111-111-111' },
    geometry: square(x, y),
});

/** Two well-formed polygons: the happy path. */
const GOOD_FILE = JSON.stringify({
    type: 'FeatureCollection',
    features: [lot('Lot A', 120.99, 14.49), lot('Lot B', 121.01, 14.51)],
});

/**
 * The rejection path: a feature with no geometry. The pipeline stages it and
 * rejects it per row (VR-32) rather than failing the whole file, which is
 * exactly the case the partial commit exists to handle.
 */
const REJECTED_FILE = JSON.stringify({
    type: 'FeatureCollection',
    features: [
        lot('Lot A', 120.99, 14.49),
        lot('Lot B', 121.01, 14.51),
        { type: 'Feature', properties: { lot_label: 'No Geometry', owner_tin: '333-333-333' }, geometry: null },
    ],
});

const seedLayer = () =>
    `INSERT INTO app.gis_layers (code, name, geometry_type) VALUES ('${LAYER_CODE}', 'E2E Import Layer', 'POLYGON') ON CONFLICT (code) DO UPDATE SET name = 'E2E Import Layer'; INSERT INTO app.gis_layer_fields (layer_id, field_name, field_label, field_type, sort_order) SELECT l.id, v.f, v.f, 'text', v.n FROM app.gis_layers l, (VALUES ('lot_label', 1), ('owner_tin', 2)) AS v(f, n) WHERE l.code = '${LAYER_CODE}' ON CONFLICT (layer_id, field_name) DO NOTHING; INSERT INTO app.layer_permissions (layer_id, role_id, can_view, can_create, can_update, can_delete, can_approve) SELECT l.id, r.id, true, true, true, true, true FROM app.gis_layers l CROSS JOIN app.roles r WHERE l.code = '${LAYER_CODE}' AND r.code = 'SYS_ADMIN' ON CONFLICT (layer_id, role_id) DO UPDATE SET can_view = true, can_create = true;`;

const reset = () =>
    `DELETE FROM app.import_jobs WHERE target_layer_id IN (SELECT id FROM app.gis_layers WHERE code = '${LAYER_CODE}'); DELETE FROM app.gis_layer_fields WHERE layer_id IN (SELECT id FROM app.gis_layers WHERE code = '${LAYER_CODE}'); DELETE FROM app.gis_features WHERE layer_id IN (SELECT id FROM app.gis_layers WHERE code = '${LAYER_CODE}'); DELETE FROM app.layer_permissions WHERE layer_id IN (SELECT id FROM app.gis_layers WHERE code = '${LAYER_CODE}'); DELETE FROM app.gis_layers WHERE code = '${LAYER_CODE}';`;

test.describe('import wizard (TASK-128)', () => {
    test.beforeAll(() => {
        psql(reset());
        psql(seedLayer());
    });

    test.afterAll(() => {
        psql(reset());
    });

    test.beforeEach(async ({ page }) => {
        await login(page);
    });

    /** Upload a CSV and land on the CRS step. */
    const startImport = async (page: import('@playwright/test').Page, contents: string, filename: string) => {
        await page.goto('/imports');
        await expect(page.getByTestId('step-upload')).toBeVisible();

        await page.getByTestId('import-file').setInputFiles({
            name: filename,
            mimeType: 'application/geo+json',
            buffer: Buffer.from(contents, 'utf8'),
        });

        await page.getByTestId('target-entity').selectOption('FEATURE');
        const layerValue = await page
            .getByTestId('target-layer')
            .locator('option', { hasText: 'E2E Import Layer' })
            .first()
            .getAttribute('value');
        expect(layerValue).toBeTruthy();
        await page.getByTestId('target-layer').selectOption(layerValue!);

        await page.getByTestId('start-import').click();
        await expect(page.getByTestId('step-crs')).toBeVisible();
    };

    test('the CRS step cannot be skipped', async ({ page }) => {
        await startImport(page, GOOD_FILE, 'good.geojson');

        // Nothing selected: the step cannot be left.
        await expect(page.getByTestId('crs-continue')).toBeDisabled();

        // A disabled attribute alone proves nothing, so actually try to pass it.
        await page.getByTestId('crs-continue').click({ force: true });
        await expect(page.getByTestId('step-crs')).toBeVisible();
        await expect(page.getByTestId('step-mapping')).toHaveCount(0);

        // The forward tabs stay locked while the CRS is undeclared.
        await expect(page.getByTestId('step-tab-mapping')).toBeDisabled();
        await expect(page.getByTestId('step-tab-preview')).toBeDisabled();
        await expect(page.getByTestId('step-tab-commit')).toBeDisabled();

        // Declaring a CRS releases field mapping. Review opens too, so staged rows
        // can be inspected before validation, but commit stays locked.
        await page.getByTestId('declared-crs').selectOption('EPSG:4326');
        await page.getByTestId('crs-continue').click();
        await expect(page.getByTestId('step-mapping')).toBeVisible();
        await expect(page.getByTestId('step-tab-commit')).toBeDisabled();
    });

    test('commit is unreachable until validation succeeds, then the import commits', async ({ page }) => {
        await startImport(page, GOOD_FILE, 'commit.geojson');

        // Before validation the commit panel does not exist in the DOM, so there
        // is no control to click, focus, or reach with the keyboard.
        await expect(page.getByTestId('step-tab-commit')).toHaveAttribute('data-state', 'locked');
        await page.getByTestId('step-tab-commit').click({ force: true });
        await expect(page.getByTestId('step-commit')).toHaveCount(0);

        await page.getByTestId('declared-crs').selectOption('EPSG:4326');
        await page.getByTestId('crs-continue').click();
        await expect(page.getByTestId('step-mapping')).toBeVisible();

        // Mapping is reachable, commit is still not.
        await expect(page.getByTestId('step-tab-commit')).toBeDisabled();

        await page.getByTestId('validate-button').click();
        await expect(page.getByTestId('step-preview')).toBeVisible();
        await expect(page.getByTestId('invalid-count')).toHaveText('0');

        // Only now does commit become reachable.
        await expect(page.getByTestId('step-tab-commit')).toBeEnabled();
        await page.getByTestId('step-tab-commit').click();
        await expect(page.getByTestId('step-commit')).toBeVisible();

        await page.getByTestId('commit-button').click();
        // A successful commit moves the job to COMMITTED, which renders the
        // terminal summary rather than the pre-commit form.
        await expect(page.getByTestId('commit-outcome')).toContainText('2 rows committed to the layer');
    });

    test('rejection path: invalid rows are reported and a full commit is refused', async ({ page }) => {
        await startImport(page, REJECTED_FILE, 'rejected.geojson');

        await page.getByTestId('declared-crs').selectOption('EPSG:4326');
        await page.getByTestId('crs-continue').click();
        await page.getByTestId('validate-button').click();
        await expect(page.getByTestId('step-preview')).toBeVisible();

        // The rejection is visible per row, not summarised away. Row 3 is the
        // feature with no geometry; rows 1 and 2 are fine.
        await expect(page.getByTestId('invalid-count')).toHaveText('1');
        await expect(page.getByTestId('preview-row-3')).toHaveAttribute('data-valid', 'false');
        await expect(page.getByTestId('row-error-3').first()).not.toBeEmpty();
        await expect(page.getByTestId('preview-row-2')).toHaveAttribute('data-valid', 'true');

        // The error report is offered once there is something to report, and
        // downloading it actually yields CSV bytes. The endpoint answers with a
        // raw CSV rather than the usual envelope, so this also proves the shared
        // apiClient hands a blob back instead of trying to unwrap it.
        const download = page.waitForEvent('download');
        await page.getByTestId('download-errors').click();
        const csv = await download;
        expect(csv.suggestedFilename()).toContain('import-errors');
        const stream = await csv.createReadStream();
        const chunks: Buffer[] = [];
        for await (const chunk of stream) chunks.push(chunk as Buffer);
        const report = Buffer.concat(chunks).toString('utf8');
        expect(report).toContain('row_number');
        expect(report).toContain('VR-32');

        // A full commit is refused while a row is invalid, and nothing is written.
        await page.getByTestId('step-tab-commit').click();
        await expect(page.getByTestId('step-commit')).toBeVisible();
        await page.getByTestId('commit-button').click();
        await expect(page.getByTestId('step-commit').getByRole('alert')).toBeVisible();
        await expect(page.getByTestId('commit-outcome')).toHaveCount(0);

        // Opting into a partial commit writes the two good rows and discards the rest.
        await page.getByTestId('commit-partial').check();
        await page.getByTestId('commit-button').click();
        await expect(page.getByTestId('commit-outcome')).toContainText('2 rows committed to the layer');
    });
});
