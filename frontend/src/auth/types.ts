/** Auth-related domain types mirroring api.md §2 / GET /me. */

export interface AuthUser {
  id: number;
  username: string;
  full_name: string;
  email?: string | null;
  org_id?: number | null;
  must_change_password: boolean;
}

export interface LayerCapabilities {
  view: boolean;
  create: boolean;
  update: boolean;
  delete: boolean;
  approve: boolean;
}

export type ScopeAccess = 'VIEW' | 'EDIT' | 'APPROVE';

export interface DataScope {
  type: string;
  code: string;
  name?: string;
  access: ScopeAccess;
}

export interface MePayload {
  user: AuthUser;
  roles: string[];
  permissions: string[];
  layer_capabilities: Record<string, LayerCapabilities>;
  scopes: DataScope[];
  scope_version: number;
}

export interface LoginResponse {
  access_token: string;
  expires_in: number;
  token_type: string;
  user: AuthUser;
}

export type AuthStatus = 'loading' | 'unauthenticated' | 'authenticated';

export type LoginErrorCode = 'AUTH_INVALID' | 'MFA_REQUIRED' | 'VALIDATION_FAILED' | 'RATE_LIMITED';