import { describe, it, expect } from 'vitest';
import { useAppStore } from './store/useAppStore';

describe('useAppStore', () => {
  it('should toggle menu state', () => {
    const initialState = useAppStore.getState().isMenuOpen;
    expect(initialState).toBe(false);

    useAppStore.getState().toggleMenu();
    expect(useAppStore.getState().isMenuOpen).toBe(true);
  });
});
