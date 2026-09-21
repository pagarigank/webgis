import apiClient from '../../../lib/apiClient';
import type { LayerField } from '../types';

export const fieldApi = {
    getAll: async (layerId: number): Promise<LayerField[]> => {
        return await apiClient.get(`/layers/${layerId}/fields`);
    },
    
    create: async (layerId: number, data: Partial<LayerField>): Promise<{id: number}> => {
        return await apiClient.post(`/layers/${layerId}/fields`, data);
    },
    
    update: async (layerId: number, id: number, data: Partial<LayerField>): Promise<{id: number}> => {
        return await apiClient.put(`/layers/${layerId}/fields/${id}`, data);
    },
    
    delete: async (layerId: number, id: number): Promise<void> => {
        await apiClient.delete(`/layers/${layerId}/fields/${id}`);
    }
};
