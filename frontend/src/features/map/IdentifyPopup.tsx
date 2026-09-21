import React from 'react';

interface IdentifyPopupProps {
    feature: {
        id: string;
        status: string;
        psgc_barangay?: string;
        attributes: Record<string, unknown>;
    } | null;
    layerName: string | null;
    distance_m: number | null;
    onClose: () => void;
}

export const IdentifyPopup: React.FC<IdentifyPopupProps> = ({
    feature,
    layerName,
    distance_m,
    onClose,
}) => {
    if (!feature) {
        return (
            <div
                style={{
                    position: 'absolute',
                    bottom: 30,
                    left: 10,
                    background: 'rgba(0,0,0,0.8)',
                    color: '#fff',
                    padding: '8px 12px',
                    borderRadius: 6,
                    fontSize: 12,
                    pointerEvents: 'none',
                    zIndex: 1000,
                }}
            >
                No feature at this location
            </div>
        );
    }

    return (
        <div
            style={{
                position: 'absolute',
                top: 10,
                right: 10,
                background: '#fff',
                border: '1px solid #d1d5db',
                borderRadius: 8,
                boxShadow: '0 4px 16px rgba(0,0,0,0.2)',
                padding: '12px 16px',
                maxWidth: 280,
                zIndex: 1000,
                fontFamily: 'system-ui, sans-serif',
                fontSize: 13,
                color: '#1f2937',
            }}
        >
            <div
                style={{
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'space-between',
                    marginBottom: 8,
                    paddingBottom: 8,
                    borderBottom: '1px solid #e5e7eb',
                }}
            >
                <strong style={{ fontSize: 14 }}>{layerName}</strong>
                <button
                    onClick={onClose}
                    style={{
                        background: 'none',
                        border: 'none',
                        fontSize: 18,
                        cursor: 'pointer',
                        color: '#6b7280',
                        padding: '0 4px',
                        lineHeight: 1,
                    }}
                    aria-label="Close"
                >
                    ×
                </button>
            </div>

            {distance_m != null && (
                <div style={{ fontSize: 12, color: '#6b7280', marginBottom: 6 }}>
                    {distance_m < 1 ? `${(distance_m * 1000).toFixed(1)} m` : `${distance_m.toFixed(2)} m`} from click point
                </div>
            )}

            <dl style={{ display: 'grid', gridTemplateColumns: '100px 1fr', gap: '4px 8px' }}>
                <dt style={{ fontWeight: 500, color: '#6b7280' }}>Status</dt>
                <dd style={{ textTransform: 'capitalize' }}>{feature.status}</dd>

                {feature.psgc_barangay && (
                    <>
                        <dt style={{ fontWeight: 500, color: '#6b7280' }}>Barangay</dt>
                        <dd>{feature.psgc_barangay}</dd>
                    </>
                )}

                <dt style={{ fontWeight: 500, color: '#6b7280' }}>ID</dt>
                <dd style={{ fontFamily: 'monospace', fontSize: 12 }}>{feature.id}</dd>
            </dl>

            {Object.keys(feature.attributes).length > 0 && (
                <>
                    <div style={{ marginTop: 8, paddingTop: 8, borderTop: '1px solid #e5e7eb' }}>
                        <div style={{ fontSize: 11, fontWeight: 600, color: '#9ca3af', textTransform: 'uppercase', letterSpacing: '0.05em', marginBottom: 4 }}>
                            Attributes
                        </div>
                        <dl style={{ display: 'grid', gridTemplateColumns: '100px 1fr', gap: '2px 8px', fontSize: 12 }}>
                            {Object.entries(feature.attributes).map(([k, v]) => (
                                <React.Fragment key={k}>
                                    <dt style={{ fontWeight: 500, color: '#6b7280', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{k}</dt>
                                    <dd style={{ overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                                        {v == null ? '—' : String(v)}
                                    </dd>
                                </React.Fragment>
                            ))}
                        </dl>
                    </div>
                </>
            )}
        </div>
    );
};
