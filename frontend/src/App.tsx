import React from 'react';
import { Routes, Route, Link, Navigate, useLocation, NavLink } from 'react-router-dom';
import { useAuth } from './auth/useAuth';
import { RequireAuth, RequirePermission } from './auth/guards';
import { hasPermission, permissions } from './auth/permissions';
import type { PermissionCode } from './auth/permissions';
import LoginPage from './pages/LoginPage';
import ChangePasswordPage from './pages/ChangePasswordPage';
import ForbiddenPage from './pages/ForbiddenPage';
import NotFoundPage from './pages/NotFoundPage';
import HealthView from './pages/HealthView';
import { AdminView } from './features/admin/AdminView';
import { AuditLogView } from './features/audit/AuditLogView';
import { MapShell } from './features/map/MapShell';
import { MapWorkspace } from './features/map/MapWorkspace';
import { ParcelListPage } from './features/parcels/pages/ParcelListPage';
import { ParcelEditorPage } from './features/parcels/pages/ParcelEditorPage';
import { ParcelCreatePage } from './features/parcels/pages/ParcelCreatePage';
import { ControlPointListPage } from './features/control-points/pages/ControlPointListPage';
import { ControlPointEditorPage } from './features/control-points/pages/ControlPointEditorPage';
import { NotificationBell } from './features/notifications/components/NotificationBell';
import { ReviewerInboxPage } from './features/parcels/pages/ReviewerInboxPage';
import { ParcelSelectionProvider } from './features/parcels/ParcelSelectionContext';

/* ─── Icon helpers ─────────────────────────────────────────────────── */
const SvgIcon: React.FC<{ children: React.ReactNode; size?: number }> = ({
  children,
  size = 20,
}) => (
  <svg
    width={size}
    height={size}
    viewBox="0 0 24 24"
    fill="none"
    stroke="currentColor"
    strokeWidth={2}
    strokeLinecap="round"
    strokeLinejoin="round"
    aria-hidden="true"
    style={{ flexShrink: 0 }}
  >
    {children}
  </svg>
);

const ICONS = {
  map: (
    <SvgIcon>
      <polygon points="1 6 1 22 8 18 16 22 23 18 23 2 16 6 8 2 1 6" />
      <line x1="8" y1="2" x2="8" y2="18" />
      <line x1="16" y1="6" x2="16" y2="22" />
    </SvgIcon>
  ),
  parcels: (
    <SvgIcon>
      <path d="M3 3h18v18H3z" />
      <path d="M3 9h18M9 21V9" />
    </SvgIcon>
  ),
  controlPoints: (
    <SvgIcon>
      <circle cx="12" cy="12" r="3" />
      <line x1="12" y1="2" x2="12" y2="7" />
      <line x1="12" y1="17" x2="12" y2="22" />
      <line x1="2" y1="12" x2="7" y2="12" />
      <line x1="17" y1="12" x2="22" y2="12" />
    </SvgIcon>
  ),
  inbox: (
    <SvgIcon>
      <path d="M22 12h-6l-2 3h-4l-2-3H2" />
      <path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z" />
    </SvgIcon>
  ),
  audit: (
    <SvgIcon>
      <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
      <polyline points="14 2 14 8 20 8" />
      <line x1="16" y1="13" x2="8" y2="13" />
      <line x1="16" y1="17" x2="8" y2="17" />
    </SvgIcon>
  ),
  admin: (
    <SvgIcon>
      <circle cx="12" cy="12" r="3" />
      <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.6a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9v.09a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z" />
    </SvgIcon>
  ),
  status: (
    <SvgIcon>
      <path d="M22 12h-4l-3 9L9 3l-3 9H2" />
    </SvgIcon>
  ),
  logout: (
    <SvgIcon size={16}>
      <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" />
      <polyline points="16 17 21 12 16 7" />
      <line x1="21" y1="12" x2="9" y2="12" />
    </SvgIcon>
  ),
  user: (
    <SvgIcon size={16}>
      <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" />
      <circle cx="12" cy="7" r="4" />
    </SvgIcon>
  ),
};

/* ─── Rail item definition ─────────────────────────────────────────── */
interface RailItem {
  to: string;
  label: string;
  icon: React.ReactNode;
  permission: PermissionCode | '';
  isActive: (pathname: string) => boolean;
  group?: 'admin';
}

const RAIL_ITEMS: RailItem[] = [
  {
    to: '/map',
    label: 'Map',
    icon: ICONS.map,
    permission: '',
    isActive: (p) => p === '/map' || p.startsWith('/map/'),
  },
  {
    to: '/parcels',
    label: 'Parcels',
    icon: ICONS.parcels,
    permission: permissions.parcelView,
    isActive: (p) => p.startsWith('/parcels') && p !== '/parcels/inbox',
  },
  {
    to: '/control-points',
    label: 'Survey',
    icon: ICONS.controlPoints,
    permission: permissions.controlPointView,
    isActive: (p) => p.startsWith('/control-points'),
  },
  {
    to: '/parcels/inbox',
    label: 'Inbox',
    icon: ICONS.inbox,
    permission: permissions.parcelReview,
    isActive: (p) => p === '/parcels/inbox',
  },
  {
    to: '/audit',
    label: 'Audit',
    icon: ICONS.audit,
    permission: permissions.auditView,
    isActive: (p) => p === '/audit',
  },
  {
    to: '/admin',
    label: 'Admin',
    icon: ICONS.admin,
    permission: permissions.userManage,
    isActive: (p) => p.startsWith('/admin'),
    group: 'admin',
  },
  {
    to: '/status',
    label: 'Status',
    icon: ICONS.status,
    permission: '',
    isActive: (p) => p === '/status',
    group: 'admin',
  },
];

/* ─── Header user menu ─────────────────────────────────────────────── */
const UserMenu: React.FC = () => {
  const { me, logout } = useAuth();
  const [open, setOpen] = React.useState(false);
  const ref = React.useRef<HTMLDivElement>(null);

  React.useEffect(() => {
    const handler = (e: MouseEvent) => {
      if (ref.current && !ref.current.contains(e.target as Node)) setOpen(false);
    };
    document.addEventListener('mousedown', handler);
    return () => document.removeEventListener('mousedown', handler);
  }, []);

  if (!me) return null;

  const user = me.user;
  const initials = (user.full_name ?? user.username ?? '?')
    .split(' ')
    .map((w: string) => w[0])
    .slice(0, 2)
    .join('')
    .toUpperCase();

  return (
    <div ref={ref} style={{ position: 'relative' }}>
      <button
        className="app-header-icon-btn"
        onClick={() => setOpen((o) => !o)}
        title={user.full_name ?? user.username}
        aria-expanded={open}
        aria-haspopup="menu"
        style={{
          width: 34,
          height: 34,
          borderRadius: '50%',
          background: 'var(--blue-600)',
          color: '#fff',
          fontWeight: 700,
          fontSize: '0.7rem',
          letterSpacing: 0,
        }}
      >
        {initials}
      </button>

      {open && (
        <div
          role="menu"
          style={{
            position: 'absolute',
            right: 0,
            top: 'calc(100% + 8px)',
            background: '#fff',
            border: '1px solid var(--border-color)',
            borderRadius: 'var(--radius-md)',
            boxShadow: 'var(--shadow-lg)',
            minWidth: 200,
            zIndex: 500,
            overflow: 'hidden',
            animation: 'auth-appear 0.15s ease both',
          }}
        >
          <div style={{ padding: '0.75rem 1rem', borderBottom: '1px solid var(--border-color)', background: 'var(--bg-surface-alt)' }}>
            <div style={{ fontWeight: 600, fontSize: '0.875rem', color: 'var(--text-primary)' }}>
              {user.full_name ?? user.username}
            </div>
            <div style={{ fontSize: '0.75rem', color: 'var(--text-muted)', marginTop: 2 }}>
              {user.email ?? user.username}
            </div>
          </div>
          <div style={{ padding: '0.375rem' }}>
            <NavLink
              to="/chpass"
              style={menuItemStyle}
              onClick={() => setOpen(false)}
            >
              {ICONS.user}
              Change password
            </NavLink>
            <button
              style={{ ...menuItemStyle, width: '100%', textAlign: 'left', border: 'none', cursor: 'pointer', color: 'var(--brand-danger)' }}
              onClick={() => { setOpen(false); void logout(); }}
            >
              {ICONS.logout}
              Sign out
            </button>
          </div>
        </div>
      )}
    </div>
  );
};

const menuItemStyle: React.CSSProperties = {
  display: 'flex',
  alignItems: 'center',
  gap: '0.5rem',
  padding: '0.5rem 0.625rem',
  borderRadius: '6px',
  fontSize: '0.875rem',
  color: 'var(--text-secondary)',
  textDecoration: 'none',
  background: 'transparent',
  transition: 'background 0.12s, color 0.12s',
};

/* ─── Main layout ──────────────────────────────────────────────────── */
const Layout: React.FC<{ children: React.ReactNode }> = ({ children }) => {
  const { me: _me } = useAuth();
  const location = useLocation();

  const visibleItems = RAIL_ITEMS.filter(
    (item) => item.permission === '' || hasPermission(_me, item.permission),
  );

  const isMapRoute = location.pathname === '/map' || location.pathname.startsWith('/map/');

  return (
    <div className="app-container">
      {/* ── Header ── */}
      <header className="app-header">
        <Link to="/map" className="app-header-brand">
          <div className="app-header-logo">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" style={{ color: '#fff' }} aria-hidden="true">
              <path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5s1.12-2.5 2.5-2.5 2.5 1.12 2.5 2.5-1.12 2.5-2.5 2.5z" />
            </svg>
          </div>
          <div>
            <div className="app-header-title">WebGIS</div>
            <div className="app-header-subtitle">Parcel Management</div>
          </div>
        </Link>

        <div className="app-header-actions">
          {_me != null && <NotificationBell />}
          <UserMenu />
        </div>
      </header>

      {/* ── Body ── */}
      <div className="app-body">
        {/* ── Rail nav ── */}
        <nav className="app-rail" aria-label="Modules">
          {visibleItems.map((item) => (
            <React.Fragment key={item.to}>
              {item.group === 'admin' &&
                visibleItems.indexOf(item) > 0 &&
                visibleItems[visibleItems.indexOf(item) - 1]?.group !== 'admin' && (
                  <div className="app-rail-divider" />
                )}
              <Link
                to={item.to}
                className={item.isActive(location.pathname) ? 'active' : ''}
                aria-current={item.isActive(location.pathname) ? 'page' : undefined}
                title={item.label}
              >
                {item.icon}
                <span>{item.label}</span>
              </Link>
            </React.Fragment>
          ))}
        </nav>

        {/* ── Main ── */}
        <main
          className="app-main"
          style={isMapRoute ? { padding: 0, overflow: 'hidden' } : undefined}
        >
          {isMapRoute ? (
            children
          ) : (
            <div className="page-content">
              {children}
            </div>
          )}
        </main>
      </div>
    </div>
  );
};

const AUTH_ROUTES = ['/login', '/forgot-password', '/404', '/403'];

const RouteShell: React.FC<{ children: React.ReactNode }> = ({ children }) => {
  const location = useLocation();
  if (AUTH_ROUTES.includes(location.pathname)) {
    return <div className="auth-layout">{children}</div>;
  }
  return <Layout>{children}</Layout>;
};

function App() {
  return (
    <ParcelSelectionProvider>
      <RouteShell>
        <Routes>
          <Route path="/login" element={<LoginPage />} />
          <Route
            path="/chpass"
            element={
              <RequireAuth>
                <ChangePasswordPage />
              </RequireAuth>
            }
          />
          <Route path="/404" element={<NotFoundPage />} />
          <Route path="/403" element={<ForbiddenPage />} />
          <Route path="/status" element={<HealthView />} />
          <Route
            path="/"
            element={
              <RequireAuth>
                <Navigate to="/map" replace />
              </RequireAuth>
            }
          />
          <Route
            path="/admin/*"
            element={
              <RequirePermission permission="user.manage">
                <AdminView />
              </RequirePermission>
            }
          />
          <Route
            path="/audit"
            element={
              <RequirePermission permission="audit.view">
                <AuditLogView />
              </RequirePermission>
            }
          />
          <Route
            path="/map"
            element={
              <RequireAuth>
                <MapShell>
                  <MapWorkspace />
                </MapShell>
              </RequireAuth>
            }
          />
          <Route
            path="/parcels"
            element={
              <RequirePermission permission="parcel.view">
                <ParcelListPage />
              </RequirePermission>
            }
          />
          <Route
            path="/parcels/inbox"
            element={
              <RequirePermission permission="parcel.review">
                <ReviewerInboxPage />
              </RequirePermission>
            }
          />
          <Route
            path="/parcels/new"
            element={
              <RequirePermission permission="parcel.create">
                <ParcelCreatePage />
              </RequirePermission>
            }
          />
          <Route
            path="/parcels/:id/:tab?"
            element={
              <RequirePermission permission="parcel.view">
                <ParcelEditorPage />
              </RequirePermission>
            }
          />
          <Route
            path="/control-points"
            element={
              <RequirePermission permission="control_point.view">
                <ControlPointListPage />
              </RequirePermission>
            }
          />
          <Route
            path="/control-points/new"
            element={
              <RequirePermission permission="control_point.create">
                <ControlPointEditorPage />
              </RequirePermission>
            }
          />
          <Route
            path="/control-points/:id"
            element={
              <RequirePermission permission="control_point.view">
                <ControlPointEditorPage />
              </RequirePermission>
            }
          />
          <Route path="/*" element={<NotFoundPage />} />
        </Routes>
      </RouteShell>
    </ParcelSelectionProvider>
  );
}

export default App;
