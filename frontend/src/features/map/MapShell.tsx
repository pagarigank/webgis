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

        // If it's an XYZ provider, update the style
        if (defaultProvider.provider_type === 'XYZ' && defaultProvider.url_template) {
            const tilesUrl = (defaultProvider.requires_api_key || defaultProvider.proxy_required)
                ? `/api/v1/basemaps/${defaultProvider.id}/tiles/{z}/{x}/{y}`
                : defaultProvider.url_template;

            // Check if source already exists
            if (map.getSource('basemap')) {
                // To replace a source in MapLibre, you often have to remove layers, then source, then add back.
                // For simplicity, we just set the style.
                map.setStyle({
                    version: 8,
                    sources: {
                        'basemap': {
                            type: 'raster',
                            tiles: [tilesUrl],
                            tileSize: 256,
                            attribution: defaultProvider.attribution_html
                        }
                    },
                    layers: [
                        {
                            id: 'basemap-layer',
                            type: 'raster',
                            source: 'basemap',
                            minzoom: defaultProvider.min_zoom ?? 0,
                            maxzoom: defaultProvider.max_zoom ?? 19
                        }
                    ]
                });
            } else {
                map.setStyle({
                    version: 8,
                    sources: {
                        'basemap': {
                            type: 'raster',
                            tiles: [tilesUrl],
                            tileSize: 256,
                            attribution: defaultProvider.attribution_html
                        }
                    },
                    layers: [
                        {
                            id: 'basemap-layer',
                            type: 'raster',
                            source: 'basemap',
                            minzoom: defaultProvider.min_zoom ?? 0,
                            maxzoom: defaultProvider.max_zoom ?? 19
                        }
                    ]
                });
            }
        }
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
