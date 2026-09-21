// @ts-nocheck
import React, { useState } from 'react';
import { Modal } from './Modal';
import type { DrawError } from '../../features/map/DrawManager';
import { layerApi } from '../../features/layers/api/layerApi';

interface ConflictDialogProps {
    error: DrawError;
    /** The feature as it existed when the user started editing (their "saved" version). */
    yourVersion: {
        id: string;
        version: number;
        updated_by: number;
        updated_at: string;
        geometry: GeoJSON.GeometryObject;
        attributes: Record<string, unknown>;
        status: string;
        psgc_barangay?: string;
        provenance?: string;
    };
    /** Current server version (from the 409 response's current_version, fetched fresh). */
    currentVersion?: number;
    layerId: number;
    onClose: () => void;
    onReload: () => void;
    onCompare: () => void;
    onNewVersion: () => void;
}

export function ConflictDialog({
    error,
    yourVersion,
    currentVersion,
    layerId,
    onClose,
    onReload,
    onCompare,
    onNewVersion,
}: ConflictDialogProps) {
    const [loading, setLoading] = useState(false);

    return (
        <Modal open onClose={onClose} title="Version conflict">
            <div style={{ fontSize: 14, color: '#333', lineHeight: 1.6 }}>
                <p style={{ margin: '0 0 12px' }}>
                    This feature was modified by another user while you were editing.
                    Your version cannot be saved as-is.
                </p>

                <div style={{ display: 'grid', gap: 10, marginBottom: 16 }}>
                    {/* ── Your version ── */}
                    <div
                        style={{
                            border: '1px solid #ddd',
                            borderRadius: 6,
                            padding: 12,
                            background: '#fafafa',
                        }}
                    >
                        <div style={{ fontSize: 12, fontWeight: 600, color: '#666', textTransform: 'uppercase', letterSpacing: '0.05em', marginBottom: 6 }}>
                            Your version (v{yourVersion.version})
                        </div>
                        <div style={{ fontSize: 13 }}>
                            <div>Updated by: {yourVersion.updated_by}</div>
                            <div>At: {new Date(yourVersion.updated_at).toLocaleString()}</div>
                        </div>
                    </div>

                    {/* ── Current server version ── */}
                    {currentVersion != null && (
                        <div
                            style={{
                                border: '1px solid #e0c060',
                                borderRadius: 6,
                                padding: 12,
                                background: '#fffbe8',
                            }}
                        >
                            <div
                                style={{
                                    fontSize: 12,
                                    fontWeight: 600,
                                    color: '#9a7000',
                                    textTransform: 'uppercase',
                                    letterSpacing: '0.05em',
                                    marginBottom: 6,
                                }}
                            >
                                Current server version (v{currentVersion})
                            </div>
                            <div style={{ fontSize: 13, color: '#555' }}>
                                The feature on the server is now at version{' '}
                                <strong>{currentVersion}</strong>. Your changes would
                                overwrite this version.
                            </div>
                        </div>
                    )}

                    {error.detail != null && (
                        <div
                            style={{
                                border: '1px solid #ccc',
                                borderRadius: 6,
                                padding: 8,
                                fontSize: 12,
                                color: '#777',
                                background: '#f5f5f5',
                            }}
                        >
                            Server detail:{' '}
                            {JSON.stringify(error.detail)}
                        </div>
                    )}
                </div>

                {/* ── Actions ── */}
                <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
                    <button
                        onClick={() => {
                            setLoading(true);
                            onReload().finally(() => setLoading(false));
                        }}
                        disabled={loading}
                        style={{
                            background: '#2563eb',
                            color: '#fff',
                            border: 'none',
                            borderRadius: 6,
                            padding: '8px 16px',
                            fontSize: 14,
                            fontWeight: 500,
                            cursor: loading ? 'not-allowed' : 'pointer',
                        }}
                    >
                        {loading ? 'Reloading…' : 'Reload (get current)'}
                    </button>

                    <button
                        onClick={onCompare}
                        style={{
                            background: '#e5e7eb',
                            color: '#1f2937',
                            border: 'none',
                            borderRadius: 6,
                            padding: '8px 16px',
                            fontSize: 14,
                            fontWeight: 500,
                            cursor: 'pointer',
                        }}
                    >
                        Compare versions
                    </button>

                    <button
                        onClick={() => {
                            setLoading(true);
                            onNewVersion().finally(() => setLoading(false));
                        }}
                        disabled={loading}
                        style={{
                            background: '#059669',
                            color: '#fff',
                            border: 'none',
                            borderRadius: 6,
                            padding: '8px 16px',
                            fontSize: 14,
                            fontWeight: 500,
                            cursor: loading ? 'not-allowed' : 'pointer',
                        }}
                    >
                        {loading ? 'Saving…' : 'Create new version (merge)'}
                    </button>

                    <button
                        onClick={onClose}
                        style={{
                            background: 'transparent',
                            color: '#6b7280',
                            border: '1px solid #d1d5db',
                            borderRadius: 6,
                            padding: '8px 16px',
                            fontSize: 14,
                            cursor: 'pointer',
                        }}
                    >
                        Cancel
                    </button>
                </div>
            </div>
        </Modal>
    );
}
