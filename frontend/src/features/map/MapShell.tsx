import React, { useEffect, useState } from 'react';
import { MapProvider, useMapContext } from './MapContext';
import apiClient from '../../lib/apiClient';
import type { BasemapProvider } from './types';
import { useAuth } from '../../auth/useAuth';

// Child component that manages fetching basemaps and applying the default one
const BasemapLoader: React.FC = () => {
    const { map, isLoaded } = useMapContext();
    const { status } = useAuth();
    const [providers, setProviders] = useState<BasemapProvider[]>([]);

    useEffect(() => {
        if (status !== 'authenticated') return;

        apiClient.get('/basemaps')
            .then((res: any) => setProviders(res))
            .catch((err: any) => console.error('Failed to load basemaps', err));
    }, [status]);

    useEffect(() => {
        if (!map || !isLoaded || providers.length === 0) return;

        // Find the default provider, or just use the first one
        const defaultProvider = providers.find(p => p.is_default) || providers[0];
        if (!defaultProvider) return;

        const providerType = defaultProvider.provider_type;
        const tilesUrl = (defaultProvider.requires_api_key || defaultProvider.proxy_required)
            ? `/api/v1/basemaps/${defaultProvider.id}/tiles/{z}/{x}/{y}`
            : defaultProvider.url_template;

        // ---- XYZ raster tiles: update only the source URL, never call setStyle() ----
        if (providerType === 'XYZ' && tilesUrl) {
            if (map.getSource('basemap')) {
                //@ts-expect-error — maplibre-gl types aren't in this project's tsconfig yet
                map.getSource('basemap').setTiles([tilesUrl]);
            } else {
                map.addSource('basemap', {
                    type: 'raster',
                    tiles: [tilesUrl],
                    tileSize: 256,
                    attribution: defaultProvider.attribution_html ?? ''
                });
                if (map.getLayer('basemap-layer')) {
                    map.removeLayer('basemap-layer');
                }
                map.addLayer({
                    id: 'basemap-layer',
                    type: 'raster',
                    source: 'basemap',
                    minzoom: defaultProvider.min_zoom ?? 0,
                    maxzoom: defaultProvider.max_zoom ?? 19
                });
            }
            return;
        }

        // ---- Non-XYZ types are not yet implemented in the frontend renderer ----
        // Log so the mismatch between what the admin form allows and what the
        // map can render is visible during development.
        console.warn(
            `[BasemapLoader] Provider "${defaultProvider.name}" uses unsupported ` +
            `provider_type "${providerType}". Only XYZ tiles are rendered client-side ` +
            `right now. The provider row is stored and listed correctly, but the map ` +
            `keeps its current style.`
        );
    }, [map, isLoaded, providers]);

    return null;
};

export const MapShell: React.FC<{ children?: React.ReactNode }> = ({ children }) => {
    return (
        <MapProvider>
            <BasemapLoader />
            {children}
        </MapProvider>
    );
};
