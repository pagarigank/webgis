import { useEffect, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { notificationsApi } from '../api/notificationsApi';
import type { NotificationRow } from '../api/notificationsApi';

/**
 * TASK-103 — notification bell (FR-140 read surface, frontend.md §4).
 *
 * Lives in the app header. Polls GET /notifications every 30 s, shows the
 * unread count as a badge, and lists recent rows in a dropdown. Marking one
 * read is optimistic (frontend.md §11: cheap toggles may be optimistic) and
 * clicking a PARCEL notification opens the parcel editor.
 */
export function NotificationBell() {
    const navigate = useNavigate();
    const queryClient = useQueryClient();
    const [open, setOpen] = useState(false);
    const rootRef = useRef<HTMLDivElement | null>(null);

    const { data } = useQuery({
        queryKey: ['notifications'],
        queryFn: () => notificationsApi.list(false, 20, 0),
        refetchInterval: 30_000,
    });

    const markRead = useMutation({
        mutationFn: (id: number) => notificationsApi.markRead(id),
        // Optimistic: stamp the row read in the cache immediately; the poll
        // and the mutation's server truth converge afterwards.
        onMutate: async (id: number) => {
            await queryClient.cancelQueries({ queryKey: ['notifications'] });
            const previous = queryClient.getQueryData(['notifications']);
            queryClient.setQueryData(['notifications'], (old: ReturnType<typeof notificationsApi.list> extends Promise<infer T> ? T : never) => {
                if (!old || typeof old !== 'object') return old;
                const payload = old as { data: NotificationRow[]; unread_count: number };
                const wasUnread = payload.data.some((n) => n.id === id && n.read_at === null);
                return {
                    ...payload,
                    unread_count: Math.max(0, payload.unread_count - (wasUnread ? 1 : 0)),
                    data: payload.data.map((n) => (n.id === id && n.read_at === null ? { ...n, read_at: new Date().toISOString() } : n)),
                };
            });
            return { previous };
        },
        onError: (_err, _id, context) => {
            if (context?.previous !== undefined) {
                queryClient.setQueryData(['notifications'], context.previous);
            }
        },
    });

    // Close the dropdown on any outside click.
    useEffect(() => {
        if (!open) return;
        const onDocClick = (e: MouseEvent) => {
            if (rootRef.current && !rootRef.current.contains(e.target as Node)) {
                setOpen(false);
            }
        };
        document.addEventListener('mousedown', onDocClick);
        return () => document.removeEventListener('mousedown', onDocClick);
    }, [open]);

    const unread = data?.unread_count ?? 0;
    const items: NotificationRow[] = data?.data ?? [];

    const openNotification = (n: NotificationRow) => {
        if (n.read_at === null) markRead.mutate(n.id);
        if (n.entity_type === 'PARCEL' && n.entity_id) {
            setOpen(false);
            navigate(`/parcels/${n.entity_id}`);
        }
    };

    return (
        <div className="dropdown" ref={rootRef} data-testid="notification-bell">
            <button
                type="button"
                className="btn btn-outline-secondary btn-sm position-relative"
                onClick={() => setOpen((o) => !o)}
                aria-label={`Notifications (${unread} unread)`}
                data-testid="notification-bell-button"
            >
                <i className="bi bi-bell" aria-hidden="true" />
                {unread > 0 && (
                    <span
                        className="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger"
                        data-testid="notification-badge"
                    >
                        {unread > 99 ? '99+' : unread}
                        <span className="visually-hidden">unread notifications</span>
                    </span>
                )}
            </button>

            {open && (
                <div
                    className="dropdown-menu dropdown-menu-end show shadow-sm"
                    style={{ minWidth: 340, maxHeight: 420, overflowY: 'auto' }}
                    data-testid="notification-menu"
                >
                    <h6 className="dropdown-header">Notifications</h6>
                    {items.length === 0 && (
                        <div className="px-3 py-3 text-muted small" data-testid="notification-empty">
                            No notifications yet.
                        </div>
                    )}
                    {items.map((n) => (
                        <button
                            key={n.id}
                            type="button"
                            className={`dropdown-item text-wrap small ${n.read_at === null ? 'fw-semibold bg-light' : ''}`}
                            onClick={() => openNotification(n)}
                            data-testid="notification-item"
                            data-unread={n.read_at === null ? 'true' : 'false'}
                            title={n.body ?? n.title}
                        >
                            <span className="d-block text-muted" style={{ fontSize: '0.72rem' }}>
                                {n.type} · {new Date(n.created_at).toLocaleString()}
                            </span>
                            {n.title}
                        </button>
                    ))}
                </div>
            )}
        </div>
    );
}
