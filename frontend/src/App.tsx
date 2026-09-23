import React from 'react';
import { Routes, Route, Link, useLocation } from 'react-router-dom';
import { useAuth } from './auth/useAuth';
import { RequireAuth, RequirePermission } from './auth/guards';
import { hasPermission } from './auth/permissions';
import LoginPage from './pages/LoginPage';
import ChangePasswordPage from './pages/ChangePasswordPage';
import ForbiddenPage from './pages/ForbiddenPage';
import HealthView from './pages/HealthView';
import { AdminView } from './features/admin/AdminView';
import { AuditLogView } from './features/audit/AuditLogView';
import { MapShell } from './features/map/MapShell';
import { MapWorkspace } from './features/map/MapWorkspace';
import { HomePage } from './pages/HomePage';
import { ParcelListPage } from './features/parcels/pages/ParcelListPage';
import { ParcelEditorPage } from './features/parcels/pages/ParcelEditorPage';

const Layout: React.FC<{ children: React.ReactNode }> = ({ children }) => {
  const { me: _me } = useAuth();
  const location = useLocation();

  const mainStyle: React.CSSProperties = location.pathname === '/'
    ? { padding: 0, maxWidth: '100%', pointerEvents: 'auto' as const }
    : { backgroundColor: '#fff', pointerEvents: 'auto' as const, minHeight: 'calc(100vh - 60px)' };

  return (
    <div className="app-container">
      <header className="app-header" style={{ pointerEvents: 'auto' }}>
        <div>
          <h2 style={{ margin: 0 }}>WebGIS</h2>
        </div>
        <nav className="app-nav">
          <Link to="/" className={location.pathname === '/' ? 'active' : ''}>Home</Link>
          <Link to="/map" className={location.pathname === '/map' ? 'active' : ''}>Map</Link>
          {hasPermission(_me, 'user.manage') && (
            <Link to="/admin" className={location.pathname.startsWith('/admin') ? 'active' : ''}>Admin</Link>
          )}
          {hasPermission(_me, 'parcel.view') && (
            <Link to="/parcels" className={location.pathname.startsWith('/parcels') ? 'active' : ''}>Parcels</Link>
          )}
          {hasPermission(_me, 'audit.view') && (
            <Link to="/audit" className={location.pathname === '/audit' ? 'active' : ''}>Audit Logs</Link>
          )}
          <Link to="/status" className={location.pathname === '/status' ? 'active' : ''}>System Status</Link>
        </nav>
      </header>
      <main className="app-main" style={mainStyle}>
        {children}
      </main>
    </div>
  );
};

function App() {
  return (
    <Layout>
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
        <Route path="/403" element={<ForbiddenPage />} />
        <Route path="/status" element={<HealthView />} />
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
          path="/parcels/:id/:tab?"
          element={
            <RequirePermission permission="parcel.view">
              <ParcelEditorPage />
            </RequirePermission>
          }
        />
        <Route
          path="/*"
          element={
            <RequireAuth>
              <HomePage />
            </RequireAuth>
          }
        />
      </Routes>
    </Layout>
  );
}

export default App;
