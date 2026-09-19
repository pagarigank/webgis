import { create } from 'zustand';

interface AppState {
  isMenuOpen: boolean;
  toggleMenu: () => void;
  // This store will hold global UI state; data state goes to TanStack Query
}

export const useAppStore = create<AppState>((set) => ({
  isMenuOpen: false,
  toggleMenu: () => set((state) => ({ isMenuOpen: !state.isMenuOpen })),
}));
