/**
 * @vitest-environment jsdom
 */
import { describe, it, expect, vi, afterEach } from 'vitest';
import { render, screen, cleanup } from '@testing-library/react';
import { PermissionGate } from './PermissionGate';
import { useAuth } from '../../auth/useAuth';
import type { MePayload } from '../../auth/types';

afterEach(cleanup);

vi.mock('../../auth/useAuth', () => ({
  useAuth: vi.fn(),
}));

describe('PermissionGate', () => {
  const mockMe = (permissions: string[]): MePayload => ({
    user: { id: 1, username: 'test', full_name: 'Test' },
    roles: [],
    permissions,
    layer_capabilities: {},
    scopes: [],
    scope_version: 1,
  });

  it('renders children when permission is granted', () => {
    vi.mocked(useAuth).mockReturnValue({ me: mockMe(['basemap.view']) } as any);
    
    render(
      <PermissionGate permission="basemap.view">
        <button>Test Button</button>
      </PermissionGate>
    );
    
    expect(screen.getByText('Test Button')).toBeDefined();
    expect(screen.getByText('Test Button').getAttribute('disabled')).toBeNull();
  });

  it('hides children and renders fallback when permission is denied (mode=hide)', () => {
    vi.mocked(useAuth).mockReturnValue({ me: mockMe([]) } as any);
    
    render(
      <PermissionGate permission="basemap.view" mode="hide" fallback={<span>Fallback</span>}>
        <button>Test Button</button>
      </PermissionGate>
    );
    
    expect(screen.queryByText('Test Button')).toBeNull();
    expect(screen.getByText('Fallback')).toBeDefined();
  });

  it('disables children when permission is denied (mode=disable)', () => {
    vi.mocked(useAuth).mockReturnValue({ me: mockMe([]) } as any);
    
    render(
      <PermissionGate permission="basemap.view" mode="disable">
        <button>Test Button</button>
      </PermissionGate>
    );
    
    const button = screen.getByText('Test Button');
    expect(button).toBeDefined();
    expect(button.getAttribute('disabled')).toBe('');
    expect(button.getAttribute('title')).toBe('You do not have permission to perform this action.');
  });

  it('forces hide mode when permission is destructive, even if disable is requested', () => {
    vi.mocked(useAuth).mockReturnValue({ me: mockMe([]) } as any);
    
    render(
      <PermissionGate permission="gis.feature.delete" mode="disable">
        <button>Delete Button</button>
      </PermissionGate>
    );
    
    expect(screen.queryByText('Delete Button')).toBeNull();
  });
});
