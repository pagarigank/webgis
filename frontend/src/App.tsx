import React from 'react';
import { Routes, Route, Link, Navigate, useLocation } from 'react-router-dom';
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

type RailIcon = React.ReactNode;

interface RailItem {
  to: string;
  label: string;
  icon: RailIcon;
  permission: PermissionCode | '';
  isActive: (pathname: string) => boolean;
  group?: 'main' | 'admin';
}

const iconProps = {
  width: 20,
  height: 20,
  viewBox: '0 0 24 24',
  fill: 'none',
  stroke: 'currentColor',
  strokeWidth: 2,
  strokeLinecap: 'round',
  strokeLinejoin: 'round',
} as const;

const ICONS = {
  map: (
    <svg {...iconProps} aria-hidden="true">
      <polygon points="1 6 1 22 8 18 16 22 23 18 23 2 16 6 8 2 1 6" />
      <line x1="8" y1="2" x2="8" y2="18" />
      <line x1="16" y1="6" x2="16" y2="22" />
    </svg>
  ),
  parcels: (
    <svg {...iconProps} aria-hidden="true">
      <path d="M3 3h18v18H3z" />
      <path d="M3 9h18M9 21V9" />
    </svg>
  ),
  controlPoints: (
    <svg {...iconProps} aria-hidden="true">
      <circle cx="12" cy="12" r="3" />
      <line x1="12" y1="2" x2="12" y2="7" />
      <line x1="12" y1="17" x2="12" y2="22" />
      <line x1="2" y1="12" x2="7" y2="12" />
      <line x1="17" y1="12" x2="22" y2="12" />
    </svg>
  ),
  inbox: (
    <svg {...iconProps} aria-hidden="true">
      <path d="M22 12h-6l-2 3h-4l-2-3H2" />
      <path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z" />
    </svg>
  ),
  audit: (
    <svg {...iconProps} aria-hidden="true">
      <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
      <polyline points="14 2 14 8 20 8" />
      <line x1="16" y1="13" x2="8" y2="13" />
      <line x1="16" y1="17" x2="8" y2="17" />
    </svg>
  ),
  admin: (
    <svg {...iconProps} aria-hidden="true">
      <circle cx="12" cy="12" r="3" />
      <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.6a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9v.09a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z" />
    </svg>
  ),
  status: (
    <svg {...iconProps} aria-hidden="true">
      <path d="M22 12h-4l-3 9L9 3l-3 9H2" />
    </svg>
  ),
} satisfies Record<string, RailIcon>;

const RAIL_ITEMS: RailItem[] = [
  {
    to: '/map',
    label: 'Map',
    icon: ICONS.map,
    permission: '',
    isActive: (p) => p === '/map',
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
    label: 'Control Points',
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
    label: 'Audit Logs',
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

const Layout: React.FC<{ children: React.ReactNode }> = ({ children }) => {
  const { me: _me } = useAuth();
  const location = useLocation();

  const visibleItems = RAIL_ITEMS.filter((item) => item.permission === '' || hasPermission(_me, item.permission));

  const mainStyle: React.CSSProperties = {
    backgroundColor: '#fff',
    minHeight: 'calc(100vh - 60px)',
  };

  return (
    <div className="app-container">
      <header className="app-header" style={{ pointerEvents: 'auto' }}>
        <div>
          <h2 style={{ margin: 0 }}>WebGIS</h2>
        </div>
        <div className="app-nav">
          {_me != null && <NotificationBell />}
        </div>
      </header>
      <div className="app-body">
        <nav className="app-rail" aria-label="Modules">
          {visibleItems.map((item) => (
            <React.Fragment key={item.to}>
              {item.group === 'admin' && <div className="app-rail-divider" />}
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
        <main className="app-main" style={mainStyle}>
          {children}
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
  );
}

export default App;
