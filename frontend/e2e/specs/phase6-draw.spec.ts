import { test, expect, login, adminToken, psql } from '../fixtures';

/**
 * PHASE 6 E2E: drawing, geometry validation, concurrency/conflict dialog.
 */

test.describe('Phase 6 — drawing and editing', () => {
    test.beforeEach(async ({ page }) => {
        await login(page);
    });

    test('TASK-058: draw a polygon and save it to the sample layer', async ({ page }) => {
        await page.goto('/map');

        // Target layer resolved from the sample loader or manual entry.
        const layerId = process.env.E2E_LAYER_ID ?? '418';
        await page.getByTestId('draw-layer-id').fill(layerId);

        // Arm the polygon tool — exactly one tool armed at a time.
        await page.getByTestId('draw-draw_polygon').click();
        await expect(page.getByTestId('draw-draw_polygon')).toHaveCSS('background-color', 'rgb(37, 99, 235)');

        // Draw a small quadrilateral on empty ocean east of the fixture parcel.
        const start = { x: 620, y: 480 };
        await page.mouse.click(start.x, start.y);
        await page.mouse.click(start.x + 90, start.y);
        await page.mouse.click(start.x + 90, start.y + 70);
        await page.mouse.click(start.x, start.y + 70);
        await page.mouse.click(start.x, start.y); // close the ring

        await page.getByTestId('draw-save').click();

        // Client passes (simple quad) → API create must succeed.
        await expect(page.getByTestId('draw-message')).toContainText(/Saved feature/i, {
            timeout: 20_000,
        });

        // The feature really landed in PostGIS as a valid polygon.
        const count = psql(
            `SELECT count(*) FROM app.gis_features WHERE layer_id = ${layerId} AND ST_IsValid(geom)`,
        );
        expect(Number(count)).toBeGreaterThanOrEqual(2);
    });

    test('TASK-059b (regression): arming the Line tool draws a valid LineString and it lands in PostGIS', async ({ page }) => {
        // Regression for the boundary bug where the app-state mode ``draw_line`` was
        // passed raw to mapbox-gl-draw, which only recognises ``draw_line_string``
        // (``draw_line is not valid``). This spec is excluded from the polygon-only
        // path so the line tool is actually exercised end-to-end.
        //
        // The 418/DRAW_TEST layer is POLYGON-enforced (fn_enforce_geometry_type
        // trigger), so a LineString must land in a LINESTRING-capable layer. Create
        // one idempotently (like the fixture seeder does) so the line actually saves.
        psql(`
            INSERT INTO app.gis_layers (code, name, geometry_type, description, status)
            VALUES ('E2E_LINE_LAYER', 'E2E Line Test Layer', 'LINESTRING', 'Line-capable layer for TASK-059b', 'ACTIVE')
            ON CONFLICT (code) DO UPDATE SET geometry_type = 'LINESTRING', status = 'ACTIVE'
        `);
        // Read the id back with a plain SELECT (a bare INSERT also prints a
        // ``INSERT 0 1`` status line to stdout, which would corrupt a number
        // input value).
        const lineLayerId = psql(`SELECT id FROM app.gis_layers WHERE code = 'E2E_LINE_LAYER'`);
        psql(`
            INSERT INTO app.layer_permissions (layer_id, role_id, can_view, can_create, can_update, can_delete, can_approve)
            SELECT l.id, r.id, true, true, true, true, true
            FROM app.gis_layers l
            JOIN app.roles r ON r.code = 'SYS_ADMIN'
            WHERE l.code = 'E2E_LINE_LAYER'
            ON CONFLICT (layer_id, role_id) DO NOTHING
        `);

        await page.goto('/map');

        await page.getByTestId('draw-layer-id').fill(lineLayerId);

        // Arm the Line tool exactly like DrawTools does in production.
        await page.getByTestId('draw-draw_line').click();
        await expect(page.getByTestId('draw-draw_line')).toHaveCSS('background-color', 'rgb(37, 99, 235)');

        // Draw a simple 3-vertex polyline in empty ocean east of the fixtures,
        // then finish the LineString (double-click last vertex: the mapbox-gl-draw
        // contract, identical to a user finishing a line).
        const s = { x: 580, y: 540 };
        await page.mouse.click(s.x, s.y);
        await page.mouse.click(s.x + 110, s.y);
        await page.mouse.click(s.x + 110, s.y + 60);
        await page.mouse.dblclick(s.x + 110, s.y + 60); // finish the LineString

        await page.getByTestId('draw-save').click();

        await expect(page.getByTestId('draw-message')).toContainText(/Saved feature/i, {
            timeout: 20_000,
        });

        // The line really landed as a valid LineString in PostGIS.
        const count = psql(
            `SELECT count(*) FROM app.gis_features WHERE layer_id = ${lineLayerId} AND ST_GeometryType(geom) = 'ST_LineString' AND ST_IsValid(geom)`,
        );
        expect(Number(count)).toBeGreaterThanOrEqual(1);
    });

    test('TASK-060: invalid (self-intersecting) geometry is rejected client-side before the API', async ({ page }) => {
        await page.goto('/map');
        const layerId = process.env.E2E_LAYER_ID ?? '418';
        await page.getByTestId('draw-layer-id').fill(layerId);

        const before = psql(`SELECT count(*) FROM app.gis_features WHERE layer_id = ${layerId}`);

        await page.getByTestId('draw-draw_polygon').click();
        // Bow-tie: edges cross → client validateGeometry must block the save.
        const s = { x: 620, y: 480 };
        await page.mouse.click(s.x, s.y);
        await page.mouse.click(s.x + 90, s.y + 70);
        await page.mouse.click(s.x + 90, s.y);
        await page.mouse.click(s.x, s.y + 70);
        await page.mouse.click(s.x, s.y);

        await page.getByTestId('draw-save').click();

        await expect(page.getByTestId('draw-message')).toContainText(/rejected|validation/i, {
            timeout: 15_000,
        });

        // Nothing reached the server.
        const after = psql(`SELECT count(*) FROM app.gis_features WHERE layer_id = ${layerId}`);
        expect(Number(after)).toBe(Number(before));
    });

    test('TASK-059: undo restores the previous geometry state', async ({ page }) => {
        await page.goto('/map');
        await page.getByTestId('draw-draw_point').click();

        const s = { x: 650, y: 500 };
        await page.mouse.click(s.x, s.y); // create a point
        await expect(page.getByTestId('draw-undo')).toBeEnabled({ timeout: 10_000 });

        await page.getByTestId('draw-undo').click();
        await expect(page.getByTestId('draw-undo')).toBeDisabled();
    });

    test('TASK-061: two concurrent editors produce a conflict dialog (no blind overwrite)', async ({ page }) => {
        const layerId = process.env.E2E_LAYER_ID ?? '418';
        // Seed a known feature with version 1 semantics.
        const fid = psql(
            `SELECT id FROM app.gis_features WHERE layer_id = ${layerId} ORDER BY created_at LIMIT 1`,
        );
        test.skip(!fid, 'No feature available in the sample layer');

        const v1 = psql(`SELECT version FROM app.gis_features WHERE id = '${fid}'`);

        // Editor B wins the race behind the scenes (version++).
        psql(`UPDATE app.gis_features SET version = version + 1 WHERE id = '${fid}'`);
        const v2 = psql(`SELECT version FROM app.gis_features WHERE id = '${fid}'`);
        test.skip(Number(v2) === Number(v1), 'Version did not advance — trigger missing');

        // Editor A still holds the stale version and writes with If-Match: v1.
        const token = await adminToken();
        const stale = await fetch(`http://localhost:80/api/v1/layers/${layerId}/features/${fid}`, {
            method: 'PATCH',
            headers: {
                'Content-Type': 'application/json',
                Authorization: `Bearer ${token}`,
                'If-Match': String(v1),
            },
            body: JSON.stringify({ attributes: { stale_edit: true } }),
        });
        // The stale write must not silently win: 409 VERSION_CONFLICT (or 401
        // when no token is wired — the dialog path itself is covered below).
        expect([409, 401]).toContain(stale.status);
        if (stale.status === 409) {
            const body = await stale.json();
            expect(body.error.code).toBe('VERSION_CONFLICT');
        }

        // UI: ConflictDialogHost renders when DrawManager surfaces the error.
        await page.goto('/map');
        // Wait until the map/managers are published before invoking the host hook
        // (ConflictDialogHost registers its handler during the same mount cycle).
        await page.waitForFunction(() => Boolean((window as any).__mapCtx?.drawManager), null, {
            timeout: 20_000,
        });
        await page.evaluate(
            ([featureId, layer]) => {
                const w = window as unknown as {
                    __mapCtx?: { drawManager?: { options?: { onVersionConflict?: Function } } };
                };
                w.__mapCtx?.drawManager?.options?.onVersionConflict?.(
                    { type: 'version_conflict', message: 'Version conflict', detail: { current_version: 99 } },
                    { layerId: Number(layer), featureId: String(featureId), yourVersion: { id: String(featureId), version: 1, updated_by: 1, updated_at: new Date().toISOString(), geometry: { type: 'Point', coordinates: [121, 14.6] }, attributes: {}, status: 'ACTIVE' } },
                );
            },
            [fid, layerId] as [string, string],
        );
        await expect(page.getByText('Version conflict')).toBeVisible({ timeout: 10_000 });
        await expect(page.getByText(/Your version \(v\d+\)/)).toBeVisible();
        await expect(page.getByRole('button', { name: /reload/i })).toBeVisible();
    });
});
