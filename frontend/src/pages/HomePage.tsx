import React from 'react';
import { Link } from 'react-router-dom';

export const HomePage: React.FC = () => {
    return (
        <div style={{ padding: '2.5rem 2rem', maxWidth: 1100, margin: '0 auto' }}>
            {/* Header */}
            <header style={{ marginBottom: '2.5rem', borderBottom: '1px solid #e2e8f0', paddingBottom: '1.5rem' }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: '0.75rem', marginBottom: '0.5rem' }}>
                    <div
                        style={{
                            width: 36,
                            height: 36,
                            borderRadius: 8,
                            background: 'linear-gradient(135deg, #2563eb 0%, #7c3aed 100%)',
                            display: 'flex',
                            alignItems: 'center',
                            justifyContent: 'center',
                            color: '#fff',
                            fontWeight: 700,
                            fontSize: 16,
                        }}
                    >
                        M
                    </div>
                    <h1 style={{ margin: 0, fontSize: '1.75rem', fontWeight: 700, color: '#0f172a', letterSpacing: '-0.02em' }}>
                        MapGIS
                    </h1>
                </div>
                <p style={{ margin: 0, color: '#64748b', fontSize: '1rem' }}>
                    Spatial data management for your organization — layers, features, and queries in one place.
                </p>
            </header>

            {/* Feature cards */}
            <div
                style={{
                    display: 'grid',
                    gridTemplateColumns: 'repeat(auto-fit, minmax(260px, 1fr))',
                    gap: '1.25rem',
                    marginBottom: '2.5rem',
                }}
            >
                <FeatureCard
                    title="GIS Layers"
                    description="Create and manage vector layers with fields, styles, and permissions. Each layer owns its features and metadata."
                    linkTo="/admin/layers"
                    color="#2563eb"
                />
                <FeatureCard
                    title="Feature Attributes"
                    description="Browse, search, edit, and export feature records in a server-driven paginated grid. Filter by status, sort columns, bulk edit."
                    linkTo="/admin/layers/1/features"
                    color="#7c3aed"
                />
                <FeatureCard
                    title="Spatial Tools"
                    description="Measure distance and area, identify features by clicking the map, run spatial queries (bbox, intersects, nearest, buffer), and zoom to features."
                    linkTo="/map"
                    color="#059669"
                />
                <FeatureCard
                    title="System Status"
                    description="Health checks, environment info, and runtime diagnostics for the API and map services."
                    linkTo="/status"
                    color="#d97706"
                />
            </div>

            {/* Quick start */}
            <div
                style={{
                    background: '#f8fafc',
                    border: '1px solid #e2e8f0',
                    borderRadius: 12,
                    padding: '1.5rem 2rem',
                    marginBottom: '2.5rem',
                }}
            >
                <p style={{ margin: 0, color: '#475569', fontSize: '0.95rem' }}>
                    Ready to get started?{' '}
                    <Link
                        to="/login"
                        style={{ color: '#2563eb', fontWeight: 600, textDecoration: 'none' }}
                    >
                        Sign in to the map
                    </Link>
                    {' '}or explore the{' '}
                    <Link
                        to="/status"
                        style={{ color: '#2563eb', fontWeight: 600, textDecoration: 'none' }}
                    >
                        system status
                    </Link>
                    .
                </p>
            </div>

            {/* Footer */}
            <footer
                style={{
                    borderTop: '1px solid #e2e8f0',
                    paddingTop: '1.25rem',
                    paddingBottom: '1.25rem',
                    color: '#94a3b8',
                    fontSize: '0.8125rem',
                    display: 'flex',
                    justifyContent: 'space-between',
                    flexWrap: 'wrap',
                    gap: '0.5rem',
                }}
            >
                <span>MapGIS · WebGIS spatial platform</span>
                <span>Built with React + MapLibre GL + Slim + PostGIS</span>
            </footer>
        </div>
    );
};

function FeatureCard({
    title,
    description,
    linkTo,
    color,
}: {
    title: string;
    description: string;
    linkTo: string;
    color: string;
}) {
    return (
        <Link
            to={linkTo}
            style={{
                display: 'block',
                padding: '1.25rem',
                background: '#fff',
                border: '1px solid #e2e8f0',
                borderRadius: 12,
                textDecoration: 'none',
                boxShadow: '0 1px 2px rgba(0,0,0,0.04)',
                transition: 'box-shadow 0.15s, border-color 0.15s',
            }}
        >
            <div
                style={{
                    width: 32,
                    height: 32,
                    borderRadius: 8,
                    background: color + '18',
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'center',
                    marginBottom: '0.75rem',
                    color,
                }}
            >
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                    <rect x="3" y="3" width="18" height="18" rx="2" ry="2" />
                    <line x1="3" y1="9" x2="21" y2="9" />
                    <line x1="9" y1="21" x2="9" y2="9" />
                </svg>
            </div>
            <h3 style={{ margin: '0 0 0.5rem', fontSize: '1rem', fontWeight: 600, color: '#0f172a' }}>
                {title}
            </h3>
            <p style={{ margin: 0, color: '#64748b', fontSize: '0.875rem', lineHeight: 1.5 }}>
                {description}
            </p>
        </Link>
    );
}
