import { Routes, Route, Navigate, NavLink, useParams } from 'react-router-dom';
import { UsersManager } from './UsersManager';
import { RolesManager } from './RolesManager';
import { OrganizationsManager } from './OrganizationsManager';
import { BasemapsManager } from './BasemapsManager';

import { LayerListPage } from '../layers/pages/LayerListPage';
import { LayerDesignerPage } from '../layers/pages/LayerDesignerPage';
import { FeatureGridPage } from '../layers/pages/FeatureGridPage';
import { useAuth } from '../../auth/useAuth';
import { hasPermission } from '../../auth/permissions';

function LayerFeaturesRoute() {
    const { id } = useParams<{ id: string }>();
    const layerId = id ? Number(id) : 0;
    return <FeatureGridPage layerId={layerId} />;
}

const UsersIcon = () => (
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
        <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/>
        <path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>
    </svg>
);

const RolesIcon = () => (
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
        <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
    </svg>
);

const OrgIcon = () => (
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
        <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>
    </svg>
);

const LayersIcon = () => (
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
        <polygon points="12 2 2 7 12 12 22 7 12 2"/><polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/>
    </svg>
);

const BasemapIcon = () => (
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
        <circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/>
        <path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>
    </svg>
);

export function AdminView() {
    const { me } = useAuth();

    return (
        <div>
            {/* Page header */}
            <div className="page-header" style={{ marginBottom: '1.25rem' }}>
                <div>
                    <h2 className="page-title" style={{ margin: 0 }}>System Administration</h2>
                    <p className="page-subtitle" style={{ marginTop: '0.25rem' }}>
                        Manage users, roles, organisations, GIS layers, and basemap providers.
                    </p>
                </div>
            </div>

            {/* Pill-style tab bar */}
            <nav className="admin-tabs-bar" aria-label="Administration sections">
                <NavLink
                    to="/admin/users"
                    className={({ isActive }) => `admin-tab${isActive ? ' active' : ''}`}
                >
                    <UsersIcon /> Users
                </NavLink>

                {hasPermission(me, 'role.manage') && (
                    <NavLink
                        to="/admin/roles"
                        className={({ isActive }) => `admin-tab${isActive ? ' active' : ''}`}
                    >
                        <RolesIcon /> Roles
                    </NavLink>
                )}

                <NavLink
                    to="/admin/organizations"
                    className={({ isActive }) => `admin-tab${isActive ? ' active' : ''}`}
                >
                    <OrgIcon /> Organizations
                </NavLink>

                {hasPermission(me, 'gis.layer.view') && (
                    <NavLink
                        to="/admin/layers"
                        className={({ isActive }) => `admin-tab${isActive ? ' active' : ''}`}
                    >
                        <LayersIcon /> GIS Layers
                    </NavLink>
                )}

                {hasPermission(me, 'basemap.manage') && (
                    <NavLink
                        to="/admin/basemaps"
                        className={({ isActive }) => `admin-tab${isActive ? ' active' : ''}`}
                    >
                        <BasemapIcon /> Basemaps
                    </NavLink>
                )}
            </nav>

            {/* Tab content */}
            <Routes>
                <Route path="/" element={<Navigate to="/admin/users" replace />} />
                <Route path="users" element={<UsersManager />} />
                <Route path="roles" element={<RolesManager />} />
                <Route path="organizations" element={<OrganizationsManager />} />
                <Route path="layers" element={<LayerListPage />} />
                <Route path="layers/:id" element={<LayerDesignerPage />} />
                <Route path="layers/:id/features" element={<LayerFeaturesRoute />} />
                <Route path="basemaps" element={<BasemapsManager />} />
            </Routes>
        </div>
    );
}
