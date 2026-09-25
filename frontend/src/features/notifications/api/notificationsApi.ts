import apiClient from '../../../lib/apiClient';

/**
 * TASK-103 — notification read surface (api.md §12). The workflow engine
 * writes rows for the parcel creator on every transition (FR-140); these
 * calls read them and stamp them read. A notification is private to its
 * recipient — the server scopes every query by user_id.
 */
export interface NotificationRow {
    id: number;
    type: string;
    title: string;
    body: string | null;
    entity_type: string | null;
    entity_id: string | null;
    read_at: string | null;
    created_at: string;
}

export interface NotificationsPayload {
    data: NotificationRow[];
    total: number;
    unread_count: number;
    limit: number;
    offset: number;
}

export const notificationsApi = {
    list: async (unreadOnly = false, limit = 50, offset = 0): Promise<NotificationsPayload> => {
        return (await apiClient.get('/notifications', {
            params: {
                ...(unreadOnly ? { unread: 1 } : {}),
                limit,
                offset,
            },
        })) as NotificationsPayload;
    },

    /** Idempotent on the server; resolves with the updated payload. */
    markRead: async (id: number): Promise<{ id: number; read: boolean }> => {
        return (await apiClient.post(`/notifications/${id}/read`)) as { id: number; read: boolean };
    },
};
