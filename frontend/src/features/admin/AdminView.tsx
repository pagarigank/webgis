
import { Routes, Route, Navigate, NavLink } from 'react-router-dom';
import { UsersManager } from './UsersManager';
import { RolesManager } from './RolesManager';
import { OrganizationsManager } from './OrganizationsManager';
import { BasemapsManager } from './BasemapsManager';

import { LayerListPage } from '../layers/pages/LayerListPage';
import { LayerDesignerPage } from '../layers/pages/LayerDesignerPage';
import { useAuth } from '../../auth/useAuth';
import { hasPermission } from '../../auth/permissions';

export function AdminView() {
  const { me } = useAuth();
  
  return (
    <div>
      <h2 style={{ marginBottom: '1.5rem' }}>System Administration</h2>
      <nav className="tabs" style={{ display: 'flex', gap: '1rem', borderBottom: '1px solid var(--border-color)', marginBottom: '2rem' }}>
        <NavLink to="/admin/users" className={({ isActive }) => `tab-item ${isActive ? 'active' : ''}`}>Users</NavLink>
        {hasPermission(me, 'role.manage') && (
          <NavLink to="/admin/roles" className={({ isActive }) => `tab-item ${isActive ? 'active' : ''}`}>Roles</NavLink>
        )}
        <NavLink to="/admin/organizations" className={({ isActive }) => `tab-item ${isActive ? 'active' : ''}`}>Organizations</NavLink>
        {hasPermission(me, 'gis.layer.create') && (
          <NavLink to="/admin/layers" className={({ isActive }) => `tab-item ${isActive ? 'active' : ''}`}>GIS Layers</NavLink>
        )}
        {hasPermission(me, 'basemap.manage') && (
          <NavLink to="/admin/basemaps" className={({ isActive }) => `tab-item ${isActive ? 'active' : ''}`}>Basemaps</NavLink>
        )}
      </nav>

      <Routes>
        <Route path="/" element={<Navigate to="/admin/users" replace />} />
        <Route path="users" element={<UsersManager />} />
        <Route path="roles" element={<RolesManager />} />
        <Route path="organizations" element={<OrganizationsManager />} />
        <Route path="layers" element={<LayerListPage />} />
        <Route path="layers/:id" element={<LayerDesignerPage />} />
        <Route path="basemaps" element={<BasemapsManager />} />
      </Routes>
    </div>
  );
}
