import apiClient from '../../../lib/apiClient';
import type { LayerStyle } from '../types';

export const styleApi = {
    getAll: async (layerId: number): Promise<LayerStyle[]> => {
        return await apiClient.get(`/layers/${layerId}/styles`);
    },
    
    create: async (layerId: number, data: Partial<LayerStyle>): Promise<{id: number}> => {
        return await apiClient.post(`/layers/${layerId}/styles`, data);
    },
    
    update: async (layerId: number, id: number, data: Partial<LayerStyle>): Promise<{id: number}> => {
        return await apiClient.put(`/layers/${layerId}/styles/${id}`, data);
    }
};
