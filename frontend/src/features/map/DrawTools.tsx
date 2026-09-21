// TASK-058/059: draw-tool UI over DrawManager.
// Arms exactly one tool at a time, exposes undo/redo, save-to-layer, and
// reports client geometry-validation errors from DrawManager.
import React, { useCallback, useEffect, useState } from 'react';
import { useMapContext } from './MapContext';
import type { DrawMode } from './MapContext';

const LAYER_ID_STORAGE_KEY = 'webgis.draw.layer_id';

const TOOLS: { mode: DrawMode; label: string }[] = [
    { mode: 'draw_point', label: '⬤ Point' },
    { mode: 'draw_line', label: '／ Line' },
    { mode: 'draw_polygon', label: '⬟ Polygon' },
];

export function DrawTools() {
    const ctx = useMapContext();
    const { drawMode, setDrawMode, drawManager } = ctx;
    const [layerId, setLayerId] = useState<string>(() =>
        localStorage.getItem(LAYER_ID_STORAGE_KEY) ?? '',
    );
    const [saving, setSaving] = useState(false);
    const [message, setMessage] = useState<{ kind: 'ok' | 'err'; text: string } | null>(null);
    const [undoRedoTick, setUndoRedoTick] = useState(0);

    useEffect(() => {
        localStorage.setItem(LAYER_ID_STORAGE_KEY, layerId);
    }, [layerId]);

    // DrawManager pushes every create/update/delete through onSave; use it as
    // a change signal to refresh the undo/redo button state.
    useEffect(() => {
        if (!drawManager) return;
        const origOnSave = ctx.onError; // (no-op; tick driven by mode + manual refresh below)
        void origOnSave;
    }, [drawManager, ctx]);

    const arm = useCallback(
        (mode: DrawMode) => {
            setMessage(null);
            // Only one tool armed at a time: arming a tool switches mode,
            // clicking the active tool disarms back to simple_select.
            setDrawMode(drawMode === mode ? 'simple_select' : mode);
        },
        [drawMode, setDrawMode],
    );

    const handleUndo = useCallback(async () => {
        await ctx.undo();
        setUndoRedoTick((t) => t + 1);
    }, [ctx]);

    const handleRedo = useCallback(async () => {
        await ctx.redo();
        setUndoRedoTick((t) => t + 1);
    }, [ctx]);

    const handleSave = useCallback(async () => {
        const lid = parseInt(layerId, 10);
        if (!Number.isFinite(lid) || lid <= 0) {
            setMessage({ kind: 'err', text: 'Enter a target layer ID first.' });
            return;
        }
        if (!drawManager) {
            setMessage({ kind: 'err', text: 'Draw manager not ready.' });
            return;
        }
        setSaving(true);
        setMessage(null);
        try {
            const saved = await drawManager.saveNew(lid);
            if (saved) {
                setMessage({ kind: 'ok', text: `Saved feature ${saved.id ?? ''} to layer ${lid}.` });
                setUndoRedoTick((t) => t + 1);
            } else {
                // onError already surfaced the validation/network reason.
                setMessage({ kind: 'err', text: 'Save rejected — see error details.' });
            }
        } finally {
            setSaving(false);
        }
    }, [layerId, drawManager]);

    const canUndo = ctx.canUndo();
    const canRedo = ctx.canRedo();
    void undoRedoTick;

    return (
        <div
            data-testid="draw-tools"
            style={{
                border: '1px solid #d1d5db',
                borderRadius: 8,
                padding: 10,
                background: '#fff',
                marginBottom: 10,
                pointerEvents: 'auto',
            }}
        >
            <div style={{ fontSize: 12, fontWeight: 600, color: '#374151', marginBottom: 6 }}>
                Draw (one tool at a time)
            </div>
            <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
                {TOOLS.map((t) => (
                    <button
                        key={t.mode}
                        data-testid={`draw-${t.mode}`}
                        onClick={() => arm(t.mode)}
                        style={{
                            ...btnStyle,
                            ...(drawMode === t.mode ? activeBtnStyle : {}),
                        }}
                    >
                        {t.label}
                    </button>
                ))}
                <button
                    data-testid="draw-undo"
                    onClick={handleUndo}
                    disabled={!canUndo}
                    style={{ ...btnStyle, opacity: canUndo ? 1 : 0.4, cursor: canUndo ? 'pointer' : 'not-allowed' }}
                >
                    ↶ Undo
                </button>
                <button
                    data-testid="draw-redo"
                    onClick={handleRedo}
                    disabled={!canRedo}
                    style={{ ...btnStyle, opacity: canRedo ? 1 : 0.4, cursor: canRedo ? 'pointer' : 'not-allowed' }}
                >
                    ↷ Redo
                </button>
            </div>
            <div style={{ display: 'flex', gap: 6, marginTop: 8, alignItems: 'center' }}>
                <span style={{ fontSize: 12, color: '#6b7280' }}>Target layer ID:</span>
                <input
                    data-testid="draw-layer-id"
                    type="number"
                    value={layerId}
                    onChange={(e) => setLayerId(e.target.value)}
                    placeholder="e.g. 418"
                    style={{ width: 80, padding: '4px 8px', border: '1px solid #d1d5db', borderRadius: 4, fontSize: 13 }}
                />
                <button
                    data-testid="draw-save"
                    onClick={handleSave}
                    disabled={saving}
                    style={{
                        ...btnStyle,
                        background: '#059669',
                        color: '#fff',
                        borderColor: '#047857',
                        opacity: saving ? 0.6 : 1,
                    }}
                >
                    {saving ? 'Saving…' : '💾 Save drawn feature'}
                </button>
            </div>
            {message && (
                <div
                    data-testid="draw-message"
                    style={{
                        marginTop: 8,
                        fontSize: 12,
                        color: message.kind === 'ok' ? '#166534' : '#b91c1c',
                        background: message.kind === 'ok' ? '#f0fdf4' : '#fef2f2',
                        border: `1px solid ${message.kind === 'ok' ? '#86efac' : '#fecaca'}`,
                        borderRadius: 6,
                        padding: '6px 10px',
                    }}
                >
                    {message.text}
                </div>
            )}
        </div>
    );
}

const btnStyle: React.CSSProperties = {
    background: '#fff',
    border: '1px solid #d1d5db',
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
