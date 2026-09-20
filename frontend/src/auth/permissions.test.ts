import { describe, expect, it } from 'vitest';
import {
  allPermissionCodes,
  hasLayerCap,
  hasPermission,
  layerCapabilities,
  permissions,
  scopeAccessFor,
  scopeAllows,
} from './permissions';
import type { MePayload } from './types';

const makeMe = (overrides: Partial<MePayload> = {}): MePayload => ({
  user: {
    id: 1,
    username: 'gis.dev',
    full_name: 'GIS Developer',
    must_change_password: false,
  },
  roles: ['app_rw'],
  permissions: [permissions.parcelView, permissions.parcelCreate],
  layer_capabilities: { parcel: { view: true, create: true, update: false, delete: false, approve: false } },
  scopes: [
    { type: 'ORG', code: 'LGU_MANILA', access: 'VIEW' },
    { type: 'ORG', code: 'LGU_QUEZON', access: 'EDIT' },
  ],
  scope_version: 7,
  ...overrides,
});

describe('permission catalogue', () => {
  it('holds every migrated code', () => {
    expect(permissions.parcelApprove).toBe('parcel.approve');
    expect(permissions.userManage).toBe('user.manage');
    expect(permissions.auditView).toBe('audit.view');
  });

  it('lists all codes once', () => {
    expect(new Set(allPermissionCodes).size).toBe(allPermissionCodes.length);
  });
});

describe('hasPermission', () => {
  it('is true when the code is granted', () => {
    expect(hasPermission(makeMe(), permissions.parcelView)).toBe(true);
  });
  it('is false when the code is not granted', () => {
    expect(hasPermission(makeMe(), permissions.parcelApprove)).toBe(false);
  });
  it('is false for an unauthenticated session', () => {
    expect(hasPermission(null, permissions.parcelView)).toBe(false);
  });
});

describe('layer capabilities', () => {
  it('reads a capability off the me payload', () => {
    const me = makeMe();
    expect(layerCapabilities(me, 'parcel')?.update).toBe(false);
    expect(hasLayerCap(me, 'parcel', 'view')).toBe(true);
    expect(hasLayerCap(me, 'parcel', 'approve')).toBe(false);
    expect(hasLayerCap(me, 'unknown_layer', 'view')).toBe(false);
  });
});

describe('data scopes', () => {
  it('returns the highest access for a scope', () => {
    const me = makeMe();
    expect(scopeAccessFor(me, 'ORG', 'LGU_QUEZON')).toBe('EDIT');
    expect(scopeAccessFor(me, 'ORG', 'LGU_MANILA')).toBe('VIEW');
    expect(scopeAccessFor(me, 'ORG', 'LGU_NONE')).toBeUndefined();
  });

  it('compares access levels', () => {
    const me = makeMe();
    // EDIT satisfies a VIEW request…
    expect(scopeAllows(me, 'ORG', 'LGU_QUEZON', 'VIEW')).toBe(true);
    // …but not the reverse.
    expect(scopeAllows(me, 'ORG', 'LGU_MANILA', 'EDIT')).toBe(false);
    expect(scopeAllows(me, 'ORG', 'LGU_NONE', 'VIEW')).toBe(false);
  });
});