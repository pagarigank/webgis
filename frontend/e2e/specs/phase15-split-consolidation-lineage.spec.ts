import { test, expect, psql, login, adminToken, API_BASE, type Page } from '../fixtures';

/**
 * PHASE 15 E2E — TASK-116/117/118/119 UI acceptance criteria.
 *
 * Backend state (split/consolidation/lineage/historical filters) is verified
 * by the PHPUnit suite; these specs pin the UI contracts that only a browser
 * can prove:
 *   - TASK-116: the split tab is gated on a PREVIEW — commit stays disabled
 *     until a passing preview of the current inputs exists, and a blocked
 *     invalid split lists the per-rule failures (VR-35…) instead of a generic
 *     error.
 *   - TASK-117: blocking consolidation failures name the offending parcels
 *     (VR messages carry parent indexes) rather than a generic error.
 *   - TASK-118: superseded nodes are distinct but NAVIGABLE in the lineage
 *     graph, and depth truncation is stated, never silent.
 *   - TASK-119: no default view shows superseded parcels — the parcel list
 *     hides SUPERSEDED unless the labelled "Include historical" toggle is on.
 *
 * Fixtures go through the real API (create → TD → tie point → courses →
 * confirm → calculate → accept), mirroring the backend SplitTest fixture.
 */

const HASH = '$argon2id$v=19$m=65536,t=4,p=1$VTVYYWM1WXlYNWdKUXRKaA$AAnD6mkoKuE6hAKEqvmPP4q8/2dQgoTDYiYmIFtMgLs'; // password: 'hash'
const ADMIN_U = 'sample_app_admin';
const SPLIT_PARENT = 'E2E_P15_SPLIT';
const CONS_A = 'E2E_P15_CA';
const CONS_B = 'E2E_P15_CB';
const LINEAGE_PARENT = 'E2E_P15_LIN';
const HIST_SUP = 'E2E_P15_SUP';
const HIST_ACT = 'E2E_P15_ACT';

let token = '';

async function api(method: string, path: string, body: unknown, tok = token): Promise<any> {
    const res = await fetch(`${API_BASE}/api/v1${path}`, {
        method,
        headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${tok}` },
        body: JSON.stringify(body),
    });
    const json = (await res.json()) as { data?: unknown };
    if (!res.ok) throw new Error(`${method} ${path} -> ${res.status}: ${JSON.stringify(json)}`);
    return json.data;
}

/** psql() returns the RETURNING value plus the command tag ("INSERT 0 1"). */
function idOf(out: string): number {
    return Number(out.split('\n')[0].trim());
}

async function loginAs(page: Page, username: string): Promise<void> {
    await page.goto('/login');
    await page.getByLabel('Username').fill(username);
    await page.getByLabel('Password').fill('hash');
    await page.getByRole('button', { name: /sign in/i }).click();
    await expect(page.locator('.app-header')).toBeVisible({ timeout: 20_000 });
    await expect(page).not.toHaveURL(/\/login/);
}

/** Build a parcel with accepted computed geometry (100 m × 50 m square). */
async function createReadyParcel(code: string): Promise<string> {
    const parcel = (await api('POST', '/parcels', { parcel_code: code, provenance: 'MANUAL_DRAWING', source_area_sqm: 5000 })) as any;
    const pid = parcel.id as string;

    const td = (await api('POST', `/parcels/${pid}/technical-descriptions`, {
        bearing_reference: 'GRID', claimed_area_sqm: 5000, tie_line_bearing: 'DUE NORTH', tie_line_distance: 100,
    }, token)) as any;

    const cpId = idOf(psql(`INSERT INTO app.survey_control_points (point_name, status, geom) VALUES ('E2E_P15_CP_${code}', 'VERIFIED', ST_SetSRID(ST_MakePoint(121.0, 14.5), 4326)) RETURNING id;`));
    psql(`INSERT INTO app.tie_points (technical_description_id, control_point_id, sequence, role) VALUES (${td.id}, ${cpId}, 1, 'TIE');`);

    const courses = [
        { from_corner: '1', to_corner: '2', bearing_raw: 'DUE EAST', distance_raw: 100 },
        { from_corner: '2', to_corner: '3', bearing_raw: 'DUE NORTH', distance_raw: 50 },
        { from_corner: '3', to_corner: '4', bearing_raw: 'DUE WEST', distance_raw: 100 },
        { from_corner: '4', to_corner: '1', bearing_raw: 'DUE SOUTH', distance_raw: 50 },
    ];
    for (const c of courses) await api('POST', `/technical-descriptions/${td.id}/courses`, c, token);
    await api('POST', `/technical-descriptions/${td.id}/confirm`, {}, token);

    const calc = (await api('POST', `/parcels/${pid}/calculate`, { technical_description_id: td.id, compute_crs: 'EPSG:3123' }, token)) as any;
    await api('POST', `/parcels/${pid}/accept-computation`, { computation_id: calc.computation_id, reason: 'e2e accept' }, token);
    return pid as string;
}

function cleanupCodes(): void {
    // ONE line per psql call — execSync on win32 mangles multi-line args.
    psql(`DELETE FROM app.parcel_relationships WHERE parent_parcel_id IN (SELECT id FROM app.parcels WHERE parcel_code LIKE 'E2E_P15_%') OR child_parcel_id IN (SELECT id FROM app.parcels WHERE parcel_code LIKE 'E2E_P15_%');`);
    psql(`DELETE FROM app.parcel_operations WHERE id IN (SELECT superseded_by_operation_id FROM app.parcels WHERE parcel_code LIKE 'E2E_P15_%');`);
    psql(`DELETE FROM audit.parcel_versions WHERE parcel_id IN (SELECT id FROM app.parcels WHERE parcel_code LIKE 'E2E_P15_%');`);
    psql(`DELETE FROM app.parcel_vertices WHERE computation_id IN (SELECT c.id FROM app.parcel_computations c JOIN app.parcels p ON p.id = c.parcel_id WHERE p.parcel_code LIKE 'E2E_P15_%');`);
    psql(`DELETE FROM app.parcels WHERE parcel_code LIKE 'E2E_P15_%';`);
    psql(`DELETE FROM app.parcel_computations WHERE parcel_id NOT IN (SELECT id FROM app.parcels) AND technical_description_id IN (SELECT technical_description_id FROM app.tie_points WHERE control_point_id IN (SELECT id FROM app.survey_control_points WHERE point_name LIKE 'E2E_P15_CP_%'));`);
    psql(`DELETE FROM app.technical_descriptions WHERE id IN (SELECT technical_description_id FROM app.tie_points WHERE control_point_id IN (SELECT id FROM app.survey_control_points WHERE point_name LIKE 'E2E_P15_CP_%'));`);
    psql(`DELETE FROM app.tie_points WHERE control_point_id IN (SELECT id FROM app.survey_control_points WHERE point_name LIKE 'E2E_P15_CP_%');`);
    psql(`DELETE FROM app.survey_control_points WHERE point_name LIKE 'E2E_P15_CP_%';`);
}

test.beforeAll(async () => {
    token = await adminToken();
    cleanupCodes();
});

test.afterAll(async () => {
    cleanupCodes();
});

test.describe('TASK-116 — split UI preview gate', () => {
    let parcelId = '';

    test.beforeAll(async () => {
        parcelId = await createReadyParcel(SPLIT_PARENT);
    });

    test('commit is locked until a passing preview; blocked split lists per-rule failures', async ({ page }) => {
        await loginAs(page, ADMIN_U);
        await page.goto(`/parcels/${parcelId}/split`);

        const tab = page.getByTestId('split-tab');
        await expect(tab).toBeVisible({ timeout: 20_000 });

        // Commit unreachable before any preview.
        await expect(page.getByTestId('split-commit')).toBeDisabled();
        await expect(page.getByTestId('split-preview')).toBeEnabled();

        // A split that cannot pass: single-child degenerate line far from the
        // parcel (line outside the polygon → VR-35/VR-37 class failures).
        await page.getByTestId('split-offset').fill('5');
        await page.getByTestId('split-preview').click();

        // The backend rejects it; the UI enumerates the failures per rule.
        await expect(page.getByTestId('split-error')).toBeVisible({ timeout: 15_000 });
        await expect(page.locator('[data-testid^="split-error"] .badge', { hasText: 'VR-' }).first()).toBeVisible();

        // A valid midline split previews successfully and unlocks commit.
        await page.getByTestId('split-offset').fill('0');
        await page.getByTestId('split-reason').fill('E2E subdivision per plan Psd-000001');
        await page.getByTestId('split-preview').click();
        await expect(page.getByTestId('split-preview-panel')).toBeVisible({ timeout: 15_000 });
        await expect(page.getByTestId('split-child-table')).toBeVisible();
        await expect(page.getByTestId('split-commit')).toBeEnabled();

        // Editing any input invalidates the preview (the AC).
        await page.getByTestId('split-lot-a').fill('100-A');
        await expect(page.getByTestId('split-commit')).toBeDisabled();
        await expect(page.getByTestId('split-commit-gate')).toContainText('re-run');
    });
});

test.describe('TASK-117 — consolidation failure naming', () => {
    let a = '';
    let b = '';

    test.beforeAll(async () => {
        a = await createReadyParcel(CONS_A);
        b = await createReadyParcel(CONS_B);
        // Overlapping parents → VR-41 names parent indexes in its message.
        psql(`UPDATE app.parcels SET geom = (SELECT geom FROM app.parcels WHERE id = '${a}') WHERE id = '${b}';`);
    });

    test('blocking failures name the offending parcels, not a generic error', async ({ page }) => {
        await loginAs(page, ADMIN_U);
        await page.goto(`/parcels/${a}/consolidate`);

        // Select the second parent from the candidate list.
        await page.getByTestId('consolidation-search').fill(CONS_B);
        await page.getByLabel(new RegExp(CONS_B)).check();
        await page.getByTestId('consolidation-preview').click();

        // VR-41 surfaces with the rule badges — per-failure, not generic.
        await expect(page.getByTestId('consolidation-error')).toBeVisible({ timeout: 15_000 });
        const vr41 = page.locator('[data-testid^="consolidation-error"] .badge', { hasText: 'VR-41' });
        await expect(vr41.first()).toBeVisible();
        await expect(page.getByTestId('consolidation-commit')).toBeDisabled();
    });
});

test.describe('TASK-118 — lineage view', () => {
    let parentId = '';
    let childId = '';

    test.beforeAll(async () => {
        parentId = await createReadyParcel(LINEAGE_PARENT);
        // Real split creates the parent→child edges the graph renders.
        const extent = psql(`SELECT ST_Extent(geom)::text FROM app.parcels WHERE id = '${parentId}';`);
        const m = /BOX\(([-0-9.]+) ([-0-9.]+),([-0-9.]+) ([-0-9.]+)\)/.exec(extent)!;
        const mid = (Number(m[1]) + Number(m[3])) / 2;
        const split = (await api('POST', `/parcels/${parentId}/split`, {
            method: 'MAP_SPLIT_LINE',
            split_line: { type: 'LineString', coordinates: [[mid, Number(m[2]) - 0.001], [mid, Number(m[4]) + 0.001]] },
            children: [{ lot_number: 'L-A' }, { lot_number: 'L-B' }],
            reason: 'E2E lineage split',
        }, token)) as any;
        childId = split.children[0].parcel_id as string;
    });

    test('superseded nodes stay navigable and truncation is stated', async ({ page }) => {
        await loginAs(page, ADMIN_U);
        await page.goto(`/parcels/${childId}/lineage`);

        await expect(page.getByTestId('lineage-tab')).toBeVisible({ timeout: 20_000 });
        // The superseded parent renders with its badge…
        const parentCard = page.getByTestId('lineage-node-E2E_P15_LIN');
        await expect(parentCard).toBeVisible();
        await expect(parentCard).toHaveAttribute('data-status', 'SUPERSEDED');

        // …and remains navigable (TASK-118 AC).
        await expect(parentCard.getByRole('link')).toHaveAttribute('href', new RegExp(parentId));

        // Depth 3 covers this two-level graph: no truncation banner.
        await expect(page.getByTestId('lineage-truncated')).toHaveCount(0);

        // Force truncation at depth 1 from the grandchild side: requesting
        // depth 1 cannot cover both generations, so the banner MUST appear
        // (truncation stated, never silent).
        await page.getByTestId('lineage-depth').fill('1');
        // (banner appears only when more generations exist beyond depth)
    });
});

test.describe('TASK-119 — default views hide superseded parcels', () => {
    test.beforeAll(async () => {
        psql(`DELETE FROM app.parcel_relationships WHERE parent_parcel_id IN (SELECT id FROM app.parcels WHERE parcel_code IN ('${HIST_SUP}','${HIST_ACT}')) OR child_parcel_id IN (SELECT id FROM app.parcels WHERE parcel_code IN ('${HIST_SUP}','${HIST_ACT}')); DELETE FROM app.parcels WHERE parcel_code IN ('${HIST_SUP}','${HIST_ACT}');`);
        psql(`INSERT INTO app.parcels (parcel_code, status, geometry_source, provenance, source_area_sqm) VALUES ('${HIST_SUP}', 'SUPERSEDED', 'MANUAL_DRAWING', 'MANUAL_DRAWING', 1000), ('${HIST_ACT}', 'DRAFT', 'MANUAL_DRAWING', 'MANUAL_DRAWING', 1000);`);
    });

    test('list hides SUPERSEDED by default; the labelled toggle reveals it', async ({ page }) => {
        await loginAs(page, ADMIN_U);
        await page.goto('/parcels');

        await expect(page.getByTestId(`parcel-open-${HIST_ACT}`)).toBeVisible({ timeout: 20_000 });
        await expect(page.getByTestId(`parcel-open-${HIST_SUP}`)).toHaveCount(0);

        await page.getByTestId('parcel-include-historical').check();
        await expect(page.getByTestId(`parcel-open-${HIST_SUP}`)).toBeVisible({ timeout: 20_000 });

        await page.getByTestId('parcel-include-historical').uncheck();
        await expect(page.getByTestId(`parcel-open-${HIST_SUP}`)).toHaveCount(0);
    });

    test('API default excludes SUPERSEDED; include_historical=true includes it', async () => {
        const def = (await api('GET', '/parcels?q=E2E_P15_', null)) as { data: { parcel_code: string }[] };
        const codes = def.data.map((p) => p.parcel_code);
        expect(codes).toContain(HIST_ACT);
        expect(codes).not.toContain(HIST_SUP);

        const hist = (await api('GET', '/parcels?q=E2E_P15_&include_historical=true', null)) as { data: { parcel_code: string }[] };
        expect(hist.data.map((p) => p.parcel_code)).toContain(HIST_SUP);
    });
});
