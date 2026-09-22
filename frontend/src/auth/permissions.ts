import type { DataScope, LayerCapabilities, MePayload, ScopeAccess } from './types';

/**
 * The immutable permission catalogue (specification.md §3.3, seeded by
 * migration 20260920000015). Strongly typed so guards catch typos at
 * compile time — the same closed set the server enforces (AuthorizeMiddleware).
 */
export const permissions = {
  gisLayerView: 'gis.layer.view',
  gisLayerCreate: 'gis.layer.create',
  gisLayerUpdate: 'gis.layer.update',
  gisLayerDelete: 'gis.layer.delete',
  gisFieldManage: 'gis.field.manage',
  gisStyleManage: 'gis.style.manage',
  gisFeatureView: 'gis.feature.view',
  gisFeatureCreate: 'gis.feature.create',
  gisFeatureUpdate: 'gis.feature.update',
  gisFeatureDelete: 'gis.feature.delete',
  basemapView: 'basemap.view',
  basemapManage: 'basemap.manage',

  parcelView: 'parcel.view',
  parcelCreate: 'parcel.create',
  parcelUpdate: 'parcel.update',
  parcelDelete: 'parcel.delete',
  parcelSubmit: 'parcel.submit',
  parcelReview: 'parcel.review',
  parcelVerify: 'parcel.verify',
  parcelApprove: 'parcel.approve',
  parcelPublish: 'parcel.publish',
  parcelArchive: 'parcel.archive',
  parcelSplit: 'parcel.split',
  parcelConsolidate: 'parcel.consolidate',
  parcelLineageView: 'parcel.lineage.view',
  parcelVersionRestore: 'parcel.version.restore',

  surveyView: 'survey.view',
  surveyCreate: 'survey.create',
  surveyUpdate: 'survey.update',
  surveyApprove: 'survey.approve',
  techdescView: 'techdesc.view',
  techdescCreate: 'techdesc.create',
  techdescUpdate: 'techdesc.update',
  techdescParse: 'techdesc.parse',
  techdescConfirm: 'techdesc.confirm',
  controlPointView: 'control_point.view',
  controlPointCreate: 'control_point.create',
  controlPointUpdate: 'control_point.update',
  controlPointVerify: 'control_point.verify',

  titleView: 'title.view',
  titleUpdate: 'title.update',
  titleViewOwner: 'title.view_owner',

  partyView: 'party.view',
  partyManage: 'party.manage',

  documentView: 'document.view',
  documentUpload: 'document.upload',
  documentDownloadRestricted: 'document.download_restricted',
  documentDelete: 'document.delete',

  rptView: 'rpt.view',
  rptUpdate: 'rpt.update',

  importExecute: 'import.execute',
  exportExecute: 'export.execute',
  cadImport: 'cad.import',

  userManage: 'user.manage',
  roleManage: 'role.manage',
  scopeManage: 'scope.manage',

  auditView: 'audit.view',
  auditExport: 'audit.export',

  reportView: 'report.view',
  reportExport: 'report.export',

  systemConfig: 'system.config',
} as const;

export type PermissionCode = (typeof permissions)[keyof typeof permissions];

/** All catalogue codes, for building static role/permission pickers. */
export const allPermissionCodes: readonly PermissionCode[] =
  Object.values(permissions) as readonly PermissionCode[];

/* ------------------------------------------------------------------ */
/* Pure predicates — exported for unit testing without a DOM/router.   */
/* ------------------------------------------------------------------ */

export function hasPermission(me: MePayload | null, code: PermissionCode): boolean {
  return (me?.permissions ?? []).includes(code);
}

export function layerCapabilities(me: MePayload | null, layerCode: string): LayerCapabilities | undefined {
  return me?.layer_capabilities[layerCode];
}

export function hasLayerCap(me: MePayload | null, layerCode: string, cap: keyof LayerCapabilities): boolean {
  return me?.layer_capabilities[layerCode]?.[cap] ?? false;
}

const ACCESS_RANK: Record<ScopeAccess, number> = { VIEW: 1, EDIT: 2, APPROVE: 3 };

/**
 * Highest access granted for the given scope type+code, or undefined when the
 * user has no scope there. Scope codes are opaque strings resolved server-side
 * (DataScopeResolver).
 */
export function scopeAccessFor(me: MePayload | null, type: string, code: string): ScopeAccess | undefined {
  let best: ScopeAccess | undefined;
  for (const scope of me?.scopes ?? ([] as DataScope[])) {
    if (scope.type === type && scope.code === code) {
      if (best === undefined || ACCESS_RANK[scope.access] > ACCESS_RANK[best]) {
        best = scope.access;
      }
    }
  }
  return best;
}

export function scopeAllows(me: MePayload | null, type: string, code: string, access: ScopeAccess): boolean {
  const best = scopeAccessFor(me, type, code);
  return best !== undefined && ACCESS_RANK[best] >= ACCESS_RANK[access];
}