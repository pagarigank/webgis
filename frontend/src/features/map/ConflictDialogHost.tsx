// @ts-nocheck
import React, { useState, useCallback, useEffect } from 'react';
import { useMapContext } from './MapContext';
import { ConflictDialog } from '../../components/dialogs/ConflictDialog';
import { layerApi } from '../layers/api/layerApi';
import type { DrawError } from './DrawManager';

export function ConflictDialogHost() {
    const ctx = useMapContext();
    const [conflict, setConflict] = useState<{
        error: DrawError;
        yourVersion: any;
        currentVersion?: number;
        layerId: number;
        featureId: string;
    } | null>(null);

    const handleError = useCallback(
        (error: DrawError, fallback: { layerId: number; featureId: string; yourVersion: any }) => {
            if (error.type === 'version_conflict') {
                setConflict({
                    error,
                    yourVersion: fallback.yourVersion,
                    currentVersion: (error.detail as any)?.current_version,
                    layerId: fallback.layerId,
                    featureId: fallback.featureId,
                });
            } else {
                if (ctx.onError) ctx.onError(error);
            }
        },
        [ctx],
    );

    useEffect(() => {
        ctx.registerConflictHandler(handleError);
    }, [ctx, handleError]);

    const handleReload = useCallback(async () => {
        if (!conflict) return;
        try {
            const latest = await layerApi.getFeature(conflict.layerId, conflict.featureId);
            // Signal DrawManager to re-apply the latest geometry
            ctx.drawManager?.applyFeatureToDraw(latest as any);
            setConflict(null);
            return latest;
        } catch (err) {
            console.error('Conflict reload failed:', err);
            return null;
        }
    }, [conflict, ctx]);

    const handleCompare = useCallback(() => {
        // Opens a comparison view — for now, just close and log
        console.info('Compare versions:', {
            yourVersion: conflict?.yourVersion,
            currentVersion: conflict?.currentVersion,
        });
        setConflict(null);
    }, [conflict]);

    const handleNewVersion = useCallback(async () => {
        if (!conflict) return;
        try {
            // Save as a new version: fetch current, then update with current version
            const latest = await layerApi.getFeature(conflict.layerId, conflict.featureId);
            // Merge: keep the user's pending changes (yourVersion) but bump version
            // For geometry edits, use yourVersion.geometry; for attribute-only, merge attributes
            const updates: any = {};
            if (conflict.yourVersion.geometry) {
                updates.geometry = conflict.yourVersion.geometry;
            }
            if (conflict.yourVersion.attributes) {
                updates.attributes = { ...(conflict.yourVersion.attributes || {}) };
            }
            const saved = await layerApi.updateFeature(
                conflict.layerId,
                conflict.featureId,
                { ...updates, version: latest.version },
                latest.version,
            );
            ctx.drawManager?.applyFeatureToDraw(saved as any);
            setConflict(null);
            return saved;
        } catch (err) {
            console.error('Conflict new-version save failed:', err);
            return null;
        }
    }, [conflict, ctx]);

    const handleClose = useCallback(() => {
        setConflict(null);
    }, []);

    if (!conflict) return null;

    return (
        <ConflictDialog
            error={conflict.error}
            yourVersion={conflict.yourVersion}
            currentVersion={conflict.currentVersion}
            layerId={conflict.layerId}
            onClose={handleClose}
            onReload={handleReload}
            onCompare={handleCompare}
            onNewVersion={handleNewVersion}
        />
    );
}
