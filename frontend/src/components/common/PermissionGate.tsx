import React from 'react';
import type { ReactElement, ReactNode } from 'react';
import { useAuth } from '../../auth/useAuth';
import { hasPermission } from '../../auth/permissions';
import type { PermissionCode } from '../../auth/permissions';

export interface PermissionGateProps {
  permission: PermissionCode;
  mode?: 'hide' | 'disable';
  fallback?: ReactNode;
  children: ReactElement; // Must be a single element to support cloning for 'disable' mode
}

const DESTRUCTIVE_SUFFIXES = ['.delete', '.archive', '.remove', '.manage'];

/**
 * Renders or disables its child based on the user's permissions.
 * Destructive actions (e.g., delete) will always hide the element, even if 'disable' is requested.
 */
export function PermissionGate({
  permission,
  mode = 'hide',
  fallback = null,
  children,
}: PermissionGateProps) {
  const { me } = useAuth();
  const granted = hasPermission(me, permission);

  if (granted) {
    return <>{children}</>;
  }

  // Force 'hide' for destructive actions
  let effectiveMode = mode;
  if (mode === 'disable') {
    const isDestructive = DESTRUCTIVE_SUFFIXES.some(suffix => permission.endsWith(suffix));
    if (isDestructive) {
      effectiveMode = 'hide';
    }
  }

  if (effectiveMode === 'hide') {
    return <>{fallback}</>;
  }

  // mode === 'disable'
  return React.cloneElement(children, {
    
    title: 'You do not have permission to perform this action.',
    'aria-disabled': true,
  });
}
