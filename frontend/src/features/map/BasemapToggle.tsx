import React from 'react';
import { useMapContext } from './MapContext';
import { useBasemapToggle, type BasemapKind } from './basemap';

const btnStyle: React.CSSProperties = {
    background: '#fff',
    borderWidth: 1,
    borderStyle: 'solid',
    borderColor: '#d1d5db',
    borderRadius: 6,
    padding: '6px 10px',
    fontSize: 13,
    cursor: 'pointer',
    color: '#1f2937',
};

const activeBtnStyle: React.CSSProperties = {
    background: '#2563eb',
    color: '#fff',
    borderColor: '#1d4ed8',
};

const OPTIONS: { kind: BasemapKind; label: string }[] = [
    { kind: 'roads', label: '🛣 Roads' },
    { kind: 'satellite', label: '🛰 Satellite' },
];

export function BasemapToggle() {
    const { map } = useMapContext();
    const { basemap, setBasemap } = useBasemapToggle(map);

    return (
        <div
            data-testid="basemap-toggle"
            style={{
                display: 'flex',
                gap: 6,
                marginBottom: 10,
                pointerEvents: 'auto',
            }}
        >
            {OPTIONS.map((o) => (
                <button
                    key={o.kind}
                    data-testid={`basemap-${o.kind}`}
                    onClick={() => setBasemap(o.kind)}
                    style={{
                        ...btnStyle,
                        ...(basemap === o.kind ? activeBtnStyle : {}),
                    }}
                >
                    {o.label}
                </button>
            ))}
        </div>
    );
}