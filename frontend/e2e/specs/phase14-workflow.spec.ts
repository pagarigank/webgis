import { test, expect, psql, API_BASE } from '../fixtures';
import type { Page } from '@playwright/test';

/**
 * PHASE 13/14 E2E — TASK-103: workflow UI, reviewer inbox, notifications.
 *
 * Two-role approval flow (the TASK-103 test gate):
 *   - ENCODER role (parcel.submit, no review rights) opens the parcel editor
 *     and submits through the workflow action bar; only SUBMIT renders (the
 *     review actions are ABSENT, FR-103).
 *   - REVIEWER role (parcel.review/verify/approve/publish, no submit) picks
 *     the parcel up from the reviewer inbox and walks START_REVIEW → VERIFY →
 *     APPROVE, where the comment prompt gates the request (FR-137) and RETURN
 *     requires a reason before anything is sent.
 *   - The parcel creator receives in-app notifications (FR-140), visible in
 *     the header bell.
 *
 * Parcel setup (valid computation so the validation_passed guard passes) is
 * done through the real HTTP API with the encoder token, mirroring the
 * backend WorkflowFlowTest fixture.
 */

const HASH = '$argon2id$v=19$m=65536,t=4,p=1$VTVYYWM1WXlYNWdKUXRKaA$AAnD6mkoKuE6hAKEqvmPP4q8/2dQgoTDYiYmIFtMgLs'; // password: 'hash'
const ENCODER = 'e2e_encoder';
const REVIEWER = 'e2e_reviewer';
const ROLE_ENC = 'E2E_ENCODER_ROLE';
const ROLE_REV = 'E2E_REVIEWER_ROLE';
const PARCEL_A = 'E2E_WF_A';
const PARCEL_B = 'E2E_WF_B';

let encoderId = 0;
let tokenEnc = '';
let tokenRev = '';
let parcelA = '';
let parcelB = '';

async function api(method: string, path: string, body: unknown, token: string): Promise<any> {
    const res = await fetch(`${API_BASE}/api/v1${path}`, {
        method,
        headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${token}` },
        body: JSON.stringify(body),
    });
    const json = (await res.json()) as { data?: unknown };
    if (!res.ok) throw new Error(`${method} ${path} -> ${res.status}: ${JSON.stringify(json)}`);
    return json.data;
}

async function loginToken(username: string): Promise<string> {
    const res = await fetch(`${API_BASE}/api/v1/auth/login`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ username, password: 'hash' }),
    });
    const json = (await res.json()) as { data?: { access_token?: string } };
    if (!json.data?.access_token) throw new Error(`login failed for ${username} (${res.status})`);
    return json.data.access_token;
}

async function loginAs(page: Page, username: string): Promise<void> {
    await page.goto('/login');
    await page.getByLabel('Username').fill(username);
    await page.getByLabel('Password').fill('hash');
    await page.getByRole('button', { name: /sign in/i }).click();
    await expect(page.locator('.app-header')).toBeVisible({ timeout: 20_000 });
    await expect(page).not.toHaveURL(/\/login/);
}

/** Build a fully valid, computed, accepted parcel through the real API. */
async function createReadyParcel(code: string, token: string): Promise<string> {
    const parcel = (await api('POST', '/parcels', { parcel_code: code, provenance: 'MANUAL_DRAWING', source_area_sqm: 5000 }, token)) as any;
    const pid = parcel.id as string;
    psql(`UPDATE app.parcels SET created_by = ${encoderId} WHERE id = '${pid}';`);

    const td = (await api('POST', `/parcels/${pid}/technical-descriptions`, {
        bearing_reference: 'GRID', claimed_area_sqm: 5000, tie_line_bearing: 'DUE NORTH', tie_line_distance: 100,
    }, token)) as any;

    const cpId = psql(`INSERT INTO app.survey_control_points (point_name, status, geom) VALUES ('E2E_CP_${code}', 'VERIFIED', ST_SetSRID(ST_MakePoint(121.0, 14.5), 4326)) RETURNING id;`);
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
    return pid;
}

test.beforeAll(async () => {
    // Roles + users (permission rows exist from migration 0015's catalogue).
    psql(`INSERT INTO app.roles (code, name, is_system) VALUES ('${ROLE_ENC}', 'E2E Encoder', false), ('${ROLE_REV}', 'E2E Reviewer', false) ON CONFLICT (code) DO NOTHING;`);
    psql(`INSERT INTO app.role_permissions (role_id, permission_id) SELECT r.id, p.id FROM app.roles r JOIN app.permissions p ON p.code IN ('parcel.view','parcel.create','parcel.update','parcel.submit') WHERE r.code = '${ROLE_ENC}' ON CONFLICT DO NOTHING;`);
    psql(`INSERT INTO app.role_permissions (role_id, permission_id) SELECT r.id, p.id FROM app.roles r JOIN app.permissions p ON p.code IN ('parcel.view','parcel.review','parcel.verify','parcel.approve','parcel.publish') WHERE r.code = '${ROLE_REV}' ON CONFLICT DO NOTHING;`);
    psql(`DELETE FROM app.users WHERE username IN ('${ENCODER}', '${REVIEWER}');`);
    psql(`INSERT INTO app.users (username, email, password_hash, full_name, org_id, status, version, must_change_password) VALUES ('${ENCODER}', '${ENCODER}@sample.local', '${HASH}', 'E2E Encoder', 999, 'ACTIVE', 1, false), ('${REVIEWER}', '${REVIEWER}@sample.local', '${HASH}', 'E2E Reviewer', 999, 'ACTIVE', 1, false);`);
    psql(`INSERT INTO app.user_roles (user_id, role_id) SELECT u.id, r.id FROM app.users u, app.roles r WHERE (u.username = '${ENCODER}' AND r.code = '${ROLE_ENC}') OR (u.username = '${REVIEWER}' AND r.code = '${ROLE_REV}') ON CONFLICT DO NOTHING;`);
    encoderId = Number(psql(`SELECT id FROM app.users WHERE username = '${ENCODER}';`));

    tokenEnc = await loginToken(ENCODER);
    tokenRev = await loginToken(REVIEWER);

    parcelA = await createReadyParcel(PARCEL_A, tokenEnc);
    parcelB = await createReadyParcel(PARCEL_B, tokenEnc);
});

test.afterAll(async () => {
    const ids = psql(`SELECT string_agg(quote_literal(id::text), ', ') FROM app.parcels WHERE parcel_code IN ('${PARCEL_A}', '${PARCEL_B}');`);
    if (ids) {
        psql(`DELETE FROM app.notifications WHERE entity_type = 'PARCEL' AND entity_id IN (${ids}); DELETE FROM app.approval_actions WHERE instance_id IN (SELECT id FROM app.workflow_instances WHERE entity_type = 'PARCEL' AND entity_id IN (${ids})); DELETE FROM app.workflow_instances WHERE entity_type = 'PARCEL' AND entity_id IN (${ids}); DELETE FROM app.parcels WHERE id IN (${ids});`);
    }
    psql(`DELETE FROM app.survey_control_points WHERE point_name LIKE 'E2E_CP_E2E_WF_%';`);
    psql(`DELETE FROM app.users WHERE username IN ('${ENCODER}', '${REVIEWER}');`);
    psql(`DELETE FROM app.roles WHERE code IN ('${ROLE_ENC}', '${ROLE_REV}');`);
});

test.describe('TASK-103 two-role approval flow', () => {
    test('encoder sees only permitted actions and submits from the editor', async ({ page }) => {
        await loginAs(page, ENCODER);
        await page.goto(`/parcels/${parcelA}`);
        await expect(page.getByTestId('parcel-status-bar')).toBeVisible({ timeout: 15_000 });
        await expect(page.getByTestId('parcel-status-text')).toHaveText('DRAFT');

        // FR-103: only server-allowed transitions render. Encoder holds
        // parcel.submit but NOT parcel.review/approve/archive.
        const bar = page.getByTestId('workflow-action-bar');
        await expect(bar.getByTestId('workflow-action-SUBMIT')).toBeVisible({ timeout: 15_000 });
        await expect(bar.getByTestId('workflow-action-START_REVIEW')).toHaveCount(0);
        await expect(bar.getByTestId('workflow-action-APPROVE')).toHaveCount(0);
        await expect(bar.getByTestId('workflow-action-ARCHIVE')).toHaveCount(0);

        await bar.getByTestId('workflow-action-SUBMIT').click();
        await page.getByTestId('workflow-action-confirm').click();

        await expect(page.getByTestId('parcel-status-text')).toHaveText('SUBMITTED', { timeout: 15_000 });
        // From SUBMITTED the encoder has no permitted transitions at all.
        await expect(page.getByTestId('workflow-action-bar')).toContainText('No workflow actions available', { timeout: 15_000 });
    });

    test('reviewer picks up from the inbox and approves; comments gate the request', async ({ page }) => {
        await loginAs(page, REVIEWER);

        // Reviewer inbox lists the submitted parcel.
        await page.goto('/parcels/inbox');
        const row = page.getByTestId(`reviewer-inbox-row-${PARCEL_A}`);
        await expect(row).toBeVisible({ timeout: 15_000 });
        await expect(row).toContainText('SUBMITTED');
        await row.getByRole('link', { name: 'Review' }).click();
        await expect(page.getByTestId('parcel-status-bar')).toBeVisible({ timeout: 15_000 });

        const bar = page.getByTestId('workflow-action-bar');
        // Reviewer never sees SUBMIT (no parcel.submit) — absent, not disabled.
        await expect(bar.getByTestId('workflow-action-SUBMIT')).toHaveCount(0);
        await expect(bar.getByTestId('workflow-action-START_REVIEW')).toBeVisible({ timeout: 15_000 });

        await bar.getByTestId('workflow-action-START_REVIEW').click();
        await page.getByTestId('workflow-action-confirm').click();
        await expect(page.getByTestId('parcel-status-text')).toHaveText('UNDER_REVIEW', { timeout: 15_000 });

        await bar.getByTestId('workflow-action-VERIFY').click();
        await page.getByTestId('workflow-action-confirm').click();
        await expect(page.getByTestId('parcel-status-text')).toHaveText('VERIFIED', { timeout: 15_000 });

        // APPROVE requires a comment (FR-137): confirm stays disabled until
        // the comment is non-blank — no request leaves before that.
        await bar.getByTestId('workflow-action-APPROVE').click();
        const confirm = page.getByTestId('workflow-action-confirm');
        await expect(confirm).toBeDisabled();
        await page.getByTestId('workflow-comment-input').fill('Survey checks out (e2e)');
        await expect(confirm).toBeEnabled();
        await confirm.click();
        await expect(page.getByTestId('parcel-status-text')).toHaveText('APPROVED', { timeout: 15_000 });
    });

    test('RETURN demands a reason before sending; creator gets bell notifications', async ({ page }) => {
        await loginAs(page, REVIEWER);
        await page.goto(`/parcels/${parcelB}`);
        await expect(page.getByTestId('parcel-status-text')).toHaveText('DRAFT', { timeout: 15_000 });

        // Advance to UNDER_REVIEW so RETURN is legal.
        const bar = page.getByTestId('workflow-action-bar');
        await bar.getByTestId('workflow-action-START_REVIEW').click();
        await page.getByTestId('workflow-action-confirm').click();
        await expect(page.getByTestId('parcel-status-text')).toHaveText('UNDER_REVIEW', { timeout: 15_000 });

        // RETURN requires a reason (FR-137) — disabled until filled.
        await bar.getByTestId('workflow-action-RETURN').click();
        const confirm = page.getByTestId('workflow-action-confirm');
        await expect(confirm).toBeDisabled();
        await page.getByTestId('workflow-reason-input').fill('Closure error exceeds tolerance; recompute.');
        await expect(confirm).toBeEnabled();
        await confirm.click();
        await expect(page.getByTestId('parcel-status-text')).toHaveText('RETURNED', { timeout: 15_000 });

        // FR-140: the creator sees the workflow notifications in the bell.
        await loginAs(page, ENCODER);
        await expect(page.getByTestId('notification-bell-button')).toBeVisible({ timeout: 15_000 });
        await expect(page.getByTestId('notification-badge')).toBeVisible({ timeout: 20_000 });
        await page.getByTestId('notification-bell-button').click();
        await expect(page.getByTestId('notification-menu')).toBeVisible();
        await expect(page.getByTestId('notification-item').first()).toContainText(PARCEL_B, { timeout: 10_000 });
    });
});
