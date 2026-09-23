import React from 'react';

export interface ControlPointStatusBadgeProps {
  status: 'UNVERIFIED' | 'VERIFIED' | 'DISPUTED' | 'RETIRED' | string;
  size?: 'sm' | 'md' | 'lg';
}

export const ControlPointStatusBadge: React.FC<ControlPointStatusBadgeProps> = ({
  status,
  size = 'md',
}) => {
  const normalized = (status || 'UNVERIFIED').toUpperCase();

  const styles: Record<string, { bg: string; color: string; border: string; label: string; icon: string }> = {
    UNVERIFIED: {
      bg: '#fff3cd',
      color: '#856404',
      border: '2px solid #ffeeba',
      label: '⚠️ UNVERIFIED',
      icon: '⚠️',
    },
    VERIFIED: {
      bg: '#d4edda',
      color: '#155724',
      border: '1px solid #c3e6cb',
      label: '✓ VERIFIED',
      icon: '✓',
    },
    DISPUTED: {
      bg: '#f8d7da',
      color: '#721c24',
      border: '1px solid #f5c6cb',
      label: '⛔ DISPUTED',
      icon: '⛔',
    },
    RETIRED: {
      bg: '#e2e3e5',
      color: '#383d41',
      border: '1px solid #d6d8db',
      label: '○ RETIRED',
      icon: '○',
    },
  };

  const styleConfig = styles[normalized] || styles.UNVERIFIED;

  const fontSizes = {
    sm: '0.75rem',
    md: '0.85rem',
    lg: '1rem',
  };

  const paddings = {
    sm: '2px 6px',
    md: '4px 10px',
    lg: '6px 14px',
  };

  return (
    <span
      data-testid={`control-point-status-${normalized.toLowerCase()}`}
      style={{
        display: 'inline-flex',
        alignItems: 'center',
        gap: '4px',
        fontWeight: 600,
        borderRadius: '4px',
        fontSize: fontSizes[size],
        padding: paddings[size],
        backgroundColor: styleConfig.bg,
        color: styleConfig.color,
        border: styleConfig.border,
        boxShadow: normalized === 'UNVERIFIED' ? '0 0 0 1px rgba(220, 53, 69, 0.4)' : undefined,
        textTransform: 'uppercase',
        letterSpacing: '0.04em',
      }}
    >
      {styleConfig.label}
    </span>
  );
};
