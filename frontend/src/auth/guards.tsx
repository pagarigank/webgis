import { Navigate, useLocation } from 'react-router-dom';
import type { ReactNode } from 'react';
import { useAuth } from './useAuth';
import { hasPermission } from './permissions';
import type { PermissionCode } from './permissions';

/**
 * Route guards (frontend.md §11.3). These are UX conveniences — the server
 * remains the control point for every operation.
 */

export function RequireAuth({ children }: { children: ReactNode }) {
  const { status, user } = useAuth();
  const location = useLocation();

  console.log('RequireAuth render:', { status, user, location: location.pathname });
  if (status === 'loading') {
    return null;
  }
  if (status === 'unauthenticated' || user === null) {
    console.log('RequireAuth redirecting to /login');
    return <Navigate to="/login" replace state={{ from: location }} />;
  }
  if (user.must_change_password) {
    return <Navigate to="/chpass" replace state={{ from: location }} />;
  }
  return <>{children}</>;
}

export function RequirePermission({
  permission,
  children,
}: {
  permission: PermissionCode;
  children: ReactNode;
}) {
  const { status, me } = useAuth();
  const location = useLocation();

  if (status === 'loading') {
    return null;
  }
  if (status === 'unauthenticated') {
    return <Navigate to="/login" replace state={{ from: location }} />;
  }
  if (!hasPermission(me, permission)) {
    return <Navigate to="/403" replace state={{ from: location }} />;
  }
  return <>{children}</>;
}
