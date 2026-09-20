import { useAuth } from './useAuth';
import type { LayerCapabilities, ScopeAccess } from './types';
import {
  hasLayerCap,
  hasPermission,
  layerCapabilities,
  scopeAccessFor,
  scopeAllows,
} from './permissions';
import type { PermissionCode } from './permissions';

/** Convenience hooks over the authenticated session (frontend.md §11.2). */

export function usePermission(code: PermissionCode): boolean {
  const { me } = useAuth();
  return hasPermission(me, code);
}

export function useLayerCap(code: string): LayerCapabilities | undefined {
  const { me } = useAuth();
  return layerCapabilities(me, code);
}

export function useScopes(type: string, code: string): ScopeAccess | undefined {
  const { me } = useAuth();
  return scopeAccessFor(me, type, code);
}

export function useScopeAllows(type: string, code: string, access: ScopeAccess): boolean {
  const { me } = useAuth();
  return scopeAllows(me, type, code, access);
}

/** True when the user can reach `code` (permission) OR `cap` on `layerCode`. */
export function useCanUse(layerCode: string, cap: keyof LayerCapabilities, permission?: PermissionCode): boolean {
  const { me } = useAuth();
  if (permission !== undefined && hasPermission(me, permission)) {
    return true;
  }
  return hasLayerCap(me, layerCode, cap);
}