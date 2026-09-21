import { createPortal } from 'react';

interface ModalProps {
    open: boolean;
    onClose: () => void;
    children: React.ReactNode;
    title?: string;
}

export function Modal({ open, onClose, children, title }: ModalProps) {
    if (!open) return null;

    return createPortal(
        <div
            style={{
                position: 'fixed',
                inset: 0,
                background: 'rgba(0,0,0,0.5)',
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
                zIndex: 9999,
            }}
            onClick={onClose}
        >
            <div
                style={{
                    background: '#fff',
                    borderRadius: 8,
                    boxShadow: '0 8px 32px rgba(0,0,0,0.3)',
                    maxWidth: 520,
                    width: '90%',
                    maxHeight: '80vh',
                    overflow: 'auto',
                    padding: '16px 20px 20px',
                }}
                onClick={(e) => e.stopPropagation()}
                role="dialog"
                aria-modal="true"
                aria-labelledby="conflict-dialog-title"
            >
                {title && (
                    <div
                        style={{
                            display: 'flex',
                            alignItems: 'center',
                            justifyContent: 'space-between',
                            marginBottom: 12,
                        }}
                    >
                        <h2
                            id="conflict-dialog-title"
                            style={{
                                margin: 0,
                                fontSize: 16,
                                fontWeight: 600,
                                color: '#1a1a1a',
                            }}
                        >
                            {title}
                        </h2>
                        <button
                            onClick={onClose}
                            style={{
                                background: 'none',
                                border: 'none',
                                fontSize: 20,
                                cursor: 'pointer',
                                color: '#666',
                                lineHeight: 1,
                                padding: '4px 8px',
                                borderRadius: 4,
                            }}
                            aria-label="Close dialog"
                        >
                            ×
                        </button>
                    </div>
                )}
                {children}
            </div>
        </div>,
        document.body,
    );
}
