import apiClient from '../../../lib/apiClient';
import type { LayerStyle } from '../types';

export const styleApi = {
    getAll: async (layerId: number): Promise<LayerStyle[]> => {
        const response = await apiClient.get(`/layers/${layerId}/styles`);
        return response.data.data;
    },
    
    create: async (layerId: number, data: Partial<LayerStyle>): Promise<{id: number}> => {
        const response = await apiClient.post(`/layers/${layerId}/styles`, data);
        return response.data.data;
    },
    
    update: async (layerId: number, id: number, data: Partial<LayerStyle>): Promise<{id: number}> => {
        const response = await apiClient.put(`/layers/${layerId}/styles/${id}`, data);
        return response.data.data;
    }
};
