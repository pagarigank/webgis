import React, { useState } from 'react';
import { Routes, Route, Navigate, NavLink } from 'react-router-dom';
import { UsersManager } from './UsersManager';
import { RolesManager } from './RolesManager';
import { OrganizationsManager } from './OrganizationsManager';
import { GISManager } from './GISManager';
import { useAuth } from '../../auth/useAuth';
import { hasPermission } from '../../auth/permissions';

export function AdminView() {
  const { me } = useAuth();
  
  return (
    <div>
      <h2 style={{ marginBottom: '1.5rem' }}>System Administration</h2>
      <nav className="tabs" style={{ display: 'flex', gap: '1rem', borderBottom: '1px solid var(--border-color)', marginBottom: '2rem' }}>
        <NavLink to="users" className={({ isActive }) => `tab-item ${isActive ? 'active' : ''}`}>Users</NavLink>
        {hasPermission(me, 'role.manage') && (
          <NavLink to="roles" className={({ isActive }) => `tab-item ${isActive ? 'active' : ''}`}>Roles</NavLink>
        )}
        <NavLink to="organizations" className={({ isActive }) => `tab-item ${isActive ? 'active' : ''}`}>Organizations</NavLink>
        {hasPermission(me, 'layer.manage') && (
          <NavLink to="layers" className={({ isActive }) => `tab-item ${isActive ? 'active' : ''}`}>GIS Layers</NavLink>
        )}
      </nav>

      <Routes>
        <Route path="/" element={<Navigate to="users" replace />} />
        <Route path="users" element={<UsersManager />} />
        <Route path="roles" element={<RolesManager />} />
        <Route path="organizations" element={<OrganizationsManager />} />
        <Route path="layers" element={<GISManager />} />
      </Routes>
    </div>
  );
}
