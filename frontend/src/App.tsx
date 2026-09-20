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
import { LayerTree } from './features/layers/LayerTree';

const HomePage: React.FC = () => {
  return (
    <div style={{ position: 'absolute', top: 20, left: 20, zIndex: 1000, width: 300, pointerEvents: 'auto' }}>
      <LayerTree />
    </div>
  );
};

const Layout: React.FC<{ children: React.ReactNode }> = ({ children }) => {
  const { me: _me } = useAuth();
  const location = useLocation();

  return (
    <div className="app-container">
      <header className="app-header" style={{ pointerEvents: 'auto' }}>
        <div>
          <h2 style={{ margin: 0 }}>WebGIS</h2>
        </div>
        <nav className="app-nav">
          <Link to="/" className={location.pathname === '/' ? 'active' : ''}>Home</Link>
          {hasPermission(_me, 'user.manage') && (
            <Link to="/admin" className={location.pathname.startsWith('/admin') ? 'active' : ''}>Admin</Link>
          )}
          {hasPermission(_me, 'audit.view') && (
            <Link to="/audit" className={location.pathname === '/audit' ? 'active' : ''}>Audit Logs</Link>
          )}
          <Link to="/status" className={location.pathname === '/status' ? 'active' : ''}>System Status</Link>
        </nav>
      </header>
      <main className="app-main" style={
        location.pathname === '/' 
          ? { padding: 0, maxWidth: '100%', pointerEvents: 'none' } 
          : { backgroundColor: '#fff', pointerEvents: 'auto', minHeight: 'calc(100vh - 60px)' }
      }>
        {children}
      </main>
    </div>
  );
};

function App() {
  return (
    <MapShell>
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
          path="/*"
          element={
            <RequireAuth>
              <HomePage />
            </RequireAuth>
          }
        />
        </Routes>
      </Layout>
    </MapShell>
  );
}

export default App;