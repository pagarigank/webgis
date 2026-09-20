import apiClient from '../../../lib/apiClient';
import type { LayerField } from '../types';

export const fieldApi = {
    getAll: async (layerId: number): Promise<LayerField[]> => {
        const response = await apiClient.get(`/layers/${layerId}/fields`);
        return response.data.data;
    },
    
    create: async (layerId: number, data: Partial<LayerField>): Promise<{id: number}> => {
        const response = await apiClient.post(`/layers/${layerId}/fields`, data);
        return response.data.data;
    },
    
    update: async (layerId: number, id: number, data: Partial<LayerField>): Promise<{id: number}> => {
        const response = await apiClient.put(`/layers/${layerId}/fields/${id}`, data);
        return response.data.data;
    },
    
    delete: async (layerId: number, id: number): Promise<void> => {
        await apiClient.delete(`/layers/${layerId}/fields/${id}`);
    }
};
