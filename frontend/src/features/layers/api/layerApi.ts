import apiClient from '../../../lib/apiClient';
import type { Layer } from '../types';

export const layerApi = {
    getAll: async (): Promise<Layer[]> => {
        const response = await apiClient.get('/layers');
        return response.data.data;
    },

    getById: async (id: number): Promise<Layer> => {
        const response = await apiClient.get(`/layers/${id}`);
        return response.data.data;
    },

    create: async (data: Partial<Layer>): Promise<{id: number}> => {
        const response = await apiClient.post('/layers', data);
        return response.data.data;
    },

    update: async (id: number, data: Partial<Layer> & { version?: number }): Promise<{id: number; version: number}> => {
        const headers: Record<string, string> = {};
        if (data.version != null) {
            headers['If-Match'] = String(data.version);
        }
        const response = await apiClient.put(`/layers/${id}`, data, { headers });
        return response.data.data;
    },

    delete: async (id: number): Promise<void> => {
        await apiClient.delete(`/layers/${id}`);
    }
};
